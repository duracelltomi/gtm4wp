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
