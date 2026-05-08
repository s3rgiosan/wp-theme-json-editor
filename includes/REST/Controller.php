<?php
/**
 * REST controller for the plugin's custom routes.
 *
 * @package S3S\WP\ThemeJSONEditor\REST
 */

namespace S3S\WP\ThemeJSONEditor\REST;

use S3S\WP\ThemeJSONEditor\Plugin;
use S3S\WP\ThemeJSONEditor\Repository\GlobalStylesRepository;
use S3S\WP\ThemeJSONEditor\Repository\ThemeFileRepository;
use S3S\WP\ThemeJSONEditor\Schema\Loader as SchemaLoader;

/**
 * Registers and serves the editor's REST endpoints under
 * `wp-theme-json-editor/v1`:
 *
 * - `GET  /schema`   — raw WP schema + core-scan snapshot.
 * - `GET  /document` — current theme.json or user global styles.
 * - `POST /document` — saves theme.json or user global styles.
 *
 * The mode argument (`theme` | `user`) selects between the file-based
 * theme.json and the user-level wp_global_styles CPT. Capability checks
 * happen at the permission_callback level so unauthorised requests are
 * rejected before reaching the repositories. The save endpoint also
 * caps payload size at `MAX_PAYLOAD_BYTES`.
 */
class Controller {

	/**
	 * REST namespace for every route registered by this controller.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'wp-theme-json-editor/v1';

	/**
	 * Maximum size (bytes) of the JSON-encoded `data` payload accepted
	 * by `POST /document`. Anything larger is rejected with HTTP 413.
	 * theme.json files in the wild rarely exceed a few hundred KB.
	 *
	 * @var int
	 */
	const MAX_PAYLOAD_BYTES = 1048576; // 1 MB

