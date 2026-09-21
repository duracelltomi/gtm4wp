<?php
/**
 * Unit tests for the settings abilities.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

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
}
