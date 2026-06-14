<?php
/**
 * Admin page registration and asset loader.
 *
 * @package S3S\WP\ThemeJSONEditor\Admin
 */

namespace S3S\WP\ThemeJSONEditor\Admin;

use S3S\WP\ThemeJSONEditor\Plugin;
use S3S\WP\ThemeJSONEditor\Repository\ThemeFileRepository;
use S3S\WP\ThemeJSONEditor\REST\Controller as RESTController;

/**
 * Registers the Appearance → Theme JSON Editor admin page and enqueues
 * the React webview bundle when it's the active screen. Boot data is
 * inlined as `window.wpThemeJSONEditor` so the JS knows the REST namespace
 * and the user's per-mode capabilities before its first request.
 */
class Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-theme-json-editor';

	/**
	 * Setup hooks.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the admin menu item under Appearance.
	 *
	 * @return void
	 */
	public function register_menu() {

		if ( Plugin::is_environment_blocked() ) {
			return;
		}

		add_theme_page(
			__( 'Theme JSON Editor', 'wp-theme-json-editor' ),
			__( 'Theme JSON Editor', 'wp-theme-json-editor' ),
			'edit_themes',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the admin page shell.
	 *
	 * Emits a non-dismissible warning above the editor root so users
	 * understand the destructive nature of saving (writes directly to
	 * theme.json with no revisions or backups), then mounts an empty
	 * div for the React app.
	 *
	 * @return void
	 */
	public function render() {

		printf(
			'<div class="wptje-admin-notice" role="status"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Heads up:', 'wp-theme-json-editor' ),
			esc_html__( 'Saving overwrites your active theme\'s theme.json directly on disk. There are no revisions and no automatic backups. Keep your theme under version control (git or a deployment pipeline) and avoid using this on production without a recent backup.', 'wp-theme-json-editor' )
		);

		printf(
			'<div class="wrap" id="%s"></div>',
			esc_attr( self::PAGE_SLUG . '-root' )
		);
	}

	/**
	 * Enqueue editor assets on the admin page.
	 *
	 * @param  string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {

		if ( 'appearance_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = S3S_THEME_JSON_EDITOR_DIST_PATH . 'admin.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : null;

		$dependencies = $asset['dependencies'] ?? [ 'wp-api-fetch', 'wp-i18n', 'wp-components' ];
		$version      = $asset['version'] ?? S3S_THEME_JSON_EDITOR_VERSION;

		wp_enqueue_script(
			'wp-theme-json-editor',
			S3S_THEME_JSON_EDITOR_DIST_URL . 'admin.js',
			$dependencies,
			$version,
			true
		);

		// Editor CSS is inlined into the JS bundle (Vite IIFE).
		// Only the WP components stylesheet needs explicit enqueue.
		wp_enqueue_style( 'wp-components' );

		$style_asset_file = S3S_THEME_JSON_EDITOR_DIST_PATH . 'admin-style.asset.php';
		$style_asset      = file_exists( $style_asset_file ) ? require $style_asset_file : null;
		$style_deps       = $style_asset['dependencies'] ?? [ 'wp-components' ];
		$style_version    = $style_asset['version'] ?? $version;

		wp_enqueue_style(
			'wp-theme-json-editor',
			S3S_THEME_JSON_EDITOR_DIST_URL . 'admin.css',
			$style_deps,
			$style_version
		);

		$user               = wp_get_current_user();
		$theme_writable     = current_user_can( 'edit_themes' ) && wp_is_writable( get_stylesheet_directory() . '/theme.json' );
		$global_styles_mode = Plugin::is_global_styles_mode_enabled() && current_user_can( 'edit_theme_options' );
		$default_mode       = $theme_writable ? 'theme' : ( $global_styles_mode ? 'user' : 'theme' );
		$theme_files        = current_user_can( 'edit_themes' ) ? ( new ThemeFileRepository() )->list() : [];

		wp_add_inline_script(
			'wp-theme-json-editor',
			sprintf(
				'window.wpThemeJSONEditor = %s;',
				wp_json_encode(
					[
						'restNamespace' => RESTController::REST_NAMESPACE,
						'rootId'        => self::PAGE_SLUG . '-root',
						'wpVersion'     => get_bloginfo( 'version' ),
						'caps'          => [
							'edit_themes'        => $theme_writable,
							'edit_theme_options' => $global_styles_mode,
						],
						'defaultMode'   => $default_mode,
						'files'         => $theme_files,
						'user'          => [
							'id'    => (int) $user->ID,
							'login' => $user->user_login,
						],
					]
				)
			),
			'before'
		);
	}
}
