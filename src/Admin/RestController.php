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
	 * The export file's type marker; owned by the store, kept here for readers
	 * of this class.
	 */
	public const EXPORT_TYPE = SettingsStore::EXPORT_TYPE;

	/**
	 * The settings service behind every route.
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * Constructor.
	 *
	 * @param Registry           $registry The module registry.
	 * @param SettingsStore|null $store    The settings service; built over the registry when null.
	 */
	public function __construct( private Registry $registry, ?SettingsStore $store = null ) {
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
						'values' => array(
							'type'       => 'object',
							'required'   => true,
							// Per-field types from the module schemas; unknown keys stay
							// allowed (the store ignores them).
							'properties' => $this->store->value_schema(),
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
	 * Permission check, using the same capability as the settings page.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return Capability::can_manage_settings();
	}

	/**
	 * Returns the current raw option values (defaults overlaid with the
	 * stored values), keyed by the 1.x compatible option keys.
	 *
	 * @return array<string, mixed>
	 */
	public function current_values(): array {
		return $this->store->current_values();
	}

	/**
	 * The values the settings screen renders: what the frontend actually
	 * loads, wp-config.php overrides applied.
	 *
	 * @return array<string, mixed>
	 */
	public function ui_values(): array {
		return $this->store->ui_values();
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
	 * The settings export envelope.
	 *
	 * @return array<string, mixed>
	 */
	public function export_data(): array {
		return $this->store->export_data();
	}

	/**
	 * GET handler for the export endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public function export_settings(): \WP_REST_Response {
		return new \WP_REST_Response( $this->export_data() );
	}

	/**
	 * POST handler for a settings import: the exported JSON as the 'payload'
	 * string, decoded and checked by the store, then every value through the
	 * same sanitizer as a save, onto the module defaults (a clean replace).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_settings( \WP_REST_Request $request ) {
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
