<?php
/**
 * WooCommerce integration helper functions.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Ecommerce\Helpers as EcommerceHelpers;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers ported from integration/ecommerce-generic.php of 1.x.
 *
 * The store-agnostic pieces (business verticals, category/taxonomy readers,
 * Enhanced Conversions hashing, product id prefixing) live in
 * GTM4WP\Ecommerce\Helpers since 2.0 so other store integrations (Easy
 * Digital Downloads) can share them; the constants and methods here delegate
 * to keep this class' public API unchanged for existing consumers.
 */
final class Helpers {

	/**
	 * Valid Google Ads business vertical ids. The labels shown on the
	 * admin page live in the AdminSchema class.
	 *
	 * @var string[]
	 */
	public const BUSINESS_VERTICALS = EcommerceHelpers::BUSINESS_VERTICALS;

	/**
	 * Business verticals that use a different name for their "id" field in
	 * Google Ads dynamic remarketing tagging.
	 *
	 * @var array<string, string>
	 */
	public const BUSINESS_VERTICALS_IDS = EcommerceHelpers::BUSINESS_VERTICALS_IDS;

	/**
	 * GA4 list-attribution cookie contract (#405), shared with the EDD module.
	 * The implementation and full documentation live in
	 * \GTM4WP\Ecommerce\Helpers; these aliases keep the 2.x-internal names the
	 * WooCommerce classes and tests already use.
	 */
	public const LIST_ATTRIBUTION_COOKIE           = EcommerceHelpers::LIST_ATTRIBUTION_COOKIE;
	public const LIST_ATTRIBUTION_JS_WRAPPER       = EcommerceHelpers::LIST_ATTRIBUTION_JS_WRAPPER;
	public const LIST_ATTRIBUTION_MAX_ENTRIES      = EcommerceHelpers::LIST_ATTRIBUTION_MAX_ENTRIES;
	public const LIST_ATTRIBUTION_COOKIE_MAX_BYTES = EcommerceHelpers::LIST_ATTRIBUTION_COOKIE_MAX_BYTES;

	/**
	 * Cookies whose mere PRESENCE means this browser already has WooCommerce
	 * state - a cart, or a session holding guest checkout fields.
	 *
	 * The session cookie is matched by prefix because WooCommerce appends
	 * COOKIEHASH to it, and that hash is derived from siteurl rather than from
	 * anything readable here.
	 *
	 * @var string[]
	 */
	private const WC_STATE_COOKIES         = array( 'woocommerce_items_in_cart', 'woocommerce_cart_hash' );
	private const WC_SESSION_COOKIE_PREFIX = 'wp_woocommerce_session_';

	/**
	 * Whether this browser plausibly has WooCommerce state worth delivering.
	 *
	 * A presence check over cookies and the login state - never a value read,
	 * never a session or cart load. That restraint is the whole point: the
	 * caller (WooCommerceModule::enqueue_visitor_cart_channel()) runs on every
	 * front-end page of a store in cache-safe mode, and touching WC()->cart
	 * there would perform exactly the session + cart load this gate exists to
	 * avoid, handing a session cookie to a visitor who has none.
	 *
	 * This is PageDataLayer::oneshot_wc()'s rule applied to the second delivery
	 * channel. That method's docblock states the consequence for the REST
	 * endpoint - "page caches routinely bypass the cache for any visitor
	 * carrying one, which would defeat the very mode this code serves" - and the
	 * cart-fragments channel added later did not inherit it. Verified 2026-08-10
	 * against WooCommerce's own client/legacy/js/frontend/cart-fragments.js: it
	 * has NO empty-cart bail-out, so an ungated enqueue costs every visitor one
	 * uncached wc-ajax round trip per browser tab. WooCommerce itself stopped
	 * enqueuing that script on all routes in 7.8 for this reason.
	 *
	 * Logged-in counts on its own: the customer half of the payload is populated
	 * for a logged-in visitor with an empty cart and no session cookie, and that
	 * is precisely the visitor a cart-only gate would silently drop.
	 *
	 * @return bool
	 */
	public static function visitor_has_wc_state(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}

