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
use GTM4WP\Options\Field;

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

	public const REST_NAMESPACE    = 'gtm4wp/v2';
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
							// Per-field types derived from the module schemas, so the
							// REST layer validates each value's type before it reaches
							// the schema-driven sanitizer. Unknown keys stay allowed
							// (save_settings() ignores them) so third party option
							// values keep round-tripping.
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

		// Mirror the fallback of the Options service: until the container
		// row option is saved for the first time, expose the rows derived
		// from the flat 1.x options so the admin UI always shows what the
		// frontend actually loads.
		if ( ! array_key_exists( GTM4WP_OPTION_GTM_CONTAINERS, $stored ) ) {
			$values[ GTM4WP_OPTION_GTM_CONTAINERS ] = ContainerRows::from_legacy( $values );
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
				'values' => $this->current_values(),
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
		$submitted = (array) $request->get_param( 'values' );

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
				'values' => $this->current_values(),
			)
		);
	}

	/**
	 * Builds the settings export envelope: a small header identifying the
	 * file plus the current option values (module defaults overlaid with the
	 * stored row). The value set is schema driven - every registered option is
	 * included automatically, and nothing outside the registered fields is - so
	 * a new option is exported the moment its Field is added, with no list to
	 * maintain here. On import every value is run back through its Field
	 * sanitizer, so the file itself is never trusted.
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
	 * POST handler for a settings import. Accepts the raw contents of a
	 * previously exported JSON file as the 'payload' string, decodes it with
	 * json_decode() only (never unserialize/eval), validates the envelope and
	 * runs every option value through the same schema-driven sanitizer as the
	 * normal save. The stored row is rebuilt from the module defaults so the
	 * import is a clean, complete replace; unknown keys in the file are
	 * ignored and values rejected by a sanitizer are reported without being
	 * stored.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_settings( \WP_REST_Request $request ) {
		$payload = $request->get_param( 'payload' );

		// The REST body arrives as a JSON string that WordPress does not slash
		// (unlike $_POST, so no wp_unslash() here - stripping slashes would
		// corrupt legitimate JSON escapes). It is decoded below and every
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

		// Replace onto the module defaults: options missing from the file fall
		// back to their default, so the import lands a complete, coherent row
		// rather than merging onto whatever the target install happened to have.
		list( $stored, $errors ) = $this->sanitize_onto( $decoded['options'], $this->registry->defaults() );

		update_option( GTM4WP_OPTIONS, $stored );

		return new \WP_REST_Response(
			array(
				'imported' => array() === $errors,
				'errors'   => (object) $errors,
				'values'   => $this->current_values(),
			)
		);
	}

	/**
	 * Runs every submitted value that maps to a registered field through the
	 * field's sanitizer and writes the sanitized result onto $base. This is the
	 * single validation path shared by the settings save and the settings
	 * import, so no field can bypass it:
	 *
	 * - unknown keys are ignored (only registered fields are iterated), so a
	 *   crafted file can never inject an arbitrary option key;
	 * - a value the sanitizer rejects (WP_Error) is collected in the error map
	 *   and leaves $base untouched for that key;
	 * - each field's derived companion values (e.g. the flat 1.x mirrors of the
	 *   container rows) are regenerated so the stored row stays coherent for
	 *   third party readers and 1.x downgrades.
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
