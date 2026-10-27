<?php
/**
 * Settings REST controller.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Capability;
use GTM4WP\Module\Registry;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint used by the React settings app: gtm4wp/v2/settings.
 *
 * A thin adapter over SettingsStore, which owns the schema-driven
 * sanitization and the option row: this class only maps requests onto it and
 * shapes the responses, so the abilities that read and write the same
 * settings share every rule with these routes.
 */
final class RestController {

	// Defined once in RestCors, which also owns the namespace's cross-origin policy.
	public const REST_NAMESPACE    = RestCors::REST_NAMESPACE;
	public const REST_ROUTE        = '/settings';
	public const REST_ROUTE_EXPORT = '/settings/export';
	public const REST_ROUTE_IMPORT = '/settings/import';

	/**
	 * The settings service behind every route. Nothing else is read here: the
	 * routes are thin adapters, and a caller that wants the values goes to the
	 * store itself (SettingsPage does) rather than through a forwarder (#255).
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * Constructor.
	 *
	 * @param Registry           $registry The module registry the store is built over.
	 * @param SettingsStore|null $store    The settings service; built over the registry when null.
	 */
	public function __construct( Registry $registry, ?SettingsStore $store = null ) {
		$this->store = $store ?? new SettingsStore( $registry );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						// Per-field types from the module schemas, built only when this
						// route runs: rest_api_init fires on every REST request of the
						// site. Unknown keys stay allowed (the store ignores them).
						'values' => array(
							'type'              => 'object',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_values' ),
							'sanitize_callback' => array( $this, 'sanitize_values' ),
						),
					),
				),
			)
		);

		// Export: streams the current option row as a JSON envelope the admin
		// can download and re-import on another install.
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_EXPORT,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		// Import: accepts the raw contents of a previously exported file and
		// runs every value back through the schema sanitizers before storing.
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_IMPORT,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'payload' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Validates the POST 'values' argument against the per-field schema.
	 *
	 * @param mixed  $value   The submitted argument.
	 * @param mixed  $request The REST request (unused).
	 * @param string $param   The argument name.
	 * @return true|\WP_Error
	 */
	public function validate_values( $value, $request, string $param ) {
		unset( $request );
		return rest_validate_value_from_schema( $value, $this->values_schema(), $param );
	}

	/**
	 * Sanitizes the POST 'values' argument against the per-field schema.
	 *
	 * @param mixed  $value   The submitted argument.
	 * @param mixed  $request The REST request (unused).
	 * @param string $param   The argument name.
	 * @return mixed
	 */
	public function sanitize_values( $value, $request, string $param ) {
		unset( $request );
		return rest_sanitize_value_from_schema( $value, $this->values_schema(), $param );
	}

	/**
	 * The schema of the POST 'values' argument.
	 *
	 * @return array<string, mixed>
	 */
	private function values_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => $this->store->value_schema(),
		);
	}

	/**
	 * Permission check, using the same capability as the settings page.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return Capability::can_manage_settings();
	}

	/**
	 * GET handler.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'values' => $this->store->ui_values(),
			)
		);
	}

	/**
	 * POST handler. Accepts a partial map of option key => raw value and
	 * hands it to the store, which sanitizes each value through its Field
	 * definition and writes the single 1.x compatible option row.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$errors = $this->store->save( (array) $request->get_param( 'values' ) );

		return new \WP_REST_Response(
			array(
				'saved'  => array() === $errors,
				'errors' => (object) $errors,
				'values' => $this->store->ui_values(),
			)
		);
	}

	/**
	 * GET handler for the export endpoint: the store's envelope.
	 *
	 * @return \WP_REST_Response
	 */
	public function export_settings(): \WP_REST_Response {
		return new \WP_REST_Response( $this->store->export_data() );
	}

	/**
	 * POST handler for a settings import: the exported JSON as the 'payload'
	 * string, decoded and checked by the store, then every value through the
	 * same sanitizer as a save, onto the module defaults (a clean replace).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// A REST body is not slashed (unlike $_POST), so no wp_unslash(); every
		// decoded value is sanitized before it can be stored.
		$options = $this->store->decode_import( $request->get_param( 'payload' ) );

		if ( $options instanceof \WP_Error ) {
			return $options;
		}

		$errors = $this->store->replace( $options );

		return new \WP_REST_Response(
			array(
				'imported' => array() === $errors,
				'errors'   => (object) $errors,
				'values'   => $this->store->ui_values(),
			)
		);
	}
}
