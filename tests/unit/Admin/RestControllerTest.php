<?php
/**
 * Unit tests for the settings REST controller.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Functions;
use GTM4WP\Admin\RestController;
use GTM4WP\Module\Registry;
use GTM4WP\Tests\unit\TestCase;

/**
 * Schema driven sanitization and persistence of the settings endpoint.
 */
final class RestControllerTest extends TestCase {

	/**
	 * Captured update_option() payload.
	 *
	 * @var array|null
	 */
	private ?array $saved = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_kses' )->alias(
			static function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
			}
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names(): array {
					return array();
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$this->saved = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->saved = $value;
				return true;
			}
		);
	}

	/**
	 * Builds a controller with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return RestController
	 */
	private function make_controller( array $stored = array() ): RestController {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new RestController( Registry::with_default_modules() );
	}

	public function test_current_values_merge_defaults_with_stored(): void {
		$controller = $this->make_controller(
			array( GTM4WP_OPTION_GTM_CODE => 'GTM-STORED' )
		);

		$values = $controller->current_values();

		$this->assertSame( 'GTM-STORED', $values[ GTM4WP_OPTION_GTM_CODE ] );
		$this->assertFalse( $values[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ], 'Unset options fall back to module defaults.' );
	}

	public function test_current_values_derive_container_rows_from_legacy_options(): void {
		$controller = $this->make_controller(
			array(
				GTM4WP_OPTION_GTM_CODE  => 'GTM-STORED',
				GTM4WP_OPTION_GTMDOMAIN => 'gtm.example.com',
			)
		);

		$values = $controller->current_values();

		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-STORED',
					'gtm_auth'    => '',
					'gtm_preview' => '',
					'domain'      => 'gtm.example.com',
					'path'        => '',
				),
			),
			$values[ GTM4WP_OPTION_GTM_CONTAINERS ],
			'Until the row option is saved, the admin UI shows the rows the frontend derives from the flat 1.x options.'
		);
	}

	public function test_current_values_keep_saved_empty_container_rows(): void {
		$controller = $this->make_controller(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(),
				GTM4WP_OPTION_GTM_CODE       => 'GTM-STALE1',
			)
		);

		$values = $controller->current_values();

		$this->assertSame(
			array(),
			$values[ GTM4WP_OPTION_GTM_CONTAINERS ],
			'An intentionally emptied table must not be repopulated from stale legacy keys.'
		);
	}

	public function test_save_sanitizes_and_persists_values(): void {
		$controller = $this->make_controller();

		$response = $controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CONTAINERS   => array(
							array(
								'id'          => ' GTM-NEW123 ',
								'gtm_auth'    => 'authtoken',
								'gtm_preview' => 'env-2',
								'domain'      => 'https://gtm.example.com',
								'path'        => '/custom/loader.js',
							),
						),
						GTM4WP_OPTION_INCLUDE_LOGGEDIN => 1,
						GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION => '250',
					),
				)
			)
		);

		$data = $response->get_data();

		$this->assertTrue( $data['saved'] );
		$this->assertSame( array(), (array) $data['errors'] );

		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-NEW123',
					'gtm_auth'    => 'authtoken',
					'gtm_preview' => 'env-2',
					'domain'      => 'gtm.example.com',
					'path'        => 'custom/loader.js',
					'no_id'       => '',
				),
			),
			$this->saved[ GTM4WP_OPTION_GTM_CONTAINERS ],
			'Rows are trimmed, the https:// prefix and the leading path slash are stripped.'
		);
		$this->assertTrue( $this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ], 'Checkbox values are cast to bool.' );
		$this->assertSame( 250, $this->saved[ GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION ], 'Integer values are cast to int.' );
	}

	public function test_saving_container_rows_syncs_legacy_mirror_options(): void {
		$controller = $this->make_controller();

		$controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CONTAINERS => array(
							array(
								'id'          => 'GTM-AAA111',
								'gtm_auth'    => 'authtoken',
								'gtm_preview' => 'env-2',
								'domain'      => 'gtm.example.com',
								'path'        => '',
							),
							array( 'id' => 'GTM-BBB222' ),
						),
					),
				)
			)
		);

		$this->assertSame( 'GTM-AAA111,GTM-BBB222', $this->saved[ GTM4WP_OPTION_GTM_CODE ], 'The flat gtm-code mirror is stored alongside the rows.' );
		$this->assertSame( 'authtoken', $this->saved[ GTM4WP_OPTION_ENV_GTM_AUTH ] );
		$this->assertSame( 'env-2', $this->saved[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] );
		$this->assertSame( 'gtm.example.com', $this->saved[ GTM4WP_OPTION_GTMDOMAIN ] );
	}

	public function test_invalid_container_row_is_rejected_and_reported(): void {
		$stored_rows = array(
			array(
				'id'          => 'GTM-OLD1',
				'gtm_auth'    => '',
				'gtm_preview' => '',
				'domain'      => '',
				'path'        => '',
			),
		);

		$controller = $this->make_controller(
			array( GTM4WP_OPTION_GTM_CONTAINERS => $stored_rows )
		);

		$response = $controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CONTAINERS => array(
							array( 'id' => 'not-a-gtm-id' ),
						),
					),
				)
			)
		);

		$data   = $response->get_data();
		$errors = (array) $data['errors'];

		$this->assertFalse( $data['saved'] );
		$this->assertArrayHasKey( GTM4WP_OPTION_GTM_CONTAINERS, $errors );
		$this->assertSame( $stored_rows, $this->saved[ GTM4WP_OPTION_GTM_CONTAINERS ], 'Invalid values must not overwrite the stored value.' );
	}

	public function test_duplicate_container_ids_are_rejected(): void {
		$controller = $this->make_controller();

		$response = $controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CONTAINERS => array(
							array( 'id' => 'GTM-AAA111' ),
							array( 'id' => 'GTM-AAA111' ),
						),
					),
				)
			)
		);

		$errors = (array) $response->get_data()['errors'];

		$this->assertArrayHasKey( GTM4WP_OPTION_GTM_CONTAINERS, $errors );
	}

	public function test_unknown_keys_are_ignored(): void {
		$controller = $this->make_controller();

		$controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						'evil-injected-key'            => 'value',
						GTM4WP_OPTION_INCLUDE_LOGGEDIN => true,
					),
				)
			)
		);

		$this->assertArrayNotHasKey( 'evil-injected-key', $this->saved );
		$this->assertTrue( $this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ] );
	}

	public function test_blacklist_status_saved_as_comma_string(): void {
		$controller = $this->make_controller();

		$controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_BLACKLIST_STATUS => array( 'html', 'gaawe', 'not-valid' ),
					),
				)
			)
		);

		$this->assertSame( 'html,gaawe', $this->saved[ GTM4WP_OPTION_BLACKLIST_STATUS ], 'Only valid entity ids are stored, as comma separated string.' );
	}

	/**
	 * Collects the option keys of every registered field, the way the
	 * controller's own fields_by_key() does. Used by the two "control" tests
	 * that pin export and import to the schema, so a newly added Field is
	 * automatically covered by both without a list to maintain.
	 *
	 * @return string[]
	 */
	private function registered_field_keys(): array {
		$keys = array();

		foreach ( Registry::with_default_modules()->all() as $module ) {
			$schema_class = $module->admin_schema();
			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$schema = new $schema_class();
			foreach ( $schema->fields() as $field ) {
				$keys[] = $field->key;
			}
		}

		return $keys;
	}

	public function test_export_returns_envelope_with_current_values(): void {
		$controller = $this->make_controller(
			array( GTM4WP_OPTION_GTM_CODE => 'GTM-EXPORTED' )
		);

		$data = $controller->export_settings()->get_data();

		$this->assertSame( 'gtm4wp', $data['plugin'] );
		$this->assertSame( RestController::EXPORT_TYPE, $data['type'] );
		$this->assertSame( GTM4WP_VERSION, $data['version'] );
		$this->assertSame( 'GTM-EXPORTED', $data['options'][ GTM4WP_OPTION_GTM_CODE ] );
	}

	/**
	 * Pre-flight control (export half): every registered option is exported.
	 * Because the export is schema driven (current_values() merges the module
	 * defaults), adding a Field automatically adds it to the export; this test
	 * fails the day the export stops being schema-complete.
	 */
	public function test_export_includes_every_registered_option(): void {
		$controller = $this->make_controller();

		$options = $controller->export_data()['options'];

		foreach ( $this->registered_field_keys() as $key ) {
			$this->assertArrayHasKey(
				$key,
				$options,
				"Export must include registered option '{$key}'."
			);
		}
	}

	/**
	 * Mandatory hostile-input regression (TC-1): an imported value carrying
	 * script-context break-out characters is re-sanitized through its schema
	 * Field before being stored - the file is never trusted. The break-out
	 * chars are written as \xNN escapes (TC-2) and the assertion is
	 * both-directions (TS-2): the safe, allow-listed form is present AND the
	 * raw break-out characters are absent. The blacklist field allow-lists its
	 * entries, so a bypass (storing the file's raw array) would leave the
	 * payload in the stored value and fail the test.
	 */
	public function test_import_re_sanitizes_hostile_payload(): void {
		$controller = $this->make_controller();

		// `</script>"&` smuggled in among two valid entity ids.
		$hostile = "\x3C/script\x3E\x22\x26";

		$payload = wp_json_encode(
			array(
				'type'    => RestController::EXPORT_TYPE,
				'options' => array(
					GTM4WP_OPTION_BLACKLIST_STATUS => array( 'html', $hostile, 'gaawe' ),
				),
			)
		);

		$response = $controller->import_settings(
			new \WP_REST_Request( array( 'payload' => $payload ) )
		);

		$this->assertTrue( $response->get_data()['imported'] );

		$stored = $this->saved[ GTM4WP_OPTION_BLACKLIST_STATUS ];

		// Present: only the valid, allow-listed entities, as a comma string.
		$this->assertSame( 'html,gaawe', $stored );

		// Absent: the raw break-out characters from the uploaded file.
		$this->assertStringNotContainsString( '</script>', $stored );
		$this->assertStringNotContainsString( '"', $stored );
		$this->assertStringNotContainsString( '&', $stored );
	}

	public function test_import_rejects_hostile_container_id_and_does_not_store_it(): void {
		$controller = $this->make_controller();

		$payload = wp_json_encode(
			array(
				'type'    => RestController::EXPORT_TYPE,
				'options' => array(
					GTM4WP_OPTION_GTM_CONTAINERS => array(
						array( 'id' => "GTM-\x3Cscript\x3Ealert(1)\x3C/script\x3E" ),
					),
				),
			)
		);

		$response = $controller->import_settings(
			new \WP_REST_Request( array( 'payload' => $payload ) )
		);

		$data = $response->get_data();

		$this->assertFalse( $data['imported'], 'A rejected value makes the import report an error.' );
		$this->assertArrayHasKey( GTM4WP_OPTION_GTM_CONTAINERS, (array) $data['errors'] );

		// The hostile id never reaches the stored row: the field kept its default.
		$this->assertStringNotContainsString(
			'<script>',
			(string) wp_json_encode( $this->saved[ GTM4WP_OPTION_GTM_CONTAINERS ] )
		);
	}

	public function test_import_ignores_unknown_keys(): void {
		$controller = $this->make_controller();

		$payload = wp_json_encode(
			array(
				'type'    => RestController::EXPORT_TYPE,
				'options' => array(
					'evil-injected-key'            => 'value',
					GTM4WP_OPTION_INCLUDE_LOGGEDIN => true,
				),
			)
		);

		$controller->import_settings(
			new \WP_REST_Request( array( 'payload' => $payload ) )
		);

		$this->assertArrayNotHasKey( 'evil-injected-key', $this->saved );
		$this->assertTrue( $this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ] );
	}

	public function test_import_replaces_missing_options_with_defaults(): void {
		// Start with a stored row that has a custom value; the import file does
		// NOT mention it, so after import it must fall back to its default.
		$controller = $this->make_controller(
			array( GTM4WP_OPTION_INCLUDE_LOGGEDIN => true )
		);

		$payload = wp_json_encode(
			array(
				'type'    => RestController::EXPORT_TYPE,
				'options' => array(
					GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION => 250,
				),
			)
		);

		$controller->import_settings(
			new \WP_REST_Request( array( 'payload' => $payload ) )
		);

		$this->assertSame( 250, $this->saved[ GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION ] );
		$this->assertFalse(
			$this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ],
			'An option absent from the file is reset to its default, not carried over from the target install.'
		);
	}

	public function test_import_rejects_empty_payload(): void {
		$controller = $this->make_controller();

		$result = $controller->import_settings(
			new \WP_REST_Request( array( 'payload' => '' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( $this->saved, 'A rejected import never writes the option row.' );
	}

	public function test_import_rejects_malformed_json(): void {
		$controller = $this->make_controller();

		$result = $controller->import_settings(
			new \WP_REST_Request( array( 'payload' => '{ this is not json ' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( $this->saved );
	}

	public function test_import_rejects_file_that_is_not_a_gtm4wp_export(): void {
		$controller = $this->make_controller();

		$result = $controller->import_settings(
			new \WP_REST_Request(
				array(
					'payload' => wp_json_encode(
						array(
							'type'    => 'some-other-plugin-export',
							'options' => array( GTM4WP_OPTION_INCLUDE_LOGGEDIN => true ),
						)
					),
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( $this->saved );
	}

	public function test_import_rejects_oversized_payload(): void {
		$controller = $this->make_controller();

		$result = $controller->import_settings(
			new \WP_REST_Request( array( 'payload' => str_repeat( 'a', 600 * 1024 ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( $this->saved );
	}

	/**
	 * Pre-flight control (import half) + round trip: a clean export imports
	 * back without errors and every registered option is routed through the
	 * import sanitizer. Together with the export control above this guarantees
	 * a newly added Field is carried by both directions automatically - no
	 * option can silently bypass import.
	 */
	public function test_export_round_trips_and_import_processes_every_option(): void {
		$exporter = $this->make_controller(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS   => array(
					array(
						'id'          => 'GTM-ROUND1',
						'gtm_auth'    => '',
						'gtm_preview' => '',
						'domain'      => '',
						'path'        => '',
					),
				),
				GTM4WP_OPTION_INCLUDE_LOGGEDIN => true,
			)
		);

		// Capture the export payload before switching the get_option() stub.
		$payload = wp_json_encode( $exporter->export_data() );

		$importer = $this->make_controller();

		$response = $importer->import_settings(
			new \WP_REST_Request( array( 'payload' => $payload ) )
		);

		$this->assertTrue(
			$response->get_data()['imported'],
			'A file produced by export must import back without any field being rejected.'
		);

		foreach ( $this->registered_field_keys() as $key ) {
			$this->assertArrayHasKey(
				$key,
				$this->saved,
				"Import must process registered option '{$key}'."
			);
		}

		$this->assertTrue( $this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ], 'Round-tripped custom values survive.' );
	}

	/**
	 * The settings screen must show the containers that are actually loaded: in
	 * 1.x the hard coded ID was rendered into a read-only input, while 2.0 fed
	 * the screen the raw stored value and let the admin save a container ID that
	 * never loads. An export, in contrast, has to stay portable and carry the
	 * site's own stored setup rather than this install's wp-config constants.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_ui_values_show_the_container_the_hardcoded_id_actually_loads(): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', 'GTM-HARD01' );

		$controller = $this->make_controller(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-STORED1',
						'gtm_auth'    => '',
						'gtm_preview' => '',
						'domain'      => 'stored.example.com',
						'path'        => '',
						'no_id'       => '',
					),
				),
			)
		);

		$ui_rows = $controller->ui_values()[ GTM4WP_OPTION_GTM_CONTAINERS ];

		$this->assertCount( 1, $ui_rows );
		$this->assertSame( 'GTM-HARD01', $ui_rows[0]['id'], 'The screen shows the container ID the frontend loads.' );
		$this->assertSame( 'stored.example.com', $ui_rows[0]['domain'], 'The hard coded ID inherits the settings of the stored row.' );

		$this->assertSame(
			'GTM-STORED1',
			$controller->current_values()[ GTM4WP_OPTION_GTM_CONTAINERS ][0]['id'],
			'The export path keeps the stored setup, so a settings file stays portable to a site without the constant.'
		);
	}

	/**
	 * With the row set fixed in wp-config.php the whole table is read-only, so
	 * nothing submitted for it can be intentional. Saving an unrelated option
	 * must not persist the constant's container ID over the admin's own stored
	 * list - that would silently change which container loads the moment the
	 * constant is removed.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_save_keeps_the_stored_containers_when_wpconfig_fixes_the_row_set(): void {
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

		$controller = $this->make_controller(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => $stored_rows,
				GTM4WP_OPTION_GTM_CODE       => 'GTM-STORED1',
			)
		);

		// Exactly what the screen submits: the effective rows it was given, plus
		// the unrelated option the admin actually changed.
		$controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CONTAINERS   => array(
							array(
								'id'          => 'GTM-HARD01',
								'gtm_auth'    => '',
								'gtm_preview' => '',
								'domain'      => 'stored.example.com',
								'path'        => '',
								'no_id'       => '',
							),
						),
						GTM4WP_OPTION_INCLUDE_LOGGEDIN => 1,
					),
				)
			)
		);

		$this->assertSame( $stored_rows, $this->saved[ GTM4WP_OPTION_GTM_CONTAINERS ], 'The stored container list survives the save untouched.' );
		$this->assertSame( 'GTM-STORED1', $this->saved[ GTM4WP_OPTION_GTM_CODE ], 'The hard coded ID must not leak into the 1.x mirror either.' );
		$this->assertTrue( $this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ], 'The option the admin did change is still saved.' );
	}

	/**
	 * A single hard coded environment parameter fixes one column but leaves the
	 * container list to the admin: an edit to any other cell must be saved,
	 * while the locked cell falls back to the value stored for that container.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_save_restores_a_hardcoded_env_column_from_the_stored_row(): void {
		define( 'GTM4WP_HARDCODED_GTM_ENV_AUTH', 'hard-auth' );

		$controller = $this->make_controller(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-AAA111',
						'gtm_auth'    => 'stored-auth',
						'gtm_preview' => 'env-7',
						'domain'      => '',
						'path'        => '',
						'no_id'       => '',
					),
				),
			)
		);

		$controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CONTAINERS => array(
							// The row as the screen renders it: the locked cell
							// carries the constant's value, the domain was edited.
							array(
								'id'          => 'GTM-AAA111',
								'gtm_auth'    => 'hard-auth',
								'gtm_preview' => 'env-7',
								'domain'      => 'new.example.com',
								'path'        => '',
								'no_id'       => '',
							),
							// A container added in the same save has no stored
							// value for the locked column.
							array(
								'id'          => 'GTM-BBB222',
								'gtm_auth'    => 'hard-auth',
								'gtm_preview' => '',
								'domain'      => '',
								'path'        => '',
								'no_id'       => '',
							),
						),
					),
				)
			)
		);

		$rows = $this->saved[ GTM4WP_OPTION_GTM_CONTAINERS ];

		$this->assertCount( 2, $rows, 'The admin can still manage the container list.' );
		$this->assertSame( 'new.example.com', $rows[0]['domain'], 'The cell the admin edited is saved.' );
		$this->assertSame( 'stored-auth', $rows[0]['gtm_auth'], 'The hard coded value never overwrites the stored one.' );
		$this->assertSame( 'env-7', $rows[0]['gtm_preview'], 'A column no constant touches is saved as submitted.' );
		$this->assertSame( '', $rows[1]['gtm_auth'], 'A newly added container has no stored value for the locked column.' );
	}
}
