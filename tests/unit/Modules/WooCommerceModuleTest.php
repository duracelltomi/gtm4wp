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
}
