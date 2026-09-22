<?php
/**
 * Unit tests for the settings abilities.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use Brain\Monkey\Filters;
use GTM4WP\Abilities\SettingsAbilities;
use GTM4WP\Admin\SettingsStore;
use GTM4WP\Module\Registry;

/**
 * The gtm4wp/get-settings ability answers from the same store as the settings REST route,
 * limited to registered options. What is pinned here: the value set (only
 * registered fields, never a third party's key in the same row), the two
 * filters and their refusals, the schema description, and the object-not-list
 * shape of an empty map.
 */
final class SettingsAbilitiesTest extends AbilitiesTestCase {

	private Registry $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->registry = $this->registry();

		( new SettingsAbilities( $this->registry ) )->register();
	}

	public function test_the_ability_is_registered_with_the_live_module_and_option_enums(): void {
		$schema = $this->registered[ SettingsAbilities::GET_SETTINGS ]['input_schema'];
		$store  = new SettingsStore( $this->registry );

		$this->assertSame( array_keys( $store->fields_by_module() ), $schema['properties']['modules']['items']['enum'], 'A typo in a module id is refused by the schema before anything runs.' );
		$this->assertSame( array_keys( $store->fields_by_key() ), $schema['properties']['keys']['items']['enum'] );
		$this->assertFalse( $schema['properties']['include_schema']['default'] );
	}

	public function test_a_bare_call_returns_every_registered_option_and_nothing_else(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( 'id' => 'GTM-ABC123' ) ),
				'third-party-key'            => 'not ours',
			)
		);

		$result = $this->execute( SettingsAbilities::GET_SETTINGS );

		$this->assertIsObject( $result['values'], 'A map, even when empty, so a client never sees a bare [].' );

		$values = (array) $result['values'];

		$this->assertSame( array_keys( ( new SettingsStore( $this->registry ) )->fields_by_key() ), array_keys( $values ) );
		$this->assertArrayNotHasKey( 'third-party-key', $values, 'A key another plugin wrote into the row is not ours to hand over.' );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_GTM_CODE, $values, 'The derived 1.x mirror is not a setting.' );
		$this->assertSame( 'GTM-ABC123', $values[ GTM4WP_OPTION_GTM_CONTAINERS ][0]['id'] );
		$this->assertSame( array(), $result['fields'], 'No schema unless asked for.' );
		$this->assertFalse( $result['locked']['locked_rows'] );
		$this->assertSame( array(), $result['locked']['locked_columns'] );
	}

	public function test_the_module_filter_limits_the_answer_to_that_module(): void {
		$values = (array) $this->execute( SettingsAbilities::GET_SETTINGS, array( 'modules' => array( 'container' ) ) )['values'];

		$container_keys = array_map( static fn ( $field ) => $field->key, ( new SettingsStore( $this->registry ) )->fields_by_module()['container'] );

		$this->assertSame( $container_keys, array_keys( $values ) );
	}

	public function test_the_key_filter_limits_the_answer_to_those_options(): void {
		$values = (array) $this->execute(
			SettingsAbilities::GET_SETTINGS,
			array( 'keys' => array( GTM4WP_OPTION_DATALAYER_NAME, GTM4WP_OPTION_GTM_PLACEMENT ) )
		)['values'];

		$this->assertSame( array( GTM4WP_OPTION_GTM_PLACEMENT, GTM4WP_OPTION_DATALAYER_NAME ), array_keys( $values ), 'Registry order, not request order.' );
	}

	public function test_an_unknown_module_is_refused_by_name(): void {
		$result = $this->execute( SettingsAbilities::GET_SETTINGS, array( 'modules' => array( 'container', 'no-such-module' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_unknown_module', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'no-such-module', $result->get_error_message() );
	}

	public function test_an_unknown_option_is_refused_by_name(): void {
		$result = $this->execute( SettingsAbilities::GET_SETTINGS, array( 'keys' => array( 'third-party-key' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_unknown_option', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_include_schema_describes_each_returned_option_in_plain_text(): void {
		$result = $this->execute(
			SettingsAbilities::GET_SETTINGS,
			array(
				'keys'           => array( GTM4WP_OPTION_DATALAYER_NAME ),
				'include_schema' => true,
			)
		);

		$this->assertCount( 1, $result['fields'] );

		$field = $result['fields'][0];

		$this->assertSame( GTM4WP_OPTION_DATALAYER_NAME, $field['key'] );
		$this->assertSame( 'container', $field['module'] );
		$this->assertNotSame( '', $field['label'] );
		$this->assertSame( 'text', $field['type'] );
		$this->assertSame( '', $field['default'] );
		$this->assertStringNotContainsString( '<', $field['description'], 'The settings screen renders limited HTML in a description; an assistant gets the text.' );
		$this->assertIsObject( $field['choices'] );
		$this->assertStringStartsWith( 'https://gtm4wp.com/', $field['doc_url'] );
		$this->assertStringEndsWith( '#' . GTM4WP_OPTION_DATALAYER_NAME, $field['doc_url'], 'The anchor is the option key, the deep link the settings screen uses.' );
	}

	public function test_the_values_are_what_the_frontend_loads_not_the_raw_row(): void {
		// A stored blacklist status is normalized by the Options service on the
		// frontend; the store answers with the stored shape, which is what the
		// settings screen edits. Pinned so the two abilities that read settings
		// (this one) and status (StatusAbilities) are known to differ here on purpose.
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'myLayer' ) );

		$values = (array) $this->execute( SettingsAbilities::GET_SETTINGS, array( 'keys' => array( GTM4WP_OPTION_DATALAYER_NAME ) ) )['values'];

		$this->assertSame( 'myLayer', $values[ GTM4WP_OPTION_DATALAYER_NAME ] );
	}

	public function test_a_hostile_stored_value_is_returned_as_data_not_rendered(): void {
		// The transport is JSON, not HTML: the ability hands the value over raw
		// and the client is responsible for its own rendering. Pinned so nobody
		// "hardens" this with esc_html() and corrupts every value containing an
		// ampersand (TS-11 / RI-4).
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'a&b"c' ) );

		$values = (array) $this->execute( SettingsAbilities::GET_SETTINGS, array( 'keys' => array( GTM4WP_OPTION_DATALAYER_NAME ) ) )['values'];

		$this->assertSame( 'a&b"c', $values[ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertStringNotContainsString( '&amp;', $values[ GTM4WP_OPTION_DATALAYER_NAME ] );
	}

	// ---- values_hash -------------------------------------------------------

	public function test_the_answer_carries_the_fingerprint_of_the_stored_row(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'myLayer' ) );

		$result = $this->execute( SettingsAbilities::GET_SETTINGS );

		$this->assertSame( ( new SettingsStore( $this->registry ) )->values_hash(), $result['values_hash'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $result['values_hash'] );
		$this->assertStringContainsString( 'values_hash', $this->registered[ SettingsAbilities::GET_SETTINGS ]['description'], 'The read tells the assistant what the hash is for.' );
	}

	// ---- update-settings ---------------------------------------------------

	public function test_the_write_is_registered_with_the_option_types_and_refuses_unknown_keys_by_schema(): void {
		$args   = $this->registered[ SettingsAbilities::UPDATE_SETTINGS ];
		$schema = $args['input_schema'];
		$store  = new SettingsStore( $this->registry );

		$this->assertSame( array( 'values' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array_keys( $store->fields_by_key() ), array_keys( $schema['properties']['values']['properties'] ), 'One typed property per registered option, from the same Field definitions as the settings REST route.' );
		$this->assertFalse( $schema['properties']['values']['additionalProperties'], 'Unlike the settings REST route, an unknown option key is refused by the schema, not silently ignored.' );
		$this->assertSame( 1, $schema['properties']['values']['minProperties'] );
		$this->assertSame( 'boolean', $schema['properties']['values']['properties'][ GTM4WP_OPTION_LOADEARLY ]['type'] );
		$this->assertSame( 'string', $schema['properties']['expected_hash']['type'] );

		// The description is the assistant's runbook: the read-first step, the
		// confirmation question and the stale guard are spelled out in it.
		$this->assertStringContainsString( 'get-settings first', $args['description'] );
		$this->assertStringContainsString( 'Confirm this change?', $args['description'] );
		$this->assertStringContainsString( 'expected_hash', $args['description'] );
		$this->assertStringContainsString( '409', $args['description'] );
	}

	public function test_a_patch_is_saved_through_the_field_sanitizers_and_reported_as_changed(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_DATALAYER_NAME => 'old',
				GTM4WP_OPTION_LOADEARLY      => false,
			)
		);
		$before = $this->execute( SettingsAbilities::GET_SETTINGS )['values_hash'];

		$result = $this->execute(
			SettingsAbilities::UPDATE_SETTINGS,
			array(
				'values'        => array(
					GTM4WP_OPTION_DATALAYER_NAME => 'myLayer',
					GTM4WP_OPTION_LOADEARLY      => 'yes',
				),
				'expected_hash' => $before,
			)
		);

		$this->assertTrue( $result['saved'] );
		$this->assertIsObject( $result['errors'] );
		$this->assertSame( array(), (array) $result['errors'] );

		$changed = (array) $result['changed'];
		ksort( $changed );

		$this->assertSame(
			array(
				GTM4WP_OPTION_DATALAYER_NAME => 'myLayer',
				GTM4WP_OPTION_LOADEARLY      => true,
			),
			$changed,
			'The checkbox lands as a bool: the Field sanitizer ran, whatever the client sent.'
		);
		$this->assertSame( 'myLayer', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertTrue( $this->stored_settings()[ GTM4WP_OPTION_LOADEARLY ] );
		$this->assertNotSame( $before, $result['values_hash'], 'The write moved the fingerprint.' );
		$this->assertSame( ( new SettingsStore( $this->registry ) )->values_hash(), $result['values_hash'], 'The hash of the row as written: what the next call sends as expected_hash.' );
	}

	public function test_a_read_after_the_write_in_the_same_request_sees_the_write(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'old' ) );

		$this->execute( SettingsAbilities::UPDATE_SETTINGS, array( 'values' => array( GTM4WP_OPTION_DATALAYER_NAME => 'newLayer' ) ) );

		$values = (array) $this->execute( SettingsAbilities::GET_SETTINGS, array( 'keys' => array( GTM4WP_OPTION_DATALAYER_NAME ) ) )['values'];

		$this->assertSame( 'newLayer', $values[ GTM4WP_OPTION_DATALAYER_NAME ], 'The store re-reads the row; nothing answers from a request-scoped copy.' );
	}

	public function test_a_hostile_text_value_lands_sanitized_never_raw(): void {
		// A transaction-id prefix is printed into the dataLayer of the purchase
		// page. The ability hands the value to the same Field sanitizer as the
		// settings screen, so the row holds the sanitized string and never the
		// submitted one (TS-1, TS-2 both directions).
		$hostile = 'ORD-"</script><script>alert(1)</script>';

		$result = $this->execute( SettingsAbilities::UPDATE_SETTINGS, array( 'values' => array( GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => $hostile ) ) );
		$stored = $this->stored_settings()[ GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX ];

		$this->assertTrue( $result['saved'] );
		$this->assertNotSame( $hostile, $stored, 'The submitted string is not what was stored.' );
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringNotContainsString( '</script', $stored );
		$this->assertSame( 'ORD-"alert(1)', $stored, 'What sanitize_text_field() leaves: the tags are gone, the quote stays (the dataLayer JSON encoding escapes it at output).' );
		$this->assertSame( $stored, ( (array) $result['changed'] )[ GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX ], 'changed reports the value as stored, not as submitted.' );
	}

	public function test_a_value_the_sanitizer_refuses_is_reported_and_not_stored_while_the_rest_is(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'old' ) );

		// The hyphen is what the data layer name sanitizer refuses (the name is
		// printed unquoted into a script body); the other option is valid.
		$result = $this->execute(
			SettingsAbilities::UPDATE_SETTINGS,
			array(
				'values' => array(
					GTM4WP_OPTION_DATALAYER_NAME => 'my-layer',
					GTM4WP_OPTION_LOADEARLY      => true,
				),
			)
		);

		$this->assertFalse( $result['saved'] );
		$this->assertSame( array( GTM4WP_OPTION_DATALAYER_NAME ), array_keys( (array) $result['errors'] ) );
		$this->assertNotSame( '', ( (array) $result['errors'] )[ GTM4WP_OPTION_DATALAYER_NAME ], 'The sanitizer\'s message, so the assistant can tell the user why.' );
		$this->assertSame( 'old', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ], 'The refused value never reaches the row.' );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_DATALAYER_NAME, (array) $result['changed'] );
		$this->assertTrue( $this->stored_settings()[ GTM4WP_OPTION_LOADEARLY ], 'The settings screen\'s partial save: the valid options of the same call are stored.' );
		$this->assertSame( array( GTM4WP_OPTION_LOADEARLY => true ), (array) $result['changed'] );
	}

	public function test_an_unknown_option_key_reaching_the_store_is_ignored_not_stored(): void {
		// Core refuses the key by schema first (additionalProperties false, pinned
		// above); this is the store's own guard for a caller that reaches the
		// method another way: ignored, never injected into the row.
		$result = $this->execute(
			SettingsAbilities::UPDATE_SETTINGS,
			array(
				'values' => array(
					'not-a-field'           => 'injected',
					GTM4WP_OPTION_LOADEARLY => true,
				),
			)
		);

		$this->assertTrue( $result['saved'] );
		$this->assertArrayNotHasKey( 'not-a-field', $this->stored_settings() );
		$this->assertArrayNotHasKey( 'not-a-field', (array) $result['changed'] );
		$this->assertTrue( $this->stored_settings()[ GTM4WP_OPTION_LOADEARLY ] );
	}

	public function test_a_stale_expected_hash_refuses_with_409_and_writes_nothing(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'original' ) );
		$read = $this->execute( SettingsAbilities::GET_SETTINGS )['values_hash'];

		// Somebody saves in between: the settings screen, an import, another assistant.
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'theirs' ) );

		$result = $this->execute(
			SettingsAbilities::UPDATE_SETTINGS,
			array(
				'values'        => array( GTM4WP_OPTION_DATALAYER_NAME => 'mine' ),
				'expected_hash' => $read,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_settings_stale', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( ( new SettingsStore( $this->registry ) )->values_hash(), $result->get_error_data()['values_hash'], 'The current fingerprint rides along; the protocol still says to read the values again.' );
		$this->assertSame( 'theirs', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ], 'The concurrent change survives.' );
		$this->assertSame( 0, $this->settings_writes(), 'Refused before the store is asked to write anything.' );
	}

	public function test_an_empty_patch_is_refused_before_anything_is_written(): void {
		$result = $this->execute( SettingsAbilities::UPDATE_SETTINGS, array( 'values' => array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_settings_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->settings_writes() );
	}

	public function test_an_option_set_to_its_current_value_is_not_reported_as_changed(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'same' ) );
		$before = ( new SettingsStore( $this->registry ) )->values_hash();

		$result = $this->execute( SettingsAbilities::UPDATE_SETTINGS, array( 'values' => array( GTM4WP_OPTION_DATALAYER_NAME => 'same' ) ) );

		$this->assertTrue( $result['saved'] );
		$this->assertIsObject( $result['changed'], 'A map even when empty, so a client never sees a bare [].' );
		$this->assertSame( array(), (array) $result['changed'] );
		$this->assertSame( $before, $result['values_hash'], 'The row did not change, so neither did its fingerprint.' );
	}

	/**
	 * With the row set fixed in wp-config.php the container table is read-only
	 * for the settings screen, and the ability goes through the same save
	 * path: the submitted rows are dropped, the stored list survives, the
	 * unrelated option in the same call is saved.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_container_rows_wp_config_controls_are_not_overwritten(): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', 'GTM-HARD01' );

		$stored_rows = array(
			array(
				'id'          => 'GTM-STORED1',
				'gtm_auth'    => '',
				'gtm_preview' => '',
				'domain'      => 'stored.example.com',
				'path'        => '',
				'no_id'       => '',
			),
		);

		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => $stored_rows,
				GTM4WP_OPTION_GTM_CODE       => 'GTM-STORED1',
			)
		);

		$result = $this->execute(
			SettingsAbilities::UPDATE_SETTINGS,
			array(
				'values' => array(
					GTM4WP_OPTION_GTM_CONTAINERS   => array(
						array(
							'id'          => 'GTM-HARD01',
							'gtm_auth'    => '',
							'gtm_preview' => '',
							'domain'      => 'agent.example.com',
							'path'        => '',
							'no_id'       => '',
						),
					),
					GTM4WP_OPTION_INCLUDE_LOGGEDIN => true,
				),
			)
		);

		$this->assertTrue( $result['saved'] );
		$this->assertSame( $stored_rows, $this->stored_settings()[ GTM4WP_OPTION_GTM_CONTAINERS ], 'The stored container list survives the call untouched.' );
		$this->assertSame( 'GTM-STORED1', $this->stored_settings()[ GTM4WP_OPTION_GTM_CODE ], 'The hard coded ID does not leak into the 1.x mirror either.' );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_GTM_CONTAINERS, (array) $result['changed'], 'A locked table is reported as unchanged, which is the truth.' );
		$this->assertTrue( ( (array) $result['changed'] )[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ] );
	}

	// ---- export-settings ---------------------------------------------------

	public function test_the_export_is_the_stores_envelope_with_the_options_as_an_object(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'myLayer' ) );

		$result = $this->execute( SettingsAbilities::EXPORT_SETTINGS );

		$this->assertSame( array( 'plugin', 'type', 'version', 'options' ), array_keys( $result ), 'The envelope the Export button downloads, member for member.' );
		$this->assertSame( 'gtm4wp', $result['plugin'] );
		$this->assertSame( SettingsStore::EXPORT_TYPE, $result['type'], 'The marker import-settings requires.' );
		$this->assertIsString( $result['version'] );
		$this->assertIsObject( $result['options'], 'A map, so a client never sees a bare [].' );
		$this->assertSame( 'myLayer', ( (array) $result['options'] )[ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertSame( SettingsStore::EXPORT_TYPE, $this->registered[ SettingsAbilities::EXPORT_SETTINGS ]['output_schema']['properties']['type']['enum'][0] );
	}

	public function test_the_export_carries_the_stored_configuration_not_the_wp_config_override(): void {
		// The custody of the service-account key is GoogleAuthCustodyTest's;
		// pinned here is the difference to get-settings: an export must carry
		// the site's OWN stored rows, ui_values() the rows actually loaded.
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( 'id' => 'GTM-STORED1' ) ) ) );

		$options = (array) $this->execute( SettingsAbilities::EXPORT_SETTINGS )['options'];

		$this->assertSame( 'GTM-STORED1', $options[ GTM4WP_OPTION_GTM_CONTAINERS ][0]['id'] );
	}

	// ---- import-settings ---------------------------------------------------

	/**
	 * A valid envelope carrying the given options.
	 *
	 * @param array<string, mixed> $options Option key => value.
	 * @return string
	 */
	private static function envelope( array $options ): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- building the fixture.
			array(
				'plugin'  => 'gtm4wp',
				'type'    => SettingsStore::EXPORT_TYPE,
				'version' => '2.1.0',
				'options' => $options,
			)
		);
	}

	public function test_the_import_is_registered_as_a_confirmed_site_wide_replace(): void {
		$args   = $this->registered[ SettingsAbilities::IMPORT_SETTINGS ];
		$schema = $args['input_schema'];

		$this->assertSame( array( 'payload', 'confirm' ), $schema['required'] );
		$this->assertSame( 'string', $schema['properties']['payload']['type'] );
		$this->assertSame( 'boolean', $schema['properties']['confirm']['type'] );
		$this->assertStringContainsString( 'export-settings first', $args['description'], 'The runbook: keep a way back before replacing everything.' );
		$this->assertStringContainsString( 'Replace all settings on this site?', $args['description'] );
	}

	/**
	 * Calls that do not carry the confirmation.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unconfirmed_imports(): array {
		return array(
			'confirm missing' => array( array() ),
			'confirm false'   => array( array( 'confirm' => false ) ),
			'confirm string'  => array( array( 'confirm' => 'true' ) ),
			'confirm 1'       => array( array( 'confirm' => 1 ) ),
		);
	}

	/**
	 * Without `confirm: true` a valid envelope is refused before it is even
	 * decoded, and the row stays as it was.
	 *
	 * @param array<string, mixed> $confirm The confirm member, or none.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unconfirmed_imports' )]
	public function test_an_import_without_confirm_true_is_refused_before_anything_runs( array $confirm ): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'keep' ) );

		$result = $this->execute(
			SettingsAbilities::IMPORT_SETTINGS,
			array_merge( array( 'payload' => self::envelope( array( GTM4WP_OPTION_DATALAYER_NAME => 'imported' ) ) ), $confirm )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_confirmation_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'keep', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertSame( 0, $this->settings_writes() );
	}

	/**
	 * Payloads the store refuses, each with the code that names the problem.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refused_payloads(): array {
		return array(
			'no type marker'        => array( '{"plugin":"gtm4wp","options":{"gtm-datalayer-variable-name":"x"}}', 'gtm4wp_import_invalid' ),
			'wrong type marker'     => array( '{"type":"somebody-elses-export","options":{}}', 'gtm4wp_import_invalid' ),
			'options not an object' => array( '{"type":"' . SettingsStore::EXPORT_TYPE . '","options":"x"}', 'gtm4wp_import_invalid' ),
			'not JSON'              => array( 'gtm-datalayer-variable-name=x', 'gtm4wp_import_invalid' ),
			'empty'                 => array( '', 'gtm4wp_import_empty' ),
			'not a string'          => array( array( 'type' => SettingsStore::EXPORT_TYPE ), 'gtm4wp_import_empty' ),
			'over the size cap'     => array( '{' . str_repeat( ' ', SettingsStore::IMPORT_MAX_BYTES ) . '}', 'gtm4wp_import_too_large' ),
		);
	}

	/**
	 * A refused payload is a 400 that stores nothing - the same checks as the
	 * settings screen's Import button, through the same store.
	 *
	 * @param mixed  $payload The payload.
	 * @param string $code    Expected error code.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_payloads' )]
	public function test_a_payload_that_is_not_an_export_is_refused_with_400_and_stores_nothing( $payload, string $code ): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'keep' ) );

		$result = $this->execute(
			SettingsAbilities::IMPORT_SETTINGS,
			array(
				'payload' => $payload,
				'confirm' => true,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'keep', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertSame( 0, $this->settings_writes() );
	}

	public function test_a_hostile_envelope_is_re_sanitised_value_by_value(): void {
		// Every value of the file runs through its Field sanitizer as if typed
		// into the settings screen (TS-1, TS-2 both directions): a tag is
		// stripped, a refused value is reported and its option keeps the
		// default, a key that is no field is never injected into the row.
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'before' ) );
		$hostile = 'ORD-"</script><script>alert(1)</script>';

		$result = $this->execute(
			SettingsAbilities::IMPORT_SETTINGS,
			array(
				'payload' => self::envelope(
					array(
						GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => $hostile,
						GTM4WP_OPTION_DATALAYER_NAME => 'my-layer',
						GTM4WP_OPTION_LOADEARLY      => 'yes',
						'not-a-field'                => 'injected',
					)
				),
				'confirm' => true,
			)
		);

		$stored = $this->stored_settings();

		$this->assertFalse( $result['imported'] );
		$this->assertSame( array( GTM4WP_OPTION_DATALAYER_NAME ), array_keys( (array) $result['errors'] ) );
		$this->assertNotSame( '', ( (array) $result['errors'] )[ GTM4WP_OPTION_DATALAYER_NAME ], 'The sanitizer\'s message, so the assistant can tell the user why.' );

		$this->assertNotSame( $hostile, $stored[ GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX ] );
		$this->assertStringNotContainsString( '<script', $stored[ GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX ] );
		$this->assertSame( 'ORD-"alert(1)', $stored[ GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX ] );
		$this->assertSame( '', $stored[ GTM4WP_OPTION_DATALAYER_NAME ], 'The refused value never reaches the row, and a replace does not keep the old value either: the default.' );
		$this->assertTrue( $stored[ GTM4WP_OPTION_LOADEARLY ], 'The checkbox lands as a bool: the Field sanitizer ran.' );
		$this->assertArrayNotHasKey( 'not-a-field', $stored );
		$this->assertSame( ( new SettingsStore( $this->registry ) )->values_hash(), $result['values_hash'] );
	}

	public function test_an_import_replaces_the_whole_row_so_an_option_missing_from_the_envelope_falls_back_to_its_default(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_DATALAYER_NAME => 'old',
				GTM4WP_OPTION_LOADEARLY      => true,
			)
		);
		$before = ( new SettingsStore( $this->registry ) )->values_hash();

		$result = $this->execute(
			SettingsAbilities::IMPORT_SETTINGS,
			array(
				'payload' => self::envelope( array( GTM4WP_OPTION_INCLUDE_LOGGEDIN => true ) ),
				'confirm' => true,
			)
		);

		$this->assertTrue( $result['imported'] );
		$this->assertIsObject( $result['errors'] );
		$this->assertSame( array(), (array) $result['errors'] );
		$this->assertSame( '', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ], 'Not a patch: the option the envelope does not name is reset.' );
		$this->assertFalse( $this->stored_settings()[ GTM4WP_OPTION_LOADEARLY ] );
		$this->assertTrue( $this->stored_settings()[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ] );
		$this->assertNotSame( $before, $result['values_hash'], 'The write moved the fingerprint.' );
	}

	public function test_an_export_round_trips_through_the_import(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'myLayer' ) );
		$payload = (string) json_encode( $this->execute( SettingsAbilities::EXPORT_SETTINGS ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- what a client sends back.

		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'lost' ) );

		$result = $this->execute(
			SettingsAbilities::IMPORT_SETTINGS,
			array(
				'payload' => $payload,
				'confirm' => true,
			)
		);

		$this->assertTrue( $result['imported'], 'What export-settings hands out is what import-settings takes.' );
		$this->assertSame( 'myLayer', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ] );
	}

	public function test_a_read_only_site_lists_the_export_but_not_the_import(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );

		$this->registered = array();
		( new SettingsAbilities( $this->registry ) )->register();

		$this->assertSame( array( SettingsAbilities::GET_SETTINGS, SettingsAbilities::EXPORT_SETTINGS ), array_keys( $this->registered ) );
	}

	public function test_an_import_run_after_the_switch_flipped_refuses_with_403_and_writes_nothing(): void {
		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'keep' ) );
		$this->assertArrayHasKey( SettingsAbilities::IMPORT_SETTINGS, $this->registered, 'Registered while writes were allowed.' );

		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );

		$result = $this->execute(
			SettingsAbilities::IMPORT_SETTINGS,
			array(
				'payload' => self::envelope( array( GTM4WP_OPTION_DATALAYER_NAME => 'imported' ) ),
				'confirm' => true,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_abilities_write_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'keep', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertSame( 0, $this->settings_writes() );
	}
}
