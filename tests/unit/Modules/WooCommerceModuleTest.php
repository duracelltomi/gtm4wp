<?php
/**
 * Unit tests for the WooCommerce module class methods.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;
use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;

require_once __DIR__ . '/wc-blocks-stub.php';

/**
 * Covers WooCommerceModule::filter_is_order_received_page() (custom order-received
 * page promotion) and is_block_cart_or_checkout() (block vs. classic detection
 * that decides which tracker bundle loads).
 */
final class WooCommerceModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Reset the block-detection stub between tests (TS-7 isolation).
		CartCheckoutUtils::$checkout_block = false;
		CartCheckoutUtils::$cart_block     = false;
	}

	/**
	 * Builds a WooCommerceModule with the given stored options injected, without
	 * booting its frontend hooks (which require the Plugin singleton).
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return WooCommerceModule
	 */
	private function make_module( array $stored = array() ): WooCommerceModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module     = new WooCommerceModule();
		$reflection = new \ReflectionProperty( $module, 'options' );
		$reflection->setValue( $module, new Options( $module->defaults() ) );

		return $module;
	}

	public function test_keeps_true_when_woocommerce_already_says_order_received(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		// Never downgrade WooCommerce's own positive result, whatever page we are on.
		$this->assertTrue( $module->filter_is_order_received_page( true ) );
	}

	public function test_promotes_the_configured_custom_page(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_page' )->alias( static fn ( $id ) => 42 === $id );

		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		$this->assertTrue( $module->filter_is_order_received_page( false ), 'The configured custom page must be treated as the order-received page.' );
	}

	public function test_does_not_promote_other_pages(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_page' )->alias( static fn ( $id ) => 42 === $id );

		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		// A different page (99) is not the configured custom order-received page.
		Functions\when( 'is_page' )->alias( static fn ( $id ) => 99 === $id );
		$this->assertFalse( $module->filter_is_order_received_page( false ) );
	}

	public function test_never_promotes_in_the_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( true );

		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		$this->assertFalse( $module->filter_is_order_received_page( false ), 'The custom-page promotion must not run on admin screens.' );
	}

	public function test_block_checkout_page_is_detected(): void {
		CartCheckoutUtils::$checkout_block = true;
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertTrue( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	public function test_block_cart_page_is_detected(): void {
		CartCheckoutUtils::$cart_block = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( true );

		$this->assertTrue( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	public function test_classic_checkout_is_not_treated_as_block(): void {
		// The utils report the classic (shortcode) checkout.
		CartCheckoutUtils::$checkout_block = false;
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertFalse( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	public function test_order_received_page_is_not_treated_as_block_checkout(): void {
		CartCheckoutUtils::$checkout_block = true;
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( true );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertFalse(
			( new WooCommerceModule() )->is_block_cart_or_checkout(),
			'The order-received endpoint is excluded; its purchase event is server-side.'
		);
	}

	public function test_non_cart_checkout_page_is_not_block(): void {
		CartCheckoutUtils::$checkout_block = true;
		CartCheckoutUtils::$cart_block     = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertFalse( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	/**
	 * Drives enqueue_scripts() with the script machinery stubbed, capturing which
	 * bundles were enqueued and the inline "window.gtm4wp_blocks_context" it sets.
	 *
	 * @param WooCommerceModule $module The module under test.
	 * @return array{scripts: array<int, string>, inline: array<string, string>}
	 */
	private function run_enqueue( WooCommerceModule $module ): array {
		$enqueued = array();
		$inline   = array();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/build/x.js' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, $options = 0 ) => json_encode( $data, $options ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code = '', $position = 'after' ) use ( &$inline ) {
				$inline[ $handle ] = $code;
			}
		);

		$module->enqueue_scripts();

		return array(
			'scripts' => $enqueued,
			'inline'  => $inline,
		);
	}

	public function test_block_cart_page_loads_block_tracker_in_cartcheckout_context(): void {
		CartCheckoutUtils::$cart_block = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );

		$result = $this->run_enqueue( $this->make_module() );

		$this->assertContains( 'gtm4wp-woocommerce-blocks', $result['scripts'] );
		$this->assertNotContains( 'gtm4wp-woocommerce', $result['scripts'], 'The classic tracker is skipped on a block Cart page (no double counting).' );
		$this->assertStringContainsString( 'cartcheckout', $result['inline']['gtm4wp-woocommerce-blocks'] );
		$this->assertStringNotContainsString( 'minicart', $result['inline']['gtm4wp-woocommerce-blocks'] );
	}

	public function test_block_store_ordinary_page_loads_classic_and_minicart_tracker(): void {
		// A block store (cart is block-based) on a page that is neither cart nor
		// checkout: the classic tracker runs and the block tracker rides along in
		// minicart mode to catch Mini-Cart removals.
		CartCheckoutUtils::$cart_block = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$result = $this->run_enqueue( $this->make_module() );

		$this->assertContains( 'gtm4wp-woocommerce', $result['scripts'] );
		$this->assertContains( 'gtm4wp-woocommerce-blocks', $result['scripts'] );
		$this->assertStringContainsString( 'minicart', $result['inline']['gtm4wp-woocommerce-blocks'] );
	}

	public function test_classic_store_ordinary_page_loads_classic_tracker_only(): void {
		// Neither cart nor checkout is block-based: the block tracker never loads.
		CartCheckoutUtils::$cart_block     = false;
		CartCheckoutUtils::$checkout_block = false;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$result = $this->run_enqueue( $this->make_module() );

		$this->assertContains( 'gtm4wp-woocommerce', $result['scripts'] );
		$this->assertNotContains( 'gtm4wp-woocommerce-blocks', $result['scripts'] );
		$this->assertArrayNotHasKey( 'gtm4wp-woocommerce-blocks', $result['inline'] );
	}
}
