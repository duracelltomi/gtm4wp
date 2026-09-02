<?php
/**
 * Unit tests for the Easy Digital Downloads module wiring.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\Frontend;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Options\Options;
use GTM4WP\Plugin;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/edd-stubs.php';

/**
 * Covers availability gating, the master option gate on hook registration,
 * the global JS variables and the script enqueueing of the EDD module.
 */
final class EasyDigitalDownloadsModuleTest extends TestCase {

	protected function tearDown(): void {
		// Release the Plugin singleton the hook gate tests install (TS-7).
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Sets a private/protected instance property via reflection.
	 *
	 * @param object $target   Target object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	private function set_private( object $target, string $property, $value ): void {
		( new \ReflectionProperty( $target, $property ) )->setValue( $target, $value );
	}

	/**
	 * Runs register_frontend_hooks() with the given stored options, installing
	 * the Plugin->Frontend->DataLayer/ScriptTag singleton chain the method
	 * reaches into. Invoked via reflection to bypass is_available() (EDD
	 * presence), which is separate wiring.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return EasyDigitalDownloadsModule
	 */
	private function register_edd_hooks( array $stored ): EasyDigitalDownloadsModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$options = new Options( ( new EasyDigitalDownloadsModule() )->defaults() );

		$frontend = ( new \ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $frontend, 'datalayer', new DataLayer( $options ) );
		$this->set_private( $frontend, 'script_tag', new ScriptTag( $options ) );

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $plugin, 'frontend', $frontend );
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $plugin );

		$module = new EasyDigitalDownloadsModule();
		$this->set_private( $module, 'options', $options );

		( new \ReflectionMethod( $module, 'register_frontend_hooks' ) )->invoke( $module );

		return $module;
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_unavailable_without_edd(): void {
		// Runs in its own process on purpose: a Brain Monkey stub of EDD() from
		// any earlier test stays defined process-wide, so in a shared process
		// this assertion would depend on test order (TS-16's inverse).
		// No EDD() function exists in this process, so the module must stay off
		// regardless of anything else.
		$this->assertFalse( ( new EasyDigitalDownloadsModule() )->is_available() );
	}

	public function test_available_with_edd_3_or_newer(): void {
		Functions\when( 'EDD' )->justReturn( new \stdClass() );
		if ( ! defined( 'EDD_VERSION' ) ) {
			define( 'EDD_VERSION', '3.6.9' );
		}

		$this->assertTrue( ( new EasyDigitalDownloadsModule() )->is_available() );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_unavailable_below_the_edd_version_floor(): void {
		// Own process for the same reason as the no-EDD deny above: EDD_VERSION
		// is a constant and therefore process-sticky, so this is the only way
		// to model "EDD active, but the 2.x line" (TS-5 - the module's docblock
		// says everything it reads is the 3.0+ orders API).
		Functions\when( 'EDD' )->justReturn( new \stdClass() );
		define( 'EDD_VERSION', '2.9.4' );

		$this->assertFalse( ( new EasyDigitalDownloadsModule() )->is_available() );
	}

	public function test_wires_nothing_when_tracking_disabled(): void {
		$this->register_edd_hooks( array( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => false ) );

		$this->assertFalse( has_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER ) );
		$this->assertFalse( has_action( 'edd_download_after' ) );
		$this->assertFalse( has_action( 'edd_blocks_downloads_after_entry_title' ) );
		$this->assertFalse( has_action( 'edd_purchase_link_end' ) );
		$this->assertFalse( has_action( 'edd_checkout_cart_item_title_after' ) );
		$this->assertFalse( has_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY ) );
	}

	public function test_wires_datalayer_markup_and_script_hooks_when_tracking_enabled(): void {
		$module = $this->register_edd_hooks( array( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => true ) );

		$this->assertNotFalse( has_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER ), 'The page data layer must be compiled.' );
		$this->assertNotFalse( has_action( 'edd_download_after' ), 'The [downloads] grid markup must be injected.' );
		$this->assertNotFalse( has_action( 'edd_blocks_downloads_after_entry_title' ), 'The downloads block grid markup must be injected.' );
		$this->assertNotFalse( has_action( 'edd_purchase_link_end' ), 'The purchase form data input must be injected.' );
		$this->assertNotFalse( has_action( 'edd_checkout_cart_item_title_after' ), 'The checkout cart row data must be injected.' );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $module, 'enqueue_scripts' ) ) );
		$this->assertNotFalse( has_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array( $module, 'add_global_vars' ) ) );
	}

	public function test_global_vars_carry_the_edd_settings(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDUSESKU          => true,
				GTM4WP_OPTION_INTEGRATE_EDDPRODPERIMPRESSION => 25,
				GTM4WP_OPTION_INTEGRATE_EDDCLEARECOMMERCEDL => true,
				GTM4WP_OPTION_INTEGRATE_EDDDLMAXTIMEOUT    => 500,
				GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION => true,
			)
		);
		Functions\when( 'edd_get_currency' )->justReturn( 'EUR' );

		$module = new EasyDigitalDownloadsModule();
		$this->set_private( $module, 'options', new Options( $module->defaults() ) );

		$vars = $module->add_global_vars( array() );

		$this->assertSame( 1, $vars['gtm4wp_use_sku_instead'] );
		$this->assertSame( 'EUR', $vars['gtm4wp_currency'] );
		$this->assertSame( 25, $vars['gtm4wp_product_per_impression'] );
		$this->assertTrue( $vars['gtm4wp_clear_ecommerce'] );
		$this->assertSame( 500, $vars['gtm4wp_datalayer_max_timeout'] );
		$this->assertTrue( $vars['gtm4wp_console_log'] );
		$this->assertSame( 1, $vars['gtm4wp_list_attribution'] );
	}

	public function test_global_vars_honor_noconsolelog_and_the_off_defaults(): void {
		// The inverse legs of the settings test above: console logging mirrors
		// the site-wide "no console.log" option, and the flags the tracker
		// reads as numbers stay 0 when their options are off/default.
		Functions\when( 'get_option' )->justReturn(
			array( GTM4WP_OPTION_NOCONSOLELOG => true )
		);
		Functions\when( 'edd_get_currency' )->justReturn( 'USD' );

		$module = new EasyDigitalDownloadsModule();
		$this->set_private( $module, 'options', new Options( $module->defaults() ) );

		$vars = $module->add_global_vars( array() );

		$this->assertFalse( $vars['gtm4wp_console_log'] );
		$this->assertSame( 0, $vars['gtm4wp_list_attribution'] );
		$this->assertSame( 0, $vars['gtm4wp_use_sku_instead'] );
		$this->assertFalse( $vars['gtm4wp_clear_ecommerce'] );
	}

	public function test_in_footer_filter_moves_the_scripts_to_the_head(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/build/file.js' );
		Filters\expectApplied( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE )
			->once()
			->with( true )
			->andReturn( false );

		$enqueue_args = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) use ( &$enqueue_args ) {
				$enqueue_args[ $handle ] = $args;
			}
		);

		$module = new EasyDigitalDownloadsModule();
		$this->set_private( $module, 'options', new Options( $module->defaults() ) );

		$module->enqueue_scripts();

		$this->assertFalse( $enqueue_args['gtm4wp-ecommerce-generic']['in_footer'], 'The filter must move the helper to the head.' );
		$this->assertFalse( $enqueue_args['gtm4wp-edd']['in_footer'], 'The filter must move the tracker to the head.' );
		$this->assertSame( 'defer', $enqueue_args['gtm4wp-ecommerce-generic']['strategy'] );
		$this->assertArrayNotHasKey( 'strategy', $enqueue_args['gtm4wp-edd'], 'The EDD tracker deliberately loads without defer (jQuery-event dependency).' );
	}

	public function test_enqueues_the_generic_helper_and_the_edd_tracker(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/build/file.js' );

		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array() ) use ( &$enqueued ) {
				$enqueued[ $handle ] = $deps;
			}
		);

		$module = new EasyDigitalDownloadsModule();
		$this->set_private( $module, 'options', new Options( $module->defaults() ) );

		$module->enqueue_scripts();

		$this->assertArrayHasKey( 'gtm4wp-ecommerce-generic', $enqueued );
		$this->assertArrayHasKey( 'gtm4wp-edd', $enqueued );
		$this->assertContains( 'jquery', $enqueued['gtm4wp-edd'], 'EDD fires its gateway event through jQuery.' );
		$this->assertContains( 'gtm4wp-ecommerce-generic', $enqueued['gtm4wp-edd'] );
	}
}
