<?php
/**
 * Unit tests for the WooCommerce purchase tracking fallback.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\PurchaseTracking;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';
require_once __DIR__ . '/wc-datastore-stub.php';

/**
 * Covers the eligibility gauntlet and script output of
 * PurchaseTracking::on_thankyou() (the woocommerce_thankyou fallback used on
 * customized order-received pages).
 */
final class PurchaseTrackingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_kses' )->alias( static fn ( $content ) => $content );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_theme_supports' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );

		unset(
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'],
			$_COOKIE['gtm4wp_orderid_tracked']
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'],
			$_COOKIE['gtm4wp_orderid_tracked']
		);
		parent::tearDown();
	}

	/**
	 * Builds a PurchaseTracking instance backed by the given stored options.
	 *
	 * @param array<string, mixed> $options Stored option overrides.
	 * @return PurchaseTracking
	 */
	private function make_tracking( array $options = array() ): PurchaseTracking {
		Functions\when( 'get_option' )->justReturn( $options );

		$service_options = new Options( ( new WooCommerceModule() )->defaults() );
		$product_data    = new ProductData( $service_options );

		return new PurchaseTracking(
			$service_options,
			$product_data,
			new DataLayer( $service_options ),
			new ScriptTag( $service_options )
		);
	}

	/**
	 * Builds a stubbed order with sensible, recent defaults.
	 *
	 * @param array<string, mixed> $data Order data overrides.
	 * @return \WC_Order
	 */
	private function make_order( array $data = array() ): \WC_Order {
		return new \WC_Order(
			array_merge(
				array(
					'order_number' => '1001',
					'total'        => 100.0,
					'currency'     => 'EUR',
					'status'       => 'processing',
					'items'        => array(),
				),
				$data
			)
		);
	}

	/**
	 * Runs on_thankyou() for the given order and returns the printed output.
	 *
	 * @param array<string, mixed> $options  Stored option overrides.
	 * @param \WC_Order            $order    The order returned by wc_get_order().
	 * @param int                  $order_id The order id passed to the hook.
	 * @return string
	 */
	private function run_thankyou( array $options, \WC_Order $order, int $order_id = 1001 ): string {
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$tracking = $this->make_tracking( $options );

		ob_start();
		$tracking->on_thankyou( $order_id );
		return (string) ob_get_clean();
	}

	public function test_skips_when_on_order_received_page(): void {
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$output = $this->run_thankyou( array(), $this->make_order() );

		$this->assertSame( '', $output, 'The fallback must not run when the standard order-received page already fired the purchase event.' );
	}

	public function test_skips_when_purchase_already_pushed(): void {
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		$output = $this->run_thankyou( array(), $this->make_order() );

		$this->assertSame( '', $output );
	}

	public function test_outputs_purchase_event_and_flags_order_tracked(): void {
		$order = $this->make_order();

		$output = $this->run_thankyou(
			array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ),
			$order
		);

		$this->assertStringContainsString( '"event":"purchase"', $output );
		$this->assertStringContainsString( '"transaction_id":"1001"', $output );
		$this->assertStringContainsString( '"new_customer":true', $output );
		$this->assertStringContainsString( 'dataLayer.push(', $output );
		$this->assertSame( 1, $order->saved_meta['_ga_tracked'] ?? null, 'A tracked order must be flagged with the _ga_tracked meta.' );
	}

	public function test_does_not_flag_order_when_no_tracked_flag_option_set(): void {
		$order = $this->make_order();

		$this->run_thankyou(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG => true,
			),
			$order
		);

		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta );
	}

	public function test_skips_order_already_tracked(): void {
		$order = $this->make_order( array( 'meta' => array( '_ga_tracked' => 1 ) ) );

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $order );

		$this->assertStringNotContainsString( 'purchase', $output, 'An order already flagged as tracked must not be output again.' );
	}

	public function test_skips_failed_order(): void {
		$order = $this->make_order( array( 'status' => 'failed' ) );

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $order );

		$this->assertStringNotContainsString( 'purchase', $output );
	}

	public function test_skips_order_older_than_max_age(): void {
		$order = $this->make_order( array( 'date_created' => '2000-01-01T00:00:00+00:00' ) );

		$output = $this->run_thankyou(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE    => 30,
			),
			$order
		);

		$this->assertStringNotContainsString( 'purchase', $output, 'Orders older than the configured max age must not be tracked.' );
	}

	public function test_skips_order_matching_tracked_cookie(): void {
		$_COOKIE['gtm4wp_orderid_tracked'] = '1001';

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $this->make_order(), 1001 );

		$this->assertStringNotContainsString( 'purchase', $output, 'An order id matching the browser cookie must be treated as already tracked.' );
	}

	public function test_purchase_datalayer_is_hex_encoded_in_script_context(): void {
		// A break-out attempt in a value reaching the purchase data layer (here
		// the order number, which a plugin can customize) must be hex-encoded by
		// wp_json_encode so it cannot close the inline <script> element.
		$order = $this->make_order( array( 'order_number' => 'ORD</script>' ) );

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $order );

		// JSON_HEX_TAG turns < into < (the trailing hex-letter case varies
		// by PHP build, so match the digit-only prefix).
		$this->assertStringContainsString( 'ORD\u003', $output, 'The < character must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( 'ORD</script>', $output, 'The raw </script> sequence must never appear in the data layer value.' );
	}
}
