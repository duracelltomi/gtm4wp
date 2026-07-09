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
						GTM4WP_OPTION_SCROLLER_CALLBACKTIME => '250',
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
				),
			),
			$this->saved[ GTM4WP_OPTION_GTM_CONTAINERS ],
			'Rows are trimmed, the https:// prefix and the leading path slash are stripped.'
		);
		$this->assertTrue( $this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ], 'Checkbox values are cast to bool.' );
		$this->assertSame( 250, $this->saved[ GTM4WP_OPTION_SCROLLER_CALLBACKTIME ], 'Integer values are cast to int.' );
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
}
