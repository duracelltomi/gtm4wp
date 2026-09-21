<?php
/**
 * Store-agnostic e-commerce helper functions.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Ecommerce;

use GTM4WP\Frontend\ScriptTag;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers shared by every e-commerce integration module (WooCommerce,
 * Easy Digital Downloads): Google Ads business verticals, product id
 * prefixing, taxonomy/category readers, Enhanced Conversions hashing and the
 * browser-side purchase dedupe guard. Nothing in here may call a
 * store-specific function - store specifics stay in the module's own helpers.
 */
final class Helpers {

	/**
	 * Valid Google Ads business vertical ids. The labels shown on the
	 * admin page live in the module AdminSchema classes.
	 *
	 * @var string[]
	 */
	public const BUSINESS_VERTICALS = array(
		'retail',
		'education',
		'flights',
		'hotel_rental',
		'jobs',
		'local',
		'real_estate',
		'travel',
		'custom',
	);

	/**
	 * Business verticals that use a different name for their "id" field in
	 * Google Ads dynamic remarketing tagging.
	 *
	 * @var array<string, string>
	 */
	public const BUSINESS_VERTICALS_IDS = array(
		'flights' => 'destination',
		'travel'  => 'destination',
	);

	/**
	 * Name of the browser-side duplicate-purchase guard (localStorage, cookie
	 * fallback), shared by every store integration.
	 *
	 * THE VALUE IS THE ORDER NUMBER, VERBATIM - not the order id, not escaped.
	 * Three sites must agree byte for byte or the guard silently stops matching:
	 * self::purchase_dedupe_guard() (writes it raw via wp_json_encode),
	 * js/frontend/gtm4wp-visitor-data.js ORDER_TRACKED_KEY (String(orderNumber))
	 * and WooCommerce ProductData::is_purchase_already_tracked() (the read). On a
	 * default install number == id, so a mismatch only surfaces with a
	 * sequential/prefixed order-number plugin.
	 */
	public const ORDER_TRACKED_COOKIE = 'gtm4wp_orderid_tracked';

	/**
	 * First-party cookie carrying GA4 list attribution across the funnel (#405),
	 * written client-side on select_item; must match the literal in
	 * js/frontend/gtm4wp-ecommerce-generic.js.
	 */
	public const LIST_ATTRIBUTION_COOKIE = 'gtm4wp_item_list_attr';

	/**
	 * JavaScript function the server-rendered view_item push is wrapped in so the
	 * list attribution is merged in the browser (#405). Must match the window
	 * export in js/frontend/gtm4wp-ecommerce-generic.js; a mismatch is silent
	 * (identity fallback, event fires without attribution).
	 */
	public const LIST_ATTRIBUTION_JS_WRAPPER = 'gtm4wp_apply_stored_item_list_to_event';

	/**
	 * Hard caps so a crafted cookie never makes the reader do unbounded work. The
	 * byte cap is measured on the DECODED value ($_COOKIE is URL-decoded), while
	 * the writer's GTM4WP_LIST_ATTR_MAX_BYTES counts encoded bytes; they are not a
	 * pair to keep in sync - this one only rejects a cookie no browser wrote.
	 */
	public const LIST_ATTRIBUTION_MAX_ENTRIES      = 20;
	public const LIST_ATTRIBUTION_COOKIE_MAX_BYTES = 4096;

