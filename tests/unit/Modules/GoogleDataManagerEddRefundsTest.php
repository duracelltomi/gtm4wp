<?php
/**
 * Unit tests for the Easy Digital Downloads refund adapter.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\EasyDigitalDownloads\DownloadData;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Modules\GoogleDataManager\AttributionCapture;
use GTM4WP\Modules\GoogleDataManager\EddRefunds;
use GTM4WP\Modules\GoogleDataManager\RefundEvent;
use GTM4WP\Modules\GoogleDataManager\RefundSource;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/edd-stubs.php';

/**
 * Easy Digital Downloads 3.x models a refund as a **child order**: a row of
 * type `refund` whose parent is the original order, with its own order number
 * and with the amounts negated. Its items are copies of the original lines with
 * the quantity and totals negated (U132).
 *
 * ⚠ The centre of this suite is the `$all_refunded` trap. `edd_refund_order`
 * passes a third argument with that name, and it means "the parent order is now
 * fully refunded" - which is TRUE for the last partial refund of a sequence,
 * exactly the case that must stay partial. Reading it as "this action returned
 * the whole order" would tell Analytics to reverse the transaction a second
 * time on top of the slices already reversed, and nothing on our side would
 * ever show it. Two tests below pin that: the hook callback does not accept the
 * argument, and a completing partial still reports its own slice.
 */
final class GoogleDataManagerEddRefundsTest extends TestCase {

	/**
	 * Order meta, keyed by order id then meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $order_meta = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'edd_get_download_sku' )->justReturn( false );
		Functions\when( 'edd_get_payment_meta' )->justReturn( null );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => 'My eBook',
					'price' => 9.99,
				)
			)
		);

		$this->order_meta = array();

		Functions\when( 'edd_get_order_meta' )->alias(
			function ( $order_id, $key = '', $single = false ) {
				return $this->order_meta[ (int) $order_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'edd_update_order_meta' )->alias(
			function ( $order_id, $key, $value ) {
				$this->order_meta[ (int) $order_id ][ $key ] = $value;

				return true;
			}
		);
	}

	/**
	 * The adapter over the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return EddRefunds
	 */
	private function adapter( array $stored = array() ): EddRefunds {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new EddRefunds( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );
	}

	/**
	 * A DownloadData over the same stored options, to compare ids against.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return DownloadData
	 */
	private function download_data( array $stored = array() ): DownloadData {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new DownloadData( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );
	}

	/**
	 * A refunded line: a copy of the original item with quantity and amounts
	 * negated, and `parent` pointing at the item it refunds.
	 *
	 * @param int   $quantity Refunded quantity, positive.
	 * @param float $total    Refunded line total, positive.
	 * @param float $tax      Refunded line tax, positive.
	 * @return \EDD\Orders\Order_Item
	 */
	private static function refund_item( int $quantity, float $total, float $tax = 0.0, float $discount = 0.0, ?int $price_id = null ): \EDD\Orders\Order_Item {
		return new \EDD\Orders\Order_Item(
			array(
				'id'           => 900,
				'order_id'     => 34,
				'parent'       => 700,
				'product_id'   => 55,
				'product_name' => 'My eBook',
				'price_id'     => $price_id,
				'quantity'     => -$quantity,
				'subtotal'     => -( $total - $tax + $discount ),
				'discount'     => -$discount,
				'tax'          => -$tax,
				'total'        => -$total,
				'status'       => 'complete',
			)
		);
	}

	/**
	 * The parent order.
	 *
	 * @param array<string, mixed> $data Overrides.
	 * @return \EDD\Orders\Order
	 */
	private static function order( array $data = array() ): \EDD\Orders\Order {
		return new \EDD\Orders\Order(
			array_merge(
				array(
					'id'           => 12,
					'parent'       => 0,
					'type'         => 'sale',
					'status'       => 'complete',
					'order_number' => '1001',
					'number'       => '1001',
					'currency'     => 'EUR',
					'total'        => 100.0,
					'tax'          => 0.0,
					// EDD keeps the country on the order's address row, which is
					// what the adapter reads through get_address().
					'address'      => array( 'country' => 'DE' ),
					'items'        => array(),
				),
				$data
			)
		);
	}

