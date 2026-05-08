<?php
/**
 * File-based theme.json repository.
 *
 * @package S3S\WP\ThemeJSONEditor\Repository
 */

namespace S3S\WP\ThemeJSONEditor\Repository;

/**
 * Reads and writes the active theme's `theme.json` from disk via
 * `WP_Filesystem`. Implements optimistic concurrency via an mtime+size
 * etag — saves whose etag has drifted return a 409 with the current
 * server-side payload so the UI can show a conflict banner.
 */
class ThemeFileRepository {

	/**
	 * Read theme.json from the active theme.
	 *
	 * Falls back to the parent theme's theme.json if the child theme
	 * doesn't ship one.
	 *
	 * @return array{data: array, etag: string, path: string}|\WP_Error
	 */
	public function read() {

		$path = $this->resolve_path();

		if ( null === $path ) {
			return new \WP_Error(
				'wptje_theme_json_unreadable',
				__( 'theme.json could not be read from the active theme.', 'wp-theme-json-editor' ),
				[ 'status' => 404 ]
			);
		}

		$filesystem = $this->get_filesystem();

		if ( ! $filesystem || ! $filesystem->is_readable( $path ) ) {
			return new \WP_Error(
				'wptje_theme_json_unreadable',
				__( 'theme.json could not be read from the active theme.', 'wp-theme-json-editor' ),
				[ 'status' => 404 ]
			);
		}

		$contents = $filesystem->get_contents( $path );

		if ( false === $contents ) {
			return new \WP_Error(
				'wptje_theme_json_read_failed',
				__( 'Failed to read theme.json from disk.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		$decoded = json_decode( $contents, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'wptje_theme_json_invalid',
				sprintf(
					/* translators: %s: JSON error message. */
					__( 'theme.json is not valid JSON: %s', 'wp-theme-json-editor' ),
					json_last_error_msg()
				),
				[ 'status' => 422 ]
			);
		}

		return [
			'data' => $decoded,
			'etag' => $this->etag_for( $path ),
			'path' => str_replace( ABSPATH, '', $path ),
		];
	}

	/**
	 * Write theme.json to the active theme.
	 *
	 * @param  array  $data Decoded theme.json data.
	 * @param  string $etag Client's last-known etag for optimistic concurrency.
	 * @return array{etag: string}|\WP_Error
	 */
	public function write( array $data, $etag = '' ) {

		if ( ! current_user_can( 'edit_themes' ) ) {
			return new \WP_Error(
				'wptje_forbidden',
				__( 'You do not have permission to edit theme files.', 'wp-theme-json-editor' ),
				[ 'status' => 403 ]
			);
		}

		$filesystem = $this->get_filesystem();

		if ( ! $filesystem ) {
			return new \WP_Error(
				'wptje_filesystem_unavailable',
				__( 'WP_Filesystem could not be initialised.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		$path = $this->resolve_path();

		if ( null === $path ) {
			$path = trailingslashit( get_stylesheet_directory() ) . 'theme.json';
		}

		if ( $filesystem->exists( $path ) ) {
			$current_etag = $this->etag_for( $path );
			if ( '' !== $etag && $etag !== $current_etag ) {
				$current = $filesystem->get_contents( $path );
				$decoded = false !== $current ? json_decode( $current, true ) : null;
				return new \WP_Error(
					'wptje_etag_conflict',
					__( 'theme.json was modified externally since you loaded it.', 'wp-theme-json-editor' ),
					[
						'status'      => 409,
						'server_etag' => $current_etag,
						'server_data' => $decoded,
					]
				);
			}
		}

		if ( $filesystem->exists( $path ) && ! $filesystem->is_writable( $path ) ) {
			return new \WP_Error(
				'wptje_not_writable',
				__( 'theme.json is not writable. Check filesystem permissions.', 'wp-theme-json-editor' ),
				[ 'status' => 403 ]
			);
		}

		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $encoded ) {
			return new \WP_Error(
				'wptje_encode_failed',
				__( 'Failed to encode theme.json payload.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		$written = $filesystem->put_contents( $path, $encoded . "\n", FS_CHMOD_FILE );

		if ( ! $written ) {
			return new \WP_Error(
				'wptje_write_failed',
				__( 'Failed to write theme.json to disk.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		clearstatcache( true, $path );

		return [
			'etag' => $this->etag_for( $path ),
		];
	}

	/**
	 * Resolve the path to the active theme's theme.json.
	 *
	 * Prefers the child theme; falls back to the parent.
	 *
	 * @return string|null Absolute path or null when neither theme has the file.
	 */
	protected function resolve_path() {

		$candidates = [
			trailingslashit( get_stylesheet_directory() ) . 'theme.json',
			trailingslashit( get_template_directory() ) . 'theme.json',
		];

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Lazy-init the WP_Filesystem instance with a direct backend.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	protected function get_filesystem() {

		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! \WP_Filesystem() ) {
			return null;
		}

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Build an etag from the file's mtime + size.
	 *
	 * @param  string $path Absolute path to theme.json.
	 * @return string
	 */
	protected function etag_for( $path ) {
		clearstatcache( true, $path );
		return md5( filemtime( $path ) . ':' . filesize( $path ) );
	}
}
