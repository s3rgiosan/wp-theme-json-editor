<?php
/**
 * File-based theme JSON repository.
 *
 * @package S3S\WP\ThemeJSONEditor\Repository
 */

namespace S3S\WP\ThemeJSONEditor\Repository;

/**
 * Reads and writes the active theme's `theme.json` and its style
 * variations (`styles/*.json`) from disk via `WP_Filesystem`.
 *
 * Files are addressed by a stable id — `theme.json` for the root file
 * and the `styles/`-relative path for variations (e.g.
 * `styles/dark.json`). Ids are never concatenated into a path: every id
 * is resolved against a map built by scanning the theme directories, so
 * a request can only ever reach a real file inside the active (or
 * parent) theme. Discovery mirrors core's
 * `WP_Theme_JSON_Resolver::get_style_variations()` — the `styles/`
 * directory is scanned recursively and child-theme files win over the
 * parent on matching relative paths.
 *
 * Implements optimistic concurrency via an mtime+size etag — saves whose
 * etag has drifted return a 409 with the current server-side payload so
 * the UI can show a conflict banner.
 */
class ThemeFileRepository {

	/**
	 * Id of the theme's root `theme.json` file.
	 *
	 * @var string
	 */
	const THEME_JSON_ID = 'theme.json';

	/**
	 * List the editable theme JSON files in the active theme.
	 *
	 * Returns `theme.json` first, then style variations. Each entry is
	 * suitable for building the editor's file picker.
	 *
	 * @return array<int, array{id: string, title: string, indent: bool}>
	 */
	public function list() {

		$items = [];

		foreach ( $this->discover() as $id => $path ) {
			$items[] = [
				'id'     => $id,
				'title'  => $this->title_for( $id, $path ),
				'indent' => self::THEME_JSON_ID !== $id,
			];
		}

		return $items;
	}

