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
// Required here explicitly, not left to whichever test file happens to load
// first: Brain Monkey / class definitions are process-wide, so relying on
// another file's require is invisible until something reorders (TS-16).
require_once __DIR__ . '/wc-datastore-stub.php';

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

		// Helpers for the list-attribution cookie reader (#405). sanitize_text_field
		// strips tags and collapses whitespace but preserves & / " (no entity
		// encoding), so downstream wp_json_encode escapes them once and correctly.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				$value = (string) preg_replace( '/<[^>]*>/', '', (string) $value );
				return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
			}
		);

		// Isolate the cookie superglobal between tests (TS-7).
		unset( $_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] );
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] );

		parent::tearDown();
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

	/**
	 * The is_order_trackable() composite is THE canonical eligibility gauntlet - the
	 * order-received page, the reliable-purchase session fallback and the
	 * thank-you hook all call this one composite - so each leg (age,
	 * already-tracked, status) must be able to veto on its own.
	 */
	public function test_is_order_trackable_requires_every_gauntlet_leg(): void {
		$product_data = $this->make_product_data();

		$this->assertTrue(
			$product_data->is_order_trackable( new \WC_Order( array( 'status' => 'processing' ) ), 11 ),
			'A fresh, untracked order in a placement status is trackable.'
		);

		$this->assertFalse(
			$product_data->is_order_trackable( new \WC_Order( array( 'status' => 'failed' ) ), 11 ),
			'The status leg must veto: a failed order never fires the purchase.'
		);

		$this->assertFalse(
			$product_data->is_order_trackable(
				new \WC_Order(
					array(
						'status' => 'processing',
						'meta'   => array( '_ga_tracked' => 1 ),
					)
				),
				11
			),
			'The already-tracked leg must veto: the _ga_tracked meta suppresses a second purchase.'
		);

		$aged = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE => 60 ) );
		$this->assertFalse(
			$aged->is_order_trackable(
				new \WC_Order(
					array(
						'status'       => 'processing',
						'date_created' => '-2 hours',
					)
				),
				11
			),
			'The age leg must veto: an order older than the configured maximum is skipped.'
		);
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

	public function test_affiliation_absent_by_default(): void {
		// #348: item-level affiliation is empty by default (WooCommerce has no native
		// value), so the item carries no affiliation key unless a filter supplies one.
		$product_data = $this->make_product_data();

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertArrayNotHasKey( 'affiliation', $item, 'No affiliation must be added when the filter returns empty.' );
	}

	public function test_affiliation_added_from_filter(): void {
		// A non-empty gtm4wp_eec_item_affiliation filter value is attached to the item.
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_AFFILIATION )
			->once()
			->andReturn( 'Google Store' );

		$product_data = $this->make_product_data();

		$item = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 'Google Store', $item['affiliation'] );
	}

	public function test_list_attribution_merged_from_cookie_in_checkout_context(): void {
		// #405: with the opt-in option on, a checkout item picks up the list
		// attribution the tracker stored (keyed by product id) on the originating
		// select_item click.
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array( 123 => array( 'item_list_name' => 'Summer Sale', 'item_list_id' => 'summer-sale' ) ) // phpcs:ignore
		);

		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ) );
		$item         = $product_data->process_product( $this->make_product(), array(), 'checkout' );

		$this->assertSame( 'Summer Sale', $item['item_list_name'] );
		$this->assertSame( 'summer-sale', $item['item_list_id'] );
	}

	public function test_list_attribution_not_merged_when_option_off(): void {
		// The feature is opt-in: with the option off (default) the cookie is ignored,
		// so a store already doing this in GTM is never double-attributed.
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array( 123 => array( 'item_list_name' => 'Summer Sale' ) ) // phpcs:ignore
		);

		$product_data = $this->make_product_data();
		$item         = $product_data->process_product( $this->make_product(), array(), 'checkout' );

		$this->assertArrayNotHasKey( 'item_list_name', $item, 'With the option off the cookie must be ignored.' );
	}

	public function test_list_attribution_not_merged_in_cacheable_context(): void {
		// The product-detail context is served from a cacheable page, so it is never
		// enriched server-side (that would make the HTML visitor-specific) - not on a
		// cached request, not on an uncached one. The browser merges it there instead,
		// which is why PageDataLayer wraps that push in the client-side helper; see
		// PageDataLayerTest::test_view_item_push_is_wrapped_for_client_side_list_attribution().
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array( 123 => array( 'item_list_name' => 'Summer Sale' ) ) // phpcs:ignore
		);

		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ) );
		$item         = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertArrayNotHasKey( 'item_list_name', $item, 'A cacheable context must not be enriched server-side.' );
	}

	public function test_list_attribution_matches_variation_by_parent_id(): void {
		// The list showed the parent product, so the cookie is keyed by the parent id;
		// a variation in the cart/checkout matches on its parent id.
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array( 123 => array( 'item_list_name' => 'Summer Sale', 'item_list_id' => 'summer-sale' ) ) // phpcs:ignore
		);

		$variation = new \WC_Product_Variation(
			array( 'id' => 456, 'type' => 'variation', 'parent_id' => 123 ) // phpcs:ignore
		);

		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ) );
		$item         = $product_data->process_product( $variation, array(), 'purchase' );

		$this->assertSame( 'Summer Sale', $item['item_list_name'], 'A variation must match its parent id in the cookie.' );
	}

	public function test_list_attribution_does_not_override_a_rendered_list_name(): void {
		// An item already tagged with a rendered list name (view_item_list / select_item)
		// keeps it; the cookie only fills items that have none.
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array( 123 => array( 'item_list_name' => 'Summer Sale' ) ) // phpcs:ignore
		);

		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ) );
		$item         = $product_data->process_product(
			$this->make_product(),
			array( 'item_list_name' => 'Related Products' ),
			'cart'
		);

		$this->assertSame( 'Related Products', $item['item_list_name'], 'A rendered list name must not be overwritten by the cookie.' );
	}

	public function test_list_attribution_ignores_a_malformed_cookie(): void {
		// A non-JSON cookie is ignored without error.
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = 'not-json{';

		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ) );
		$item         = $product_data->process_product( $this->make_product(), array(), 'checkout' );

		$this->assertArrayNotHasKey( 'item_list_name', $item );
	}

	public function test_list_attribution_sanitizes_hostile_cookie_value(): void {
		// TC-5 / RI-6: the cookie is untrusted input. A hostile item_list_name has its
		// tags stripped (sanitize_text_field) but keeps & and " RAW - not entity
		// encoded - so the downstream wp_json_encode() hex-flag sink escapes them once
		// and correctly (RI-4 / TS-11). The </script> break-out itself is proven at the
		// sink by DataLayerTest::test_flush_pushes_hex_encodes_script_breakout_characters
		// and by PageDataLayerTest.
		$hostile                                     = 'Deals ' . "\x3C/script\x3E" . ' ' . "\x26" . ' ' . "\x22" . 'x' . "\x22";
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array( 123 => array( 'item_list_name' => $hostile ) ) // phpcs:ignore
		);

		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ) );
		$item         = $product_data->process_product( $this->make_product(), array(), 'checkout' );

		$name = $item['item_list_name'];
		$this->assertStringNotContainsString( '<', $name, 'Tags must be stripped from the cookie value.' );
		$this->assertStringNotContainsString( 'script', $name );
		$this->assertStringContainsString( "\x26", $name, 'A raw & must survive for the JSON sink to hex-encode.' );
		$this->assertStringContainsString( "\x22", $name, 'A raw " must survive for the JSON sink to hex-encode.' );
		$this->assertStringNotContainsString( '&amp;', $name, 'The value must not be entity-encoded upstream of the sink.' );
		$this->assertStringNotContainsString( '&quot;', $name );
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
		// Phone: already in international form, so it only loses its spaces.
		$this->assertSame( hash( 'sha256', '+1555123' ), $user_data['sha256_phone_number'] );
		$this->assertSame( hash( 'sha256', 'john' ), $user_data['address']['sha256_first_name'] );
		$this->assertSame( hash( 'sha256', 'doe' ), $user_data['address']['sha256_last_name'] );
		// Address components stay plaintext (Google hashes them on send).
		$this->assertSame( '1 Main St', $user_data['address']['street'] );
		$this->assertSame( 'Town', $user_data['address']['city'] );
		$this->assertSame( 'US', $user_data['address']['country'] );
	}

	/**
	 * T44: the name fields pass trim_intermediate_spaces = FALSE - Google's
	 * matching keeps interior spaces in names, unlike phones and emails. Every
	 * fixture above is single-token, where the flag's value is invisible:
	 * flipping the call-site argument (or the helper regressing to
	 * always-collapse) left the suite green while every multi-word name hashed
	 * to an unmatchable value, silently.
	 *
	 * @return void
	 */
	public function test_purchase_datalayer_keeps_interior_spaces_in_name_hashes(): void {
		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true ) );

		$order = new \WC_Order(
			array(
				'order_number'       => '1003',
				'total'              => 100.0,
				'currency'           => 'EUR',
				'billing_email'      => 'mary@example.com',
				'billing_first_name' => 'Mary Ann',
				'billing_last_name'  => 'van der Berg',
				'billing_country'    => 'US',
			)
		);

		$user_data = $product_data->get_purchase_datalayer( $order, array() )['user_data'] ?? null;

		$this->assertIsArray( $user_data );
		// TS-2 both directions on each field: the space-keeping hash ships, the
		// collapsed one (what an always-strip regression produces) does not.
		$this->assertSame( hash( 'sha256', 'mary ann' ), $user_data['address']['sha256_first_name'] );
		$this->assertNotSame( hash( 'sha256', 'maryann' ), $user_data['address']['sha256_first_name'] );
		$this->assertSame( hash( 'sha256', 'van der berg' ), $user_data['address']['sha256_last_name'] );
		$this->assertNotSame( hash( 'sha256', 'vanderberg' ), $user_data['address']['sha256_last_name'] );
	}

	/**
	 * A gmail address whose local part is nothing but a "+" tag folds away to
	 * nothing, and the helper says so by returning ''. The key must then be
	 * ABSENT rather than present and empty: this object is Google's user_data,
	 * where an empty identifier is the consumer's to interpret (RI-13). The
	 * sibling phone branch has always done this; the email branch had not.
	 *
	 * @return void
	 */
	public function test_purchase_datalayer_omits_an_email_hash_that_folds_away_to_nothing(): void {
		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true ) );

		$order = new \WC_Order(
			array(
				'order_number'       => '1002',
				'total'              => 100.0,
				'currency'           => 'EUR',
				'billing_email'      => '+shopping@gmail.com',
				'billing_first_name' => 'John',
				'billing_country'    => 'US',
			)
		);

		$user_data = $product_data->get_purchase_datalayer( $order, array() )['user_data'] ?? null;

		$this->assertIsArray( $user_data );
		// Both directions (TS-2): the key is gone, not merely falsy, and the rest
		// of the block still ships - an unusable email must not cost the order its
		// other identifiers.
		$this->assertArrayNotHasKey( 'sha256_email_address', $user_data );
		$this->assertNotSame( '', $user_data['sha256_email_address'] ?? 'absent' );
		$this->assertSame( hash( 'sha256', 'john' ), $user_data['address']['sha256_first_name'] );
	}

	/**
	 * The same address at any other domain is a real, separate mailbox and must
	 * still be hashed - the guard above must not turn into "drop tagged emails".
	 *
	 * @return void
	 */
	public function test_purchase_datalayer_keeps_a_tagged_email_outside_gmail(): void {
		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true ) );

		$order = new \WC_Order(
			array(
				'order_number'    => '1003',
				'total'           => 100.0,
				'currency'        => 'EUR',
				'billing_email'   => '+shopping@example.com',
				'billing_country' => 'US',
			)
		);

		$user_data = $product_data->get_purchase_datalayer( $order, array() )['user_data'] ?? null;

		$this->assertIsArray( $user_data );
		$this->assertSame( hash( 'sha256', '+shopping@example.com' ), $user_data['sha256_email_address'] );
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

	public function test_user_data_normalizes_a_national_phone_number_to_e164(): void {
		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true ) );

		// A Hungarian customer typing the number the way they dial it. This is
		// the shape the previous normalization got wrong: it only lowercased and
		// stripped spaces, so the hash could never match Google's.
		$order = new \WC_Order(
			array(
				'order_number'    => '1001',
				'total'           => 100.0,
				'currency'        => 'HUF',
				'billing_email'   => 'a@b.com',
				'billing_phone'   => '06 20 123 4567',
				'billing_country' => 'HU',
			)
		);

		$user_data = $product_data->get_purchase_datalayer( $order, array() )['user_data'];

		$this->assertSame( hash( 'sha256', '+36201234567' ), $user_data['sha256_phone_number'] );
		$this->assertNotSame(
			hash( 'sha256', '06201234567' ),
			$user_data['sha256_phone_number'],
			'The pre-E.164 hash must not survive.'
		);
	}

	public function test_user_data_omits_a_phone_that_cannot_be_placed_in_e164(): void {
		$product_data = $this->make_product_data( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true ) );

		// A national number with no country to anchor it to. Guessing a calling
		// code would produce a hash that can never match, so the key is dropped.
		$order = new \WC_Order(
			array(
				'order_number'    => '1001',
				'total'           => 100.0,
				'currency'        => 'EUR',
				'billing_email'   => 'a@b.com',
				'billing_phone'   => '20 123 4567',
				'billing_country' => '',
			)
		);

		$user_data = $product_data->get_purchase_datalayer( $order, array() )['user_data'];

		$this->assertArrayHasKey( 'sha256_email_address', $user_data );
		$this->assertArrayNotHasKey( 'sha256_phone_number', $user_data );
	}

	public function test_raw_order_datalayer_hashes_the_phone_in_e164(): void {
		$order = new \WC_Order(
			array(
				'order_number'    => '1001',
				'total'           => 100.0,
				'currency'        => 'HUF',
				'billing_phone'   => '06 20 123 4567',
				'billing_country' => 'HU',
			)
		);

		$raw = $this->make_product_data()->get_raw_order_datalayer( $order, array() );

		$this->assertSame( hash( 'sha256', '+36201234567' ), $raw['customer']['billing']['phone_hash'] );
		$this->assertNotSame( hash( 'sha256', '06201234567' ), $raw['customer']['billing']['phone_hash'] );
	}

	public function test_raw_order_datalayer_keeps_the_phone_hash_key_when_not_normalizable(): void {
		$order = new \WC_Order(
			array(
				'order_number'    => '1001',
				'total'           => 100.0,
				'currency'        => 'EUR',
				'billing_phone'   => '20 123 4567',
				'billing_country' => '',
			)
		);

		$raw = $this->make_product_data()->get_raw_order_datalayer( $order, array() );

		// orderData has always emitted this key with '' for a missing phone, and
		// third-party GTM variables read it by path, so the key stays put here
		// even though user_data above drops its own.
		$this->assertArrayHasKey( 'phone_hash', $raw['customer']['billing'] );
		$this->assertSame( '', $raw['customer']['billing']['phone_hash'] );
	}

	/**
	 * T47: the email sibling of the case above. The fold-to-nothing gmail tag
	 * makes the hash helper return '', and on the raw order path the key stays
	 * put with '' - the same 1.x path contract as phone_hash, asserted only for
	 * the phone until now (TS-5 sibling symmetry).
	 *
	 * @return void
	 */
	public function test_raw_order_datalayer_keeps_the_email_hash_keys_when_the_address_folds_away(): void {
		$order = new \WC_Order(
			array(
				'order_number'  => '1001',
				'total'         => 100.0,
				'currency'      => 'EUR',
				'billing_email' => '+shopping@gmail.com',
			)
		);

		$raw = $this->make_product_data()->get_raw_order_datalayer( $order, array() );

		$this->assertArrayHasKey( 'email_hash', $raw['customer']['billing'] );
		$this->assertSame( '', $raw['customer']['billing']['email_hash'] );
		// The deprecated spelling rides along unchanged - it is the same value.
		$this->assertSame( '', $raw['customer']['billing']['emailhash'] );
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

	public function test_item_with_source_filter_receives_null_when_there_is_no_source(): void {
		// #324: with no per-line source (product detail page), the new
		// gtm4wp_eec_item_with_source filter's third argument is null.
		$captured = 'unset';
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE )
			->once()
			->andReturnUsing(
				static function ( $item, $context, $source ) use ( &$captured ) {
					$captured = $source;
					return $item;
				}
			);

		$this->make_product_data()->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertNull( $captured, 'With no per-line source the new filter must receive null.' );
	}

	public function test_item_with_source_filter_receives_the_order_item_on_the_purchase_path(): void {
		// #324 (a): the new filter receives the raw WC_Order_Item when building an
		// order line, so extensions can read custom order-item meta.
		$order_item = new class( $this->make_product() ) {
			public function __construct( private $product ) {}
			public function get_product() {
				return $this->product;
			}
			public function get_quantity() {
				return 1;
			}
			public function get_subtotal() {
				return 0.0;
			}
			public function get_total() {
				return 0.0;
			}
		};

		$order = new \WC_Order(
			array(
				'items'           => array( $order_item ),
				'item_total_incl' => 10.0,
				'item_total_excl' => 10.0,
			)
		);

		$captured = 'unset';
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE )
			->once()
			->with( \Mockery::type( 'array' ), 'purchase', \Mockery::type( 'object' ) )
			->andReturnUsing(
				static function ( $item, $context, $source ) use ( &$captured ) {
					$captured = $source;
					return $item;
				}
			);

		$this->make_product_data()->process_order_items( $order );

		$this->assertSame( $order_item, $captured, 'The new filter must receive the exact WC_Order_Item as its source on the purchase path.' );
	}

	public function test_source_item_is_not_merged_into_the_item_array(): void {
		// #324 (b): the whole point of the new approach - the raw source object must
		// NOT leak into the GA4 item array (which would bloat every event). A callback
		// has to copy fields explicitly; absent one, none of the source's keys/values
		// appear on the item.
		$source = array(
			'data'           => $this->make_product(),
			'quantity'       => 1,
			'my_custom_meta' => 'secret-source-value',
		);

		$item = $this->make_product_data()->process_product( $this->make_product(), array(), 'cart', $source );

		$this->assertArrayNotHasKey( 'my_custom_meta', $item, 'Source meta must not be merged into the item array.' );
		$this->assertArrayNotHasKey( 'data', $item, 'The source WC_Product entry must not be merged into the item array.' );
		$this->assertStringNotContainsString(
			'secret-source-value',
			json_encode( $item ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'No source value may leak anywhere into the item array unless a callback adds it.'
		);
	}

	public function test_item_with_source_filter_callback_can_attach_data_from_the_source(): void {
		// #324: a callback CAN read the source and copy just the fields it needs onto
		// the item - the intended, opt-in way to surface custom cart/order item meta.
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE )
			->once()
			->andReturnUsing(
				static function ( $item, $context, $source ) {
					if ( is_array( $source ) && isset( $source['my_custom_meta'] ) ) {
						$item['dimension1'] = $source['my_custom_meta'];
					}
					return $item;
				}
			);

		$item = $this->make_product_data()->process_product(
			$this->make_product(),
			array(),
			'cart',
			array( 'my_custom_meta' => 'attached-value' )
		);

		$this->assertSame( 'attached-value', $item['dimension1'], 'A callback may explicitly copy source meta onto the item.' );
	}

	public function test_deprecated_product_array_filter_still_fires_and_can_modify_the_array(): void {
		// #324 (c): the deprecated gtm4wp_eec_product_array filter must keep firing
		// with its original two arguments so existing consumers are not broken, and its
		// mutation must survive into the final item.
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY )
			->once()
			->with( \Mockery::type( 'array' ), 'cart' )
			->andReturnUsing(
				static function ( $item ) {
					$item['legacy_dimension'] = 'legacy';
					return $item;
				}
			);

		$item = $this->make_product_data()->process_product( $this->make_product(), array(), 'cart', array( 'x' => 1 ) );

		$this->assertSame( 'legacy', $item['legacy_dimension'], 'The deprecated filter must still fire and its changes must survive.' );
	}

	public function test_deprecated_filter_keeps_applying_to_every_product_after_the_first(): void {
		// #38: the deprecation NOTICE is gated to fire once per request, but the
		// deprecated filter must still APPLY to every product (the else branch calls
		// apply_filters() directly). A listener's mutation must land on the second and
		// third product too, not just the first - otherwise later products silently lose
		// the legacy filter's changes.
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY )
			->times( 3 )
			->andReturnUsing(
				static function ( $item ) {
					$item['legacy_dimension'] = 'legacy';
					return $item;
				}
			);

		$product_data = $this->make_product_data();
		$items        = array(
			$product_data->process_product( $this->make_product(), array(), 'cart' ),
			$product_data->process_product( $this->make_product(), array(), 'cart' ),
			$product_data->process_product( $this->make_product(), array(), 'cart' ),
		);

		foreach ( $items as $i => $item ) {
			$this->assertSame( 'legacy', $item['legacy_dimension'], "Product $i must still carry the deprecated filter's mutation." );
		}
	}

	public function test_both_filters_run_in_order_and_each_can_modify_the_array(): void {
		// #324: ordering contract - the deprecated filter runs first, the new
		// source-aware filter runs after it, and BOTH can modify the array. The new
		// filter must see the deprecated filter's mutation (proves the order).
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY )
			->once()
			->andReturnUsing(
				static function ( $item ) {
					$item['from_deprecated'] = 'old';
					return $item;
				}
			);

		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE )
			->once()
			->andReturnUsing(
				static function ( $item ) {
					$item['saw_deprecated'] = $item['from_deprecated'] ?? 'missing';
					$item['from_new']       = 'new';
					return $item;
				}
			);

		$item = $this->make_product_data()->process_product( $this->make_product(), array(), 'cart' );

		$this->assertSame( 'old', $item['from_deprecated'], 'The deprecated filter can still modify the array.' );
		$this->assertSame( 'new', $item['from_new'], 'The new filter can also modify the array.' );
		$this->assertSame( 'old', $item['saw_deprecated'], 'The new filter runs after the deprecated one, so it sees its changes.' );
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

	public function test_display_price_not_recomputed_when_a_price_is_supplied(): void {
		// #436: wc_get_price_to_display() is expensive in the cart/checkout context.
		// When the caller already supplies a price (cart line price / order line total)
		// it must be skipped, so it is not called once per cart item.
		$called = false;
		Functions\when( 'wc_get_price_to_display' )->alias(
			static function () use ( &$called ) {
				$called = true;
				return 99.0;
			}
		);

		$item = $this->make_product_data()->process_product(
			$this->make_product(),
			array( 'price' => 5.5 ),
			'cart'
		);

		$this->assertFalse( $called, 'wc_get_price_to_display() must not run when a price is supplied.' );
		$this->assertSame( 5.5, $item['price'] );
	}

	public function test_display_price_computed_when_no_price_supplied(): void {
		$called = false;
		Functions\when( 'wc_get_price_to_display' )->alias(
			static function () use ( &$called ) {
				$called = true;
				return 12.0;
			}
		);

		$item = $this->make_product_data()->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertTrue( $called, 'Without a supplied price the display price is computed as before.' );
		$this->assertSame( 12.0, $item['price'] );
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

	public function test_transaction_id_prefix_is_empty_by_default(): void {
		// The option ships empty so an upgrade does not change the transaction id
		// any store already reports on.
		$order = new \WC_Order(
			array(
				'order_number' => '1001',
				'items'        => array(),
			)
		);

		$full = $this->make_product_data()->get_purchase_datalayer( $order );

		$this->assertSame( '1001', $full['ecommerce']['transaction_id'] );
	}

	public function test_transaction_id_prefix_is_prepended_when_set(): void {
		$order = new \WC_Order(
			array(
				'order_number' => '1001',
				'items'        => array(),
			)
		);

		$full = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => 'store-a-' )
		)->get_purchase_datalayer( $order );

		$this->assertSame( 'store-a-1001', $full['ecommerce']['transaction_id'] );
	}

	public function test_transaction_id_prefix_leaves_the_raw_order_number_untouched(): void {
		// Only the purchase event carries the prefix: orderData keeps the real
		// WooCommerce order number, which the duplicate-tracking guards key on.
		$order = new \WC_Order(
			array(
				'order_number' => '1001',
				'order_key'    => 'wc_order_abc',
			)
		);

		$raw = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => 'store-a-' )
		)->get_raw_order_datalayer( $order, array() );

		$this->assertSame( '1001', $raw['attributes']['order_number'] );
	}

	/**
	 * Builds an order carrying a single line item, with fixtures for the
	 * tax-inclusive and tax-exclusive per-item totals.
	 *
	 * @param float $incl     Per-item total including tax.
	 * @param float $excl     Per-item total excluding tax.
	 * @param int   $qty      Line quantity.
	 * @param float $subtotal Line subtotal before discount (ex-tax); 0 = no discount.
	 * @param float $total    Line total after discount (ex-tax); 0 = no discount.
	 * @return \WC_Order
	 */
	private function make_order_with_item( float $incl, float $excl, int $qty = 1, float $subtotal = 0.0, float $total = 0.0 ): \WC_Order {
		$product    = $this->make_product();
		$order_item = new class( $product, $qty, $subtotal, $total ) {
			public function __construct( private $product, private int $qty, private float $subtotal, private float $total ) {}
			public function get_product() {
				return $this->product;
			}
			public function get_quantity() {
				return $this->qty;
			}
			public function get_subtotal() {
				return $this->subtotal;
			}
			public function get_total() {
				return $this->total;
			}
		};

		return new \WC_Order(
			array(
				'items'           => array( $order_item ),
				'item_total_incl' => $incl,
				'item_total_excl' => $excl,
			)
		);
	}

	public function test_order_item_price_excludes_tax_when_exclude_tax_option_on(): void {
		// #176: with WCEXCLUDETAX on, the transaction value is tax-exclusive, so the
		// per-item price (product performance) must be tax-exclusive too - regardless
		// of the shop's tax-inclusive display setting - to reconcile the two reports.
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				if ( 'woocommerce_tax_display_shop' === $key ) {
					return 'incl';
				}
				return array( GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX => true );
			}
		);

		$product_data = new ProductData( new Options( ( new WooCommerceModule() )->defaults() ) );
		$items        = $product_data->process_order_items( $this->make_order_with_item( 24.0, 20.0, 2 ) );

		$this->assertSame( 20.0, $items[0]['price'], 'With WCEXCLUDETAX on, the item price must exclude tax.' );
		$this->assertSame( 2, $items[0]['quantity'] );
	}

	public function test_order_item_price_follows_shop_display_when_exclude_tax_off(): void {
		// Default: WCEXCLUDETAX off, so the item price follows the shop's inclusive
		// display setting and stays tax-inclusive.
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				if ( 'woocommerce_tax_display_shop' === $key ) {
					return 'incl';
				}
				return array();
			}
		);

		$product_data = new ProductData( new Options( ( new WooCommerceModule() )->defaults() ) );
		$items        = $product_data->process_order_items( $this->make_order_with_item( 24.0, 20.0 ) );

		$this->assertSame( 24.0, $items[0]['price'], 'With WCEXCLUDETAX off and inclusive display, the item price includes tax.' );
	}

	public function test_order_item_carries_per_unit_discount_when_line_discounted(): void {
		// #348: a line with subtotal 30 (pre-discount) and total 20 (after a coupon)
		// over quantity 2 exposes a per-unit discount of (30 - 20) / 2 = 5 on the item.
		Functions\when( 'get_option' )->justReturn( array() );

		$product_data = new ProductData( new Options( ( new WooCommerceModule() )->defaults() ) );
		$items        = $product_data->process_order_items(
			$this->make_order_with_item( 24.0, 20.0, 2, 30.0, 20.0 )
		);

		$this->assertSame( 5.0, $items[0]['discount'], 'The per-unit order-item discount must be reported for a discounted line.' );
	}

	public function test_order_item_omits_discount_when_line_not_discounted(): void {
		// An undiscounted line (subtotal == total) carries no discount key, so GA4
		// never receives a spurious 0 discount.
		Functions\when( 'get_option' )->justReturn( array() );

		$product_data = new ProductData( new Options( ( new WooCommerceModule() )->defaults() ) );
		$items        = $product_data->process_order_items(
			$this->make_order_with_item( 24.0, 20.0, 2, 40.0, 40.0 )
		);

		$this->assertArrayNotHasKey( 'discount', $items[0] );
	}

	public function test_raw_order_datalayer_types_money_totals_as_floats(): void {
		// Several WC_Order money getters return wc_format_decimal() decimal
		// STRINGS ("35.90"). The data layer encode no longer numeric-coerces
		// (JSON_NUMERIC_CHECK removed - it mangled leading-zero SKUs and order
		// numbers), so the builder must type the totals itself for them to keep
		// reaching GTM as JSON numbers. The fixture feeds the WC-realistic
		// string form on purpose (TS-13).
		$order = new \WC_Order(
			array(
				'order_number'   => '001001',
				'order_key'      => 'wc_order_abc',
				'total'          => '35.90',
				'total_tax'      => '5.90',
				'discount_total' => '2.50',
				'discount_tax'   => '0.50',
				'shipping_total' => '4.90',
				'shipping_tax'   => '0.98',
				'cart_tax'       => '4.92',
				'total_discount' => '3.00',
				'subtotal'       => '33.50',
			)
		);

		$raw = $this->make_product_data()->get_raw_order_datalayer( $order, array() );

		$this->assertSame( 2.5, $raw['totals']['discount_total'] );
		$this->assertSame( 0.5, $raw['totals']['discount_tax'] );
		$this->assertSame( 4.9, $raw['totals']['shipping_total'] );
		$this->assertSame( 0.98, $raw['totals']['shipping_tax'] );
		$this->assertSame( 4.92, $raw['totals']['cart_tax'] );
		$this->assertSame( 35.9, $raw['totals']['total'] );
		$this->assertSame( 5.9, $raw['totals']['total_tax'] );
		$this->assertSame( 3.0, $raw['totals']['total_discount'] );
		$this->assertSame( 33.5, $raw['totals']['subtotal'] );

		// The order number is an identifier, not money: it keeps its exact string
		// form - leading zeros preserved - matching the purchase transaction_id.
		$this->assertSame( '001001', $raw['attributes']['order_number'] );
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

	public function test_item_category_passes_special_characters_raw(): void {
		// TS-11: the category name is handed raw to the shared wp_json_encode() hex-flag
		// sink, so & and " must survive verbatim. A re-added esc_js()/esc_attr()
		// pre-escape (RI-4 data corruption) would turn "Foo & ..." into "Foo &amp; ..."
		// before the sink can escape it once and correctly; with a benign category
		// ('Shoes') that regression stays invisible and coverage green.
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array( (object) array( 'name' => "Foo \x26 \x22Bar\x22", 'term_id' => 5 ) ) // phpcs:ignore
		);

		$item = $this->make_product_data()->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertStringContainsString( "\x26", $item['item_category'], 'A raw & must survive for the JSON sink to hex-encode.' );
		$this->assertStringContainsString( "\x22", $item['item_category'], 'A raw " must survive for the JSON sink to hex-encode.' );
		$this->assertStringNotContainsString( '&amp;', $item['item_category'], 'The category must not be entity-encoded upstream of the sink.' );
		$this->assertStringNotContainsString( '&quot;', $item['item_category'] );
	}

	public function test_item_variant_and_brand_pass_special_characters_raw(): void {
		// TS-11: item_variant (imploded variation attributes) and item_brand (brand
		// taxonomy term) are also handed raw to the wp_json_encode() sink. A value
		// carrying & / " must arrive verbatim so the sink escapes it once - a
		// re-introduced esc_* pre-escape would corrupt the data unnoticed with benign
		// input.
		Functions\when( 'wp_get_post_terms' )->alias(
			static function ( $product_id, $taxonomy ) {
				if ( 'product_brand' === $taxonomy ) {
					return array( (object) array( 'name' => "AT\x26T \x22Store\x22" ) );
				}
				return array();
			}
		);

		$product = new \WC_Product_Variation(
			array(
				'id'                   => 456,
				'type'                 => 'variation',
				'parent_id'            => 99,
				'variation_attributes' => array( 'attribute_pa_color' => "Red \x26 \x22Blue\x22" ),
			)
		);

		$item = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY => 'product_brand' )
		)->process_product( $product, array(), 'productdetail' );

		$this->assertSame( "Red \x26 \x22Blue\x22", $item['item_variant'], 'The variant must reach the JSON sink raw.' );
		$this->assertStringNotContainsString( '&amp;', $item['item_variant'] );
		$this->assertStringNotContainsString( '&quot;', $item['item_variant'] );

		$this->assertSame( "AT\x26T \x22Store\x22", $item['item_brand'], 'The brand must reach the JSON sink raw.' );
		$this->assertStringNotContainsString( '&amp;', $item['item_brand'] );
		$this->assertStringNotContainsString( '&quot;', $item['item_brand'] );
	}

	public function test_purchase_coupon_passes_special_characters_raw(): void {
		// TS-11: the purchase coupon string is imploded and handed raw to the
		// wp_json_encode() sink (ProductData.php:489). A coupon code carrying & must
		// survive verbatim (not A&amp;B) so the sink escapes it once and correctly.
		$order = new \WC_Order(
			array(
				'order_number' => '1001',
				'total'        => 10.0,
				'currency'     => 'EUR',
				'coupon_codes' => array( "A\x26B" ),
			)
		);

		$dl = $this->make_product_data()->get_purchase_datalayer( $order, array() );

		$this->assertSame( "A\x26B", $dl['ecommerce']['coupon'], 'The coupon must reach the JSON sink raw.' );
		$this->assertStringNotContainsString( '&amp;', $dl['ecommerce']['coupon'], 'The coupon must not be entity-encoded upstream of the sink.' );
	}

	public function test_order_not_older_than_max_age_uses_paid_date_reference(): void {
		// TS-10: is_order_older_than_max_age() measures the age from get_date_paid()
		// for a paid order, not get_date_created() (ProductData.php:598-599). An order
		// created decades ago but paid moments ago must NOT be treated as stale - if
		// the reference were the created date it would fail the 30-minute gate. The
		// TRUE branch (via the created date) is covered by
		// PurchaseTrackingTest::test_skips_order_older_than_max_age.
		//
		// The method reads "now" internally (new \DateTime('now')), which is not
		// injectable, so the recent-paid fixture is time()-relative; a 60-second paid
		// age against a 30-minute gate leaves a 29-minute non-flake margin (TS-8),
		// while the created date is a fixed absolute value.
		$order = new \WC_Order(
			array(
				'is_paid'      => true,
				'date_paid'    => gmdate( 'c', time() - 60 ),
				'date_created' => '2000-01-01T00:00:00+00:00',
			)
		);

		$product_data = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE => 30 )
		);

		$this->assertFalse(
			$product_data->is_order_older_than_max_age( $order ),
			'A recently paid order must not be stale even when created long ago - the reference is the paid date.'
		);
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

	public function test_helpers_email_normalization_strips_gmail_plus_suffixes(): void {
		// Google's own worked example: Jane.Doe+Shopping@googlemail.com is
		// lowercased and then folded to janedoe@googlemail.com before hashing.
		$actual = Helpers::normalize_and_hash_email_address( 'sha256', 'Jane.Doe+Shopping@googlemail.com' );

		$this->assertSame( hash( 'sha256', 'janedoe@googlemail.com' ), $actual );
		$this->assertNotSame(
			hash( 'sha256', 'jane.doe+shopping@googlemail.com' ),
			$actual,
			'The un-normalized hash must not survive.'
		);
		$this->assertNotSame(
			hash( 'sha256', 'janedoe+shopping@googlemail.com' ),
			$actual,
			'Removing only the dots is the pre-fix behavior and must not survive.'
		);

		$this->assertSame(
			hash( 'sha256', 'janedoe@gmail.com' ),
			Helpers::normalize_and_hash_email_address( 'sha256', 'jane.doe+shop+ping@gmail.com' ),
			'Everything from the first + onwards goes, gmail.com included.'
		);
	}

	public function test_helpers_email_normalization_keeps_plus_suffixes_on_other_domains(): void {
		// Outside gmail/googlemail a + suffix addresses a real, distinct mailbox,
		// so folding it would hash a different person than the one who ordered.
		$this->assertSame(
			hash( 'sha256', 'jane.doe+shop@example.com' ),
			Helpers::normalize_and_hash_email_address( 'sha256', 'Jane.Doe+Shop@example.com' )
		);
	}

	public function test_helpers_email_normalization_only_folds_the_exact_google_domains(): void {
		// A domain that merely starts with gmail.com is somebody else's domain.
		$this->assertSame(
			hash( 'sha256', 'jane.doe+shop@gmail.com.example.com' ),
			Helpers::normalize_and_hash_email_address( 'sha256', 'Jane.Doe+Shop@gmail.com.example.com' )
		);
	}

	public function test_helpers_email_normalization_empty_when_the_local_part_is_only_a_tag(): void {
		$this->assertSame( '', Helpers::normalize_and_hash_email_address( 'sha256', '+shopping@gmail.com' ) );
	}

	/**
	 * Google names the same idea twice on two surfaces: Google Ads customer
	 * acquisition reads the boolean `new_customer`, the GA4 e-commerce
	 * reference documents a `customer_type` string of `new` / `returning`.
	 * Both must ship together - dropping either silently breaks one
	 * integration while the other keeps working.
	 *
	 * Drives both branches through the real WooCommerce analytics call, which
	 * needs the shared stub flipped; it is restored in `finally` because that
	 * static is process-wide and leaking it would break test-order
	 * independence for every other purchase test.
	 */
	public function test_customer_signals_cover_both_branches(): void {
		$product_data = $this->make_product_data();
		$order        = new \WC_Order();

		try {
			\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::$is_returning = false;

			$this->assertSame(
				array(
					'new_customer'  => true,
					'customer_type' => 'new',
				),
				$product_data->customer_signals( $order ),
				'A first-time buyer is new_customer=true and customer_type="new".'
			);

			\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::$is_returning = true;

			$this->assertSame(
				array(
					'new_customer'  => false,
					'customer_type' => 'returning',
				),
				$product_data->customer_signals( $order ),
				'A repeat buyer is new_customer=false and customer_type="returning".'
			);
		} finally {
			\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::$is_returning = false;
		}
	}

	// ---------------------------------------------------------------------
	// Master-language product consolidation (issue #145). With the option on,
	// the GA4 item identity/text (item_id, item_name, sku, item_category*,
	// item_brand, item_variant) is built from the product's default-language
	// equivalent so a product sold in several languages reports as one GA4
	// item. Off by default, WPML AND Polylang. The Polylang case is LAST
	// because defining pll_* leaks function_exists() process-wide.
	// ---------------------------------------------------------------------

	/**
	 * Activates WPML for a test: registers the wpml_current_language filter
	 * (DefaultLanguage::is_active() detection) and maps wpml_object_id /
	 * wpml_default_language.
	 *
	 * @param array<int, int> $map Current-language id => default-language id.
	 * @return void
	 */
	private function activate_wpml( array $map ): void {
		add_filter( 'wpml_current_language', static fn () => 'de' );
		Filters\expectApplied( 'wpml_default_language' )->zeroOrMoreTimes()->andReturn( 'en' );
		Filters\expectApplied( 'wpml_object_id' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( $id ) use ( $map ) {
				return $map[ (int) $id ] ?? $id;
			}
		);
	}

	public function test_master_language_off_keeps_the_current_product_values(): void {
		// Opt-in gate: with the option OFF, even with WPML active and a master
		// translation available, the current (translated) product is reported.
		$this->activate_wpml( array( 123 => 100 ) );
		Functions\when( 'wc_get_product' )->alias(
			static fn ( $id ) => 100 === (int) $id
				? new \WC_Product(
					array(
						'id'    => 100,
						'title' => 'Master Product',
						'sku'   => 'SKU-MASTER',
					)
				)
				: null
		);
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array( (object) array( 'name' => 'Current Cat', 'term_id' => 5 ) ) // phpcs:ignore
		);

		$item = $this->make_product_data()->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 123, $item['item_id'], 'With the option off the current product id is kept.' );
		$this->assertSame( 'Test Product', $item['item_name'] );
		$this->assertSame( 'Current Cat', $item['item_category'] );
	}

	public function test_master_language_resolves_full_item_via_wpml(): void {
		// Product 123 -> master 100; item identity/text and categories/brand are
		// read from the master product.
		$this->activate_wpml( array( 123 => 100 ) );

		Functions\when( 'wc_get_product' )->alias(
			static fn ( $id ) => 100 === (int) $id
				? new \WC_Product(
					array(
						'id'    => 100,
						'title' => 'Master Product',
						'sku'   => 'SKU-MASTER',
					)
				)
				: null
		);
		Functions\when( 'wp_get_post_terms' )->alias(
			static function ( $product_id, $taxonomy ) {
				if ( 100 !== (int) $product_id ) {
					return array();
				}
				if ( 'product_brand' === $taxonomy ) {
					return array( (object) array( 'name' => 'Master Brand' ) ); // phpcs:ignore
				}
				return array( (object) array( 'name' => 'Master Cat', 'term_id' => 5 ) ); // phpcs:ignore
			}
		);

		$product_data = $this->make_product_data(
			array(
				GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE   => true,
				GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY => 'product_brand',
			)
		);
		$item         = $product_data->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 123, $item['internal_id'], 'internal_id stays the current product id for client-side list matching.' );
		$this->assertSame( 100, $item['item_id'], 'item_id is the master product id so GA4 merges the translations.' );
		$this->assertSame( 'Master Product', $item['item_name'] );
		$this->assertSame( 'SKU-MASTER', $item['sku'] );
		$this->assertSame( 'Master Cat', $item['item_category'] );
		$this->assertSame( 'Master Brand', $item['item_brand'] );
	}

	public function test_master_language_variation_resolves_parent_and_variant_via_wpml(): void {
		// Variation 456 (parent 99) -> master variation 400 (parent 300).
		$this->activate_wpml(
			array(
				456 => 400,
				99  => 300,
			)
		);

		Functions\when( 'wc_get_product' )->alias(
			static fn ( $id ) => 400 === (int) $id
				? new \WC_Product_Variation(
					array(
						'id'                   => 400,
						'type'                 => 'variation',
						'parent_id'            => 300,
						'title'                => 'Master Variation',
						'variation_attributes' => array( 'attribute_pa_color' => 'red' ),
					)
				)
				: null
		);
		Functions\when( 'wp_get_post_terms' )->alias(
			static fn ( $product_id ) => 300 === (int) $product_id
				? array( (object) array( 'name' => 'Master Cat', 'term_id' => 5 ) ) // phpcs:ignore
				: array()
		);

		$variation = new \WC_Product_Variation(
			array(
				'id'                   => 456,
				'type'                 => 'variation',
				'parent_id'            => 99,
				'title'                => 'Current Variation',
				'variation_attributes' => array( 'attribute_pa_color' => 'rouge' ),
			)
		);

		$item = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE => true )
		)->process_product( $variation, array(), 'productdetail' );

		$this->assertSame( 400, $item['item_id'] );
		$this->assertSame( 'Master Variation', $item['item_name'] );
		$this->assertSame( 300, $item['item_group_id'], 'The variation parent resolves to the master parent id.' );
		$this->assertSame( 'red', $item['item_variant'], 'The variant attributes come from the master variation.' );
		$this->assertSame( 'Master Cat', $item['item_category'], 'Category comes from the master parent product.' );
	}

	public function test_master_language_falls_back_when_product_is_untranslated(): void {
		// WPML active but no translation: wpml_object_id returns the same id, so
		// the current product is used unchanged (no wc_get_product swap).
		$this->activate_wpml( array() );
		Functions\when( 'wc_get_product' )->justReturn( null );
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array( (object) array( 'name' => 'Current Cat', 'term_id' => 5 ) ) // phpcs:ignore
		);

		$item = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE => true )
		)->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 123, $item['item_id'] );
		$this->assertSame( 'Test Product', $item['item_name'] );
		$this->assertSame( 'Current Cat', $item['item_category'] );
	}

	public function test_master_language_resolves_full_item_via_polylang(): void {
		Functions\when( 'pll_default_language' )->justReturn( 'en' );
		Functions\when( 'pll_get_post' )->alias( static fn ( $id ): int => 123 === (int) $id ? 100 : 0 );

		Functions\when( 'wc_get_product' )->alias(
			static fn ( $id ) => 100 === (int) $id
				? new \WC_Product(
					array(
						'id'    => 100,
						'title' => 'Master Product',
						'sku'   => 'SKU-MASTER',
					)
				)
				: null
		);
		Functions\when( 'wp_get_post_terms' )->alias(
			static fn ( $product_id ) => 100 === (int) $product_id
				? array( (object) array( 'name' => 'Master Cat', 'term_id' => 5 ) ) // phpcs:ignore
				: array()
		);

		$item = $this->make_product_data(
			array( GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE => true )
		)->process_product( $this->make_product(), array(), 'productdetail' );

		$this->assertSame( 100, $item['item_id'] );
		$this->assertSame( 'Master Product', $item['item_name'] );
		$this->assertSame( 'Master Cat', $item['item_category'] );
	}
}
