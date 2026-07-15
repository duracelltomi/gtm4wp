<?php
/**
 * Unit tests for the WooCommerce integration helper functions.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\WooCommerce\Helpers;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the static helpers ported from ecommerce-generic.php. The
 * normalize_and_hash* helpers are exercised by ProductDataTest; this file
 * covers the string/id/taxonomy helpers that had no test.
 */
final class HelpersTest extends TestCase {

	protected function tearDown(): void {
		unset( $_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] );

		parent::tearDown();
	}

	/**
	 * Installs the sanitizer stubs the cookie reader needs. sanitize_text_field
	 * strips tags but keeps & / " so the value reaches the JSON sink raw.
	 *
	 * @return void
	 */
	private function stub_cookie_sanitizers(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) )
		);
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);
	}

	public function test_read_item_list_cookie_ignores_oversized_cookie(): void {
		// A crafted, bloated cookie is rejected wholesale so the reader never does
		// unbounded work.
		$this->stub_cookie_sanitizers();
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = str_repeat( 'a', Helpers::LIST_ATTRIBUTION_COOKIE_MAX_BYTES + 1 );

		$this->assertSame( array(), Helpers::read_item_list_cookie() );
	}

	public function test_read_item_list_cookie_caps_the_number_of_entries(): void {
		$this->stub_cookie_sanitizers();

		$entries = array();
		for ( $i = 1; $i <= Helpers::LIST_ATTRIBUTION_MAX_ENTRIES + 5; $i++ ) {
			$entries[ $i ] = array( 'item_list_name' => 'List ' . $i );
		}
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( $entries ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertCount(
			Helpers::LIST_ATTRIBUTION_MAX_ENTRIES,
			Helpers::read_item_list_cookie(),
			'No more than the entry cap is ever processed.'
		);
	}

	public function test_read_item_list_cookie_skips_invalid_entries(): void {
		// A zero/negative id or an entry without a list name is dropped.
		$this->stub_cookie_sanitizers();
		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				0   => array( 'item_list_name' => 'Zero id dropped' ),
				7   => array( 'item_list_id' => 'no-name-dropped' ),
				9   => array( 'item_list_name' => 'Kept' ),
			)
		);

		$map = Helpers::read_item_list_cookie();

		$this->assertSame( array( 9 ), array_keys( $map ) );
		$this->assertSame( 'Kept', $map[9]['item_list_name'] );
		$this->assertSame( 'kept', $map[9]['item_list_id'], 'Missing item_list_id is derived from the name.' );
	}

	public function test_str_replace_first_replaces_only_the_first_occurrence(): void {
		$this->assertSame(
			'X-href="a" href="b"',
			Helpers::str_replace_first( 'href="', 'X-href="', 'href="a" href="b"' )
		);
	}

	public function test_str_replace_first_returns_subject_when_needle_absent(): void {
		$this->assertSame(
			'no match here',
			Helpers::str_replace_first( 'href="', 'X', 'no match here' )
		);
	}

	public function test_prefix_productid_applies_prefix_only_when_set(): void {
		$this->assertSame( 'feed_123', Helpers::prefix_productid( 123, 'feed_' ) );
		$this->assertSame( 123, Helpers::prefix_productid( 123, '' ), 'An empty prefix leaves the id untouched.' );
	}

	public function test_gads_id_field_name_is_destination_for_travel_verticals(): void {
		$this->assertSame( 'destination', Helpers::get_gads_product_id_variable_name( 'flights' ) );
		$this->assertSame( 'destination', Helpers::get_gads_product_id_variable_name( 'travel' ) );
		$this->assertSame( 'id', Helpers::get_gads_product_id_variable_name( 'retail' ) );
		$this->assertSame( 'id', Helpers::get_gads_product_id_variable_name( 'unknown' ) );
	}

	public function test_product_category_hierarchy_trims_separators(): void {
		Functions\when( 'get_term_parents_list' )->justReturn( '/Home/Clothing/Toddlers/' );

		$this->assertSame(
			'Home/Clothing/Toddlers',
			Helpers::get_product_category_hierarchy( 5 )
		);
	}

	public function test_product_category_hierarchy_empty_when_no_list(): void {
		// get_term_parents_list returns false/WP_Error for an unknown term.
		Functions\when( 'get_term_parents_list' )->justReturn( false );

		$this->assertSame( '', Helpers::get_product_category_hierarchy( 999 ) );
	}

	public function test_get_product_term_returns_first_term_name(): void {
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array(
				(object) array( 'name' => 'ACME' ),
				(object) array( 'name' => 'Other' ),
			)
		);

		$this->assertSame( 'ACME', Helpers::get_product_term( 7, 'product_brand' ) );
	}

	public function test_get_product_term_empty_when_no_terms(): void {
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );

		$this->assertSame( '', Helpers::get_product_term( 7, 'product_brand' ) );
	}

	public function test_cart_line_display_price_excludes_tax_by_default(): void {
		// #436: the per-unit price comes from the already-calculated line totals
		// (line_subtotal / quantity) instead of recomputing wc_get_price_to_display().
		$this->assertSame(
			20.0,
			Helpers::cart_line_display_price(
				array(
					'line_subtotal'     => 40.0,
					'line_subtotal_tax' => 8.0,
					'quantity'          => 2,
				),
				false
			)
		);
	}

	public function test_cart_line_display_price_includes_tax_when_requested(): void {
		$this->assertSame(
			24.0,
			Helpers::cart_line_display_price(
				array(
					'line_subtotal'     => 40.0,
					'line_subtotal_tax' => 8.0,
					'quantity'          => 2,
				),
				true
			),
			'Including tax adds line_subtotal_tax before dividing by quantity.'
		);
	}

	public function test_cart_line_display_price_null_without_line_totals(): void {
		// No line totals yet (e.g. cart not calculated) - caller falls back to
		// wc_get_price_to_display() rather than fabricating a price.
		$this->assertNull( Helpers::cart_line_display_price( array( 'quantity' => 2 ), false ) );
	}

	public function test_cart_line_display_price_null_for_zero_quantity(): void {
		$this->assertNull(
			Helpers::cart_line_display_price(
				array(
					'line_subtotal' => 40.0,
					'quantity'      => 0,
				),
				false
			)
		);
	}

	public function test_cart_line_discount_returns_per_unit_gap_excluding_tax(): void {
		// #348: per-unit discount = (line_subtotal - line_total) / quantity, on the
		// same tax basis as the price. subtotal 40 (pre-discount), total 30 (after a
		// coupon), quantity 2 => (40 - 30) / 2 = 5.
		$this->assertSame(
			5.0,
			Helpers::cart_line_discount(
				array(
					'line_subtotal' => 40.0,
					'line_total'    => 30.0,
					'quantity'      => 2,
				),
				false
			)
		);
	}

	public function test_cart_line_discount_includes_tax_on_both_sides_when_requested(): void {
		// Including tax adds line_subtotal_tax and line_total_tax before the gap:
		// (40 + 8) - (30 + 6) = 12, / 2 = 6.
		$this->assertSame(
			6.0,
			Helpers::cart_line_discount(
				array(
					'line_subtotal'     => 40.0,
					'line_subtotal_tax' => 8.0,
					'line_total'        => 30.0,
					'line_total_tax'    => 6.0,
					'quantity'          => 2,
				),
				true
			)
		);
	}

	public function test_cart_line_discount_null_when_no_discount(): void {
		// An undiscounted line (subtotal == total) yields null so the caller omits
		// the field rather than emitting a 0 discount.
		$this->assertNull(
			Helpers::cart_line_discount(
				array(
					'line_subtotal' => 40.0,
					'line_total'    => 40.0,
					'quantity'      => 2,
				),
				false
			)
		);
	}

	public function test_cart_line_discount_null_without_line_totals(): void {
		$this->assertNull( Helpers::cart_line_discount( array( 'quantity' => 2 ), false ) );
	}

	public function test_cart_line_discount_null_for_zero_quantity(): void {
		$this->assertNull(
			Helpers::cart_line_discount(
				array(
					'line_subtotal' => 40.0,
					'line_total'    => 30.0,
					'quantity'      => 0,
				),
				false
			)
		);
	}

	public function test_get_product_category_uses_first_assigned_category(): void {
		// No Yoast primary term, so the first assigned category is used.
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array( (object) array( 'name' => 'Shoes', 'term_id' => 3 ) ) // phpcs:ignore
		);

		$this->assertSame( 'Shoes', Helpers::get_product_category( 7 ) );
	}

	public function test_get_product_category_empty_when_no_terms(): void {
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );

		$this->assertSame( '', Helpers::get_product_category( 7 ) );
	}
}
