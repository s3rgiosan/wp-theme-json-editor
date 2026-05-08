<?php
/**
 * WordPress theme.json schema + core-scan snapshot loader.
 *
 * @package S3S\WP\ThemeJSONEditor\Schema
 */

namespace S3S\WP\ThemeJSONEditor\Schema;

/**
 * Fetches the official WP theme.json schema from `schemas.wp.org`,
 * caches it in a 24h transient, and falls back to a bundled copy when
 * the network or cache is unavailable. Also exposes the bundled
 * core-scan snapshot (experimental + undocumented property paths
 * extracted from WP core source).
 *
 * The webview merges the raw schema and snapshot client-side in a
 * Web Worker, so the loader never resolves $ref / allOf itself.
 */
class Loader {

	/**
	 * Prefix for the per-version schema transient cache key.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wptje_schema_';

	/**
	 * Option that records the schema versions currently held in the
	 * transient cache. The uninstall routine reads it to know which
	 * transients to drop without resorting to a `LIKE` query.
	 *
	 * @var string
	 */
	const VERSIONS_OPTION = 'wptje_cached_schema_versions';

	/**
	 * Plugin-relative path to the bundled fallback schema (used when
	 * both network and transient cache are unavailable).
	 *
	 * @var string
	 */
	const FALLBACK_RELATIVE_PATH = 'build/theme.json.fallback';

	/**
	 * Plugin-relative path to the core-scan snapshot listing
	 * experimental and undocumented theme.json property paths.
	 *
	 * @var string
	 */
	const SNAPSHOT_RELATIVE_PATH = 'build/core-scan-snapshot.json';

	/**
	 * Load the raw WordPress theme.json schema for the given version.
	 *
	 * Network → transient cache → bundled fallback.
	 *
	 * @param  string $version WP version string (e.g. "6.7" or "trunk").
	 * @return array{schema: array, source: string}
	 */
	public function load( $version ) {

		$version   = $this->normalize_version( $version );
		$cache_key = self::TRANSIENT_PREFIX . $version;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return [
				'schema' => $cached,
				'source' => 'cache',
			];
		}

		$response = wp_remote_get(
			sprintf( 'https://schemas.wp.org/wp/%s/theme.json', rawurlencode( $version ) ),
			[ 'timeout' => 5 ]
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body ) ) {
				set_transient( $cache_key, $body, DAY_IN_SECONDS );
				$this->record_cached_version( $version );
				return [
					'schema' => $body,
					'source' => 'network',
				];
			}
		}

		return [
			'schema' => $this->load_fallback(),
			'source' => 'fallback',
		];
	}

	/**
	 * Load the bundled core-scan snapshot.
	 *
	 * @return array
	 */
	public function load_snapshot() {

		$path    = S3S_THEME_JSON_EDITOR_PATH . self::SNAPSHOT_RELATIVE_PATH;
		$decoded = $this->read_local_json( $path );

		if ( ! is_array( $decoded ) ) {
			return [
				'generatedAt'  => '',
				'wpVersion'    => '',
				'experimental' => [],
				'undocumented' => [],
			];
		}

		return $decoded;
	}

	/**
	 * Append `$version` to the cached-versions index option so the
	 * uninstall routine can drop the matching transients without a
	 * `LIKE` query.
	 *
	 * @param  string $version Normalised schema version.
	 * @return void
	 */
	protected function record_cached_version( $version ) {

		$tracked = (array) get_option( self::VERSIONS_OPTION, [] );

		if ( in_array( $version, $tracked, true ) ) {
			return;
		}

		$tracked[] = $version;
		update_option( self::VERSIONS_OPTION, $tracked, false );
	}

	/**
	 * Resolve a usable schema version string.
	 *
	 * @param  string $version Requested version.
	 * @return string
	 */
	protected function normalize_version( $version ) {

		$version = (string) $version;

		if ( '' === $version || 'auto' === $version ) {
			$wp_version = get_bloginfo( 'version' );
			$parts      = explode( '.', $wp_version );
			$version    = isset( $parts[0], $parts[1] ) ? $parts[0] . '.' . $parts[1] : 'trunk';
		}

		return $version;
	}

	/**
	 * Load the bundled fallback schema.
	 *
	 * @return array
	 */
	protected function load_fallback() {

		$path    = S3S_THEME_JSON_EDITOR_PATH . self::FALLBACK_RELATIVE_PATH;
		$decoded = $this->read_local_json( $path );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Read and decode a JSON file shipped with the plugin.
	 *
	 * @param  string $path Absolute path to a JSON file.
	 * @return mixed Decoded JSON or null on failure.
	 */
	protected function read_local_json( $path ) {

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			\WP_Filesystem();
		}

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base || ! $wp_filesystem->is_readable( $path ) ) {
			return null;
		}

		$contents = $wp_filesystem->get_contents( $path );

		if ( false === $contents ) {
			return null;
		}

		return json_decode( $contents, true );
	}
}
