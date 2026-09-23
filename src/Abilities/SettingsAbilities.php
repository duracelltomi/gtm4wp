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
 * The four settings abilities, thin adapters over SettingsStore (the service
 * the settings REST routes use):
 *
 * - `get-settings`: every registered option with its value and, on request,
 *   its admin schema. Registered options only - never the third-party keys
 *   the row may carry, never the derived 1.x mirrors.
 * - `update-settings`: a sparse patch through SettingsStore::save(), the save
 *   button's own path, so every value meets its Field sanitizer and the
 *   wp-config container locks hold. Guarded by the write switch (re-checked
 *   when the ability runs) and an optional expected_hash, which refuses with
 *   409 when somebody saved in between. Annotated destructive, and its
 *   description carries the confirmation protocol.
 * - `export-settings` / `import-settings`: the screen's own envelope (stored
 *   values, never a service-account key) and import path - size cap, depth
 *   cap, type marker, Field sanitizers onto the module defaults. The import
 *   is a SITE-WIDE replace, so it needs confirm: true as well.
 */
final class SettingsAbilities implements ProviderInterface {

	public const GET_SETTINGS    = Registrar::NAMESPACE_PREFIX . 'get-settings';
	public const UPDATE_SETTINGS = Registrar::NAMESPACE_PREFIX . 'update-settings';
	public const EXPORT_SETTINGS = Registrar::NAMESPACE_PREFIX . 'export-settings';
	public const IMPORT_SETTINGS = Registrar::NAMESPACE_PREFIX . 'import-settings';

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
	 * Registers the abilities. The module and option enums come from the
	 * registry, so a client sees this install's ids and a typo is refused by the
	 * schema; the writes are registered only while the site allows them.
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
				'description'         => __( 'Returns the current value of every option of Google Tag Manager for WordPress, keyed by option key, as the frontend loads them (wp-config.php overrides applied). Filter by module id or by option key to keep the answer short. Set include_schema to true to also get each option\'s label, description, type, default, allowed choices and documentation link - that is how to explain a setting to the user or to find the key of the option to change. The answer carries values_hash, a fingerprint of the stored settings: pass it as expected_hash to update-settings so a change made in between is not overwritten. Read-only.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'modules'        => array(
							'type'        => 'array',
							'description' => __( 'Return only the options of these modules (the sections of the settings screen). Omit for every module.', 'duracelltomi-google-tag-manager' ),
							'items'       => array(
								'type' => 'string',
								'enum' => $module_ids,
							),
						),
						'keys'           => array(
							'type'        => 'array',
							'description' => __( 'Return only these options. Omit for every option.', 'duracelltomi-google-tag-manager' ),
							'items'       => array(
								'type' => 'string',
								'enum' => $option_keys,
							),
						),
						'include_schema' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Also describe each returned option: label, description, type, default, choices, documentation link.', 'duracelltomi-google-tag-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'values'      => array(
							'type'        => 'object',
							'description' => __( 'Option key => current value.', 'duracelltomi-google-tag-manager' ),
						),
						'fields'      => array(
							'type'        => 'array',
							'description' => __( 'One entry per returned option when include_schema is true, otherwise empty.', 'duracelltomi-google-tag-manager' ),
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
									'default'     => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
									'choices'     => array( 'type' => 'object' ),
									'columns'     => array( 'type' => 'array' ),
									'depends_on'  => array(
										'type'        => 'string',
										'description' => __( 'The key of the setting this field depends on in the settings screen, or several keys comma separated (the field is enabled while any one of them is on); empty when it stands alone. Informational: a value is stored either way.', 'duracelltomi-google-tag-manager' ),
									),
									'rows_locked' => array( 'type' => 'boolean' ),
									'doc_url'     => array( 'type' => 'string' ),
								),
							),
						),
						'locked'      => array(
							'type'        => 'object',
							'description' => __( 'Which parts of the container table wp-config.php constants take over: locked_columns (column keys) and locked_rows (the whole table).', 'duracelltomi-google-tag-manager' ),
							'properties'  => array(
								'locked_columns' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'locked_rows'    => array( 'type' => 'boolean' ),
							),
						),
						'values_hash' => array(
							'type'        => 'string',
							'description' => __( 'Fingerprint of the stored settings at the time of this answer; pass it to update-settings as expected_hash.', 'duracelltomi-google-tag-manager' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_settings' ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);

		$this->register_export_settings();

		if ( Registrar::writes_allowed() ) {
			$this->register_update_settings();
			$this->register_import_settings();
		}
	}

	/**
	 * Registers gtm4wp/export-settings.
	 *
	 * @return void
	 */
	private function register_export_settings(): void {
		wp_register_ability(
			self::EXPORT_SETTINGS,
			array(
				'label'               => __( 'Export the Google Tag Manager settings', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Returns the settings of Google Tag Manager for WordPress as the portable JSON envelope the Export button of the settings screen downloads: every option with its stored value (the site\'s own configuration, without the wp-config.php overrides get-settings applies), the plugin version and a type marker. Keep the envelope as a backup before a change, or hand it to import-settings on another site. It contains no service-account key - keys are never exported. Read-only.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => Registrar::no_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin'  => array( 'type' => 'string' ),
						'type'    => array(
							'type'        => 'string',
							'enum'        => array( SettingsStore::EXPORT_TYPE ),
							'description' => __( 'The marker import-settings requires.', 'duracelltomi-google-tag-manager' ),
						),
						'version' => array(
							'type'        => 'string',
							'description' => __( 'The plugin version that wrote the envelope.', 'duracelltomi-google-tag-manager' ),
						),
						'options' => array(
							'type'        => 'object',
							'description' => __( 'Option key => stored value.', 'duracelltomi-google-tag-manager' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'export_settings' ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);
	}

	/**
	 * Registers gtm4wp/import-settings.
	 *
	 * @return void
	 */
	private function register_import_settings(): void {
		wp_register_ability(
			self::IMPORT_SETTINGS,
			array(
				'label'               => __( 'Import the Google Tag Manager settings', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Replaces EVERY option of Google Tag Manager for WordPress with the values of a settings export - site-wide: an option missing from the envelope falls back to its default, so this is a full restore, not a patch (use update-settings to change a few options). payload is the export envelope as a JSON string, exactly as export-settings returned it or as the Export button of the settings screen downloaded it; anything else is refused with 400 (a size cap, a check of the type marker) and nothing is stored. Every value passes the same sanitizer as the settings screen: a value the sanitizer refuses is reported under errors and its option keeps the default, while the rest is stored. Protocol, in this order: 1) call export-settings first and keep its answer, so the previous configuration can be restored with this ability; 2) tell the user which site and plugin version the envelope came from and that every option of this site will be replaced, the container IDs included, and ask "Replace all settings on this site?" - wait for an explicit yes in the same turn; a general instruction such as "restore my settings" is not a confirmation; 3) call this ability with confirm true. Without confirm true the call is refused with 400 and nothing is stored. Destructive: a wrong envelope switches tracking off for every visitor or points the site at somebody else\'s container. Options that wp-config.php constants control keep the constant\'s value whatever is stored. The answer carries the new values_hash for update-settings.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'required'             => array( 'payload', 'confirm' ),
					'properties'           => array(
						'payload' => array(
							'type'        => 'string',
							'description' => __( 'The export envelope as a JSON string: the answer of export-settings, or the contents of a downloaded export file.', 'duracelltomi-google-tag-manager' ),
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true: the user confirmed, in this turn, that every option of this site is to be replaced.', 'duracelltomi-google-tag-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'imported'    => array(
							'type'        => 'boolean',
							'description' => __( 'True when every option of the envelope was stored; false when at least one was refused (see errors).', 'duracelltomi-google-tag-manager' ),
						),
						'errors'      => array(
							'type'        => 'object',
							'description' => __( 'Option key => the sanitizer\'s message, for every value of the envelope that was refused; that option keeps its default.', 'duracelltomi-google-tag-manager' ),
						),
						'values_hash' => array(
							'type'        => 'string',
							'description' => __( 'Fingerprint of the stored settings after the import.', 'duracelltomi-google-tag-manager' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'import_settings' ),
				'permission_callback' => array( Registrar::class, 'can_write' ),
				'meta'                => Meta::write( true, true ),
			)
		);
	}

	/**
	 * Registers gtm4wp/update-settings. The per-option types come from the same
	 * Field definitions the settings REST route publishes; unlike that route,
	 * an unknown option key is refused by the schema, so an assistant's typo
	 * is a validation error and not a silently ignored key.
	 *
	 * @return void
	 */
	private function register_update_settings(): void {
		wp_register_ability(
			self::UPDATE_SETTINGS,
			array(
				'label'               => __( 'Update the Google Tag Manager settings', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Changes one or more options of Google Tag Manager for WordPress and saves them. Send only the options that change (a sparse patch); the others keep their value. A table option - the container list, the Google Data Manager destinations - is replaced as a whole: send every row with every column, not only the cells that change. Protocol, in this order: 1) call get-settings first (with include_schema when the option key or its allowed values are not known) and keep its values_hash; 2) show the user each option\'s current value and the new value and ask "Confirm this change?" - wait for an explicit yes in the same turn; a general instruction such as "enable it" or "fix it" is not a confirmation; 3) call this ability with the confirmed values and that values_hash as expected_hash. When expected_hash no longer matches, the settings were saved by somebody else in between (the settings screen, another assistant) and nothing is saved: the call is refused with status 409 - call get-settings again and repeat from step 2. Every value runs through the same sanitizer as the settings screen: a normalised value is stored (a checkbox becomes true or false, tags are stripped from a text), a value the sanitizer refuses is reported under errors with its message and is not stored, while the other options of the same call are. The answer lists under changed the options whose stored value actually changed, with the value as stored, and the new values_hash. Options that wp-config.php constants control (locked in the get-settings answer) cannot be changed here. Destructive: a wrong container ID or placement switches tracking off for every visitor, so never call this without the confirmation above.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'required'             => array( 'values' ),
					'properties'           => array(
						'values'        => array(
							'type'                 => 'object',
							'description'          => __( 'Option key => new value, only for the options that change. The keys and value types are those of get-settings.', 'duracelltomi-google-tag-manager' ),
							'minProperties'        => 1,
							'properties'           => $this->store->value_schema(),
							'additionalProperties' => false,
						),
						'expected_hash' => array(
							'type'        => 'string',
							'description' => __( 'The values_hash of the get-settings answer the new values were decided from. Refused with 409 when the settings changed since; omit only when overwriting a concurrent change is acceptable.', 'duracelltomi-google-tag-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'saved'       => array(
							'type'        => 'boolean',
							'description' => __( 'True when every submitted option was stored; false when at least one was refused (see errors).', 'duracelltomi-google-tag-manager' ),
						),
						'errors'      => array(
							'type'        => 'object',
							'description' => __( 'Option key => the sanitizer\'s message, for every submitted value that was refused and not stored.', 'duracelltomi-google-tag-manager' ),
						),
						'changed'     => array(
							'type'        => 'object',
							'description' => __( 'Option key => the value as stored, for every option whose stored value differs from before the call.', 'duracelltomi-google-tag-manager' ),
						),
						'values_hash' => array(
							'type'        => 'string',
							'description' => __( 'Fingerprint of the stored settings after the call.', 'duracelltomi-google-tag-manager' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'update_settings' ),
				'permission_callback' => array( Registrar::class, 'can_write' ),
				'meta'                => Meta::write( true, true ),
			)
		);
	}

	/**
	 * The gtm4wp/get-settings ability. The input-schema enums already refuse an
	 * unknown module or option; the checks here answer a caller that reached the
	 * method another way with a named refusal rather than an empty result.
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
			'values'      => (object) $out,
			'fields'      => $fields,
			'locked'      => array(
				'locked_columns' => array_keys( $locks['columns'] ),
				'locked_rows'    => array() !== $locks['rows'],
			),
			'values_hash' => $this->store->values_hash(),
		);
	}

	/**
	 * The gtm4wp/update-settings ability. Guards in cheapest-refusal order: the
	 * write switch (repeated from the permission callback, so a direct caller
	 * gets the named 403), the shape of the patch, the stale check against the
	 * row as it is now, then SettingsStore::save() with its Field sanitizers and
	 * container locks.
	 *
	 * @param mixed $input The validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_settings( $input = null ) {
		if ( ! Registrar::writes_allowed() ) {
			return Registrar::write_disabled_error();
		}

		$input  = is_array( $input ) ? $input : array();
		$values = $input['values'] ?? null;

		if ( ! is_array( $values ) || array() === $values ) {
			return new \WP_Error(
				'gtm4wp_settings_invalid',
				__( 'values must be an object with at least one option key.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		$current_hash  = $this->store->values_hash();
		$expected_hash = $input['expected_hash'] ?? '';

		if ( is_string( $expected_hash ) && '' !== $expected_hash && ! hash_equals( $current_hash, $expected_hash ) ) {
			return new \WP_Error(
				'gtm4wp_settings_stale',
				__( 'The settings changed since they were last read; nothing was saved. Call get-settings again and repeat the change from the current values.', 'duracelltomi-google-tag-manager' ),
				array(
					'status'      => 409,
					'values_hash' => $current_hash,
				)
			);
		}

		$before = $this->store->current_values();
		$errors = $this->store->save( $values );
		$after  = $this->store->current_values();

		$changed = array();

		foreach ( array_keys( $this->store->fields_by_key() ) as $option_key ) {
			$was = $before[ $option_key ] ?? null;
			$is  = $after[ $option_key ] ?? null;

			if ( $was !== $is ) {
				$changed[ $option_key ] = $is;
			}
		}

		return array(
			'saved'       => array() === $errors,
			'errors'      => (object) $errors,
			'changed'     => (object) $changed,
			'values_hash' => $this->store->values_hash(),
		);
	}

	/**
	 * The gtm4wp/export-settings ability: the store's envelope, with the
	 * options as an object so a client never sees a bare [] where a map was
	 * promised.
	 *
	 * @return array<string, mixed>
	 */
	public function export_settings(): array {
		$envelope = $this->store->export_data();

		$envelope['options'] = (object) $envelope['options'];

		return $envelope;
	}

	/**
	 * The gtm4wp/import-settings ability. Refusals in cheapest-first order: the
	 * write switch, the missing confirmation, the store's payload checks (size,
	 * depth, type marker), then the replace - the Import button's own path.
	 *
	 * @param mixed $input The validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function import_settings( $input = null ) {
		if ( ! Registrar::writes_allowed() ) {
			return Registrar::write_disabled_error();
		}

		$input = is_array( $input ) ? $input : array();

		if ( true !== ( $input['confirm'] ?? null ) ) {
			return Registrar::confirmation_required_error();
		}

		$options = $this->store->decode_import( $input['payload'] ?? null );

		if ( $options instanceof \WP_Error ) {
			return $options;
		}

		$errors = $this->store->replace( $options );

		return array(
			'imported'    => array() === $errors,
			'errors'      => (object) $errors,
			'values_hash' => $this->store->values_hash(),
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