	/**
	 * Setup hooks.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes under the plugin namespace.
	 *
	 * @return void
	 */
	public function register_routes() {

		register_rest_route(
			self::REST_NAMESPACE,
			'/schema',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => [ $this, 'can_view' ],
				'callback'            => [ $this, 'get_schema' ],
				'args'                => [
					'version' => [
						'type'              => 'string',
						'default'           => 'auto',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/document',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => [ $this, 'can_view' ],
					'callback'            => [ $this, 'get_document' ],
					'args'                => $this->mode_arg(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => [ $this, 'can_save' ],
					'callback'            => [ $this, 'save_document' ],
					'args'                => array_merge(
						$this->mode_arg(),
						[
							'data' => [
								'type'     => 'object',
								'required' => true,
							],
							'etag' => [
								'type'    => 'string',
								'default' => '',
							],
						]
					),
				],
			]
		);
	}

	/**
	 * Permission callback for the read-only endpoints.
	 *
	 * @return bool
	 */
	public function can_view() {

		if ( Plugin::is_environment_blocked() ) {
			return false;
		}

		return current_user_can( 'edit_themes' );
	}

	/**
	 * Permission callback for the save endpoint.
	 *
	 * Enforces the cap matching the requested mode so the request is
	 * rejected before reaching the repository layer.
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_save( $request ) {

		if ( Plugin::is_environment_blocked() ) {
			return new \WP_Error(
				'wptje_environment_blocked',
				__( 'theme.json editing is disabled on this environment.', 'wp-theme-json-editor' ),
				[ 'status' => 403 ]
			);
		}

		$mode = $request->get_param( 'mode' );

		if ( 'theme' === $mode ) {
			if ( ! current_user_can( 'edit_themes' ) ) {
				return new \WP_Error(
					'wptje_forbidden',
					__( 'You do not have permission to edit theme files.', 'wp-theme-json-editor' ),
					[ 'status' => 403 ]
				);
			}
			return true;
		}

		if ( ! Plugin::is_global_styles_mode_enabled() ) {
			return new \WP_Error(
				'wptje_mode_disabled',
				__( 'User Global Styles editing is disabled.', 'wp-theme-json-editor' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error(
				'wptje_forbidden',
				__( 'You do not have permission to edit user global styles.', 'wp-theme-json-editor' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET /schema — returns raw WP schema + core-scan snapshot.
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_schema( $request ) {

		$loader   = new SchemaLoader();
		$bundle   = $loader->load( $request->get_param( 'version' ) );
		$snapshot = $loader->load_snapshot();

		return rest_ensure_response(
			[
				'schema'        => $bundle['schema'],
				'snapshot'      => $snapshot,
				'schemaVersion' => $request->get_param( 'version' ),
				'source'        => $bundle['source'],
			]
		);
	}

	/**
	 * GET /document — returns current theme.json or user global styles.
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_document( $request ) {

		$mode = $request->get_param( 'mode' );

		if ( 'user' === $mode && ! Plugin::is_global_styles_mode_enabled() ) {
			return new \WP_Error(
				'wptje_mode_disabled',
				__( 'User Global Styles editing is disabled.', 'wp-theme-json-editor' ),
				[ 'status' => 404 ]
			);
		}

		$result = 'theme' === $mode
			? ( new ThemeFileRepository() )->read()
			: ( new GlobalStylesRepository() )->read();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['mode'] = $mode;
		return rest_ensure_response( $result );
	}

	/**
	 * POST /document — saves theme.json or user global styles.
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_document( $request ) {

		$mode = $request->get_param( 'mode' );
		$data = $request->get_param( 'data' );
		$etag = (string) $request->get_param( 'etag' );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'wptje_invalid_payload',
				__( '`data` must be an object.', 'wp-theme-json-editor' ),
				[ 'status' => 400 ]
			);
		}

		$encoded = wp_json_encode( $data );
		if ( false !== $encoded && strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return new \WP_Error(
				'wptje_payload_too_large',
				sprintf(
					/* translators: 1: payload size in bytes, 2: max allowed bytes. */
					__( 'theme.json payload is %1$d bytes; maximum allowed is %2$d.', 'wp-theme-json-editor' ),
					strlen( $encoded ),
					self::MAX_PAYLOAD_BYTES
				),
				[ 'status' => 413 ]
			);
		}

		$shape_error = $this->validate_payload_shape( $data, $mode );
		if ( is_wp_error( $shape_error ) ) {
			return $shape_error;
		}

		$result = 'theme' === $mode
			? ( new ThemeFileRepository() )->write( $data, $etag )
			: ( new GlobalStylesRepository() )->write( $data, $etag );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['mode'] = $mode;
		return rest_ensure_response( $result );
	}

	/**
	 * Shallow shape validation of a theme.json payload.
	 *
	 * Catches obvious type mistakes before the data reaches the
	 * repository and the JSON encoder — `version` must be a positive
	 * int when present; `settings`, `styles`, `customTemplates`,
	 * `templateParts`, `patterns` must be arrays/objects when present.
	 * Deep schema validation against the resolved theme.json schema
	 * is intentionally not performed here; that's the editor's job
	 * client-side.
	 *
	 * @param  array  $data Decoded payload.
	 * @param  string $mode Editing mode (`theme` | `user`).
	 * @return true|\WP_Error
	 */
	protected function validate_payload_shape( array $data, $mode ) {

		if ( isset( $data['version'] ) && ( ! is_int( $data['version'] ) || $data['version'] < 1 ) ) {
			return new \WP_Error(
				'wptje_invalid_payload',
				__( '`version` must be a positive integer.', 'wp-theme-json-editor' ),
				[ 'status' => 400 ]
			);
		}

		$object_keys = [ 'settings', 'styles', 'customTemplates', 'templateParts', 'patterns' ];

		// User Global Styles in core only support a subset of the schema.
		$allowed_keys = 'user' === $mode
			? [ 'version', 'settings', 'styles' ]
			: null;

		foreach ( $data as $key => $value ) {
			if ( null !== $allowed_keys && ! in_array( $key, $allowed_keys, true ) ) {
				return new \WP_Error(
					'wptje_invalid_payload',
					sprintf(
						/* translators: %s: top-level key. */
						__( '`%s` is not allowed in user global styles.', 'wp-theme-json-editor' ),
						(string) $key
					),
					[ 'status' => 400 ]
				);
			}

			if ( in_array( $key, $object_keys, true ) && null !== $value && ! is_array( $value ) ) {
				return new \WP_Error(
					'wptje_invalid_payload',
					sprintf(
						/* translators: %s: top-level key. */
						__( '`%s` must be an object or array.', 'wp-theme-json-editor' ),
						(string) $key
					),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}

	/**
	 * Shared definition for the `mode` query/body argument.
	 *
	 * @return array
	 */
	protected function mode_arg() {
		return [
			'mode' => [
				'type'              => 'string',
				'enum'              => [ 'theme', 'user' ],
				'default'           => 'theme',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
