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

use GTM4WP\Module\Registry;
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\Container\HardcodedContainers;
use GTM4WP\Options\Field;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint used by the React settings app: gtm4wp/v2/settings.
 *
 * Sanitization and validation is schema driven: each submitted value runs
 * through its Field sanitizer (ported from gtm4wp_sanitize_options() of
 * 1.x). Sanitizers may return WP_Error; those values are rejected and
 * reported back, the stored value stays unchanged - mirroring how
 * add_settings_error() flows worked in 1.x.
 */
final class RestController {

	// Defined once in RestCors, which also owns the namespace's cross-origin policy.
	public const REST_NAMESPACE    = RestCors::REST_NAMESPACE;
	public const REST_ROUTE        = '/settings';
	public const REST_ROUTE_EXPORT = '/settings/export';
	public const REST_ROUTE_IMPORT = '/settings/import';

	/**
	 * Marker written into - and required by - a settings export file. An
	 * uploaded file that does not carry this exact type is rejected on import.
	 */
	public const EXPORT_TYPE = 'gtm4wp-settings-export';

	/**
	 * Hard upper bound on the raw import payload size, in bytes. The settings
	 * JSON is a few KB in practice, so this rejects oversized/abusive uploads
	 * before they are ever decoded.
	 */
	private const IMPORT_MAX_BYTES = 512 * 1024;

	/**
	 * Maximum nesting depth json_decode() accepts for an import payload. The
	 * deepest legitimate structure (a table/container-rows field) is two levels
	 * deep, so this bounds decoder recursion without any unserialize/eval.
	 */
	private const IMPORT_MAX_DEPTH = 16;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 */
	public function __construct( private Registry $registry ) {
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
							// allowed (save_settings() ignores them).
							'properties' => $this->value_schema(),
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
	 * Permission check, using the same capability filter as the settings page.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		/** This filter is documented in src/Plugin.php */
		return current_user_can( apply_filters( 'gtm4wp_admin_page_capability', 'manage_options' ) );
	}

