<?php
/**
 * User-level global styles repository.
 *
 * @package S3S\WP\ThemeJSONEditor\Repository
 */

namespace S3S\WP\ThemeJSONEditor\Repository;

/**
 * Reads and writes the active theme's user-level global styles, stored
 * as a `wp_global_styles` CPT post. Mirrors the shape of
 * `ThemeFileRepository` — same `data` / `etag` envelope and the same
 * 409-on-conflict semantics — so the editor can switch modes
 * transparently. Saves go through `wp_insert_post` / `wp_update_post`,
 * tagged to the active theme via the `wp_theme` taxonomy.
 */
class GlobalStylesRepository {

	/**
	 * Read the user-level global styles for the active theme.
	 *
	 * Wraps WP_Theme_JSON_Resolver::get_user_global_styles_post() and
	 * exposes the same shape the file-based repository returns.
	 *
	 * @return array{data: array, etag: string, post_id: int}|\WP_Error
	 */
	public function read() {

		$post = $this->get_user_post();

		if ( ! $post ) {
			return [
				'data'    => [
					'version'  => $this->theme_json_version(),
					'settings' => new \stdClass(),
					'styles'   => new \stdClass(),
				],
				'etag'    => '',
				'post_id' => 0,
			];
		}

		$decoded = json_decode( $post->post_content, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$decoded = [];
		}

		return [
			'data'    => $decoded,
			'etag'    => $this->etag_for( $post ),
			'post_id' => (int) $post->ID,
		];
	}

	/**
	 * Persist the user-level global styles for the active theme.
	 *
	 * @param  array  $data Decoded theme.json data.
	 * @param  string $etag Client's last-known etag for optimistic concurrency.
	 * @return array{etag: string, post_id: int}|\WP_Error
	 */
	public function write( array $data, $etag = '' ) {

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error(
				'wptje_forbidden',
				__( 'You do not have permission to edit user global styles.', 'wp-theme-json-editor' ),
				[ 'status' => 403 ]
			);
		}

		$post = $this->get_user_post();

		if ( $post ) {
			$current_etag = $this->etag_for( $post );
			if ( '' !== $etag && $etag !== $current_etag ) {
				$decoded = json_decode( $post->post_content, true );
				return new \WP_Error(
					'wptje_etag_conflict',
					__( 'Global styles were modified elsewhere since you loaded them.', 'wp-theme-json-editor' ),
					[
						'status'      => 409,
						'server_etag' => $current_etag,
						'server_data' => is_array( $decoded ) ? $decoded : [],
					]
				);
			}
		}

		$payload            = $data;
		$payload['version'] = $this->theme_json_version();

		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $encoded ) {
			return new \WP_Error(
				'wptje_encode_failed',
				__( 'Failed to encode global styles payload.', 'wp-theme-json-editor' ),
				[ 'status' => 500 ]
			);
		}

		if ( $post ) {
			$post_id = wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => wp_slash( $encoded ),
				],
				true
			);
		} else {
			$post_id = wp_insert_post(
				[
					'post_type'    => 'wp_global_styles',
					'post_status'  => 'publish',
					'post_title'   => sprintf( 'wp-global-styles-%s', get_stylesheet() ),
					'post_name'    => sprintf( 'wp-global-styles-%s', get_stylesheet() ),
					'tax_input'    => [
						'wp_theme' => [ get_stylesheet() ],
					],
					'post_content' => wp_slash( $encoded ),
				],
				true
			);
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$saved = get_post( $post_id );

		return [
			'etag'    => $saved ? $this->etag_for( $saved ) : '',
			'post_id' => (int) $post_id,
		];
	}

	/**
	 * Get the wp_global_styles post for the active theme, if any.
	 *
	 * @return \WP_Post|null
	 */
	protected function get_user_post() {

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) && method_exists( '\WP_Theme_JSON_Resolver', 'get_user_global_styles_post' ) ) {
			$post = \WP_Theme_JSON_Resolver::get_user_global_styles_post();
			return $post instanceof \WP_Post ? $post : null;
		}

		$query = new \WP_Query(
			[
				'post_type'      => 'wp_global_styles',
				'posts_per_page' => 1,
				'tax_query'      => [
					[
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					],
				],
				'no_found_rows'  => true,
			]
		);

		$post = $query->posts[0] ?? null;
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Build an etag from the post's modified-gmt + ID.
	 *
	 * @param  \WP_Post $post Post object.
	 * @return string
	 */
	protected function etag_for( $post ) {
		return md5( $post->ID . ':' . $post->post_modified_gmt );
	}

	/**
	 * Resolve the theme.json schema version core expects.
	 *
	 * @return int
	 */
	protected function theme_json_version() {
		if ( defined( '\WP_Theme_JSON::LATEST_SCHEMA' ) ) {
			return (int) \WP_Theme_JSON::LATEST_SCHEMA;
		}
		return 3;
	}
}
