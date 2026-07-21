<?php
/**
 * Unit tests for the Easy Digital Downloads DownloadData builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\EasyDigitalDownloads\DownloadData;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/edd-stubs.php';

/**
 * Covers the GA4 item / order / purchase array mapping of the EDD
 * integration with stubbed EDD objects.
 */
final class DownloadDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

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
		Functions\when( 'edd_get_order_meta' )->justReturn( '' );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => 'My eBook',
					'price' => 9.99,
				)
			)
		);

		// Isolate the browser dedupe cookie between tests (TS-7).
		unset( $_COOKIE['gtm4wp_orderid_tracked'] );
	}

	protected function tearDown(): void {
		unset( $_COOKIE['gtm4wp_orderid_tracked'] );

		parent::tearDown();
	}

	/**
	 * Builds a DownloadData instance with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return DownloadData
	 */
	private function make_download_data( array $stored = array() ): DownloadData {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new DownloadData( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );
	}

	/**
	 * Builds a stubbed download.
	 *
	 * @param array<string, mixed> $data Download data overrides.
	 * @return \EDD_Download
	 */
	private function make_download( array $data = array() ): \EDD_Download {
		return new \EDD_Download(
			array_merge(
				array(
					'id'    => 55,
					'name'  => 'My eBook',
					'price' => 9.99,
				),
				$data
			)
		);
	}

	/**
	 * Builds a stubbed order with one typical order item and a discount.
	 *
	 * @param array<string, mixed> $data Order data overrides.
	 * @return \EDD\Orders\Order
	 */
	private function make_order( array $data = array() ): \EDD\Orders\Order {
		return new \EDD\Orders\Order(
			array_merge(
				array(
					'id'          => 77,
					'status'      => 'complete',
					'currency'    => 'EUR',
					'subtotal'    => 40.0,
					'discount'    => 4.0,
					'tax'         => 2.0,
					'total'       => 38.0,
					'gateway'     => 'stripe',
					'mode'        => 'live',
					'email'       => 'buyer@example.com',
					'customer_id' => 12,
					'payment_key' => 'pk_super_secret_key',
					'items'       => array(
						new \EDD\Orders\Order_Item(
							array(
								'product_id' => 55,
								'quantity'   => 2,
								'subtotal'   => 40.0,
								'discount'   => 4.0,
								'tax'        => 2.0,
								'total'      => 38.0,
								'price_id'   => null,
							)
						),
					),
					'adjustments' => array(
						new \EDD\Orders\Order_Adjustment(
							array(
								'type'        => 'discount',
								'description' => 'SAVE10',
							)
						),
					),
				),
				$data
			)
		);
	}

	public function test_non_download_returns_false(): void {
		$download_data = $this->make_download_data();

		$this->assertFalse( $download_data->process_download( null, array(), 'productdetail' ) );
		$this->assertFalse( $download_data->process_download( 'not a download', array(), 'productdetail' ) );
	}

	public function test_item_array_contains_core_fields(): void {
		Functions\when( 'edd_get_download_sku' )->justReturn( 'EBOOK-1' );

		$item = $this->make_download_data()->process_download( $this->make_download(), array(), 'productdetail' );

		$this->assertSame( 55, $item['internal_id'] );
		$this->assertSame( 55, $item['item_id'], 'Without the use-SKU option the id stays the download id.' );
		$this->assertSame( 'EBOOK-1', $item['sku'] );
		$this->assertSame( 'My eBook', $item['item_name'] );
		$this->assertSame( 9.99, $item['price'] );
		$this->assertSame( 'retail', $item['google_business_vertical'] );
		$this->assertSame( 55, $item['id'], 'The Google Ads remarketing id field defaults to "id" carrying the item id.' );
		$this->assertArrayNotHasKey( 'item_variant', $item, 'A fixed-price download has no variant.' );
	}

	public function test_use_sku_option_switches_the_ids_and_prefix_applies(): void {
		Functions\when( 'edd_get_download_sku' )->justReturn( 'EBOOK-1' );

		$item = $this->make_download_data(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDUSESKU       => true,
				GTM4WP_OPTION_INTEGRATE_EDDPRODIDPREFIX => 'edd_',
			)
		)->process_download( $this->make_download(), array(), 'productdetail' );

		$this->assertSame( 'EBOOK-1', $item['item_id'] );
		$this->assertSame( 'edd_EBOOK-1', $item['id'], 'The remarketing id carries the configured feed prefix.' );
	}

	public function test_unset_sku_falls_back_to_the_download_id(): void {
		// edd_get_download_sku() returns '-' when SKUs are disabled/unset.
		Functions\when( 'edd_get_download_sku' )->justReturn( '-' );

		$item = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDUSESKU => true )
		)->process_download( $this->make_download(), array(), 'productdetail' );

		$this->assertSame( 55, $item['item_id'] );
		$this->assertSame( 55, $item['sku'] );
	}

	public function test_variable_prices_use_the_lowest_option_without_a_selection(): void {
		Functions\when( 'edd_get_lowest_price_option' )->justReturn( 5.0 );

		$item = $this->make_download_data()->process_download(
			$this->make_download( array( 'has_variable_prices' => true ) ),
			array(),
			'productdetail'
		);

		$this->assertSame( 5.0, $item['price'] );
		$this->assertArrayNotHasKey( 'item_variant', $item, 'No option selected means no variant.' );
	}

	public function test_selected_price_option_sets_price_and_item_variant(): void {
		Functions\when( 'edd_get_price_option_amount' )->justReturn( 15.0 );
		Functions\when( 'edd_get_variable_prices' )->justReturn(
			array(
				2 => array(
					'name'   => 'Professional',
					'amount' => 15.0,
				),
			)
		);

		$item = $this->make_download_data()->process_download(
			$this->make_download( array( 'has_variable_prices' => true ) ),
			array(),
			'cart',
			null,
			2
		);

		$this->assertSame( 15.0, $item['price'] );
		$this->assertSame( 'Professional', $item['item_variant'], 'The price option name becomes the GA4 item_variant.' );
	}

	public function test_flights_vertical_uses_the_destination_id_field(): void {
		$item = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDBUSINESSVERTICAL => 'flights' )
		)->process_download( $this->make_download(), array(), 'productdetail' );

		$this->assertSame( 'flights', $item['google_business_vertical'] );
		$this->assertArrayHasKey( 'destination', $item );
		$this->assertArrayNotHasKey( 'id', $item );
	}

	public function test_item_list_id_is_derived_from_the_list_name(): void {
		$item = $this->make_download_data()->process_download(
			$this->make_download(),
			array( 'item_list_name' => 'Downloads List' ),
			'productlist'
		);

		$this->assertSame( 'downloads-list', $item['item_list_id'] );
	}

	public function test_cart_item_price_id_reads_the_item_number_options(): void {
		$this->assertSame(
			3,
			DownloadData::cart_item_price_id(
				array( 'item_number' => array( 'options' => array( 'price_id' => '3' ) ) )
			)
		);
		$this->assertNull( DownloadData::cart_item_price_id( array() ) );
	}

	public function test_cart_line_attributes_follow_the_tax_setting(): void {
		$line = array(
			'quantity' => 2,
			'price'    => 42.0,
			'tax'      => 2.0,
			'discount' => 4.0,
		);

		$attributes = $this->make_download_data()->cart_line_attributes( $line );
		$this->assertSame( 21.0, $attributes['price'], 'Tax stays included by default.' );
		$this->assertSame( 2.0, $attributes['discount'] );

		$attributes = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX => true )
		)->cart_line_attributes( $line );
		$this->assertSame( 20.0, $attributes['price'], 'Excluding tax removes the line tax from the per-unit price.' );
	}

	public function test_order_items_map_quantity_price_and_discount(): void {
		$items = $this->make_download_data()->process_order_items( $this->make_order() );

		$this->assertCount( 1, $items );
		$this->assertSame( 19.0, $items[0]['price'], 'Per-unit price = line total / quantity, tax included by default.' );
		$this->assertSame( 2, $items[0]['quantity'] );
		$this->assertSame( 2.0, $items[0]['discount'] );
		$this->assertArrayNotHasKey( 'internal_id', $items[0], 'The internal id must never reach the data layer.' );
	}

	public function test_order_items_respect_the_exclude_tax_option(): void {
		$items = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX => true )
		)->process_order_items( $this->make_order() );

		$this->assertSame( 18.0, $items[0]['price'], '(total - tax) / quantity when tax is excluded.' );
	}

	public function test_order_item_filter_can_exclude_a_line(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_EDD_ORDER_ITEM )->andReturn( false );

		$this->assertSame( array(), $this->make_download_data()->process_order_items( $this->make_order() ) );
	}

	public function test_purchase_datalayer_core_fields(): void {
		$data_layer = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDTRANSACTIONIDPREFIX => 'store1-' )
		)->get_purchase_datalayer( $this->make_order() );

		$this->assertSame( 'purchase', $data_layer['event'] );
		$this->assertSame( 'EUR', $data_layer['ecommerce']['currency'] );
		$this->assertSame( 'store1-77', $data_layer['ecommerce']['transaction_id'] );
		$this->assertSame( 38.0, $data_layer['ecommerce']['value'] );
		$this->assertSame( 2.0, $data_layer['ecommerce']['tax'] );
		$this->assertSame( 'SAVE10', $data_layer['ecommerce']['coupon'], 'Discount codes come from the discount-type order adjustments.' );
		$this->assertCount( 1, $data_layer['ecommerce']['items'] );
		$this->assertArrayNotHasKey( 'user_data', $data_layer, 'No Enhanced Conversions block unless customer data is enabled.' );
	}

	public function test_purchase_datalayer_excludes_tax_when_configured(): void {
		$data_layer = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX => true )
		)->get_purchase_datalayer( $this->make_order() );

		$this->assertSame( 36.0, $data_layer['ecommerce']['value'] );
	}

	public function test_purchase_datalayer_adds_hashed_user_data_when_customer_data_enabled(): void {
		$data_layer = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA => true )
		)->get_purchase_datalayer(
			$this->make_order(
				array(
					'address' => array(
						'first_name' => 'Jane',
						'country'    => 'HU',
					),
				)
			)
		);

		$this->assertSame( hash( 'sha256', 'buyer@example.com' ), $data_layer['user_data']['sha256_email_address'] );
		$this->assertSame( hash( 'sha256', 'jane' ), $data_layer['user_data']['address']['sha256_first_name'] );
		$this->assertSame( 'HU', $data_layer['user_data']['address']['country'] );
	}

	public function test_raw_order_datalayer_never_exposes_the_payment_key(): void {
		$download_data = $this->make_download_data();
		$order         = $this->make_order();

		$order_data = $download_data->get_raw_order_datalayer( $order, $download_data->process_order_items( $order ) );

		$this->assertSame( '77', (string) $order_data['attributes']['order_number'] );
		$this->assertSame( 'stripe', $order_data['attributes']['payment_gateway'] );
		$this->assertSame( 'buyer@example.com', $order_data['customer']['email'] );

		// The payment key authorizes viewing the receipt; the data layer is
		// readable by every tag on the page, so the key must never appear -
		// neither as a field nor as a value anywhere in the structure.
		$serialized = (string) wp_json_encode( $order_data );
		$this->assertStringNotContainsString( 'pk_super_secret_key', $serialized );
		$this->assertStringNotContainsString( 'payment_key', $serialized );
	}

	public function test_status_gate_defaults_include_pending_arrivals(): void {
		$download_data = $this->make_download_data();

		// Offsite gateways can land the buyer on the confirmation page while the
		// order is still pending, so the lenient default counts it once, there.
		$this->assertTrue( $download_data->is_order_status_trackable( $this->make_order( array( 'status' => 'pending' ) ) ) );
		$this->assertTrue( $download_data->is_order_status_trackable( $this->make_order( array( 'status' => 'processing' ) ) ) );
		$this->assertTrue( $download_data->is_order_status_trackable( $this->make_order( array( 'status' => 'complete' ) ) ) );

		$this->assertFalse( $download_data->is_order_status_trackable( $this->make_order( array( 'status' => 'failed' ) ) ) );
		$this->assertFalse( $download_data->is_order_status_trackable( $this->make_order( array( 'status' => 'refunded' ) ) ) );
	}

	public function test_status_gate_honors_a_custom_list_and_the_empty_fallback(): void {
		$strict = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES => array( 'complete' ) )
		);
		$this->assertTrue( $strict->is_order_status_trackable( $this->make_order( array( 'status' => 'complete' ) ) ) );
		$this->assertFalse( $strict->is_order_status_trackable( $this->make_order( array( 'status' => 'pending' ) ) ) );

		// An empty list falls back to "anything but failed" so a misconfiguration
		// can never silently disable all purchase tracking.
		$empty = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES => array() )
		);
		$this->assertTrue( $empty->is_order_status_trackable( $this->make_order( array( 'status' => 'refunded' ) ) ) );
		$this->assertFalse( $empty->is_order_status_trackable( $this->make_order( array( 'status' => 'failed' ) ) ) );
	}

	public function test_already_tracked_via_order_meta(): void {
		Functions\when( 'edd_get_order_meta' )->justReturn( 1 );

		$this->assertTrue( $this->make_download_data()->is_purchase_already_tracked( $this->make_order() ) );
	}

	public function test_already_tracked_via_the_browser_cookie(): void {
		$download_data = $this->make_download_data();

		$_COOKIE['gtm4wp_orderid_tracked'] = '77';
		$this->assertTrue( $download_data->is_purchase_already_tracked( $this->make_order() ) );

		// The browser guard stores the order NUMBER, which differs from the id
		// when sequential order numbers are on - both must match.
		$_COOKIE['gtm4wp_orderid_tracked'] = 'EDD-2001';
		$this->assertTrue( $download_data->is_purchase_already_tracked( $this->make_order( array( 'order_number' => 'EDD-2001' ) ) ) );

		$_COOKIE['gtm4wp_orderid_tracked'] = '999';
		$this->assertFalse( $download_data->is_purchase_already_tracked( $this->make_order() ) );
	}

	public function test_do_not_flag_option_disables_all_tracked_checks_and_the_flag_write(): void {
		Functions\when( 'edd_get_order_meta' )->justReturn( 1 );
		$_COOKIE['gtm4wp_orderid_tracked'] = '77';

		$download_data = $this->make_download_data(
			array( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG => true )
		);

		$this->assertFalse( $download_data->is_purchase_already_tracked( $this->make_order() ) );

		Functions\expect( 'edd_update_order_meta' )->never();
		$download_data->flag_order_tracked( $this->make_order() );
	}

	public function test_flag_order_tracked_writes_the_meta(): void {
		Functions\expect( 'edd_update_order_meta' )
			->once()
			->with( 77, DownloadData::ORDER_TRACKED_META, 1 );

		$this->make_download_data()->flag_order_tracked( $this->make_order() );
	}

	public function test_order_age_gate(): void {
		$download_data = $this->make_download_data();

		$young = $this->make_order( array( 'date_created' => gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertFalse( $download_data->is_order_older_than_max_age( $young ) );

		$old = $this->make_order( array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * 3600 ) ) ) );
		$this->assertTrue( $download_data->is_order_older_than_max_age( $old ) );

		$disabled = $this->make_download_data( array( GTM4WP_OPTION_INTEGRATE_EDDORDERMAXAGE => 0 ) );
		$this->assertFalse( $disabled->is_order_older_than_max_age( $old ), 'A 0 max age disables the gate.' );
	}

	public function test_new_customer_flag(): void {
		$download_data = $this->make_download_data();

		$this->assertTrue( $download_data->is_new_customer( $this->make_order( array( 'customer_id' => 0 ) ) ), 'Guest orders without a customer record report as new.' );

		Functions\when( 'edd_get_customer' )->justReturn( new \EDD_Customer( array( 'purchase_count' => 1 ) ) );
		$this->assertTrue( $download_data->is_new_customer( $this->make_order() ) );

		Functions\when( 'edd_get_customer' )->justReturn( new \EDD_Customer( array( 'purchase_count' => 3 ) ) );
		$this->assertFalse( $download_data->is_new_customer( $this->make_order() ) );
	}

	public function test_row_prop_reads_magic_getters_without_isset(): void {
		$order = $this->make_order();

		// The stub (like real EDD rows) implements __get without __isset, so an
		// isset()-based read would wrongly report the property as missing (RI-12).
		$this->assertFalse( isset( $order->gateway ) );
		$this->assertSame( 'stripe', DownloadData::row_prop( $order, 'gateway' ) );
		$this->assertSame( 'fallback', DownloadData::row_prop( $order, 'no_such_prop', 'fallback' ) );
		$this->assertSame( 'fallback', DownloadData::row_prop( null, 'gateway', 'fallback' ) );
	}
}
