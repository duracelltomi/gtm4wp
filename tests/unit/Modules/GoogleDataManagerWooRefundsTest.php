<?php
/**
 * Unit tests for the WooCommerce refund adapter.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\AttributionCapture;
use GTM4WP\Modules\GoogleDataManager\RefundEvent;
use GTM4WP\Modules\GoogleDataManager\RefundSource;
use GTM4WP\Modules\GoogleDataManager\WooCommerceRefunds;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';
// Required explicitly rather than left to whichever file loads first: class
// definitions are process-wide, so relying on another suite's require is
// invisible until something reorders (TS-16).
require_once __DIR__ . '/wc-datastore-stub.php';

/**
 * WooCommerce stores a refund as its own object whose totals are negated, and
 * `woocommerce_order_refunded` fires for a full refund and for a partial one
 * alike. So the adapter's job is to turn all of that into positive numbers and
 * a description the platform-neutral half can decide from, and the two things
 * worth pinning are the ones that are silent when wrong:
 *
 * - The transaction id has to be the parent order's, built exactly the way the
 *   purchase event built it. It is the join key: a refund carrying anything
 *   else reverses nothing and nobody is told.
 * - The item ids have to come out of the same builder the purchase used, since
 *   they depend on store settings a second implementation would have to mirror
 *   forever. Both are asserted against the real purchase builders' output
 *   rather than against a literal.
 */
final class GoogleDataManagerWooRefundsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'wc_get_price_to_display' )->justReturn( 19.99 );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
	}

	/**
	 * Installs the stored options and returns the adapter over them.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return WooCommerceRefunds
	 */
	private function adapter( array $stored = array() ): WooCommerceRefunds {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( $stored ) {
				if ( 'woocommerce_tax_display_shop' === $key ) {
					return 'excl';
				}

				return ( 'gtm4wp-options' === $key ) ? $stored : $default_value;
			}
		);

		return new WooCommerceRefunds( new Options( ( new WooCommerceModule() )->defaults() ) );
	}

	/**
	 * A ProductData over the same stored options, to compare item ids against.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ProductData
	 */
	private function product_data( array $stored = array() ): ProductData {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new ProductData( new Options( ( new WooCommerceModule() )->defaults() ) );
	}

	/**
	 * A refunded line item: WooCommerce negates the quantity and the totals.
	 *
	 * @param \WC_Product $product  The product.
	 * @param int         $quantity Refunded quantity, as a positive number.
	 * @param float       $total    Refunded line total, as a positive number.
	 * @return object
	 */
	private static function refund_item( \WC_Product $product, int $quantity, float $total ): object {
		return new class( $product, $quantity, $total ) {
			/**
			 * Builds the refunded line.
			 *
			 * @param \WC_Product $product  Product.
			 * @param int         $quantity Positive quantity.
			 * @param float       $total    Positive total.
			 */
			public function __construct( private \WC_Product $product, private int $quantity, private float $total ) {
			}

			public function get_product() {
				return $this->product;
			}

			public function get_quantity() {
				return -$this->quantity;
			}

			public function get_total() {
				return -$this->total;
			}

			public function get_total_tax() {
				return 0;
			}
		};
	}

	/**
	 * Installs wc_get_order() over one order and one refund.
	 *
	 * @param \WC_Order|null        $order  The parent order.
	 * @param \WC_Order_Refund|null $refund The refund.
	 * @return void
	 */
	private function stub_orders( ?\WC_Order $order, ?\WC_Order_Refund $refund ): void {
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $order, $refund ) {
				if ( 12 === (int) $id ) {
					return $order;
				}

				if ( 34 === (int) $id ) {
					return $refund;
				}

				return false;
			}
		);
	}

	/**
	 * A parent order.
	 *
	 * @param array<string, mixed> $data Overrides.
	 * @return \WC_Order
	 */
	private static function order( array $data = array() ): \WC_Order {
		return new \WC_Order(
			array_merge(
				array(
					'id'              => 12,
					'order_number'    => '1001',
					'total'           => 100.0,
					'currency'        => 'EUR',
					'billing_country' => 'DE',
					'meta'            => array(
						AttributionCapture::META_CLIENT_ID => '313930999.1788522497',
						AttributionCapture::META_CONSENT_STATE => array(
							'signals'     => array( 'analytics_storage' => 'granted' ),
							'captured_at' => 1_800_000_000,
						),
					),
				),
				$data
			)
		);
	}

	/**
	 * A refund object.
	 *
	 * @param float                $amount Refunded amount, positive.
	 * @param array<int, object>   $items  Refunded lines.
	 * @param array<string, mixed> $data   Overrides.
	 * @return \WC_Order_Refund
	 */
	private static function refund( float $amount, array $items = array(), array $data = array() ): \WC_Order_Refund {
		return new \WC_Order_Refund(
			array_merge(
				array(
					'id'           => 34,
					// WooCommerce stores the total negated.
					'total'        => -$amount,
					'amount'       => $amount,
					'items'        => $items,
					'date_created' => '2027-01-15 10:00:00',
				),
				$data
			)
		);
	}

	// ---- The join key ------------------------------------------------------

	public function test_the_transaction_id_is_the_parent_orders_and_matches_the_purchase_event(): void {
		$order = self::order();
		$this->stub_orders( $order, self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );

		$purchase = $this->product_data()->get_purchase_datalayer( $order, array() );

		$this->assertSame(
			$purchase['ecommerce']['transaction_id'],
			$refund->transaction_id,
			'The refund reverses the transaction the purchase event reported; the two ids are produced by the same rule, not by two copies of it.'
		);
		$this->assertSame( '1001', $refund->transaction_id );
	}

	public function test_the_configured_transaction_id_prefix_is_applied_to_both(): void {
		$stored = array( GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => 'SHOP-' );
		$order  = self::order();

		$this->stub_orders( $order, self::refund( 40.0 ) );
		$refund = $this->adapter( $stored )->load( 12, 34 );

		$purchase = $this->product_data( $stored )->get_purchase_datalayer( $order, array() );

		$this->assertSame( 'SHOP-1001', $refund->transaction_id );
		$this->assertSame( $purchase['ecommerce']['transaction_id'], $refund->transaction_id );
	}

	public function test_the_refunds_own_order_number_is_never_used(): void {
		$this->stub_orders( self::order(), self::refund( 40.0, array(), array( 'order_number' => '1001-R1' ) ) );

		$this->assertSame(
			'1001',
			$this->adapter()->load( 12, 34 )->transaction_id,
			'The refund has an order number of its own that Google Analytics has never seen.'
		);
	}

	// ---- Amounts and the two shapes ---------------------------------------

	public function test_a_negated_refund_total_becomes_a_positive_amount(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( 40.0, $refund->amount );
		$this->assertSame( 100.0, $refund->order_total );
		$this->assertFalse( RefundEvent::is_full( $refund ) );
	}

	public function test_a_refund_of_the_whole_order_is_recognised_as_full(): void {
		$this->stub_orders( self::order(), self::refund( 100.0 ) );

		$this->assertTrue( RefundEvent::is_full( $this->adapter()->load( 12, 34 ) ) );
	}

	/**
	 * The trap, from the WooCommerce side: the order is fully refunded by the
	 * time this last slice is issued, but the slice itself returned 40 of 100.
	 */
	public function test_a_partial_that_completes_the_order_is_still_partial(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );
		$event  = RefundEvent::build( $refund );

		$this->assertFalse( RefundEvent::is_full( $refund ) );
		$this->assertSame( 40.0, $event['conversionValue'] );
	}

	public function test_the_currency_comes_from_the_parent_order(): void {
		$this->stub_orders( self::order( array( 'currency' => 'HUF' ) ), self::refund( 40.0 ) );

		$this->assertSame( 'HUF', $this->adapter()->load( 12, 34 )->currency );
	}

	public function test_the_timestamp_is_the_refunds_creation_time(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$this->assertSame(
			( new \DateTime( '2027-01-15 10:00:00' ) )->getTimestamp(),
			$this->adapter()->load( 12, 34 )->timestamp
		);
	}

	// ---- Items -------------------------------------------------------------

	public function test_refunded_lines_come_back_with_positive_quantities_and_unit_prices(): void {
		$product = new \WC_Product(
			array(
				'id'    => 123,
				'title' => 'Test Product',
				'sku'   => 'SKU-1',
			)
		);

		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( $product, 2, 40.0 ) ) ) );

		$this->assertSame(
			array(
				array(
					'itemId'    => '123',
					'unitPrice' => 20.0,
					'quantity'  => 2,
				),
			),
			$this->adapter()->load( 12, 34 )->items
		);
	}

	public function test_the_item_id_is_the_one_the_purchase_builder_produces(): void {
		$stored  = array( GTM4WP_OPTION_INTEGRATE_WCUSESKU => true );
		$product = new \WC_Product(
			array(
				'id'    => 123,
				'title' => 'Test Product',
				'sku'   => 'SKU-1',
			)
		);

		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( $product, 2, 40.0 ) ) ) );
		$items = $this->adapter( $stored )->load( 12, 34 )->items;

		$built = $this->product_data( $stored )->process_product( $product, array( 'price' => 20.0 ), 'purchase' );

		$this->assertSame(
			$built['item_id'],
			$items[0]['itemId'],
			'With the SKU option on the purchase reports the SKU, so the refunded line must too - Analytics joins the two on this value.'
		);
		$this->assertSame( 'SKU-1', $items[0]['itemId'] );
	}

	public function test_a_line_refunded_by_amount_only_is_left_out_of_the_items(): void {
		$product = new \WC_Product( array( 'id' => 123 ) );

		// WooCommerce records money returned against a line without returning
		// any of it as a zero quantity; the API's item shape cannot say that.
		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( $product, 0, 40.0 ) ) ) );

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( array(), $refund->items );
		$this->assertSame( 40.0, $refund->amount, 'The money is still reported; only the per-item breakdown is unavailable.' );
	}

	public function test_a_line_whose_product_is_gone_is_skipped_rather_than_invented(): void {
		$this->stub_orders(
			self::order(),
			self::refund(
				40.0,
				array(
					new class() {
						public function get_product() {
							return null;
						}

						public function get_quantity() {
							return -2;
						}

						public function get_total() {
							return -40.0;
						}
					},
				)
			)
		);

		$this->assertSame( array(), $this->adapter()->load( 12, 34 )->items );
	}

	// ---- The stored attribution -------------------------------------------

	public function test_the_client_id_and_consent_state_come_from_the_parent_order(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( '313930999.1788522497', $refund->client_id );
		$this->assertSame( array( 'analytics_storage' => 'granted' ), $refund->consent_signals() );
		$this->assertSame( 'DE', $refund->billing_country );
	}

	public function test_an_order_with_no_captured_consent_reports_unknown(): void {
		$this->stub_orders( self::order( array( 'meta' => array() ) ), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( '', $refund->client_id );
		$this->assertNull( $refund->consent_signals(), 'Absent is unknown, and unknown is not denied.' );
	}

	// ---- Guards ------------------------------------------------------------

	public function test_a_refund_that_is_not_a_refund_object_is_refused(): void {
		$this->stub_orders( self::order(), null );

		$this->assertNull(
			$this->adapter()->load( 12, 34 ),
			'A WC_Order_Refund is not a WC_Order; nothing is built from an object that is neither.'
		);
	}

	public function test_a_missing_parent_order_is_refused(): void {
		$this->stub_orders( null, self::refund( 40.0 ) );

		$this->assertNull( $this->adapter()->load( 12, 34 ) );
	}

	// ---- Idempotency -------------------------------------------------------

	public function test_a_refund_is_only_marked_sent_once_and_remembers_the_request(): void {
		$refund_object = self::refund( 40.0 );
		$this->stub_orders( self::order(), $refund_object );

		$adapter = $this->adapter();

		$this->assertFalse( $adapter->is_sent( 34 ) );

		$adapter->mark_sent( 34, 'req-42' );

		$this->assertTrue( $adapter->is_sent( 34 ) );
		$this->assertSame( 'req-42', $refund_object->saved_meta[ RefundSource::META_SENT ] );
		$this->assertSame( 1, $refund_object->saves, 'The meta is persisted, not only held in memory.' );
	}

	public function test_a_send_with_no_request_id_still_marks_the_refund_sent(): void {
		$refund_object = self::refund( 40.0 );
		$this->stub_orders( self::order(), $refund_object );

		$this->adapter()->mark_sent( 34, '' );

		$this->assertTrue(
			$this->adapter()->is_sent( 34 ),
			'An empty marker would read as "not sent" and the refund would be sent a second time.'
		);
	}

	public function test_the_marker_lives_on_the_refund_so_each_partial_is_its_own_send(): void {
		$this->assertSame( '_gtm4wp_gdm_refund_sent', RefundSource::META_SENT );
	}

	// ---- Wiring ------------------------------------------------------------

	public function test_the_platform_hook_enqueues_the_order_and_refund_ids(): void {
		$hooked   = null;
		$priority = null;
		$accepted = null;

		Actions\expectAdded( 'woocommerce_order_refunded' )
			->once()
			->whenHappen(
				static function ( $callback, $hook_priority, $accepted_args ) use ( &$hooked, &$priority, &$accepted ) {
					$hooked   = $callback;
					$priority = $hook_priority;
					$accepted = $accepted_args;
				}
			);

		$captured = array();

		$this->adapter()->register_hooks(
			static function ( int $order_id, int $refund_id ) use ( &$captured ) {
				$captured[] = array( $order_id, $refund_id );
			}
		);

		$this->assertSame( 10, $priority );
		$this->assertSame( 2, $accepted, 'WooCommerce passes the order id and the refund id; taking only one would lose which refund it was.' );

		// The hook is what WooCommerce fires for BOTH a full and a partial
		// refund, which is why the shape is decided from amounts later and not
		// from which hook ran.
		$hooked( '12', '34' );

		$this->assertSame( array( array( 12, 34 ) ), $captured, 'Both ids arrive as integers whatever WooCommerce passed.' );
	}

	public function test_the_adapter_names_its_platform(): void {
		$this->assertSame( RefundSource::PLATFORM_WOOCOMMERCE, $this->adapter()->platform() );
		$this->assertSame( 'woocommerce', $this->adapter()->platform() );
	}
}
