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

	public const REST_NAMESPACE = 'gtm4wp/v2';
	public const REST_ROUTE     = '/settings';

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
							'type'     => 'object',
							'required' => true,
						),
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

			// Store derived companion values (e.g. the flat 1.x mirrors of
			// the container rows) so the raw option row stays coherent for
			// third party readers and 1.x downgrades.
			foreach ( $field->derived_values( $sanitized ) as $derived_key => $derived_value ) {
				$stored[ $derived_key ] = $derived_value;
			}
		}

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