	/**
	 * Reads and validates the list-attribution cookie (#405) into a map of
	 * product id => array( item_list_name, item_list_id ). Untrusted input: absint
	 * on ids, sanitize_text_field on names, sanitize_title on list ids, returned
	 * RAW so the wp_json_encode() sink escapes once. Malformed or oversized yields
	 * an empty map. The loop visits every decoded entry, so the work bound is the
	 * byte cap, not the entry cap - keep the byte cap if the entry cap is relaxed.
	 *
	 * @return array<int, array{item_list_name: string, item_list_id: string}>
	 */
	public static function read_item_list_cookie(): array {
		if ( ! isset( $_COOKIE[ self::LIST_ATTRIBUTION_COOKIE ] ) ) {
			return array();
		}

		// A JSON container, not an output value: a blanket sanitizer would corrupt
		// it, so it is unslashed only and each field is sanitized after decoding.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_COOKIE[ self::LIST_ATTRIBUTION_COOKIE ] );
		if ( ! is_string( $raw ) || strlen( $raw ) > self::LIST_ATTRIBUTION_COOKIE_MAX_BYTES ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$map   = array();
		$count = 0;
		foreach ( $decoded as $product_id => $entry ) {
			if ( $count >= self::LIST_ATTRIBUTION_MAX_ENTRIES ) {
				break;
			}

			$pid = absint( $product_id );
			if ( $pid <= 0 || ! is_array( $entry ) ) {
				continue;
			}

			$name = isset( $entry['item_list_name'] ) ? sanitize_text_field( (string) $entry['item_list_name'] ) : '';
			if ( '' === $name ) {
				continue;
			}

			$id = ( isset( $entry['item_list_id'] ) && '' !== $entry['item_list_id'] )
				? sanitize_title( (string) $entry['item_list_id'] )
				: sanitize_title( $name );

			$map[ $pid ] = array(
				'item_list_name' => $name,
				'item_list_id'   => $id,
			);
			++$count;
		}

		return $map;
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
		if ( '' !== $prefix ) {
			return $prefix . $product_id;
		}

		return $product_id;
	}

	/**
	 * Undoes the HTML encoding WordPress applies to a term name on save
	 * (`pre_term_name` runs `_wp_specialchars()`, so "Shirts & Ties" is stored as
	 * "Shirts &amp; Ties"). `wp_specialchars_decode()` with ENT_QUOTES is the
	 * exact inverse. Safe because the result is a data value every sink escapes
	 * for its own context afterwards (wp_json_encode hex flags, esc_attr) - not
	 * the blanket decode of script output RI-3 warns about. Shared so WooCommerce
	 * and EDD categories are reported the same way.
	 *
	 * @param string $name A term name as WordPress stored it.
	 * @return string The name as it was typed.
	 */
	public static function decode_term_name( string $name ): string {
		return wp_specialchars_decode( $name, ENT_QUOTES );
	}

	/**
	 * Given a category ID, this function returns the full path to this category separated with the / character.
	 *
	 * @param int    $category_id The ID of the category that needs to be scanned for parents.
	 * @param string $category_taxonomy The name of the WordPress taxonomy where the category ID needs to be searched.
	 * @return string The category path. An example output can be: Home/Clothing/Toddlers.
	 */
	public static function get_product_category_hierarchy( $category_id, string $category_taxonomy = 'product_cat' ): string {
		$cat_hierarchy = '';

		$category_parent_list = get_term_parents_list(
			$category_id,
			$category_taxonomy,
			array(
				'format'    => 'name',
				'separator' => '/',
				'link'      => false,
				'inclusive' => true,
			)
		);

		if ( is_string( $category_parent_list ) ) {
			// Decoded after joining: the encoding never touches the separator, so
			// no decoded name can introduce a level boundary.
			$cat_hierarchy = self::decode_term_name( trim( $category_parent_list, '/' ) );
		}

		return $cat_hierarchy;
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
		$product_category    = '';
		$primary_category_id = false;
		$category_data       = false;

		if ( function_exists( 'yoast_get_primary_term_id' ) ) {
			$primary_category_id = yoast_get_primary_term_id( $category_taxonomy, $product_id );
		} elseif ( function_exists( 'rank_math' ) ) {
			$rank_math_data = get_post_meta( $product_id, 'rank_math_primary_' . $category_taxonomy, true );
			if ( ! empty( $rank_math_data ) && intval( $rank_math_data ) ) {
				$primary_category_id = $rank_math_data;
			}
		}

		if ( false === $primary_category_id ) {
			$product_categories = wp_get_post_terms(
				$product_id,
				$category_taxonomy,
				array(
					'orderby' => 'parent',
					'order'   => 'ASC',
				)
			);

			if ( ( is_array( $product_categories ) ) && ( count( $product_categories ) > 0 ) ) {
				$category_data = array_pop( $product_categories );
			}
		} else {
			$category_data = get_term( $primary_category_id, $category_taxonomy );
			if ( is_wp_error( $category_data ) || is_null( $category_data ) ) {
				$category_data = false;
			}
		}

		if ( false !== $category_data ) {
			if ( $fullpath ) {
				$product_category = self::get_product_category_hierarchy( $category_data->term_id, $category_taxonomy );
			} elseif ( isset( $category_data->name ) ) {
				$product_category = self::decode_term_name( (string) $category_data->name );
			}
		}

		return $product_category;
	}

	/**
	 * Given a product ID, this function returns the assigned value of a custom taxonomy like the brand name.
	 *
	 * @param int    $product_id A product ID whose taxonomy association needs to be queried.
	 * @param string $taxonomy The taxonomy slug for which to retrieve terms.
	 * @return string Returns the first assigned taxonomy value of the given product ID.
	 */
	public static function get_product_term( $product_id, string $taxonomy ): string {
		$gtm4wp_product_terms = wp_get_post_terms(
			$product_id,
			$taxonomy,
			array(
				'orderby' => 'parent',
				'order'   => 'ASC',
			)
		);

		if ( is_array( $gtm4wp_product_terms ) && ( count( $gtm4wp_product_terms ) > 0 ) ) {
			return self::decode_term_name( (string) $gtm4wp_product_terms[0]->name );
		}

		return '';
	}

	/**
	 * Given a Google Business vertical ID, this function returns the name of the "ID" field in tagging
	 * Google Ads dynamic remarketing. This is "id" in most cases, but sometimes "destination".
	 *
	 * @param string $vertical_id The Google Business vertical ID (like retail, flights, etc.).
	 * @return string The name of the "ID" field for tagging.
	 */
	public static function get_gads_product_id_variable_name( string $vertical_id ): string {
		if ( array_key_exists( $vertical_id, self::BUSINESS_VERTICALS_IDS ) ) {
			return self::BUSINESS_VERTICALS_IDS[ $vertical_id ];
		}

		return 'id';
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
		// Normalizes by first converting all characters to lowercase, then trimming spaces.
		$normalized = strtolower( $value );
		if ( true === $trim_intermediate_spaces ) {
			// Removes leading, trailing, and intermediate spaces.
			$normalized = str_replace( ' ', '', $normalized );
		} else {
			// Removes only leading and trailing spaces.
			$normalized = trim( $normalized );
		}

		if ( '' === $normalized ) {
			return '';
		}

		return hash( $hash_algorithm, $normalized );
	}

	/**
	 * Returns the result of normalizing and hashing an email address. For
	 * gmail.com / googlemail.com ONLY, the local part is folded the way those
	 * mailboxes resolve: dots removed, '+' and everything after it dropped. Two
	 * deliberate deviations from Google's PHP sample, pinned by the regression
	 * test - do NOT "restore" either: the sample implements only the dot rule
	 * (the plus rule went missing that way, #321), and the split here is on the
	 * FIRST '@' so a string with several '@' is hashed as typed.
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web Google Ads: the normalization rules, in prose.
	 *
	 * @param string $hash_algorithm the hash algorithm to use.
	 * @param string $email_address the email address to normalize and hash.
	 * @return string the normalized and hashed email address, or '' if nothing is left to hash.
	 */
	public static function normalize_and_hash_email_address( string $hash_algorithm, string $email_address ): string {
		$normalized_email = strtolower( $email_address );
		$email_parts      = explode( '@', $normalized_email, 2 );
		if (
			count( $email_parts ) > 1
			// Anchored at both ends: unanchored also matches "gmail.com.example.com".
			// \s* absorbs trailing whitespace normalize_and_hash() strips later.
			&& preg_match( '/^(gmail|googlemail)\.com\s*$/', $email_parts[1] )
		) {
			$email_parts[0] = str_replace( '.', '', $email_parts[0] );

			$plus_position = strpos( $email_parts[0], '+' );
			if ( false !== $plus_position ) {
				$email_parts[0] = substr( $email_parts[0], 0, $plus_position );
			}

			// A local part that was only a tag leaves nothing to hash.
			if ( '' === $email_parts[0] ) {
				return '';
			}

			$normalized_email = sprintf( '%s@%s', $email_parts[0], $email_parts[1] );
		}

		return self::normalize_and_hash( $hash_algorithm, $normalized_email, true );
	}

	/**
	 * Builds the browser-side duplicate-tracking guard around a purchase push: a
	 * "before" fragment that pushes only when the order number is not yet
	 * recorded in localStorage / the cookie, and an "after" fragment that records
	 * it. The cookie idiom is the PHP-side copy of js/frontend/lib/gtm4wp-cookies.js
	 * (an inline script cannot import a module); key and format must stay
	 * compatible with it and with gtm4wp-visitor-data.js (see ORDER_TRACKED_COOKIE).
	 *
	 * @param string $order_number The order number to guard on (raw; escaped here).
	 * @return array{0:string,1:string} The before and after JavaScript fragments.
	 */
	public static function purchase_dedupe_guard( string $order_number ): array {
		// A JSON string literal (quotes included) with the hex flags: the RI-2
		// escaper for a <script> body. Do NOT use esc_js(): it is an HTML-attribute
		// ENCODING that rewrote &/"/< in the order number, so this guard and the one
		// gtm4wp-visitor-data.js writes to the same key stopped matching (PA-4).
		// json_literal(), not bare wp_json_encode(): a false return would render as
		// '' inside an expression and take the whole guard down as a SyntaxError
		// (RI-21/#141) - it cannot fail on a string today, but the exemption is not
		// worth documenting.
		$order_number_literal = ScriptTag::json_literal(
			$order_number,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		$storage_key = self::ORDER_TRACKED_COOKIE;

		$before_purchase_dl_push = '
			// Check whether this order has been already tracked in this browser.

			// Read order number already tracked from cookies or local storage.
			let gtm4wp_orderid_tracked = "";

			if ( !window.localStorage ) {
				let gtm4wp_cookie = "; " + document.cookie;
				let gtm4wp_cookie_parts = gtm4wp_cookie.split( "; ' . $storage_key . '=" );
				if ( gtm4wp_cookie_parts.length == 2 ) {
					gtm4wp_orderid_tracked = gtm4wp_cookie_parts.pop().split(";").shift();
				}
			} else {
				gtm4wp_orderid_tracked = window.localStorage.getItem( "' . $storage_key . '" );
			}

			// Check whether this order has been already tracked before in this browser.
			let gtm4wp_order_already_tracked = false;
			if ( gtm4wp_orderid_tracked && ( ' . $order_number_literal . ' == gtm4wp_orderid_tracked ) ) {
				gtm4wp_order_already_tracked = true;
			}

			// only push purchase action if not tracked already.
			if ( !gtm4wp_order_already_tracked ) {';

		$after_purchase_dl_push = '
			}

			// Store the order number to prevent tracking this purchase again.
			if ( !window.localStorage ) {
				var gtm4wp_orderid_cookie_expire = new Date();
				gtm4wp_orderid_cookie_expire.setTime( gtm4wp_orderid_cookie_expire.getTime() + (365*24*60*60*1000) );
				var gtm4wp_orderid_cookie_expires_part = "expires=" + gtm4wp_orderid_cookie_expire.toUTCString();
				document.cookie = "' . $storage_key . '=" + ' . $order_number_literal . ' + ";" + gtm4wp_orderid_cookie_expires_part + ";path=/";
			} else {
				window.localStorage.setItem( "' . $storage_key . '", ' . $order_number_literal . ' );
			}';

		return array( $before_purchase_dl_push, $after_purchase_dl_push );
	}

	/**
	 * Digit-count bounds of an E.164 number, excluding the "+". The maximum is the
	 * standard's limit; the minimum is deliberately below the shortest real number
	 * (Saint Helena, +290 plus four digits) so it only rejects junk (UC-5).
	 */
	private const E164_MAX_DIGITS = 15;
	private const E164_MIN_DIGITS = 5;

	/**
	 * A phone extension after the number, cut before the digits are harvested.
	 * No leading \b: "0121 234 5678x22" has no word boundary before the "x".
	 */
	private const EXTENSION_PATTERN = '/(?:extension|ext|x|#)\W*\d+\s*$/i';

	/**
	 * The trunk prefix printed in parentheses inside an international number,
	 * "+49 (0) 30 12345678" - exactly the digit E.164 must not carry. A lone zero
	 * only, so an area code in brackets "(030)" is left alone.
	 */
	private const COURTESY_ZERO_PATTERN = '/\(\s*0\s*\)/';

	/**
	 * Whether a string of digits is a number this country's numbering plan has.
	 * Used ONLY to choose between two readings, never to reject one: the caller
	 * falls through to positional rules, so a stale pattern can fail to improve a
	 * number but never throws one away (UC-5). "#" delimits because a digit
	 * grammar cannot contain it (tools/generate-phone-table.php enforces that); a
	 * pattern that fails to compile returns false, i.e. "cannot tell".
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
	 * Converts a store billing phone number into E.164, which Google requires
	 * before hashing. An explicit international form is taken at face value;
	 * everything else is anchored to the order's country via the three
	 * CountryPhoneData columns, GENERATED from libphonenumber
	 * (tools/generate-phone-table.php): the calling code; the trunk prefix, or
	 * null when the country has none (Italy: a leading zero is part of the
	 * number); and the general national-number pattern, the only thing that can
	 * separate "34 612 345 678" (Spanish, "+" left off) from "391 234 5678"
	 * (Italian mobile beginning with Italy's own calling code). The pattern is a
	 * tie-breaker, never a validator (UC-5).
	 *
	 * Deliberately not modelled (each needs transform rules, not facts):
	 * Argentina's mobile "9" and Brazil's carrier codes; international-access
	 * codes other than "00" (US "011", JP "010", AU "0011", RU "810").
	 *
	 * Returns '' when the number cannot be placed with confidence: an invented
	 * country would produce a hash that can never match.
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

		// Both BEFORE the digits are harvested; afterwards they are just digits.
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

		// 1. A trunk prefix the plan confirms: strip only when what is left is a
		// number this country has.
		if ( null !== $trunk_prefix && str_starts_with( $digits, $trunk_prefix )
			&& self::is_national_number( substr( $digits, strlen( $trunk_prefix ) ), $number_pattern ) ) {
			return self::to_e164( $calling_code . substr( $digits, strlen( $trunk_prefix ) ) );
		}

		// 2. The international form with the "+" left off, decided as libphonenumber
		// does: the whole string is NOT a national number and the remainder after
		// the calling code IS. Both halves matter (Italian 39x mobiles; Spanish
		// "34 612 345 678").
		if ( str_starts_with( $digits, $calling_code )
			&& ! self::is_national_number( $digits, $number_pattern )
			&& self::is_national_number( substr( $digits, strlen( $calling_code ) ), $number_pattern ) ) {
			return self::to_e164( $digits );
		}

		// 3. A local number the plan recognises exactly as typed.
		if ( self::is_national_number( $digits, $number_pattern ) ) {
			return self::to_e164( $calling_code . $digits );
		}

		// 4. The plan recognises neither reading (typo, new range, stale pattern):
		// fall back to the positional rules, uniform across countries, so the
		// worst the pattern column can do is fail to improve a number.
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