		foreach ( self::WC_STATE_COOKIES as $one_cookie ) {
			if ( isset( $_COOKIE[ $one_cookie ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; no value is read.
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; no value is read.
		foreach ( array_keys( $_COOKIE ) as $one_name ) {
			if ( 0 === strpos( (string) $one_name, self::WC_SESSION_COOKIE_PREFIX ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Name of the short-lived, JS-readable event cookie the cache-safe data layer
	 * (issue #398, Phase 3) sets when a WooCommerce one-shot event is queued in the
	 * session — a product re-added to the cart ("Undo") or a placed order awaiting
	 * its reliable-purchase fallback. Its mere presence tells the client runtime to
	 * fetch the session endpoint on the next page; the client fires the event once,
	 * de-dupes it and then clears this cookie. An anonymous visitor on a cached page,
	 * who never has it, never fetches. Must match the literal the client clears in
	 * js/frontend/gtm4wp-visitor-data.js and the cookie_gate declared in
	 * PageDataLayer::declare_visitor_scoped_fields().
	 */
	public const ONESHOT_EVENT_COOKIE = 'gtm4wp_woo_event';

	/**
	 * Flags that a WooCommerce one-shot event is pending for this session by
	 * setting the short-lived event cookie (self::ONESHOT_EVENT_COOKIE) — but only
	 * when the cache-safe data layer is on, since that is the only mode in which the
	 * one-shots are delivered client-side (otherwise they render server-side as
	 * before and no cookie is needed). Called from the same hooks that seed the
	 * session markers (ListTracking::cart_item_restored,
	 * PurchaseTracking::remember_order). Skipped silently once headers are sent
	 * (a cookie cannot be set then) and never lands on a cacheable response, because
	 * those hooks run only on non-cached cart/checkout requests.
	 *
	 * The cookie is deliberately NOT HttpOnly (the client must read it) and carries
	 * no visitor value — only the fact that a fetch is due. The client clears it
	 * after delivery; the 2-day expiry only bounds the case where delivery never
	 * happened, and comfortably covers a WooCommerce session.
	 *
	 * @param bool $cache_safe_enabled Whether GTM4WP_OPTION_CACHE_SAFE_DATALAYER is on.
	 * @return void
	 */
	public static function flag_oneshot_event( bool $cache_safe_enabled ): void {
		if ( ! $cache_safe_enabled || headers_sent() ) {
			return;
		}

		setcookie(
			self::ONESHOT_EVENT_COOKIE,
			'1',
			array(
				'expires'  => time() + ( 2 * DAY_IN_SECONDS ),
				'path'     => '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		// Reflect it into $_COOKIE so any later same-request read sees it set.
		$_COOKIE[ self::ONESHOT_EVENT_COOKIE ] = '1';
	}

	/**
	 * Replace only the first occurrence of the search string with the replacement string.
	 *
	 * Both arguments are treated as literal strings. This deliberately does NOT use
	 * preg_replace(): its replacement argument expands $0/$1/${1}/\1 as backreferences,
	 * so a replacement carrying product data would have such a sequence substituted with
	 * the matched text. Where the matched text contains a quote (the cart remove-link
	 * injects at `href="`), that expansion lands a raw quote inside an already-esc_attr'd
	 * attribute and terminates it - the escaping runs before the substitution, so it
	 * cannot defend against it. See PA-7 / RI-17 in .security/code-review-patterns.md.
	 *
	 * @param string $search The value being searched for, otherwise known as the needle.
	 * @param string $replace The replacement value that replaces found search values.
	 * @param string $subject The string being searched and replaced on, otherwise known as the haystack.
	 * @return string This function returns a string with the replaced values.
	 */
	public static function str_replace_first( string $search, string $replace, string $subject ): string {
		$position = strpos( $subject, $search );

		if ( false === $position ) {
			return $subject;
		}

		return substr_replace( $subject, $replace, $position, strlen( $search ) );
	}

	/**
	 * Takes a product ID and returns a string that has a prefix appended.
	 *
	 * This is needed in cases where the generated feed has IDs with some sort of constant prefix and
	 * tracking needs to align with this ID in order for dynamic remarketing to work properly.
	 *
	 * @param int|string $product_id A product ID that has to be prefixed.
	 * @param string     $prefix The prefix that needs to be added in front of the product ID.
	 * @return int|string The product ID with the prefix string.
	 */
	public static function prefix_productid( $product_id, string $prefix ) {
		return EcommerceHelpers::prefix_productid( $product_id, $prefix );
	}

	/**
	 * Per-unit display price for a WooCommerce cart line, taken from the line
	 * totals WooCommerce has already calculated (line_subtotal / line_subtotal_tax)
	 * instead of recomputing wc_get_price_to_display() per item. That call is
	 * expensive in the cart/checkout context and, run once per cart item, caused
	 * memory exhaustion on carts after a WooCommerce update (#436).
	 *
	 * Returns null when the line totals are not available (e.g. before the cart is
	 * calculated), so the caller can fall back to wc_get_price_to_display().
	 *
	 * @param array<string, mixed> $cart_item_data A WooCommerce cart item.
	 * @param bool                 $include_tax    Whether to include tax (the shop's price-display setting).
	 * @return float|null The per-unit display price rounded to 2 decimals, or null when it cannot be derived.
	 */
	public static function cart_line_display_price( array $cart_item_data, bool $include_tax ): ?float {
		if ( ! isset( $cart_item_data['line_subtotal'] ) ) {
			return null;
		}

		$quantity = (float) ( $cart_item_data['quantity'] ?? 0 );
		if ( $quantity <= 0 ) {
			return null;
		}

		$line = (float) $cart_item_data['line_subtotal'];
		if ( $include_tax ) {
			$line += (float) ( $cart_item_data['line_subtotal_tax'] ?? 0 );
		}

		return round( $line / $quantity, 2 );
	}

	/**
	 * Per-unit discount for a WooCommerce cart line: the gap between the
	 * pre-discount subtotal (line_subtotal) and the post-discount total
	 * (line_total), on the same tax basis as cart_line_display_price(), divided
	 * by the line quantity. Used to add GA4's per-item `discount` field where a
	 * coupon or sale reduced the line (#348).
	 *
	 * Returns null when the totals are not available or when there is no discount
	 * (≤ 0), so the caller can simply omit the field on undiscounted lines rather
	 * than emit a 0.
	 *
	 * @param array<string, mixed> $cart_item_data A WooCommerce cart item.
	 * @param bool                 $include_tax    Whether to include tax (the shop's price-display setting).
	 * @return float|null The per-unit discount rounded to 2 decimals, or null when there is none.
	 */
	public static function cart_line_discount( array $cart_item_data, bool $include_tax ): ?float {
		if ( ! isset( $cart_item_data['line_subtotal'], $cart_item_data['line_total'] ) ) {
			return null;
		}

		$quantity = (float) ( $cart_item_data['quantity'] ?? 0 );
		if ( $quantity <= 0 ) {
			return null;
		}

		$subtotal = (float) $cart_item_data['line_subtotal'];
		$total    = (float) $cart_item_data['line_total'];
		if ( $include_tax ) {
			$subtotal += (float) ( $cart_item_data['line_subtotal_tax'] ?? 0 );
			$total    += (float) ( $cart_item_data['line_total_tax'] ?? 0 );
		}

		$discount = round( ( $subtotal - $total ) / $quantity, 2 );
		if ( $discount <= 0 ) {
			return null;
		}

		return $discount;
	}

	/**
	 * Reads and validates the first-party list-attribution cookie (#405).
	 * Delegates to the shared store-agnostic implementation - see
	 * \GTM4WP\Ecommerce\Helpers::read_item_list_cookie() for the full
	 * sanitization contract.
	 *
	 * @return array<int, array{item_list_name: string, item_list_id: string}>
	 */
	public static function read_item_list_cookie(): array {
		return EcommerceHelpers::read_item_list_cookie();
	}

	/**
	 * Given a category ID, this function returns the full path to this category separated with the / character.
	 *
	 * @param int    $category_id The ID of the category that needs to be scanned for parents.
	 * @param string $category_taxonomy The name of the WordPress taxonomy where the category ID needs to be searched.
	 * @return string The category path. An example output can be: Home/Clothing/Toddlers.
	 */
	public static function get_product_category_hierarchy( $category_id, string $category_taxonomy = 'product_cat' ): string {
		return EcommerceHelpers::get_product_category_hierarchy( $category_id, $category_taxonomy );
	}

	/**
	 * Given a product ID, this function will return the first assigned category of the product.
	 * Uses the "primary category" of Yoast SEO or Rank Math when available.
	 *
	 * @param int     $product_id A product ID whose first assigned category has to be returned.
	 * @param boolean $fullpath Set this to true if you need to query the full path including parent categories.
	 * @param string  $category_taxonomy The name of the WordPress taxonomy where the category ID needs to be searched.
	 * @return string The first category name of the product. Includes parent category names if $fullpath is true.
	 */
	public static function get_product_category( $product_id, bool $fullpath = false, string $category_taxonomy = 'product_cat' ): string {
		return EcommerceHelpers::get_product_category( $product_id, $fullpath, $category_taxonomy );
	}

	/**
	 * Given a product ID, this function returns the assigned value of a custom taxonomy like the brand name.
	 *
	 * @param int    $product_id A WooCommerce product ID whose taxonomy association needs to be queried.
	 * @param string $taxonomy The taxonomy slug for which to retrieve terms.
	 * @return string Returns the first assigned taxonomy value of the given WooCommerce product ID.
	 */
	public static function get_product_term( $product_id, string $taxonomy ): string {
		return EcommerceHelpers::get_product_term( $product_id, $taxonomy );
	}

	/**
	 * Given a Google Business vertical ID, this function returns the name of the "ID" field in tagging
	 * Google Ads dynamic remarketing. This is "id" in most cases, but sometimes "destination".
	 *
	 * @param string $vertical_id The Google Business vertical ID (like retail, flights, etc.).
	 * @return string The name of the "ID" field for tagging.
	 */
	public static function get_gads_product_id_variable_name( string $vertical_id ): string {
		return EcommerceHelpers::get_gads_product_id_variable_name( $vertical_id );
	}

	/**
	 * Returns the result of normalizing and hashing any data.
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web#php
	 *
	 * @param string $hash_algorithm the hash algorithm to use.
	 * @param string $value the value to normalize and hash.
	 * @param bool   $trim_intermediate_spaces whether to remove all spaces before hashing (true) or only leading and trailing spaces (false).
	 * @return string the normalized and hashed value.
	 */
	public static function normalize_and_hash( string $hash_algorithm, string $value, bool $trim_intermediate_spaces ): string {
		return EcommerceHelpers::normalize_and_hash( $hash_algorithm, $value, $trim_intermediate_spaces );
	}

	/**
	 * Returns the result of normalizing and hashing an email address.
	 *
	 * The gmail/googlemail folding rules and the deliberate deviations from
	 * Google's PHP sample (the plus rule, the first-'@' split) are documented on
	 * the shared implementation in GTM4WP\Ecommerce\Helpers - read that docblock
	 * before changing anything; the regression test pins both directions.
	 *
	 * @param string $hash_algorithm the hash algorithm to use.
	 * @param string $email_address the email address to normalize and hash.
	 * @return string the normalized and hashed email address, or '' if nothing is left to hash.
	 */
	public static function normalize_and_hash_email_address( string $hash_algorithm, string $email_address ): string {
		return EcommerceHelpers::normalize_and_hash_email_address( $hash_algorithm, $email_address );
	}

	/**
	 * Digit-count bounds of an E.164 number, excluding the leading "+".
	 *
	 * The maximum is the standard's own hard limit. The minimum is deliberately
	 * below the shortest real number (Saint Helena, +290 plus four digits) so it
	 * only ever rejects junk like "-" or "n/a", never a valid number: a validator
	 * that encodes today's formats rejects tomorrow's (upstream UC-5).
	 */
	private const E164_MAX_DIGITS = 15;
	private const E164_MIN_DIGITS = 5;

	/**
	 * Matches a phone extension written after the number itself.
	 *
	 * Cut before the digits are harvested, or the extension is absorbed into the
	 * subscriber number and the result is a well-formed number nobody has. No
	 * leading \b: "0121 234 5678x22" has no word boundary before the "x".
	 */
	private const EXTENSION_PATTERN = '/(?:extension|ext|x|#)\W*\d+\s*$/i';

	/**
	 * Matches the trunk prefix printed in parentheses inside an international
	 * number - "+49 (0) 30 12345678".
	 *
	 * The convention means "dial this digit only from inside the country", so it
	 * is exactly the digit E.164 must not carry. Removed unconditionally: the
	 * pattern is a lone zero in brackets, so an area code in brackets - "(030)"
	 * - does not match and is left alone. Standard on business stationery across
	 * the German-speaking countries and the Netherlands, and taking the "+" form
	 * at face value silently mangled every one of them.
	 */
	private const COURTESY_ZERO_PATTERN = '/\(\s*0\s*\)/';

	/**
	 * Whether a string of digits is a number this country's numbering plan has.
	 *
	 * Used ONLY to choose between two readings of the same digits, never to
	 * reject one. A number the plan does not recognise is not refused here - the
	 * caller falls through to positional rules that predate this test - so a
	 * pattern that has gone stale can fail to improve a number but can never
	 * throw one away (upstream UC-5).
	 *
	 * "#" is the delimiter because a national-number pattern is a digit grammar
	 * that cannot contain one, and tools/generate-phone-table.php refuses to write
	 * a pattern outside that grammar rather than leaving it to be discovered here.
	 * A pattern that somehow fails to compile makes preg_match() return false,
	 * which is not 1, so the answer is "cannot tell" and the caller falls through
	 * - the same direction as an unrecognised number.
	 *
	 * @param string $digits         Bare digits, no "+" and no separators.
	 * @param string $number_pattern The country's general national-number pattern.
	 * @return bool
	 */
	private static function is_national_number( string $digits, string $number_pattern ): bool {
		if ( '' === $digits || '' === $number_pattern ) {
			return false;
		}

		return 1 === preg_match( '#^(?:' . $number_pattern . ')$#', $digits );
	}

	/**
	 * Assembles a validated E.164 string from bare digits.
	 *
	 * @param string $digits The digits of the number, without a leading "+".
	 * @return string The E.164 number, or '' when the digit count is implausible.
	 */
	private static function to_e164( string $digits ): string {
		$length = strlen( $digits );
		if ( $length < self::E164_MIN_DIGITS || $length > self::E164_MAX_DIGITS ) {
			return '';
		}

		return '+' . $digits;
	}

	/**
	 * Converts a WooCommerce billing phone number into E.164 format.
	 *
	 * Google requires this exact format before hashing: "Format phone numbers
	 * according to the E164 standard." A number that is merely lowercased and
	 * stripped of spaces still fails to match, silently, because a hash either
	 * matches or does not and nothing reports which.
	 *
	 * An explicit international form is taken at face value. Everything else is
	 * anchored to the order's country using the three columns of
	 * CountryPhoneData, which is GENERATED from libphonenumber's metadata
	 * (tools/generate-phone-table.php):
	 *
	 * - the **calling code**;
	 * - the **national (trunk) prefix, or null** - null meaning the country has
	 *   none at all, so a leading zero is part of the number rather than
	 *   something to strip. Italy is the commercially important case: reading
	 *   "no trunk prefix" as "uses 0" made every Italian landline hash to a value
	 *   Google could never match;
	 * - the **general national-number pattern**, which is what decides between
	 *   two readings of the same digits. Positional rules cannot: "34 612 345
	 *   678" from a Spanish address is the international form with the "+" left
	 *   off, while "391 234 5678" from an Italian one is a national mobile that
	 *   happens to begin with Italy's own calling code, and no amount of counting
	 *   digits separates those two. Neither do possible lengths, which is what an
	 *   earlier version of this comment claimed was needed - both Italian
	 *   readings are possible Italian lengths.
	 *
	 * The pattern is a tie-breaker, never a validator: a number it does not
	 * recognise falls through to the positional rules rather than being refused,
	 * so a pattern that has gone stale can fail to improve a number but cannot
	 * throw one away (upstream UC-5).
	 *
	 * Not modelled, deliberately - each returns a wrong number rather than none,
	 * and each needs TRANSFORM RULES rather than facts, which is where the line
	 * is drawn. See tools/generate-phone-table.php.
	 *
	 * - Argentina's mobile "9" and Brazil's carrier selection codes, which need
	 *   libphonenumber's nationalPrefixTransformRule.
	 * - Dialling a foreign number with a national international-access code other
	 *   than "00" (US "011", JP "010", AU "0011", RU "810").
	 *
	 * Returns '' whenever the number cannot be placed with confidence. The caller
	 * decides what to do with that; inventing a country would produce a hash that
	 * can never match, which is worse than sending nothing.
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web Google Ads: normalization rules before hashing.
	 *
	 * @param string $phone_number The phone number as the customer typed it.
	 * @param string $country_code ISO 3166-1 alpha-2 country code of the billing address.
	 * @return string The number in E.164 format, or '' if it cannot be normalized.
	 */
	public static function normalize_phone_number( string $phone_number, string $country_code = '' ): string {
		$phone_number = trim( $phone_number );
		if ( '' === $phone_number ) {
			return '';
		}

		// Both run BEFORE the digits are harvested: an extension and a courtesy
		// zero are digits, and once they are in the string nothing downstream can
		// tell them from the number.
		$phone_number = (string) preg_replace( self::EXTENSION_PATTERN, '', $phone_number );
		$phone_number = (string) preg_replace( self::COURTESY_ZERO_PATTERN, '', $phone_number );

		$has_plus = str_starts_with( ltrim( $phone_number ), '+' );
		$digits   = (string) preg_replace( '/\D+/', '', $phone_number );
		if ( '' === $digits ) {
			return '';
		}

		// Already international: a leading "+", or the "00" call prefix.
		if ( $has_plus ) {
			return self::to_e164( $digits );
		}

		if ( str_starts_with( $digits, '00' ) ) {
			return self::to_e164( substr( $digits, 2 ) );
		}

		$dialling = CountryPhoneData::lookup( $country_code );
		if ( null === $dialling ) {
			return '';
		}

		list( $calling_code, $trunk_prefix, $number_pattern ) = $dialling;

		// 1. A trunk prefix the plan confirms. Stripping is only right when what
		// is left is a number this country actually has - which is also what stops
		// a number that merely begins with the trunk digit from being cut.
		if ( null !== $trunk_prefix && str_starts_with( $digits, $trunk_prefix )
			&& self::is_national_number( substr( $digits, strlen( $trunk_prefix ) ), $number_pattern ) ) {
			return self::to_e164( $calling_code . substr( $digits, strlen( $trunk_prefix ) ) );
		}

		// 2. The international form with the "+" left off, decided the way
		// libphonenumber decides it: the whole string is NOT a national number
		// here, and the remainder after the calling code IS. Both halves matter.
		// Without the first, an Italian mobile in the 39x range - which begins
		// with Italy's own calling code - would be cut down to a number one
		// operator short. Without the second, a Spanish number typed as
		// "34 612 345 678" would have "34" prepended a second time.
		if ( str_starts_with( $digits, $calling_code )
			&& ! self::is_national_number( $digits, $number_pattern )
			&& self::is_national_number( substr( $digits, strlen( $calling_code ) ), $number_pattern ) ) {
			return self::to_e164( $digits );
		}

		// 3. A local number the plan recognises exactly as typed.
		if ( self::is_national_number( $digits, $number_pattern ) ) {
			return self::to_e164( $calling_code . $digits );
		}

		// 4. The plan recognises neither reading: a typo, a range it has not
		// caught up with, or a pattern of ours that has gone stale. Fall back to
		// the positional rules that predate the pattern column, so the worst this
		// column can do is fail to improve a number.
		//
		// Deliberately uniform across countries, including the ones with no trunk
		// prefix. The asymmetry that used to live here - anchor and stop, without
		// trying the calling code - existed only because the trunk column carried
		// a default that could not say "this country has none"; it is not a rule
		// anybody chose. For input the plan rejects either way no reading is
		// defensible, so this applies one set of rules rather than preserving a
		// historical accident in the one branch nothing tests.
		if ( null !== $trunk_prefix && str_starts_with( $digits, $trunk_prefix )
			&& strlen( $digits ) - strlen( $trunk_prefix ) >= 4 ) {
			return self::to_e164( $calling_code . substr( $digits, strlen( $trunk_prefix ) ) );
		}

		if ( str_starts_with( $digits, $calling_code ) && strlen( $digits ) - strlen( $calling_code ) >= 4 ) {
			return self::to_e164( $digits );
		}

		return self::to_e164( $calling_code . $digits );
	}

	/**
	 * Returns the result of normalizing a phone number to E.164 and hashing it.
	 *
	 * @param string $hash_algorithm The hash algorithm to use.
	 * @param string $phone_number   The phone number to normalize and hash.
	 * @param string $country_code   ISO 3166-1 alpha-2 country code of the billing address.
	 * @return string The hashed E.164 number, or '' if it cannot be normalized.
	 */
	public static function normalize_and_hash_phone_number( string $hash_algorithm, string $phone_number, string $country_code = '' ): string {
		$normalized = self::normalize_phone_number( $phone_number, $country_code );
		if ( '' === $normalized ) {
			return '';
		}

		return hash( $hash_algorithm, $normalized );
	}
}
