<?php
/**
 * Unit tests for the WooCommerce ProductData builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\WooCommerce\Helpers;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * Covers the GA4 item array mapping ported from
 * gtm4wp_woocommerce_process_product() with a stubbed WC product.
 */
final class ProductDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wc_get_price_to_display' )->justReturn( 19.999 );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );
		// process_product() derives item_list_id from the list name via sanitize_title.
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);
	}

	/**
	 * Builds a ProductData instance with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ProductData
	 */
	private function make_product_data( array $stored = array() ): ProductData {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new ProductData( new Options( ( new WooCommerceModule() )->defaults() ) );
	}

	/**
	 * Builds a stubbed simple product.
	 *
	 * @param array<string, mixed> $data Product data overrides.
	 * @return \WC_Product
	 */
	private function make_product( array $data = array() ): \WC_Product {
		return new \WC_Product(
			array_merge(
				array(
					'id'    => 123,
					'title' => 'Test Product',
					'sku'   => 'SKU-1',
				),
				$data
			)
		);
	}

	public function test_non_product_returns_false(): void {
		$product_data = $this->make_product_data();

		$this->assertFalse( $product_data->process_product( null, array(), 'productdetail' ) );
		$this->assertFalse( $product_data->process_product( 'not a product', array(), 'productdetail' ) );
	}

	public function test_order_status_trackable_uses_the_default_placement_statuses(): void {
		$product_data = $this->make_product_data();

		// Placement statuses fire the purchase - Cash on Delivery (processing) and
		// bank transfer (on-hold) count at checkout even though payment is not yet in.
		$this->assertTrue( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'processing' ) ) ) );
		$this->assertTrue( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'on-hold' ) ) ) );
		$this->assertTrue( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'completed' ) ) ) );

		// Non-placed / failed orders are not sales and must not fire the purchase.
		$this->assertFalse( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'pending' ) ) ) );
		$this->assertFalse( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'failed' ) ) ) );
		$this->assertFalse( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'cancelled' ) ) ) );
	}

	public function test_order_status_trackable_honors_a_custom_status_list(): void {
		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES => array( 'completed' ) )
		);

		$this->assertTrue( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'completed' ) ) ) );
		$this->assertFalse( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'processing' ) ) ), 'A status not in the configured list must not be trackable.' );
	}

	public function test_order_status_trackable_empty_list_falls_back_to_anything_but_failed(): void {
		// A misconfigured empty list must never silently disable all purchase
		// tracking; it falls back to the pre-2.0 "anything but failed" rule.
		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES => array() )
		);

		$this->assertTrue( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'pending' ) ) ) );
		$this->assertFalse( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'failed' ) ) ) );
	}

	public function test_item_list_id_is_derived_from_the_item_list_name(): void {
		$product_data = $this->make_product_data();

		$item = $product_data->process_product(
			$this->make_product(),
			array( 'item_list_name' => 'Related Products' ),
			'productlist'
		);

		$this->assertSame( 'Related Products', $item['item_list_name'] );
		$this->assertSame( 'related-products', $item['item_list_id'], 'GA4 list attribution needs an item_list_id alongside the name.' );
	}

	public function test_item_list_id_absent_without_a_list_name(): void {
		$product_data = $this->make_product_data();

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertArrayNotHasKey( 'item_list_id', $item, 'No list context means no item_list_id.' );
	}

	public function test_explicit_item_list_id_overrides_the_derived_one(): void {
		$product_data = $this->make_product_data();

		$item = $product_data->process_product(
			$this->make_product(),
			array(
				'item_list_name' => 'Related Products',
				'item_list_id'   => 'custom_list_42',
			),
			'productlist'
		);

		$this->assertSame( 'custom_list_42', $item['item_list_id'], 'A caller-supplied item_list_id must be preserved.' );
	}

	public function test_purchase_datalayer_adds_enhanced_conversions_user_data_when_customer_data_on(): void {
		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true ) );

		$order = new \WC_Order(
			array(
				'order_number'       => '1001',
				'total'              => 100.0,
				'currency'           => 'EUR',
				'billing_email'      => 'John.Doe@Gmail.com',
				'billing_phone'      => '+1 555 123',
				'billing_first_name' => 'John',
				'billing_last_name'  => 'Doe',
				'billing_address_1'  => '1 Main St',
				'billing_city'       => 'Town',
				'billing_state'      => 'CA',
				'billing_postcode'   => '90001',
				'billing_country'    => 'US',
			)
		);

		$user_data = $product_data->get_purchase_datalayer( $order, array() )['user_data'] ?? null;

		$this->assertIsArray( $user_data );
		// gmail dots are stripped and the address is lowercased before hashing.
		$this->assertSame( hash( 'sha256', 'johndoe@gmail.com' ), $user_data['sha256_email_address'] );
		// Phone: all spaces removed, then hashed.
		$this->assertSame( hash( 'sha256', '+1555123' ), $user_data['sha256_phone_number'] );
		$this->assertSame( hash( 'sha256', 'john' ), $user_data['address']['sha256_first_name'] );
		$this->assertSame( hash( 'sha256', 'doe' ), $user_data['address']['sha256_last_name'] );
		// Address components stay plaintext (Google hashes them on send).
		$this->assertSame( '1 Main St', $user_data['address']['street'] );
		$this->assertSame( 'Town', $user_data['address']['city'] );
		$this->assertSame( 'US', $user_data['address']['country'] );
	}

	public function test_purchase_datalayer_omits_user_data_when_customer_data_off(): void {
		$product_data = $this->make_product_data();

		$order = new \WC_Order(
			array(
				'order_number'  => '1001',
				'total'         => 10.0,
				'currency'      => 'EUR',
				'billing_email' => 'a@b.com',
			)
		);

		$this->assertArrayNotHasKey( 'user_data', $product_data->get_purchase_datalayer( $order, array() ) );
	}

	public function test_user_data_omits_empty_identifier_fields(): void {
		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true ) );

		// Only the email is set; nothing else must be fabricated.
		$order = new \WC_Order(
			array(
				'order_number'  => '1001',
				'total'         => 10.0,
				'currency'      => 'EUR',
				'billing_email' => 'a@b.com',
			)
		);

		$user_data = $product_data->get_purchase_datalayer( $order, array() )['user_data'];

		$this->assertArrayHasKey( 'sha256_email_address', $user_data );
		$this->assertArrayNotHasKey( 'sha256_phone_number', $user_data, 'An empty phone must not be hashed.' );
		$this->assertArrayNotHasKey( 'address', $user_data, 'No name/address fields means no address block.' );
	}

	public function test_order_status_trackable_is_filterable(): void {
		Filters\expectApplied( 'gtm4wp_purchase_trackable_statuses' )->andReturn( array( 'refunded' ) );

		$product_data = $this->make_product_data();

		$this->assertTrue( $product_data->is_order_status_trackable( new \WC_Order( array( 'status' => 'refunded' ) ) ), 'The filter must be able to add trackable statuses.' );
	}

	public function test_simple_product_mapping(): void {
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array( (object) array( 'name' => 'Shoes', 'term_id' => 5 ) ) // phpcs:ignore
		);

		$product_data = $this->make_product_data();
		$item         = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 123, $item['internal_id'] );
		$this->assertSame( 123, $item['item_id'], 'ID is used when SKU mode is off.' );
		$this->assertSame( 'Test Product', $item['item_name'] );
		$this->assertSame( 'SKU-1', $item['sku'] );
		$this->assertSame( 20.0, $item['price'], 'Price must be rounded to 2 decimals.' );
		$this->assertSame( 'Shoes', $item['item_category'] );
		$this->assertSame( 'retail', $item['google_business_vertical'] );
		$this->assertSame( 123, $item['id'], 'Retail vertical uses the id field name.' );
		$this->assertArrayNotHasKey( 'item_group_id', $item );
		$this->assertArrayNotHasKey( 'item_variant', $item );
	}

	public function test_sku_used_when_option_enabled(): void {
		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCUSESKU => true )
		);

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 'SKU-1', $item['item_id'] );
	}

	public function test_sku_mode_falls_back_to_id_without_sku(): void {
		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCUSESKU => true )
		);

		$item = $product_data->process_product( $this->make_product( array( 'sku' => '' ) ), array(), 'productdetail' );

		$this->assertSame( 123, $item['item_id'] );
		$this->assertSame( 123, $item['sku'], 'sku field falls back to the product ID.' );
	}

	public function test_product_id_prefix_applied_to_remarketing_id(): void {
		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX => 'woocommerce_gpf_' )
		);

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 'woocommerce_gpf_123', $item['id'] );
		$this->assertSame( 123, $item['item_id'], 'item_id itself stays unprefixed.' );
	}

	public function test_variation_product_mapping(): void {
		$product = $this->make_product(
			array(
				'type'                 => 'variation',
				'parent_id'            => 99,
				'variation_attributes' => array(
					'attribute_pa_color' => 'blue',
					'attribute_pa_size'  => 'xl',
				),
			)
		);

		$item = $this->make_product_data()->process_product( $product, array(), 'productdetail' );

		$this->assertSame( 99, $item['item_group_id'] );
		$this->assertSame( 'blue,xl', $item['item_variant'] );
	}

	public function test_subscription_variation_still_gets_variant_group_and_parent_category(): void {
		// #264: a WooCommerce Subscriptions variation reports type
		// 'subscription_variation' (not 'variation') but extends
		// WC_Product_Variation, so it must still expose item_variant, item_group_id
		// and the parent product's category - not empty values.
		Functions\when( 'wp_get_post_terms' )->alias(
			static function ( $product_id ) {
				// Category terms live on the parent (99), not on the variation.
				return 99 === $product_id
					? array( (object) array( 'name' => 'Coffee', 'term_id' => 5 ) ) // phpcs:ignore
					: array();
			}
		);

		$product = new \WC_Product_Variation(
			array(
				'id'                   => 501,
				'type'                 => 'subscription_variation',
				'parent_id'            => 99,
				'variation_attributes' => array( 'attribute_pa_size' => '1kg' ),
			)
		);

		$item = $this->make_product_data()->process_product( $product, array(), 'productdetail' );

		$this->assertSame( 99, $item['item_group_id'], 'A subscription variation must expose its parent as item_group_id.' );
		$this->assertSame( '1kg', $item['item_variant'], 'A subscription variation must expose its variant attributes.' );
		$this->assertSame( 'Coffee', $item['item_category'], 'Category must be resolved from the parent product.' );
	}

	public function test_category_path_split_into_max_five_levels(): void {
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( 7 );
		Functions\when( 'get_term' )->justReturn( (object) array( 'term_id' => 7, 'name' => 'Leaf' ) ); // phpcs:ignore
		Functions\when( 'get_term_parents_list' )->justReturn( '/L1/L2/L3/L4/L5/L6/' );

		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH => true )
		);

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 'L1', $item['item_category'] );
		$this->assertSame( 'L2', $item['item_category2'] );
		$this->assertSame( 'L5', $item['item_category5'] );
		$this->assertArrayNotHasKey( 'item_category6', $item, 'GA4 supports at most 5 category levels.' );
	}

	public function test_brand_taxonomy_adds_item_brand(): void {
		Functions\when( 'wp_get_post_terms' )->alias(
			static function ( $product_id, $taxonomy ) {
				if ( 'product_brand' === $taxonomy ) {
					return array( (object) array( 'name' => 'ACME' ) );
				}
				return array();
			}
		);

		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY => 'product_brand' )
		);

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 'ACME', $item['item_brand'] );
	}

	public function test_invalid_business_vertical_falls_back_to_retail(): void {
		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL => 'no-such-vertical' )
		);

		$this->assertSame( 'retail', $product_data->business_vertical() );
	}

	public function test_travel_vertical_uses_destination_id_field(): void {
		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL => 'travel' )
		);

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 'travel', $item['google_business_vertical'] );
		$this->assertSame( 123, $item['destination'] );
		$this->assertArrayNotHasKey( 'id', $item );
	}

	public function test_product_array_filter_receives_placement(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY )
			->once()
			->with( \Mockery::type( 'array' ), 'addtocartsingle' )
			->andReturnUsing(
				static function ( $item ) {
					$item['custom_dimension'] = 'added';
					return $item;
				}
			);

		$item = $this->make_product_data()->process_product( $this->make_product(), array(), 'addtocartsingle' );

		$this->assertSame( 'added', $item['custom_dimension'] );
	}

	public function test_additional_attributes_override_generated_ones(): void {
		$item = $this->make_product_data()->process_product(
			$this->make_product(),
			array(
				'quantity' => 3,
				'price'    => 5.5,
			),
			'purchase'
		);

		$this->assertSame( 3, $item['quantity'] );
		$this->assertSame( 5.5, $item['price'], 'Order item price overrides the display price.' );
	}

	public function test_purchase_datalayer_revenue_math(): void {
		$order = new \WC_Order(
			array(
				'total'          => 100.0,
				'total_tax'      => 20.0,
				'shipping_total' => 10.0,
				'currency'       => 'EUR',
				'order_number'   => '1001',
				'coupon_codes'   => array( 'SAVE10' ),
				'items'          => array(),
			)
		);

		$full = $this->make_product_data()->get_purchase_datalayer( $order );
		$this->assertSame( 'purchase', $full['event'] );
		$this->assertSame( 100.0, $full['ecommerce']['value'] );
		$this->assertSame( 20.0, $full['ecommerce']['tax'] );
		$this->assertSame( 10.0, $full['ecommerce']['shipping'] );
		$this->assertSame( 'EUR', $full['ecommerce']['currency'] );
		$this->assertSame( '1001', $full['ecommerce']['transaction_id'] );
		$this->assertSame( 'SAVE10', $full['ecommerce']['coupon'] );

		$no_tax = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX => true )
		)->get_purchase_datalayer( $order );
		$this->assertSame( 80.0, $no_tax['ecommerce']['value'] );

		$no_tax_no_shipping = $this->make_product_data(
			array(
				GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX      => true,
				GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING => true,
			)
		)->get_purchase_datalayer( $order );
		$this->assertSame( 70.0, $no_tax_no_shipping['ecommerce']['value'] );
	}

	public function test_raw_order_datalayer_passes_values_without_entity_escaping(): void {
		$order = new \WC_Order(
			array(
				'order_number'       => '1001',
				'order_key'          => 'wc_order_abc',
				'billing_company'    => 'Marks & Spencer',
				'billing_first_name' => "O'Brien",
				'billing_last_name'  => '<b>Smith</b>',
				'billing_address_1'  => '"Villa" 5',
				'billing_email'      => 'john@example.com',
				'shipping_city'      => 'A & B',
			)
		);

		$raw = $this->make_product_data()->get_raw_order_datalayer( $order, array() );

		// esc_js() would have turned these into &amp; / \' / &lt; / &quot;. The
		// values must stay raw so the single output sink (wp_json_encode with the
		// full hex flag set) can escape them for the inline script without
		// corrupting the order data (see review finding #8, RI-4).
		$this->assertSame( 'Marks & Spencer', $raw['customer']['billing']['company'] );
		$this->assertSame( "O'Brien", $raw['customer']['billing']['first_name'] );
		$this->assertSame( '<b>Smith</b>', $raw['customer']['billing']['last_name'] );
		$this->assertSame( '"Villa" 5', $raw['customer']['billing']['address_1'] );
		$this->assertSame( 'A & B', $raw['customer']['shipping']['city'] );
	}

	public function test_helpers_email_normalization(): void {
		$this->assertSame(
			hash( 'sha256', 'johndoe@gmail.com' ),
			Helpers::normalize_and_hash_email_address( 'sha256', ' John.Doe@GMAIL.com ' ),
			'Dots before gmail.com must be removed and value lowercased.'
		);

		$this->assertSame(
			hash( 'sha256', 'john.doe@example.com' ),
			Helpers::normalize_and_hash_email_address( 'sha256', 'John.Doe@example.com' ),
			'Dots must be kept for non-Google domains.'
		);

		$this->assertSame( '', Helpers::normalize_and_hash( 'sha256', '   ', true ) );
	}
}
