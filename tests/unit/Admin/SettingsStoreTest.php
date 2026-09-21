<?php
/**
 * Unit tests for the settings store.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Functions;
use GTM4WP\Admin\SettingsStore;
use GTM4WP\Module\Registry;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The store is the one path the settings are read for editing and written
 * back through; the REST controller's suite drives it through the routes, this
 * file pins what the abilities rely on directly: the per-module field map, the
 * two write verbs and the import decoder's refusals.
 */
final class SettingsStoreTest extends TestCase {

	use OptionStoreTrait;

	private Registry $registry;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn ( $value ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names(): array {
					return array();
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$this->stub_option_store();

		$this->registry = Registry::with_default_modules();
	}

	private function store(): SettingsStore {
		return new SettingsStore( $this->registry );
	}

	public function test_fields_are_grouped_by_module_in_registry_order(): void {
		$by_module = $this->store()->fields_by_module();

		$this->assertSame( array_keys( $this->registry->all() ), array_keys( $by_module ) );

		foreach ( $by_module as $module_id => $fields ) {
			$this->assertIsArray( $fields, $module_id );
		}

		$this->assertSame( count( $this->store()->fields_by_key() ), array_sum( array_map( 'count', $by_module ) ), 'Every field belongs to exactly one module.' );
	}

	public function test_save_sanitizes_and_writes_only_registered_keys(): void {
		$this->options[ GTM4WP_OPTIONS ] = array( GTM4WP_OPTION_DATALAYER_NAME => 'old' );

		$errors = $this->store()->save(
			array(
				GTM4WP_OPTION_DATALAYER_NAME => 'myLayer',
				GTM4WP_OPTION_LOADEARLY      => 'yes',
				'not-a-field'                => 'injected',
			)
		);

		$this->assertSame( array(), $errors );
		$this->assertSame( 'myLayer', $this->options[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertTrue( $this->options[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_LOADEARLY ], 'Through the Field sanitizer: a checkbox stores a bool, whatever was submitted.' );
		$this->assertArrayNotHasKey( 'not-a-field', $this->options[ GTM4WP_OPTIONS ], 'An unregistered key cannot be injected into the row.' );
	}

	public function test_save_reports_a_rejected_value_and_leaves_the_stored_one_alone(): void {
		$this->options[ GTM4WP_OPTIONS ] = array( GTM4WP_OPTION_DATALAYER_NAME => 'old' );

		// A hyphen is the character the data layer name sanitizer refuses: the
		// name is emitted unquoted into a <script> body, where it would read as
		// a subtraction (PA-2). The refusal is the field's; the store's part is
		// to report it under the key and store nothing for it.
		$errors = $this->store()->save( array( GTM4WP_OPTION_DATALAYER_NAME => 'my-layer' ) );

		$this->assertSame( array( GTM4WP_OPTION_DATALAYER_NAME ), array_keys( $errors ) );
		$this->assertNotSame( '', $errors[ GTM4WP_OPTION_DATALAYER_NAME ] );
		$this->assertSame( 'old', $this->options[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_DATALAYER_NAME ], 'The rejected value never reaches the row.' );
	}

	public function test_replace_rebuilds_the_row_onto_the_module_defaults(): void {
		$this->options[ GTM4WP_OPTIONS ] = array(
			GTM4WP_OPTION_DATALAYER_NAME => 'keepMe',
			GTM4WP_OPTION_LOADEARLY      => true,
		);

		$errors = $this->store()->replace( array( GTM4WP_OPTION_LOADEARLY => true ) );

		$this->assertSame( array(), $errors );
		$this->assertTrue( $this->options[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_LOADEARLY ] );
		$this->assertSame( '', $this->options[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_DATALAYER_NAME ], 'A key missing from the input falls back to its default, not to the old value.' );
	}

	public function test_decode_import_refuses_an_empty_payload(): void {
		$result = $this->store()->decode_import( '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_import_empty', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_decode_import_refuses_an_oversized_payload_before_decoding(): void {
		$result = $this->store()->decode_import( str_repeat( 'x', SettingsStore::IMPORT_MAX_BYTES + 1 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_import_too_large', $result->get_error_code() );
	}

	public function test_decode_import_refuses_a_file_without_the_type_marker(): void {
		$result = $this->store()->decode_import( '{"type":"something-else","options":{}}' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_import_invalid', $result->get_error_code() );
	}

	public function test_decode_import_returns_the_options_of_a_valid_envelope(): void {
		$payload = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'type'    => SettingsStore::EXPORT_TYPE,
				'options' => array( GTM4WP_OPTION_DATALAYER_NAME => 'imported' ),
			)
		);

		$this->assertSame( array( GTM4WP_OPTION_DATALAYER_NAME => 'imported' ), $this->store()->decode_import( $payload ) );
	}

	public function test_export_carries_the_type_marker_and_the_stored_configuration(): void {
		$this->options[ GTM4WP_OPTIONS ] = array( GTM4WP_OPTION_DATALAYER_NAME => 'mine' );

		$export = $this->store()->export_data();

		$this->assertSame( SettingsStore::EXPORT_TYPE, $export['type'] );
		$this->assertSame( 'gtm4wp', $export['plugin'] );
		$this->assertSame( 'mine', $export['options'][ GTM4WP_OPTION_DATALAYER_NAME ] );
	}
}