	/**
	 * Returns the current raw option values (defaults overlaid with the
	 * stored values), keyed by the 1.x compatible option keys.
	 *
	 * @return array<string, mixed>
	 */
	public function current_values(): array {
		$stored = get_option( GTM4WP_OPTIONS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$values = array_merge( $this->registry->defaults(), $stored );

		// The Options service's pre-migration fallback, so the admin UI shows
		// what the frontend loads.
		if ( ! array_key_exists( GTM4WP_OPTION_GTM_CONTAINERS, $stored ) ) {
			$values[ GTM4WP_OPTION_GTM_CONTAINERS ] = ContainerRows::from_legacy( $values );
		}

		return $values;
	}

	/**
	 * The values the settings screen renders: the current values with the
	 * container rows replaced by the ones actually loaded under a
	 * GTM4WP_HARDCODED_* constant (recomputed here, since the Options service
	 * predates this request's write). Separate from current_values(): an
	 * export must carry the site's OWN stored configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function ui_values(): array {
		$values = $this->current_values();

		if ( HardcodedContainers::is_active() ) {
			list( $rows ) = HardcodedContainers::apply(
				ContainerRows::normalize( $values[ GTM4WP_OPTION_GTM_CONTAINERS ] ?? array() )
			);

			$values[ GTM4WP_OPTION_GTM_CONTAINERS ] = $rows;
		}

		return $values;
	}

	/**
	 * GET handler.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'values' => $this->ui_values(),
			)
		);
	}

	/**
	 * POST handler. Accepts a partial map of option key => raw value,
	 * sanitizes each value through its Field definition and stores the
	 * result in the single 1.x compatible option row.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$submitted = $this->keep_hardcoded_containers( (array) $request->get_param( 'values' ) );

		$stored = get_option( GTM4WP_OPTIONS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		list( $stored, $errors ) = $this->sanitize_onto( $submitted, $stored );

		update_option( GTM4WP_OPTIONS, $stored );

		return new \WP_REST_Response(
			array(
				'saved'  => array() === $errors,
				'errors' => (object) $errors,
				'values' => $this->ui_values(),
			)
		);
	}

	/**
	 * Keeps the container values wp-config.php controls out of the stored row:
	 * the screen submits the rows actually loaded, so a save would otherwise
	 * persist the constant's value over the admin's own setup. When the
	 * constants decide the row set the submitted value is dropped; when only
	 * columns are locked, each locked cell is restored from the stored row with
	 * the same container ID (matching by ID survives reordering). A crafted
	 * request can still write anything; it stays inert at output time.
	 *
	 * @param array<string, mixed> $submitted Raw option key => value map (untrusted).
	 * @return array<string, mixed> The submitted map with the locked container values restored.
	 */
	private function keep_hardcoded_containers( array $submitted ): array {
		if ( ! array_key_exists( GTM4WP_OPTION_GTM_CONTAINERS, $submitted ) || ! HardcodedContainers::is_active() ) {
			return $submitted;
		}

		$locks = HardcodedContainers::locks();

		if ( array() !== $locks['rows'] ) {
			unset( $submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] );

			return $submitted;
		}

		$stored_rows  = ContainerRows::normalize( $this->current_values()[ GTM4WP_OPTION_GTM_CONTAINERS ] ?? array() );
		$stored_by_id = array_column( $stored_rows, null, ContainerRows::COLUMN_ID );

		$rows = is_array( $submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] ) ? $submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] : array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row_id = isset( $row[ ContainerRows::COLUMN_ID ] ) && is_scalar( $row[ ContainerRows::COLUMN_ID ] )
				? trim( (string) $row[ ContainerRows::COLUMN_ID ] )
				: '';

			foreach ( array_keys( $locks['columns'] ) as $column ) {
				$rows[ $index ][ $column ] = $stored_by_id[ $row_id ][ $column ] ?? '';
			}
		}

		$submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] = $rows;

		return $submitted;
	}

	/**
	 * Builds the settings export envelope: a header plus the current option
	 * values. Schema driven, so every registered option is included and
	 * nothing else; on import every value passes its Field sanitizer again.
	 *
	 * @return array<string, mixed>
	 */
	public function export_data(): array {
		return array(
			'plugin'  => 'gtm4wp',
			'type'    => self::EXPORT_TYPE,
			'version' => defined( 'GTM4WP_VERSION' ) ? GTM4WP_VERSION : '',
			'options' => $this->current_values(),
		);
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
	 * string, json_decode() only, envelope validated, every value through the
	 * same sanitizer as a save. The row is rebuilt from the module defaults (a
	 * clean replace); unknown keys are ignored, rejected values reported.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_settings( \WP_REST_Request $request ) {
		$payload = $request->get_param( 'payload' );

		// A REST body is not slashed (unlike $_POST), so no wp_unslash(); every
		// decoded value is sanitized before it can be stored.
		if ( ! is_string( $payload ) || '' === $payload ) {
			return new \WP_Error(
				'gtm4wp_import_empty',
				__( 'No settings file was uploaded.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $payload ) > self::IMPORT_MAX_BYTES ) {
			return new \WP_Error(
				'gtm4wp_import_too_large',
				__( 'The settings file is too large to be a GTM4WP export.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		$decoded = json_decode( $payload, true, self::IMPORT_MAX_DEPTH );

		if ( ! is_array( $decoded )
			|| ! isset( $decoded['type'], $decoded['options'] )
			|| self::EXPORT_TYPE !== $decoded['type']
			|| ! is_array( $decoded['options'] )
		) {
			return new \WP_Error(
				'gtm4wp_import_invalid',
				__( 'The uploaded file is not a valid GTM4WP settings export.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		// Onto the module defaults, so the import lands a complete row.
		list( $stored, $errors ) = $this->sanitize_onto( $decoded['options'], $this->registry->defaults() );

		update_option( GTM4WP_OPTIONS, $stored );

		return new \WP_REST_Response(
			array(
				'imported' => array() === $errors,
				'errors'   => (object) $errors,
				'values'   => $this->ui_values(),
			)
		);
	}

	/**
	 * Runs every submitted value that maps to a registered field through its
	 * sanitizer onto $base: the single validation path of save and import.
	 * Unknown keys are ignored (no arbitrary option key can be injected), a
	 * rejected value leaves $base untouched and lands in the error map, and
	 * each field's derived values (the flat 1.x mirrors) are regenerated.
	 *
	 * @param array<string, mixed> $submitted Raw option key => value map (untrusted).
	 * @param array<string, mixed> $base      Option row the sanitized values are written onto.
	 * @return array{0: array<string, mixed>, 1: array<string, string>} [ stored row, error map ].
	 */
	private function sanitize_onto( array $submitted, array $base ): array {
		$stored = $base;
		$errors = array();

		foreach ( $this->fields_by_key() as $option_key => $field ) {
			if ( ! array_key_exists( $option_key, $submitted ) ) {
				continue;
			}

			$sanitized = $field->sanitize( $submitted[ $option_key ] );

			if ( is_wp_error( $sanitized ) ) {
				$errors[ $option_key ] = $sanitized->get_error_message();
				continue;
			}

			$stored[ $option_key ] = $sanitized;

			foreach ( $field->derived_values( $sanitized ) as $derived_key => $derived_value ) {
				$stored[ $derived_key ] = $derived_value;
			}
		}

		return array( $stored, $errors );
	}

	/**
	 * Builds the JSON schema properties of the settings object from the
	 * registered fields: each option key maps to its REST value type. Used
	 * to give the POST endpoint per-field type validation.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function value_schema(): array {
		$properties = array();

		foreach ( $this->fields_by_key() as $option_key => $field ) {
			$properties[ $option_key ] = array(
				'type' => $field->rest_type(),
			);
		}

		return $properties;
	}

	/**
	 * Collects the Field definitions of every registered module, keyed by
	 * option key. Unknown submitted keys are silently ignored, so third
	 * party values can not be injected into the option row.
	 *
	 * @return array<string, Field>
	 */
	private function fields_by_key(): array {
		$fields = array();

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();
			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$schema = new $schema_class();
			foreach ( $schema->fields() as $field ) {
				$fields[ $field->key ] = $field;
			}
		}

		return $fields;
	}
}