	/**
	 * Read a theme JSON file by id.
	 *
	 * @param  string $id File id (`theme.json` or a `styles/`-relative path).
	 * @return array{data: array, etag: string, path: string, id: string}|\WP_Error
	 */
	public function read( $id = self::THEME_JSON_ID ) {

		$path = $this->resolve( $id );

		if ( null === $path ) {
			return new \WP_Error(
				'wptje_file_not_found',
				__( 'The requested theme file could not be found.', 'wp-theme-json-editor' ),
				[ 'status' => 404 ]
			);
		}

		$filesystem = $this->get_filesystem();

		if ( ! $filesystem || ! $filesystem->is_readable( $path ) ) {
			return new \WP_Error(
				'wptje_file_unreadable',
				__( 'The requested theme file could not be read.', 'wp-theme-json-editor' ),
				[ 'status' => 404 ]
			);
		}

		$contents = $filesystem->get_contents( $path );

		if ( false === $contents ) {
			return new \WP_Error(
				'wptje_file_read_failed',
				__( 'Failed to read the theme file from disk.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		$decoded = json_decode( $contents, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'wptje_file_invalid',
				sprintf(
					/* translators: %s: JSON error message. */
					__( 'The theme file is not valid JSON: %s', 'wp-theme-json-editor' ),
					json_last_error_msg()
				),
				[ 'status' => 422 ]
			);
		}

		return [
			'data' => $decoded,
			'etag' => $this->etag_for( $path ),
			'path' => str_replace( ABSPATH, '', $path ),
			'id'   => $id,
		];
	}

	/**
	 * Write a theme JSON file by id.
	 *
	 * @param  string $id   File id (`theme.json` or a `styles/`-relative path).
	 * @param  array  $data Decoded theme.json data.
	 * @param  string $etag Client's last-known etag for optimistic concurrency.
	 * @return array{etag: string, id: string}|\WP_Error
	 */
	public function write( $id, array $data, $etag = '' ) {

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

		$path = $this->resolve( $id );

		// The root theme.json may be created when the theme ships without
		// one. Style variations must already exist on disk to be editable.
		if ( null === $path ) {
			if ( self::THEME_JSON_ID !== $id ) {
				return new \WP_Error(
					'wptje_file_not_found',
					__( 'The requested theme file could not be found.', 'wp-theme-json-editor' ),
					[ 'status' => 404 ]
				);
			}
			$path = trailingslashit( get_stylesheet_directory() ) . 'theme.json';
		}

		if ( ! $this->is_allowed_path( $path ) ) {
			return new \WP_Error(
				'wptje_forbidden',
				__( 'Refusing to write outside the active theme.', 'wp-theme-json-editor' ),
				[ 'status' => 403 ]
			);
		}

		if ( $filesystem->exists( $path ) ) {
			$current_etag = $this->etag_for( $path );
			if ( '' !== $etag && $etag !== $current_etag ) {
				$current = $filesystem->get_contents( $path );
				$decoded = false !== $current ? json_decode( $current, true ) : null;
				return new \WP_Error(
					'wptje_etag_conflict',
					__( 'The theme file was modified externally since you loaded it.', 'wp-theme-json-editor' ),
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
				__( 'The theme file is not writable. Check filesystem permissions.', 'wp-theme-json-editor' ),
				[ 'status' => 403 ]
			);
		}

		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $encoded ) {
			return new \WP_Error(
				'wptje_encode_failed',
				__( 'Failed to encode the theme file payload.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		$written = $filesystem->put_contents( $path, $encoded . "\n", FS_CHMOD_FILE );

		if ( ! $written ) {
			return new \WP_Error(
				'wptje_write_failed',
				__( 'Failed to write the theme file to disk.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		clearstatcache( true, $path );

		return [
			'etag' => $this->etag_for( $path ),
			'id'   => $id,
		];
	}

	/**
	 * Resolve a file id to an absolute path.
	 *
	 * Only ids present in the discovered file map resolve; everything
	 * else returns null. This is the allowlist that prevents path
	 * traversal — the id is a map key, never a path fragment.
	 *
	 * @param  string $id File id.
	 * @return string|null Absolute path or null when the id is unknown.
	 */
	protected function resolve( $id ) {

		$files = $this->discover();

		return $files[ $id ] ?? null;
	}

	/**
	 * Discover the editable theme JSON files.
	 *
	 * Builds an ordered map of `id => absolute path`: `theme.json` first
	 * (child theme preferred, falling back to the parent), then style
	 * variations found by recursively scanning the `styles/` directory of
	 * the child and parent themes. Child files win over parent files on a
	 * matching relative id.
	 *
	 * @return array<string, string>
	 */
	protected function discover() {

		$map = [];

		$theme_json = $this->resolve_theme_json_path();
		if ( null !== $theme_json ) {
			$map[ self::THEME_JSON_ID ] = $theme_json;
		}

		$variations = [];

		$base_dirs = [
			get_stylesheet_directory(),
			get_template_directory(),
		];

		foreach ( $base_dirs as $base ) {
			$styles_dir = trailingslashit( $base ) . 'styles';
			if ( ! is_dir( $styles_dir ) ) {
				continue;
			}
			$prefix_length = strlen( trailingslashit( $styles_dir ) );
			foreach ( $this->iterate_json_files( $styles_dir ) as $absolute ) {
				$relative = 'styles/' . str_replace( '\\', '/', substr( $absolute, $prefix_length ) );
				// First writer wins; the stylesheet (child) directory is
				// scanned before the template (parent) directory.
				if ( ! isset( $variations[ $relative ] ) ) {
					$variations[ $relative ] = $absolute;
				}
			}
		}

		ksort( $variations );

		return $map + $variations;
	}

	/**
	 * Recursively collect `*.json` file paths within a directory.
	 *
	 * @param  string $dir Absolute directory path.
	 * @return array<int, string> Absolute file paths.
	 */
	protected function iterate_json_files( $dir ) {

		$files = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 0 === strcasecmp( $file->getExtension(), 'json' ) ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Resolve the path to the active theme's theme.json.
	 *
	 * Prefers the child theme; falls back to the parent.
	 *
	 * @return string|null Absolute path or null when neither theme has the file.
	 */
	protected function resolve_theme_json_path() {

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
	 * Derive a display title for a file.
	 *
	 * Uses the variation's `title` when present, otherwise the basename.
	 * The root file is always labelled `theme.json`.
	 *
	 * @param  string $id   File id.
	 * @param  string $path Absolute path.
	 * @return string
	 */
	protected function title_for( $id, $path ) {

		if ( self::THEME_JSON_ID === $id ) {
			return self::THEME_JSON_ID;
		}

		$contents = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded  = is_string( $contents ) ? json_decode( $contents, true ) : null;

		if ( is_array( $decoded ) && ! empty( $decoded['title'] ) && is_string( $decoded['title'] ) ) {
			return $decoded['title'];
		}

		return basename( $path );
	}

	/**
	 * Whether an absolute path is a `.json` file inside the active or
	 * parent theme directory. Defence-in-depth for the write path.
	 *
	 * @param  string $path Absolute path.
	 * @return bool
	 */
	protected function is_allowed_path( $path ) {

		$normalized = wp_normalize_path( $path );

		if ( 0 !== strcasecmp( substr( $normalized, -5 ), '.json' ) ) {
			return false;
		}

		$roots = [
			wp_normalize_path( get_stylesheet_directory() ),
			wp_normalize_path( get_template_directory() ),
		];

		foreach ( $roots as $root ) {
			if ( str_starts_with( $normalized, trailingslashit( $root ) ) ) {
				return true;
			}
		}

		return false;
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
	 * @param  string $path Absolute path.
	 * @return string
	 */
	protected function etag_for( $path ) {
		clearstatcache( true, $path );
		return md5( filemtime( $path ) . ':' . filesize( $path ) );
	}
}
