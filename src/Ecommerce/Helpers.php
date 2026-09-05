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
	 * Name of the browser-side duplicate-purchase guard, stored in localStorage
	 * (falling back to a cookie). Shared by every store integration so all
	 * purchase events consult the same browser guard.
	 *
	 * THE VALUE IS THE ORDER NUMBER, VERBATIM - not the order id, and not escaped.
	 * Every site touching this key must agree byte for byte, or the guard
	 * silently stops matching and a purchase is counted twice:
	 *
	 * 1. self::purchase_dedupe_guard() below - the inline JS on the
	 *    order-received page (writes it with wp_json_encode, so the value is raw).
	 * 2. js/frontend/gtm4wp-visitor-data.js (ORDER_TRACKED_KEY) - the cache-safe
	 *    reliable-purchase fallback (writes String(payload.orderNumber), raw).
	 * 3. WooCommerce ProductData::is_purchase_already_tracked() - the
	 *    server-side read (via the delegating ProductData::ORDER_TRACKED_COOKIE).
	 *
	 * On a default install the order number IS the numeric order id, so every
	 * spelling coincides and a mismatch stays invisible; it only surfaces on
	 * stores using a sequential/prefixed order-number plugin, or an order number
	 * containing a character an escaper would rewrite.
	 */
	public const ORDER_TRACKED_COOKIE = 'gtm4wp_orderid_tracked';

	/**
	 * Name of the first-party cookie that carries GA4 list attribution
	 * (item_list_name / item_list_id keyed by product/download id) across the
	 * funnel (#405). Written client-side by the store trackers on a
	 * select_item list click; must match the literal used in
	 * js/frontend/gtm4wp-ecommerce-generic.js.
	 */
	public const LIST_ATTRIBUTION_COOKIE = 'gtm4wp_item_list_attr';

	/**
	 * Name of the JavaScript function the server-rendered product-detail
	 * view_item push is wrapped in so the list attribution is merged in the
	 * browser instead of being baked into cacheable HTML (#405).
	 *
	 * PHP writes this identifier into a <script> body and JS has to define it
	 * under exactly this name: it must match the window export at the bottom of
	 * js/frontend/gtm4wp-ecommerce-generic.js. A mismatch is silent - the
	 * emitted call falls back to an identity function, so the event still fires,
	 * just without the attribution.
	 */
	public const LIST_ATTRIBUTION_JS_WRAPPER = 'gtm4wp_apply_stored_item_list_to_event';

	/**
	 * Hard caps on the list-attribution cookie so a crafted or bloated cookie
	 * can never make the reader do unbounded work: entries beyond the limit are
	 * dropped and an oversized cookie is ignored wholesale.
	 *
	 * The byte cap is measured on the DECODED value, because PHP URL-decodes
	 * $_COOKIE before we see it, while the writer's own budget
	 * (GTM4WP_LIST_ATTR_MAX_BYTES in js/frontend/gtm4wp-ecommerce-generic.js) is
	 * measured on the encoded bytes a browser counts against its ~4096-byte
	 * per-cookie limit. The same number therefore means different things on the
	 * two sides and they are not a pair to keep in sync: anything a browser
	 * accepted decodes to well under this cap, so this one only ever rejects a
	 * cookie no browser wrote.
	 */
	public const LIST_ATTRIBUTION_MAX_ENTRIES      = 20;
	public const LIST_ATTRIBUTION_COOKIE_MAX_BYTES = 4096;

	/**
	 * Reads and validates the first-party list-attribution cookie (#405) into a
	 * map of product id => array( item_list_name, item_list_id ). The cookie is
	 * untrusted client input, so every part is sanitized here: the id via absint,
	 * the list name via sanitize_text_field and the id via sanitize_title. The
	 * sanitized values are returned RAW (not entity-encoded) so the downstream
	 * wp_json_encode() dataLayer sink can escape them once and correctly. A
	 * malformed, non-JSON or oversized cookie yields an empty map, and no more
	 * than LIST_ATTRIBUTION_MAX_ENTRIES entries are ever ACCEPTED. Note that the
	 * loop still visits every decoded entry, so the bound on the work done is the
	 * LIST_ATTRIBUTION_COOKIE_MAX_BYTES cap above (a few hundred entries at most),
	 * not the entry cap - keep that byte cap if you ever relax the entry cap.
	 *
	 * @return array<int, array{item_list_name: string, item_list_id: string}>
	 */
	public static function read_item_list_cookie(): array {
		if ( ! isset( $_COOKIE[ self::LIST_ATTRIBUTION_COOKIE ] ) ) {
			return array();
		}

		// The raw value is a JSON container, not a value used at any output sink; it
		// is json_decode'd below and every extracted field is individually sanitized
		// (absint on ids, sanitize_text_field on names, sanitize_title on ids). A
		// blanket sanitizer here would corrupt valid multi-entry JSON, so it is
		// unslashed only and each field is sanitized after decoding.
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
	 * Undoes the HTML encoding WordPress applies to a term name when it is saved.
	 *
	 * WordPress runs every term name through `_wp_specialchars()` on save (the
	 * `pre_term_name` filter, `wp-includes/default-filters.php`), so a category
	 * called "Shirts & Ties" is stored, and read back, as "Shirts &amp; Ties".
	 * Written into the data layer unchanged, that is what GA4 reports and what a
	 * GTM trigger has to be written against.
	 *
	 * `wp_specialchars_decode()` with ENT_QUOTES is the exact inverse of what was
	 * applied, so it reverses that encoding and nothing else. The decoded value is
	 * a data value, not markup: every sink it reaches escapes for its own context
	 * afterwards (the data layer through `wp_json_encode()` with the hex flags,
	 * the product-data attribute through `esc_attr()`), which is why decoding here
	 * is safe and is not the blanket decode of script output RI-3 warns about.
	 *
	 * Lives here rather than beside either integration, so a WooCommerce product
	 * category and an Easy Digital Downloads download category are reported the
	 * same way.
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
			// Decoded after the path is joined, never before: the encoding
			// WordPress applies does not touch the separator, so no decoded name
			// can introduce a level boundary that was not there.
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
	 * Returns the result of normalizing and hashing an email address.
	 *
	 * For gmail.com and googlemail.com Google folds the local part the way those
	 * mailboxes actually resolve, before hashing: all '.' characters removed, and
	 * a '+' together with everything after it discarded. Both rules apply to
	 * those two domains ONLY - everywhere else "jane.doe" and "janedoe" are
	 * different mailboxes, and stripping either would hash the wrong person.
	 *
	 * Note which of Google's two artifacts this follows. Their prose documents
	 * both rules; the PHP sample on the same page implements only the dot rule,
	 * and this function was originally ported from that sample (#321), which is
	 * how the plus rule went missing. Do not "restore" this to match the sample.
	 *
	 * The split below deviates from that sample a second time, deliberately:
	 * everything after the FIRST '@' is the domain (explode limit 2), so a
	 * string carrying more than one '@' is never treated as a foldable
	 * mailbox - it is hashed exactly as typed. Ordinary addresses split
	 * identically either way. Do not "restore" the sample's unlimited split;
	 * the regression test pins both directions.
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
			// Anchored at both ends on purpose: an unanchored match also accepts
			// "gmail.com.example.com", and folding a third party's local part
			// would silently hash a value that can never match. The \s* absorbs
			// trailing whitespace, which normalize_and_hash() strips afterwards.
			&& preg_match( '/^(gmail|googlemail)\.com\s*$/', $email_parts[1] )
		) {
			$email_parts[0] = str_replace( '.', '', $email_parts[0] );

			$plus_position = strpos( $email_parts[0], '+' );
			if ( false !== $plus_position ) {
				$email_parts[0] = substr( $email_parts[0], 0, $plus_position );
			}

			// A local part that was nothing but a tag leaves no address behind,
			// and hashing a bare "@gmail.com" would be a value no lookup can
			// ever match - so there is nothing honest to return but ''.
			if ( '' === $email_parts[0] ) {
				return '';
			}

			$normalized_email = sprintf( '%s@%s', $email_parts[0], $email_parts[1] );
		}

		return self::normalize_and_hash( $hash_algorithm, $normalized_email, true );
	}

	/**
	 * Builds the browser-side duplicate-tracking guard wrapped around a purchase
	 * push: a "before" fragment that only pushes when this order number is not
	 * already recorded in the cookie / local storage, and an "after" fragment
	 * that records it. Shared by every store integration so all purchase events
	 * consult the same browser guard.
	 *
	 * The cookie read/write idiom emitted below is the PHP-side copy of the
	 * shared helpers in js/frontend/lib/gtm4wp-cookies.js (this inline script
	 * cannot import a bundle module); the storage key and byte format must stay
	 * compatible with that lib and with gtm4wp-visitor-data.js, which reuses the
	 * same guard for the fallback purchase. See the contract on
	 * self::ORDER_TRACKED_COOKIE - all sites store the order number verbatim.
	 *
	 * @param string $order_number The order number to guard on (raw; escaped here).
	 * @return array{0:string,1:string} The before and after JavaScript fragments.
	 */
	public static function purchase_dedupe_guard( string $order_number ): array {
		// Emitted as a JSON string literal (quotes included, so it is NOT wrapped
		// in quotes below) with the full hex flag set - the RI-2 escaper for an
		// inline-script context. It replaces an esc_js() call, which was wrong on
		// two counts: esc_js() is for HTML-attribute JS, not a raw <script> body
		// (PA-4), and it is an ENCODING - it rewrote &, " and < in the order
		// number to &amp;/&quot;/&lt;, which this inline-script path never decodes.
		// The value stored here therefore differed from the raw order number that
		// gtm4wp-visitor-data.js writes to the very same key, so the two guards
		// stopped recognising each other's entries.
		// json_literal(), not a bare wp_json_encode(): the result is interpolated
		// into three JavaScript EXPRESSION positions below, and the encoder returns
		// false - which PHP renders as '' - for a value it cannot encode, leaving
		// `( == gtm4wp_orderid_tracked )`. That is a SyntaxError taking the whole
		// duplicate-purchase guard with it (RI-21/#141).
		//
		// The value is a (string) cast scalar, so today the encoder cannot actually
		// fail on it. Routed through the shared helper anyway, because an
		// undocumented exemption is indistinguishable from an oversight, and the
		// next person to widen this line should not have to re-derive the argument.
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
	 * Converts a store billing phone number into E.164 format.
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
