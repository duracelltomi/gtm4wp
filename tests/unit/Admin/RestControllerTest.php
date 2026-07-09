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

	public function test_save_sanitizes_and_persists_values(): void {
		$controller = $this->make_controller();

		$response = $controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CODE         => ' GTM-NEW123 ',
						GTM4WP_OPTION_INCLUDE_LOGGEDIN => 1,
						GTM4WP_OPTION_SCROLLER_CALLBACKTIME => '250',
					),
				)
			)
		);

		$data = $response->get_data();

		$this->assertTrue( $data['saved'] );
		$this->assertSame( array(), (array) $data['errors'] );

		$this->assertSame( 'GTM-NEW123', $this->saved[ GTM4WP_OPTION_GTM_CODE ], 'GTM ID is trimmed.' );
		$this->assertTrue( $this->saved[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ], 'Checkbox values are cast to bool.' );
		$this->assertSame( 250, $this->saved[ GTM4WP_OPTION_SCROLLER_CALLBACKTIME ], 'Integer values are cast to int.' );
	}

	public function test_invalid_value_is_rejected_and_reported(): void {
		$controller = $this->make_controller(
			array( GTM4WP_OPTION_GTM_CODE => 'GTM-OLD1' )
		);

		$response = $controller->save_settings(
			new \WP_REST_Request(
				array(
					'values' => array(
						GTM4WP_OPTION_GTM_CODE => 'not-a-gtm-id',
					),
				)
			)
		);

		$data   = $response->get_data();
		$errors = (array) $data['errors'];

		$this->assertFalse( $data['saved'] );
		$this->assertArrayHasKey( GTM4WP_OPTION_GTM_CODE, $errors );
		$this->assertSame( 'GTM-OLD1', $this->saved[ GTM4WP_OPTION_GTM_CODE ], 'Invalid values must not overwrite the stored value.' );
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
