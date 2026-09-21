<?php
/**
 * Unit tests for the Abilities API wiring in Plugin::boot().
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use GTM4WP\Abilities\Registrar;
use GTM4WP\Capability;
use GTM4WP\Plugin;

/**
 * The registrar's own suite drives register_category() and
 * register_abilities() on a hand-built instance, which is exactly how the
 * attachment in Plugin::boot() could be deleted with every direct test green
 * (the TS-15 attachment corollary). These tests run the real boot(), capture
 * the two callables it hangs on core's actions, invoke them and assert the
 * category and the catalogue land - on a request where the capability is
 * denied, because the abilities are registered before the admin/frontend
 * split and never depend on who is logged in at registration time.
 */
final class PluginAbilitiesWiringTest extends TestCase {

	/**
	 * The callable hung on each of the two core actions, keyed by hook.
	 *
	 * @var array<string, callable>
	 */
	private array $captured = array();

	/**
	 * Every wp_register_ability() call, keyed by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $registered = array();

	/**
	 * Every wp_register_ability_category() call, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $categories = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static fn ( $key, $default_value = false ) => ( GTM4WP_OPTIONS === $key ) ? array() : $default_value
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'plugin_basename' )->alias( static fn ( $file ) => basename( (string) $file ) );

		// The AdminSchemas walked while the settings ability builds its enums (TS-16).
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

		$this->captured   = array();
		$this->registered = array();
		$this->categories = array();

		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->registered[ (string) $name ] = (array) $args;

				return null;
			}
		);
		Functions\when( 'wp_register_ability_category' )->alias(
			function ( $slug, $args ) {
				$this->categories[ (string) $slug ] = (array) $args;

				return null;
			}
		);
	}

	protected function tearDown(): void {
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );
		unset(
			$GLOBALS['gtm4wp_options'],
			$GLOBALS['gtm4wp_datalayer_name'],
			$GLOBALS['gtm4wp_datalayer_data'],
			$GLOBALS['gtm4wp_additional_datalayer_pushes'],
			$GLOBALS['gtm4wp_container_code_written']
		);

		parent::tearDown();
	}

	/**
	 * Boots the plugin and captures what it hangs on the two core actions.
	 *
	 * @return void
	 */
	private function boot(): void {
		foreach ( array( Registrar::HOOK_CATEGORIES, Registrar::HOOK_ABILITIES ) as $hook ) {
			Actions\expectAdded( $hook )
				->once()
				->whenHappen(
					function ( $callback ) use ( $hook ) {
						$this->captured[ $hook ] = $callback;
					}
				);
		}

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $plugin );
		$plugin->boot();
	}

	public function test_boot_hangs_the_registrar_on_both_core_actions(): void {
		$this->boot();

		$this->assertArrayHasKey( Registrar::HOOK_CATEGORIES, $this->captured );
		$this->assertArrayHasKey( Registrar::HOOK_ABILITIES, $this->captured );

		foreach ( $this->captured as $hook => $callback ) {
			$this->assertIsArray( $callback, $hook );
			$this->assertInstanceOf( Registrar::class, $callback[0], "$hook is handled by the registrar boot() built." );
		}
	}

	public function test_the_captured_callables_register_the_category_and_the_catalogue(): void {
		$this->boot();

		call_user_func( $this->captured[ Registrar::HOOK_CATEGORIES ] );
		call_user_func( $this->captured[ Registrar::HOOK_ABILITIES ] );

		$this->assertArrayHasKey( Registrar::CATEGORY, $this->categories, 'The category lands, so the abilities naming it are not dropped by core.' );

		$this->assertSame(
			array(
				'gtm4wp/get-status',
				'gtm4wp/get-site-health',
				'gtm4wp/get-settings',
				'gtm4wp/update-settings',
				'gtm4wp/get-google-data-manager-log',
			),
			array_keys( $this->registered ),
			'The catalogue of phases 1 and 2, in registration order.'
		);

		foreach ( $this->registered as $name => $args ) {
			$this->assertSame( Registrar::CATEGORY, $args['category'], $name );
			$this->assertContains(
				$args['permission_callback'],
				array(
					array( Capability::class, 'can_manage_settings' ),
					array( Registrar::class, 'can_write' ),
				),
				"$name is gated at the attachment, not only in the provider's own test (ContractTest pins which gate each ability has)."
			);
		}
	}

	public function test_the_wiring_does_not_depend_on_the_capability_of_the_booting_request(): void {
		// current_user_can() is stubbed false in setUp: the admin path is not
		// booted, and the abilities are registered all the same. The gate is
		// applied when an ability runs, by its permission callback.
		$this->boot();

		$this->assertFalse( has_action( 'admin_menu' ), 'The admin path did not boot.' );
		$this->assertCount( 2, $this->captured );
	}
}
