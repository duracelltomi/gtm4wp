<?php
/**
 * Custody test: the service-account private key leaves the vault through no
 * read path of the plugin.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Admin\RestController as SettingsRestController;
use GTM4WP\Admin\SettingsPage;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Module\Registry;
use GTM4WP\Modules\GoogleAuth\AdminSchema;
use GTM4WP\Modules\GoogleAuth\RestController;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The key is write-only by design: it is stored outside the settings row so
 * that the settings GET, the settings export and the React bootstrap - the
 * three paths that serialize "everything the plugin knows" - never see it.
 * That is true by construction today and this test pins it against a future
 * "also export the other gtm4wp_* rows" sweep: with a key stored, each path's
 * full payload is searched for the PEM, its ciphertext and the sealed-blob
 * key name.
 *
 * The bootstrap payload is also where the panel descriptor of the module
 * crosses to JS, so the descriptor is pinned here rather than in a schema
 * test: the React side keys its panel registry on exactly these strings.
 */
final class GoogleAuthCustodyTest extends TestCase {

	use OptionStoreTrait;

	private const SECRET = 'unit-test-site-secret-do-not-reuse';

	private string $ciphertext = '';

	protected function setUp(): void {
		parent::setUp();

		// The full default registry is built for the bootstrap payload, which
		// needs the same stub set the settings page test uses (TS-16).
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $value ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);
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
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$this->stub_option_store(
			array(
				GTM4WP_OPTIONS => array( GTM4WP_OPTION_INCLUDE_LOGGEDIN => true ),
			)
		);

		$id = ( new KeyVault( self::SECRET ) )->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $id );
		$this->ciphertext = $this->options[ KeyVault::OPTION_NAME ][ $id ]['key']['ciphertext'];
	}

	/**
	 * The three strings whose presence anywhere in a payload is the leak.
	 *
	 * @param string $flat  Serialized payload.
	 * @param string $which Which path, for the failure message.
	 * @return void
	 */
	private function assert_key_absent_from( string $flat, string $which ): void {
		$this->assertStringNotContainsString( 'BEGIN', $flat, "{$which}: the PEM is absent." );
		$this->assertStringNotContainsString( $this->ciphertext, $flat, "{$which}: the ciphertext is absent." );
		$this->assertStringNotContainsString( 'ciphertext', $flat, "{$which}: the sealed blob is absent." );
	}

	/**
	 * The settings-page wiring exactly as Plugin builds it.
	 *
	 * @return array{0: SettingsPage, 1: SettingsRestController}
	 */
	private function make_settings_stack(): array {
		$registry = Registry::with_default_modules();
		$rest     = new SettingsRestController( $registry );

		return array( new SettingsPage( $registry, $rest ), $rest );
	}

	public function test_the_settings_get_route_does_not_carry_the_key(): void {
		list( , $rest ) = $this->make_settings_stack();

		$payload = $rest->get_settings()->get_data();

		$this->assertTrue( $payload['values'][ GTM4WP_OPTION_INCLUDE_LOGGEDIN ], 'The settings row itself is served.' );
		$this->assertArrayNotHasKey( KeyVault::OPTION_NAME, $payload['values'] );
		$this->assert_key_absent_from( (string) json_encode( $payload ), 'settings GET' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_the_settings_export_does_not_carry_the_key(): void {
		list( , $rest ) = $this->make_settings_stack();

		$payload = $rest->export_settings()->get_data();

		$this->assertSame( 'gtm4wp', $payload['plugin'] );
		$this->assertArrayNotHasKey( KeyVault::OPTION_NAME, $payload['options'] );
		$this->assert_key_absent_from( (string) json_encode( $payload ), 'settings export' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_the_react_bootstrap_does_not_carry_the_key(): void {
		list( $page ) = $this->make_settings_stack();

		$data = $page->bootstrap_data();
		$flat = (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		// Positive anchor first: an empty payload would pass the absence
		// assertions vacuously.
		$this->assertArrayHasKey( 'modules', $data );
		$this->assertNotEmpty( $data['modules'] );

		$this->assert_key_absent_from( $flat, 'React bootstrap' );
	}

	public function test_the_accounts_listing_does_not_carry_the_key(): void {
		$vault      = new KeyVault( self::SECRET );
		$controller = new RestController( $vault, new TokenService( $vault, new FakeTransport() ) );

		$payload = $controller->list_accounts()->get_data();

		$this->assertCount( 1, $payload['accounts'] );
		$this->assertSame( 'Production', $payload['accounts'][0]['label'] );
		$this->assert_key_absent_from( (string) json_encode( $payload ), 'accounts listing' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * The vault row is not a settings option: it never enters the merged
	 * defaults the settings row is served from, so the import path cannot
	 * write it either.
	 */
	public function test_no_module_declares_the_vault_row_as_an_option(): void {
		foreach ( Registry::with_default_modules()->all() as $module ) {
			$this->assertArrayNotHasKey( KeyVault::OPTION_NAME, $module->defaults(), $module->id() );
		}
	}

	public function test_the_module_descriptor_names_its_panel_and_boot_data(): void {
		list( $page ) = $this->make_settings_stack();

		$descriptor = null;
		foreach ( $page->bootstrap_data()['modules'] as $module ) {
			if ( 'google-auth' === $module['id'] ) {
				$descriptor = $module;
			}
		}

		$this->assertNotNull( $descriptor, 'The module is in the bootstrap payload.' );
		$this->assertTrue( $descriptor['available'], 'Always available: an empty list is the onboarding state.' );
		$this->assertSame( array(), $descriptor['fields'], 'No Field controls: the panel is custom.' );
		$this->assertSame( AdminSchema::PANEL, $descriptor['panel'] );
		$this->assertSame( 'google-service-accounts', $descriptor['panel'], 'The React panel registry is keyed on this string.' );
		$this->assertSame(
			array(
				'restPath'        => 'gtm4wp/v2/google/service-accounts',
				'keyFileMaxBytes' => RestController::KEY_FILE_MAX_BYTES,
				'labelMaxLength'  => KeyVault::LABEL_MAX_LENGTH,
			),
			(array) $descriptor['panelData']
		);
	}

	public function test_modules_without_a_panel_get_the_empty_descriptor(): void {
		list( $page ) = $this->make_settings_stack();

		// The two Google modules declare panels of their own (google-data-manager
		// mixes a panel WITH fields); every other module gets the empty descriptor.
		$panelled = array( 'google-auth', 'google-data-manager' );

		foreach ( $page->bootstrap_data()['modules'] as $module ) {
			if ( in_array( $module['id'], $panelled, true ) ) {
				continue;
			}
			$this->assertSame( '', $module['panel'], $module['id'] );
			$this->assertSame( array(), (array) $module['panelData'], $module['id'] );
		}
	}
}
