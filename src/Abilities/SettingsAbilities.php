<?php
/**
 * Abilities that read and change the plugin's settings.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Abilities;

use GTM4WP\Admin\Docs;
use GTM4WP\Admin\SettingsStore;
use GTM4WP\Capability;
use GTM4WP\Module\Registry;
use GTM4WP\Modules\Container\HardcodedContainers;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The gtm4wp/get-settings ability: every option with its current value and, on request,
 * its schema - the label, description, type, default, choices and
 * documentation link the settings screen shows. A thin adapter over
 * SettingsStore, the same service the settings REST routes use.
 *
 * Only registered options are returned: the option row can carry keys a third
 * party wrote next to ours, and those are not the plugin's to hand to an
 * assistant. The derived 1.x mirror keys are left out for the same reason -
 * they are not settings, they are computed from the container rows.
 */
final class SettingsAbilities implements ProviderInterface {

	public const GET_SETTINGS = Registrar::NAMESPACE_PREFIX . 'get-settings';

	/**
	 * The settings service.
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
	public function __construct( Registry $registry, ?SettingsStore $store = null ) {
		$this->store = $store ?? new SettingsStore( $registry );
	}

	/**
	 * Registers the ability. The module and option enums are read from the
	 * registry at registration time, so a client sees exactly the ids this
	 * install has and a typo is refused by the schema before anything runs.
	 *
	 * @return void
	 */
	public function register(): void {
		$module_ids  = array_keys( $this->store->fields_by_module() );
		$option_keys = array_keys( $this->store->fields_by_key() );

		wp_register_ability(
			self::GET_SETTINGS,
			array(
				'label'               => __( 'Get the Google Tag Manager settings', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Returns the current value of every option of Google Tag Manager for WordPress, keyed by option key, as the frontend loads them (wp-config.php overrides applied). Filter by module id or by option key to keep the answer short. Set include_schema to true to also get each option\'s label, description, type, default, allowed choices and documentation link - that is how to explain a setting to the user or to find the key of the option to change. Read-only.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'modules'        => array(
							'type'        => 'array',
							'description' => 'Return only the options of these modules (the sections of the settings screen). Omit for every module.',
							'items'       => array(
								'type' => 'string',
								'enum' => $module_ids,
							),
						),
						'keys'           => array(
							'type'        => 'array',
							'description' => 'Return only these options. Omit for every option.',
							'items'       => array(
								'type' => 'string',
								'enum' => $option_keys,
							),
						),
						'include_schema' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Also describe each returned option: label, description, type, default, choices, documentation link.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'values' => array(
							'type'        => 'object',
							'description' => 'Option key => current value.',
						),
						'fields' => array(
							'type'        => 'array',
							'description' => 'One entry per returned option when include_schema is true, otherwise empty.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'key'         => array( 'type' => 'string' ),
									'module'      => array( 'type' => 'string' ),
									'group'       => array( 'type' => 'string' ),
									'label'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'type'        => array( 'type' => 'string' ),
									'phase'       => array( 'type' => 'string' ),
									'default'     => array(),
									'choices'     => array( 'type' => 'object' ),
									'columns'     => array( 'type' => 'array' ),
									'depends_on'  => array( 'type' => 'string' ),
									'rows_locked' => array( 'type' => 'boolean' ),
									'doc_url'     => array( 'type' => 'string' ),
								),
							),
						),
						'locked' => array(
							'type'        => 'object',
							'description' => 'Which parts of the container table wp-config.php constants take over: locked_columns (column keys) and locked_rows (the whole table).',
							'properties'  => array(
								'locked_columns' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'locked_rows'    => array( 'type' => 'boolean' ),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_settings' ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);
	}

	/**
	 * The gtm4wp/get-settings ability.
	 *
	 * The enums in the input schema already refuse an unknown module or option
	 * when core validates the call; the checks here repeat that for a caller
	 * that reaches the method another way, so the answer is a named refusal
	 * rather than a silently empty result.
	 *
	 * @param mixed $input The validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_settings( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$by_module      = $this->store->fields_by_module();
		$modules        = self::string_list( $input['modules'] ?? array() );
		$keys           = self::string_list( $input['keys'] ?? array() );
		$include_schema = ! empty( $input['include_schema'] );

		$unknown_modules = array_diff( $modules, array_keys( $by_module ) );
		if ( array() !== $unknown_modules ) {
			return new \WP_Error(
				'gtm4wp_unknown_module',
				sprintf(
					/* translators: %s: comma separated list of module ids. */
					__( 'Unknown module: %s.', 'duracelltomi-google-tag-manager' ),
					implode( ', ', $unknown_modules )
				),
				array( 'status' => 400 )
			);
		}

		$unknown_keys = array_diff( $keys, array_keys( $this->store->fields_by_key() ) );
		if ( array() !== $unknown_keys ) {
			return new \WP_Error(
				'gtm4wp_unknown_option',
				sprintf(
					/* translators: %s: comma separated list of option keys. */
					__( 'Unknown option: %s.', 'duracelltomi-google-tag-manager' ),
					implode( ', ', $unknown_keys )
				),
				array( 'status' => 400 )
			);
		}

		$values = $this->store->ui_values();
		$out    = array();
		$fields = array();

		foreach ( $by_module as $module_id => $module_fields ) {
			if ( array() !== $modules && ! in_array( $module_id, $modules, true ) ) {
				continue;
			}

			foreach ( $module_fields as $field ) {
				if ( array() !== $keys && ! in_array( $field->key, $keys, true ) ) {
					continue;
				}

				$out[ $field->key ] = array_key_exists( $field->key, $values ) ? $values[ $field->key ] : $field->default_value;

				if ( $include_schema ) {
					$fields[] = self::describe( $module_id, $field );
				}
			}
		}

		$locks = HardcodedContainers::locks();

		return array(
			// An object even when empty, so a client never sees a bare [] where
			// a map was promised.
			'values' => (object) $out,
			'fields' => $fields,
			'locked' => array(
				'locked_columns' => array_keys( $locks['columns'] ),
				'locked_rows'    => array() !== $locks['rows'],
			),
		);
	}

	/**
	 * One option's schema as the settings screen knows it, with the
	 * description as plain text (the screen renders limited HTML in it).
	 *
	 * @param string $module_id The module the field belongs to.
	 * @param Field  $field     The field.
	 * @return array<string, mixed>
	 */
	private static function describe( string $module_id, Field $field ): array {
		return array(
			'key'         => $field->key,
			'module'      => $module_id,
			'group'       => $field->group,
			'label'       => $field->label,
			'description' => trim( wp_strip_all_tags( $field->description ) ),
			'type'        => $field->type,
			'phase'       => $field->phase,
			'default'     => $field->default_value,
			'choices'     => (object) $field->choices,
			'columns'     => array_values( $field->columns ),
			'depends_on'  => $field->depends_on,
			'rows_locked' => $field->rows_locked,
			'doc_url'     => Docs::url( $field->doc, $field->key ),
		);
	}

	/**
	 * A list input as unique strings; anything else is dropped.
	 *
	 * @param mixed $value The raw list.
	 * @return string[]
	 */
	private static function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$strings[] = $item;
			}
		}

		return array_values( array_unique( $strings ) );
	}
}
