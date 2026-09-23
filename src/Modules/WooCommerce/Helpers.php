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
	 * Whether this browser plausibly has WooCommerce state worth delivering: a
	 * presence check over cookies and the login state. Never a value read and
	 * never a session or cart load - touching WC()->cart here would hand a session
	 * cookie to a visitor who has none, on every page of a cache-safe store
	 * (PageDataLayer::oneshot_wc()'s rule on the second delivery channel).
	 * Logged-in counts on its own: the customer half is populated for a logged-in
	 * visitor with an empty cart.
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
	 * JS-readable event cookie the cache-safe data layer (issue #398) sets when a
	 * WooCommerce one-shot event is queued in the session; its presence tells the
	 * client runtime to fetch the session endpoint on the next page, and the client
	 * clears it after delivery. The runtime learns the name from the baked
	 * config (VisitorDataModule::build_config() actions); the cookie_gate in
	 * PageDataLayer::declare_visitor_scoped_fields() names the same constant.
	 */
	public const ONESHOT_EVENT_COOKIE = 'gtm4wp_woo_event';

	/**
	 * Flags a pending one-shot event by setting ONESHOT_EVENT_COOKIE, only when the
	 * cache-safe data layer is on (otherwise the event renders server-side). Called
	 * from the hooks that seed the session markers, which run only on non-cached
	 * requests. Not HttpOnly on purpose (the client reads it); it carries no
	 * visitor value. The 2-day expiry only bounds an undelivered event. Host-only
	 * on purpose (no Domain attribute, unlike the login-gate cookie): the JS
	 * clearer writes no Domain either, and a cookie set with one is a different
	 * cookie the clear would never remove (#270, RI-14).
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
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		// Reflect it into $_COOKIE so any later same-request read sees it set.
		$_COOKIE[ self::ONESHOT_EVENT_COOKIE ] = '1';
	}

	/**
	 * Replace only the first occurrence of the search string, both treated as
	 * literals. Deliberately NOT preg_replace(): its replacement expands $0/\1
	 * backreferences, which would let product data break out of an already
	 * esc_attr'd attribute (PA-7 / RI-17).
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
	 * Per-unit display price for a cart line, from the already-calculated line
	 * totals instead of wc_get_price_to_display() per item (#436, memory
	 * exhaustion). Null when the totals are not available yet, so the caller can
	 * fall back.
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
	 * Per-unit discount for a cart line (line_subtotal minus line_total, same tax
	 * basis as cart_line_display_price()) for GA4's per-item `discount` (#348).
	 * The tax keys are asymmetric: `line_subtotal_tax` but `line_tax`, not
	 * `line_total_tax` - reading a missing key turned the whole line tax into a
	 * phantom discount (#470). Null when unavailable or not discounted, so the
	 * caller omits the field rather than emitting 0.
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
			$total    += (float) ( $cart_item_data['line_tax'] ?? 0 );
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
	 * Returns the result of normalizing and hashing an email address; the folding
	 * rules are documented on GTM4WP\Ecommerce\Helpers.
	 *
	 * @param string $hash_algorithm the hash algorithm to use.
	 * @param string $email_address the email address to normalize and hash.
	 * @return string the normalized and hashed email address, or '' if nothing is left to hash.
	 */
	public static function normalize_and_hash_email_address( string $hash_algorithm, string $email_address ): string {
		return EcommerceHelpers::normalize_and_hash_email_address( $hash_algorithm, $email_address );
	}

	/**
	 * Converts a billing phone number into E.164 format; rules documented on
	 * \GTM4WP\Ecommerce\Helpers::normalize_phone_number().
	 *
	 * @param string $phone_number The phone number as the customer typed it.
	 * @param string $country_code ISO 3166-1 alpha-2 country code of the billing address.
	 * @return string The number in E.164 format, or '' if it cannot be normalized.
	 */
	public static function normalize_phone_number( string $phone_number, string $country_code = '' ): string {
		return EcommerceHelpers::normalize_phone_number( $phone_number, $country_code );
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
		return EcommerceHelpers::normalize_and_hash_phone_number( $hash_algorithm, $phone_number, $country_code );
	}
}
