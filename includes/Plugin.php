<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package S3S\WP\ThemeJSONEditor
 */

namespace S3S\WP\ThemeJSONEditor;

use S3S\WP\ThemeJSONEditor\Admin\Page;
use S3S\WP\ThemeJSONEditor\REST\Controller;

/**
 * Plugin bootstrap: registers hooks, admin notices, and the settings page.
 */
class Plugin {

	/**
	 * Plugin singleton instance.
	 *
	 * @var Plugin
	 */
	public static $instance = null;

	/**
	 * Retrieve the plugin instance.
	 *
	 * @return Plugin The plugin instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Setup hooks and register modules.
	 *
	 * @return void
	 */
	public function setup() {
		( new Page() )->setup();
		( new Controller() )->setup();
	}

	/**
	 * Whether the editor is blocked on the current environment.
	 *
	 * Returns `true` on `production` and `false` on every other
	 * `wp_get_environment_type()` value. Filter the result via
	 * `wp_theme_json_editor_block_environment` to override.
	 *
	 * @return bool
	 */
	public static function is_environment_blocked() {

		/**
		 * Filters whether the Theme JSON Editor is blocked on the
		 * current environment.
		 *
		 * @param  bool $blocked Whether to block the editor. Default true on production, false elsewhere.
		 * @return bool
		 */
		return (bool) apply_filters( 'wp_theme_json_editor_block_environment', wp_get_environment_type() === 'production' );
	}

	/**
	 * Whether the User Global Styles editing mode is exposed.
	 *
	 * Disabled by default because the WordPress Site Editor already
	 * provides a visual interface for the same data. Opt in via the
	 * `wp_theme_json_editor_enable_global_styles_mode` filter.
	 *
	 * @return bool
	 */
	public static function is_global_styles_mode_enabled() {

		/**
		 * Filters whether the User Global Styles editing mode is
		 * exposed in the WP Theme JSON Editor.
		 *
		 * Disabled by default. Return `true` to expose it alongside
		 * the theme-file mode (the Site Editor already provides a
		 * visual UI for the same data, so this is mainly useful for
		 * power-users editing experimental or undocumented props).
		 *
		 * @param  bool $enabled Whether the mode is enabled.
		 * @return bool
		 */
		return (bool) apply_filters( 'wp_theme_json_editor_enable_global_styles_mode', false );
	}
}