	/**
	 * The refund order: a child row with negated amounts.
	 *
	 * @param float                $amount Refunded amount, positive.
	 * @param array<int, object>   $items  Refunded lines.
	 * @param array<string, mixed> $data   Overrides.
	 * @return \EDD\Orders\Order
	 */
	private static function refund( float $amount, array $items = array(), array $data = array() ): \EDD\Orders\Order {
		return new \EDD\Orders\Order(
			array_merge(
				array(
					'id'           => 34,
					'parent'       => 12,
					'type'         => 'refund',
					'status'       => 'complete',
					'order_number' => '1001-R-1',
					'number'       => '1001-R-1',
					'currency'     => 'EUR',
					'subtotal'     => -$amount,
					'tax'          => 0.0,
					'total'        => -$amount,
					'date_created' => '2027-01-15 10:00:00',
					'items'        => $items,
				),
				$data
			)
		);
	}

	/**
	 * Installs edd_get_order() over one order and one refund.
	 *
	 * @param \EDD\Orders\Order|null $order  Parent order.
	 * @param \EDD\Orders\Order|null $refund Refund order.
	 * @return void
	 */
	private function stub_orders( ?\EDD\Orders\Order $order, ?\EDD\Orders\Order $refund ): void {
		Functions\when( 'edd_get_order' )->alias(
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

	// ---- The $all_refunded trap --------------------------------------------

	/**
	 * The hook signature itself is the guard: a callback that never receives
	 * `$all_refunded` cannot start deciding from it in a later edit.
	 */
	public function test_the_hook_callback_does_not_accept_the_all_refunded_argument(): void {
		$accepted = null;
		$hooked   = null;

		Actions\expectAdded( 'edd_refund_order' )
			->once()
			->whenHappen(
				static function ( $callback, $priority, $accepted_args ) use ( &$hooked, &$accepted ) {
					$hooked   = $callback;
					$accepted = $accepted_args;
				}
			);

		$captured = array();
		$this->adapter()->register_hooks(
			static function ( int $order_id, int $refund_id ) use ( &$captured ) {
				$captured[] = array( $order_id, $refund_id );
			}
		);

		$this->assertSame(
			2,
			$accepted,
			'edd_refund_order passes three arguments; the third one answers a different question than the two event shapes ask, so it is never received.'
		);

		$hooked( '12', '34' );

		$this->assertSame( array( array( 12, 34 ) ), $captured );
	}

	/**
	 * The behavioural half of the same guard, driven through the exact case
	 * `$all_refunded` is true for: the last of three partial refunds. It
	 * returns 40 of an order of 100, and it must stay a partial refund.
	 */
	public function test_the_partial_that_completes_the_order_still_reports_only_its_own_slice(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );
		$event  = RefundEvent::build( $refund );

		// EDD would hand this refund $all_refunded = true. Nothing reads it,
		// and the event reports the slice's own amount and nothing more.
		$this->assertSame( 40.0, $event['conversionValue'] );
		$this->assertSame( 'EUR', $event['currency'] );
	}

	public function test_one_refund_returning_the_whole_order_reports_the_whole_amount(): void {
		$this->stub_orders( self::order(), self::refund( 100.0 ) );

		$event = RefundEvent::build( $this->adapter()->load( 12, 34 ) );

		$this->assertSame( 100.0, $event['conversionValue'], 'A whole-order refund is a refund of the whole amount - not a transaction id with the amount left off.' );
		$this->assertSame( 'EUR', $event['currency'] );
	}

	// ---- The refund order model (U132) -------------------------------------

	public function test_the_negated_totals_become_positive_amounts(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( 40.0, $refund->amount );
		$this->assertSame( 100.0, $refund->order_total, 'The parent keeps its own total; EDD does not reduce it when a refund is issued.' );
	}

	public function test_a_row_that_is_not_a_refund_is_refused(): void {
		$this->stub_orders( self::order(), self::refund( 40.0, array(), array( 'type' => 'sale' ) ) );

		$this->assertNull(
			$this->adapter()->load( 12, 34 ),
			'Orders and refunds live in the same table, so the type is what says which one this is.'
		);
	}

	public function test_a_refund_of_a_different_order_is_refused(): void {
		$this->stub_orders( self::order(), self::refund( 40.0, array(), array( 'parent' => 99 ) ) );

		$this->assertNull(
			$this->adapter()->load( 12, 34 ),
			'Without this a mistaken id pair would produce an event reversing the wrong transaction.'
		);
	}

	public function test_a_missing_order_or_refund_is_refused(): void {
		$this->stub_orders( null, self::refund( 40.0 ) );
		$this->assertNull( $this->adapter()->load( 12, 34 ) );

		$this->stub_orders( self::order(), null );
		$this->assertNull( $this->adapter()->load( 12, 34 ) );
	}

	public function test_the_creation_time_is_read_as_utc(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$this->assertSame(
			strtotime( '2027-01-15 10:00:00 UTC' ),
			$this->adapter()->load( 12, 34 )->timestamp,
			'EDD stores the datetime in UTC, so the zone is stated rather than left to the server.'
		);
	}

	public function test_an_unreadable_creation_time_falls_back_rather_than_failing(): void {
		$this->stub_orders( self::order(), self::refund( 40.0, array(), array( 'date_created' => '' ) ) );

		$this->assertGreaterThan( 0, $this->adapter()->load( 12, 34 )->timestamp );
	}

	// ---- The join key ------------------------------------------------------

	public function test_the_transaction_id_is_the_parent_orders_and_matches_the_purchase_event(): void {
		$order = self::order();
		$this->stub_orders( $order, self::refund( 40.0 ) );

		$refund   = $this->adapter()->load( 12, 34 );
		$purchase = $this->download_data()->get_purchase_datalayer( $order, array() );

		$this->assertSame( $purchase['ecommerce']['transaction_id'], $refund->transaction_id );
		$this->assertSame( '1001', $refund->transaction_id );
	}

	public function test_the_refunds_own_order_number_is_never_used(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$this->assertSame(
			'1001',
			$this->adapter()->load( 12, 34 )->transaction_id,
			'The refund order has a number of its own (1001-R-1) that Google Analytics has never seen.'
		);
	}

	public function test_the_configured_prefix_is_applied_to_both(): void {
		$stored = array( GTM4WP_OPTION_INTEGRATE_EDDTRANSACTIONIDPREFIX => 'DL-' );
		$order  = self::order();

		$this->stub_orders( $order, self::refund( 40.0 ) );

		$this->assertSame( 'DL-1001', $this->adapter( $stored )->load( 12, 34 )->transaction_id );
		$this->assertSame(
			$this->download_data( $stored )->get_purchase_datalayer( $order, array() )['ecommerce']['transaction_id'],
			$this->adapter( $stored )->load( 12, 34 )->transaction_id
		);
	}

	// ---- Items -------------------------------------------------------------

	public function test_refunded_lines_come_back_positive_with_the_purchase_builders_item_id(): void {
		$stored = array();
		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( 2, 40.0 ) ) ) );

		$items = $this->adapter( $stored )->load( 12, 34 )->items;

		$built = $this->download_data( $stored )->process_download( 55, array( 'price' => 20.0 ), 'purchase' );

		$this->assertCount( 1, $items );
		$this->assertSame( $built['item_id'], $items[0]['itemId'] );
		$this->assertSame( '55', $items[0]['itemId'] );
		$this->assertSame( 20.0, $items[0]['unitPrice'] );
		$this->assertSame( 2, $items[0]['quantity'] );

		// The line is described the way the purchase described it - same
		// builder, same name - so Analytics can attribute the refund to the
		// product instead of to "(not set)".
		$this->assertContains(
			array(
				'parameterName' => 'item_name',
				'value'         => (string) $built['item_name'],
			),
			$items[0]['additionalItemParameters']
		);
	}

	public function test_the_unit_price_excludes_tax_when_the_store_option_says_so(): void {
		$this->stub_orders( self::order(), self::refund( 48.0, array( self::refund_item( 2, 48.0, 8.0 ) ) ) );

		$with_tax = $this->adapter()->load( 12, 34 )->items;
		$this->assertSame( 24.0, $with_tax[0]['unitPrice'] );

		$this->stub_orders( self::order(), self::refund( 48.0, array( self::refund_item( 2, 48.0, 8.0 ) ) ) );
		$without_tax = $this->adapter( array( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX => true ) )->load( 12, 34 )->items;

		$this->assertSame(
			20.0,
			$without_tax[0]['unitPrice'],
			'Both members are negated, so subtracting the tax before taking the absolute value is what removes it.'
		);
	}

	public function test_a_line_with_no_quantity_is_left_out(): void {
		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( 0, 40.0 ) ) ) );

		$this->assertSame( array(), $this->adapter()->load( 12, 34 )->items );
	}

	// ---- The stored attribution -------------------------------------------

	public function test_the_client_id_and_consent_state_come_from_the_parent_orders_meta(): void {
		$this->order_meta[12] = array(
			AttributionCapture::META_CLIENT_ID     => '313930999.1788522497',
			AttributionCapture::META_CONSENT_STATE => array(
				'signals'     => array( 'analytics_storage' => 'granted' ),
				'captured_at' => 1_800_000_000,
			),
		);

		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( '313930999.1788522497', $refund->client_id );
		$this->assertSame( array( 'analytics_storage' => 'granted' ), $refund->consent_signals() );
		$this->assertSame( 'DE', $refund->billing_country );
	}

	public function test_an_order_with_no_captured_attribution_reports_unknown(): void {
		$this->stub_orders( self::order(), self::refund( 40.0 ) );

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( '', $refund->client_id );
		$this->assertNull( $refund->consent_signals() );
	}

	// ---- Idempotency -------------------------------------------------------

	public function test_the_sent_marker_is_written_on_the_refund_order_not_the_parent(): void {
		$adapter = $this->adapter();

		$this->assertFalse( $adapter->is_sent( 34 ) );

		$adapter->mark_sent( 34, 'req-42' );

		$this->assertTrue( $adapter->is_sent( 34 ) );
		$this->assertSame( 'req-42', $this->order_meta[34][ RefundSource::META_SENT ] );
		$this->assertArrayNotHasKey(
			12,
			$this->order_meta,
			'A per-order marker could not express "each of several partial refunds sends exactly once".'
		);
	}

	public function test_a_send_with_no_request_id_still_marks_the_refund_sent(): void {
		$adapter = $this->adapter();
		$adapter->mark_sent( 34, '' );

		$this->assertTrue( $adapter->is_sent( 34 ) );
	}

	public function test_the_adapter_names_its_platform(): void {
		$this->assertSame( RefundSource::PLATFORM_EDD, $this->adapter()->platform() );
		$this->assertSame( 'edd', $this->adapter()->platform() );
	}

	public function test_the_adapter_is_active_only_with_easy_digital_downloads_loaded(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'EDD'           => true,
			'edd_get_order' => true,
		);

		try {
			$this->assertTrue( $this->adapter()->is_active() );

			$GLOBALS['gtm4wp_test_forced_functions']['edd_get_order'] = false;
			$this->assertFalse( $this->adapter()->is_active() );

			$GLOBALS['gtm4wp_test_forced_functions'] = array(
				'EDD'           => false,
				'edd_get_order' => true,
			);
			$this->assertFalse( $this->adapter()->is_active() );
		} finally {
			$GLOBALS['gtm4wp_test_forced_functions'] = array();
		}
	}

	// ---- Refunded tax ------------------------------------------------------

	public function test_the_returned_tax_is_read_off_the_refund_order_as_a_positive_amount(): void {
		$this->stub_orders(
			self::order(),
			self::refund( 40.0, array(), array( 'tax' => -8.0 ) )
		);

		$refund = $this->adapter()->load( 12, 34 );

		$this->assertSame( 8.0, $refund->tax );
	}

	public function test_no_shipping_is_reported_because_easy_digital_downloads_has_none(): void {
		$this->stub_orders( self::order(), self::refund( 40.0, array(), array( 'tax' => -8.0 ) ) );

		$refund = $this->adapter()->load( 12, 34 );

		// Not an oversight: an EDD order carries no shipping total, and its
		// purchase event reports none either, so there is nothing to reverse.
		$this->assertSame( 0.0, $refund->shipping );
	}

	// ---- Parity with the purchase item: discount -----------------------------

	public function test_a_discounted_line_carries_its_per_unit_discount_like_the_purchase_item(): void {
		// Two units, EUR 10 off the line: EUR 5 per unit, negated on the refund
		// order item like the other amounts.
		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( 2, 40.0, 0.0, 10.0 ) ) ) );

		$this->assertContains(
			array(
				'parameterName' => 'discount',
				'value'         => '5',
			),
			$this->adapter()->load( 12, 34 )->items[0]['additionalItemParameters']
		);
	}

	public function test_an_undiscounted_line_carries_no_discount_parameter(): void {
		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( 2, 40.0 ) ) ) );

		$names = array_column( $this->adapter()->load( 12, 34 )->items[0]['additionalItemParameters'], 'parameterName' );

		$this->assertNotContains( 'discount', $names );
	}

	// ---- Parity with the WooCommerce adapter: variations, affiliation ------

	/**
	 * An EDD variable price is an option on the same download, and the
	 * purchase item reports the option's name as item_variant. The refund of
	 * that line has to say the same, which is why the refund item's price_id
	 * is handed to the purchase builder - the WC sibling pins its variation
	 * the same way, and every EDD fixture here had price_id null (T91b).
	 */
	public function test_a_variable_price_line_is_reported_the_way_the_purchase_reports_it(): void {
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'                  => (int) $id,
					'name'                => 'My eBook',
					'price'               => 9.99,
					'has_variable_prices' => true,
				)
			)
		);
		Functions\when( 'edd_get_price_option_amount' )->justReturn( 20.0 );
		Functions\when( 'edd_get_variable_prices' )->justReturn(
			array(
				2 => array(
					'name'   => 'Professional',
					'amount' => 20.0,
				),
			)
		);

		$stored = array();
		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( 2, 40.0, 0.0, 0.0, 2 ) ) ) );

		$items = $this->adapter( $stored )->load( 12, 34 )->items;
		$built = $this->download_data( $stored )->process_download( 55, array( 'price' => 20.0 ), 'purchase', null, 2 );

		$this->assertSame( 'Professional', $built['item_variant'], 'Fixture check: the purchase builder resolves the option name.' );
		$this->assertContains(
			array(
				'parameterName' => 'item_variant',
				'value'         => 'Professional',
			),
			$items[0]['additionalItemParameters'],
			'The refunded line names the same price option the purchase item did.'
		);
		$this->assertSame( '55', $items[0]['itemId'], 'The item id stays the download id; the option is a variant, not a product.' );
		$this->assertSame( 20.0, $items[0]['unitPrice'] );
	}

	public function test_a_site_supplied_affiliation_travels_with_the_refunded_line(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_AFFILIATION )->andReturn( 'Outlet store' );

		$this->stub_orders( self::order(), self::refund( 40.0, array( self::refund_item( 2, 40.0 ) ) ) );

		$this->assertContains(
			array(
				'parameterName' => 'affiliation',
				'value'         => 'Outlet store',
			),
			$this->adapter()->load( 12, 34 )->items[0]['additionalItemParameters']
		);
	}
}
