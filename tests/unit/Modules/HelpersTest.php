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

// Required here explicitly rather than left to whichever test file happens to
// load first: these doubles are process-wide class definitions, and depending on
// another file's require is invisible until something reorders (TS-16).
require_once __DIR__ . '/wc-stubs.php';

/**
 * Covers the static helpers ported from ecommerce-generic.php. The email and
 * name normalize_and_hash* helpers are exercised by ProductDataTest; this file
 * covers the string/id/taxonomy helpers that had no test, plus the E.164 phone
 * normalization that feeds Google's enhanced-conversions user_data.
 */
final class HelpersTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ],
			$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ]
		);

		parent::tearDown();
	}

	/**
	 * Captured setcookie() calls from flag_oneshot_event(): each entry is
	 * array( name, value, options ).
	 *
	 * @var array<int, array{0:string,1:string,2:array}>
	 */
	private array $cookie_writes = array();

	/**
	 * Captures the setcookie() calls flag_oneshot_event() makes (setcookie /
	 * headers_sent are made redefinable via patchwork.json).
	 *
	 * @return void
	 */
	private function capture_event_cookie(): void {
		$this->cookie_writes = array();

		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->alias(
			function ( $name, $value = '', $options = array() ) {
				$this->cookie_writes[] = array( $name, (string) $value, (array) $options );
				return true;
			}
		);
	}

	public function test_flag_oneshot_event_sets_the_event_cookie_when_cache_safe_on(): void {
		// Phase 3 (issue #398): flagging a pending one-shot writes the short-lived,
		// JS-readable event cookie so the client fetches on the next page.
		Functions\when( 'headers_sent' )->justReturn( false );
		$this->capture_event_cookie();

		Helpers::flag_oneshot_event( true );

		$this->assertCount( 1, $this->cookie_writes );
		$this->assertSame( Helpers::ONESHOT_EVENT_COOKIE, $this->cookie_writes[0][0] );
		$this->assertSame( '1', $this->cookie_writes[0][1], 'The cookie carries only presence, no visitor value.' );
		$this->assertFalse( $this->cookie_writes[0][2]['httponly'], 'The event cookie must be JS-readable.' );
		$this->assertSame( '/', $this->cookie_writes[0][2]['path'], 'Site-wide path so the next page (anywhere) sees it and the client can clear it.' );
		$this->assertGreaterThan( time(), $this->cookie_writes[0][2]['expires'] );
		// Reflected into $_COOKIE so a later same-request read sees it.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- test assertion reading the value the code under test just set.
		$this->assertSame( '1', $_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] );
	}

	public function test_flag_oneshot_event_is_a_noop_when_cache_safe_off(): void {
		// With the cache-safe mode off the one-shots render server-side as before, so
		// no cookie is set - behavior is unchanged.
		Functions\when( 'headers_sent' )->justReturn( false );
		$this->capture_event_cookie();

		Helpers::flag_oneshot_event( false );

		$this->assertSame( array(), $this->cookie_writes, 'No cookie may be set when the cache-safe mode is off.' );
		$this->assertArrayNotHasKey( Helpers::ONESHOT_EVENT_COOKIE, $_COOKIE );
	}

	public function test_flag_oneshot_event_is_a_noop_after_headers_sent(): void {
		// A cookie cannot be set once output started; skip silently (the next
		// non-cached request re-flags it).
		Functions\when( 'headers_sent' )->justReturn( true );
		$this->capture_event_cookie();

		Helpers::flag_oneshot_event( true );

		$this->assertSame( array(), $this->cookie_writes );
		$this->assertArrayNotHasKey( Helpers::ONESHOT_EVENT_COOKIE, $_COOKIE );
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
				0 => array( 'item_list_name' => 'Zero id dropped' ),
				7 => array( 'item_list_id' => 'no-name-dropped' ),
				9 => array( 'item_list_name' => 'Kept' ),
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

	/**
	 * Both callers pass a data-bearing replacement (the esc_attr'd product JSON),
	 * so a $0/${0}/\1 sequence arriving in product data must stay literal. The
	 * helper used to run preg_replace, whose replacement argument expands those
	 * into the MATCHED text - and the cart remove-link matches on `href="`, which
	 * carries a quote, so the expansion terminated the attribute the caller had
	 * already esc_attr'd. Escaping runs before the substitution and cannot defend
	 * against it (PA-7 / RI-17). This test fails if the regex is reintroduced.
	 */
	public function test_str_replace_first_treats_backreference_sequences_in_the_replacement_literally(): void {
		$out = Helpers::str_replace_first( 'href="', 'data="$0 ${0} \1" href="', 'href="x">' );

		$this->assertSame( 'data="$0 ${0} \1" href="x">', $out, 'Backreference sequences stay literal.' );
		// Both directions: the matched text was never substituted into the value.
		$this->assertStringNotContainsString( 'data="href="', $out );
	}

	public function test_str_replace_first_treats_regex_metacharacters_in_the_needle_literally(): void {
		// The needle was previously wrapped in a pattern via preg_quote; with the
		// regex gone it is a plain literal, so this stays a non-match either way.
		$this->assertSame(
			'a.c',
			Helpers::str_replace_first( 'a+c', 'X', 'a.c' ),
			'The needle is matched literally, never as a pattern.'
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

	public function test_normalize_phone_number_keeps_explicit_international_format(): void {
		$this->assertSame( '+36201234567', Helpers::normalize_phone_number( '+36 20 123 4567', 'HU' ) );
		// The country is not even consulted when the number carries its own "+".
		$this->assertSame( '+36201234567', Helpers::normalize_phone_number( '+36 (20) 123-4567', '' ) );
	}

	public function test_normalize_phone_number_converts_the_double_zero_call_prefix(): void {
		$this->assertSame( '+36201234567', Helpers::normalize_phone_number( '0036 20 123 4567', 'HU' ) );
	}

	public function test_normalize_phone_number_strips_a_multi_digit_trunk_prefix(): void {
		// Hungary dials 06 nationally. Stripping only the leading zero would
		// leave the 6 in place and produce +366201234567, which never matches.
		$this->assertSame( '+36201234567', Helpers::normalize_phone_number( '06 20 123 4567', 'HU' ) );
		$this->assertStringNotContainsString( '+366', Helpers::normalize_phone_number( '06 20 123 4567', 'HU' ) );
	}

	public function test_normalize_phone_number_strips_a_single_zero_trunk_prefix(): void {
		$this->assertSame( '+493012345678', Helpers::normalize_phone_number( '030 12345678', 'DE' ) );
	}

	public function test_normalize_phone_number_strips_a_non_zero_trunk_prefix(): void {
		// Russia dials 8 nationally while its calling code is 7.
		$this->assertSame( '+74951234567', Helpers::normalize_phone_number( '8 (495) 123-45-67', 'RU' ) );

		// Turkmenistan is the same shape and was missing while its four ex-Soviet
		// siblings were present - the gap a hand-written exception list leaves.
		$this->assertSame( '+99312345678', Helpers::normalize_phone_number( '8 12 345678', 'TM' ) );
	}

	/**
	 * The defect that made this table generated rather than written.
	 *
	 * These countries have NO trunk prefix, so their leading zero is part of the
	 * number. Reading "not in the exceptions list" as "dials 0" removed a
	 * significant digit and produced a number that is well formed, passes every
	 * length check, and can never match. Italy is the commercially important one.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_keeps_a_significant_leading_zero(): void {
		$this->assertSame( '+390669821234', Helpers::normalize_phone_number( '06 6982 1234', 'IT' ) );
		$this->assertSame( '+390212345678', Helpers::normalize_phone_number( '02 1234 5678', 'IT' ) );
		$this->assertSame( '+2250123456789', Helpers::normalize_phone_number( '01 23 45 67 89', 'CI' ) );
		$this->assertSame( '+2290120211234', Helpers::normalize_phone_number( '01 20 21 12 34', 'BJ' ) );
		$this->assertSame( '+24101441234', Helpers::normalize_phone_number( '01 44 12 34', 'GA' ) );

		// Both directions (TS-2): the zero survives, and the value the old
		// leading-zero strip produced is gone.
		$this->assertNotSame( '+39669821234', Helpers::normalize_phone_number( '06 6982 1234', 'IT' ) );
	}

	/**
	 * Italian mobiles begin with 3, so they were never affected - which is
	 * exactly why the landline defect could ship unnoticed.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_leaves_a_country_without_a_leading_zero_alone(): void {
		$this->assertSame( '+393201234567', Helpers::normalize_phone_number( '320 1234567', 'IT' ) );
	}

	public function test_normalize_phone_number_handles_north_american_numbers(): void {
		// Both the bare 10 digit form and the 1-prefixed form land on the same
		// E.164 number, which is why North America needs no special entry.
		$this->assertSame( '+12015550123', Helpers::normalize_phone_number( '(201) 555-0123', 'US' ) );
		$this->assertSame( '+12015550123', Helpers::normalize_phone_number( '1 (201) 555-0123', 'US' ) );

		// A trunk prefix of "1" with a calling code that is NOT 1: the case the
		// North America reasoning does not transfer to.
		$this->assertSame( '+6922471234', Helpers::normalize_phone_number( '1 247 1234', 'MH' ) );
	}

	/**
	 * The Channel Islands and the Isle of Man carry their area code inside
	 * WooCommerce's calling-code table, which pushed the result past E.164's 15
	 * digit ceiling and dropped the number entirely. Reading the calling code
	 * from the generated table instead is what fixes it.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_handles_territories_with_their_own_area_code(): void {
		$this->assertSame( '+441534456789', Helpers::normalize_phone_number( '01534 456789', 'JE' ) );
		$this->assertSame( '+441624756789', Helpers::normalize_phone_number( '01624 756789', 'IM' ) );
	}

	/**
	 * The calling code typed without a "+", in BOTH halves of the table.
	 *
	 * This case used to assert Germany alone, which is a country that dials a
	 * trunk prefix - so it pinned the half where the behaviour worked and read as
	 * pinning all of it. The 101 territories with no trunk prefix returned before
	 * ever reaching this test and had their calling code prepended a second time:
	 * well formed, inside the length bounds, and unmatchable.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_accepts_the_international_form_without_a_plus(): void {
		// Countries that dial a trunk prefix.
		$this->assertSame( '+493012345678', Helpers::normalize_phone_number( '49 30 12345678', 'DE' ) );
		$this->assertSame( '+442071234567', Helpers::normalize_phone_number( '44 20 7123 4567', 'GB' ) );
		$this->assertSame( '+33142685300', Helpers::normalize_phone_number( '33 1 42 68 53 00', 'FR' ) );

		// Countries that dial none. Same spelling, and it used to double the code.
		$this->assertSame( '+34612345678', Helpers::normalize_phone_number( '34 612 345 678', 'ES' ) );
		$this->assertSame( '+351912345678', Helpers::normalize_phone_number( '351 912 345 678', 'PT' ) );
		$this->assertSame( '+302109999999', Helpers::normalize_phone_number( '30 21 0999 9999', 'GR' ) );
		$this->assertSame( '+4532123456', Helpers::normalize_phone_number( '45 32 12 34 56', 'DK' ) );
		$this->assertSame( '+420212345678', Helpers::normalize_phone_number( '420 212 345 678', 'CZ' ) );

		// Both directions (TS-2): the doubled-calling-code value is gone.
		$this->assertNotSame( '+3434612345678', Helpers::normalize_phone_number( '34 612 345 678', 'ES' ) );
	}

	/**
	 * The other reading of the same shape, which is why the fix is a pattern and
	 * not a symmetry.
	 *
	 * Each of these is a NATIONAL number that legitimately begins with its own
	 * country's calling code. Simply letting every country try the "international
	 * form with the + left off" branch would cut a real operator prefix off each
	 * one - so would a possible-lengths column, for Italy, where both readings
	 * are possible Italian lengths. Only the national-number pattern separates
	 * them.
	 *
	 * Kazakhstan is here because it was a known, accepted defect: its mobile
	 * ranges all start with 7 and its calling code is 7, so the bare spelling
	 * came out one digit short while the trunk-prefixed one was correct.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_keeps_a_national_number_that_starts_with_its_own_calling_code(): void {
		// Italian mobiles in the 39x ranges, against calling code 39.
		$this->assertSame( '+393912345678', Helpers::normalize_phone_number( '391 234 5678', 'IT' ) );

		// A Danish subscriber number beginning 45, against calling code 45. Its
		// international-form sibling three tests above has the same first two
		// digits and a different length.
		$this->assertSame( '+4545123456', Helpers::normalize_phone_number( '45 12 34 56', 'DK' ) );

		// Kazakhstan, both spellings, landing on the same number.
		$this->assertSame( '+77012345678', Helpers::normalize_phone_number( '701 234 5678', 'KZ' ) );
		$this->assertSame( '+77012345678', Helpers::normalize_phone_number( '8 701 234 5678', 'KZ' ) );

		// Both directions (TS-2): the one-digit-short value the bare spelling used
		// to produce is gone.
		$this->assertNotSame( '+7012345678', Helpers::normalize_phone_number( '701 234 5678', 'KZ' ) );
	}

	/**
	 * What happens when the numbering plan recognises neither reading.
	 *
	 * The pattern column is a tie-breaker, never a validator: a number it does
	 * not know is not refused, it falls through to the positional rules. This is
	 * exactly the path every number would take if our copy of the plan went
	 * completely out of date, and it is otherwise unreachable from the generated
	 * corpus, whose cases are all numbers the plan recognises by construction.
	 *
	 * A stale pattern therefore cannot REJECT a number - but it is not a no-op
	 * either, and the docblock here used to say "harmless", which overstated it.
	 * The positional rules are applied uniformly now; before the pattern column
	 * existed a territory with no trunk prefix returned early and never reached
	 * the calling-code test. Measured under a fully stale pattern the uniform rule
	 * is right in 6,060 sampled cases and wrong in 7, so it is the better trade -
	 * not a free one.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_falls_back_when_the_plan_recognises_neither_reading(): void {
		// Too long for any British number, so neither reading matches: the trunk
		// prefix is still stripped positionally, exactly as before the pattern
		// column existed.
		$this->assertSame( '+442071234567890', Helpers::normalize_phone_number( '020 7123 4567890', 'GB' ) );

		// Trunk-shaped but too short to be a real Hungarian number.
		$this->assertSame( '+3612345', Helpers::normalize_phone_number( '06 12345', 'HU' ) );

		// A country with no trunk prefix, and digits that begin with nothing it
		// recognises: the calling code is prepended, which is the only reading
		// left.
		$this->assertSame( '+3412345678', Helpers::normalize_phone_number( '12345678', 'ES' ) );

		// The calling-code leg of the fall-through, which NOTHING else in the suite
		// reaches - instrumented 2026-08-12, it was executed zero times by all 1952
		// tests, for trunk and no-trunk territories alike. It is also the one leg
		// whose behaviour this column changed: before it existed, a territory with
		// no trunk prefix returned early and prepended the calling code a second
		// time, so this asserted +34341234567890 and now asserts the code is read
		// as a country code instead.
		//
		// ES is the stable choice for pinning it: exactly one valid national length
		// (9), so neither the 12-digit whole nor the 10-digit remainder can become
		// a recognised number on a plan update and quietly move this case into an
		// earlier branch. IT would not be stable - its valid lengths span 6 to 12.
		$this->assertSame( '+341234567890', Helpers::normalize_phone_number( '34 1234 567890', 'ES' ) );

		// Same leg, a territory that DOES have a trunk prefix, so the uniformity is
		// asserted rather than assumed for both halves of the table. Stated plainly
		// because it matters when reading this file: unlike the ES case above, this
		// one is GREEN against the pre-column code too (probe-verified). It is a
		// must-not-break guard, not a regression test, and must not be counted as
		// evidence that the change is pinned - the ES case is what does that.
		$this->assertSame( '+361234567890', Helpers::normalize_phone_number( '36 1234 5678 90', 'HU' ) );

		// The guard still refuses what it always refused - falling through is not
		// the same as accepting anything.
		$this->assertSame( '', Helpers::normalize_phone_number( '1', 'ES' ) );
	}

	public function test_normalize_phone_number_prepends_the_calling_code_to_a_local_number(): void {
		$this->assertSame( '+31612345678', Helpers::normalize_phone_number( '612345678', 'NL' ) );
	}

	/**
	 * "+49 (0) 30 ..." is standard business stationery across the German
	 * speaking countries and the Netherlands, and the bracketed zero is precisely
	 * the digit E.164 must not carry. Taking the "+" form at face value mangled
	 * every one of them.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_drops_a_courtesy_trunk_prefix(): void {
		$this->assertSame( '+493012345678', Helpers::normalize_phone_number( '+49 (0) 30 12345678', 'DE' ) );
		$this->assertSame( '+31612345678', Helpers::normalize_phone_number( '+31 (0)6 12345678', 'NL' ) );

		// Also in the national and the 00 spellings, since the convention is not
		// tied to the "+".
		$this->assertSame( '+493012345678', Helpers::normalize_phone_number( '(0)30 12345678', 'DE' ) );
		$this->assertSame( '+493012345678', Helpers::normalize_phone_number( '0049 (0) 30 12345678', 'DE' ) );
	}

	/**
	 * An area code in brackets is not a courtesy zero and must survive.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_keeps_a_bracketed_area_code(): void {
		$this->assertSame( '+493012345678', Helpers::normalize_phone_number( '(030) 12345678', 'DE' ) );
	}

	public function test_normalize_phone_number_cuts_an_extension(): void {
		// Absorbed into the subscriber number otherwise, which yields a number
		// that is well formed and belongs to nobody.
		$this->assertSame( '+441212345678', Helpers::normalize_phone_number( '0121 234 5678 x22', 'GB' ) );
		$this->assertSame( '+441212345678', Helpers::normalize_phone_number( '0121 234 5678x22', 'GB' ) );
		$this->assertSame( '+441212345678', Helpers::normalize_phone_number( '0121 234 5678 ext. 22', 'GB' ) );
		$this->assertSame( '+441212345678', Helpers::normalize_phone_number( '0121 234 5678 #4', 'GB' ) );
	}

	public function test_normalize_phone_number_empty_when_the_country_cannot_anchor_it(): void {
		// No country at all, and a country the table does not know: two different
		// events, both of which decline to guess rather than invent a calling code
		// that would hash to something unmatchable (RI-19).
		$this->assertSame( '', Helpers::normalize_phone_number( '20 123 4567', '' ) );
		$this->assertSame( '', Helpers::normalize_phone_number( '20 123 4567', 'ZZ' ) );
	}

	/**
	 * The dialling facts no longer come from WooCommerce, so an absent WC() is
	 * not an obstacle any more. Asserted rather than assumed, because the
	 * previous implementation returned '' in exactly this situation.
	 *
	 * @return void
	 */
	public function test_normalize_phone_number_does_not_need_woocommerce(): void {
		Functions\when( 'WC' )->justReturn( null );

		$this->assertSame( '+36201234567', Helpers::normalize_phone_number( '06 20 123 4567', 'HU' ) );
	}

	public function test_normalize_phone_number_rejects_implausible_input(): void {
		$this->assertSame( '', Helpers::normalize_phone_number( 'n/a', 'HU' ) );
		$this->assertSame( '', Helpers::normalize_phone_number( '  ', 'HU' ) );
		$this->assertSame( '', Helpers::normalize_phone_number( '12', 'HU' ) );
		// Past E.164's 15 digit ceiling.
		$this->assertSame( '', Helpers::normalize_phone_number( '+1234567890123456', 'HU' ) );
	}

	/**
	 * Every territory's own example number, spelled nationally, against the E.164
	 * the numbering plan says it is.
	 *
	 * This is the control that makes the generated table trustworthy: both halves
	 * of each case are read out of the upstream metadata, so it fails when this
	 * function disagrees with the numbering plan rather than when it merely
	 * changes. Regenerate with `composer generate:phone-table`.
	 *
	 * It proves the per-country table and nothing about how people type - the
	 * courtesy zero, extension and international-form cases above are where that
	 * lives.
	 *
	 * @param string $country  ISO 3166-1 alpha-2 code.
	 * @param string $typed    The example number in its national spelling.
	 * @param string $expected The E.164 form.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_national_number_corpus' )]
	public function test_normalize_phone_number_matches_the_numbering_plan_of_every_country( string $country, string $typed, string $expected ): void {
		$this->assertSame( $expected, Helpers::normalize_phone_number( $typed, $country ) );
	}

	/**
	 * The generated corpus, kept in its own file so regenerating it never
	 * rewrites a hand-written test.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function provide_national_number_corpus(): array {
		return require __DIR__ . '/phone-corpus.php';
	}

	public function test_normalize_and_hash_phone_number_hashes_the_e164_form(): void {
		$actual = Helpers::normalize_and_hash_phone_number( 'sha256', '06 20 123 4567', 'HU' );

		// Both directions: the E.164 hash is what ships, and the pre-fix hash of
		// the merely space-stripped number is gone. Asserting only the first
		// would still pass if the normalization silently regressed.
		$this->assertSame( hash( 'sha256', '+36201234567' ), $actual );
		$this->assertNotSame( hash( 'sha256', '06201234567' ), $actual );
	}

	public function test_normalize_and_hash_phone_number_empty_when_not_normalizable(): void {
		$this->assertSame( '', Helpers::normalize_and_hash_phone_number( 'sha256', '20 123 4567', 'ZZ' ) );
	}
}
