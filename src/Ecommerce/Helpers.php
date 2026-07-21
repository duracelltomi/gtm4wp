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
			$cat_hierarchy = trim( $category_parent_list, '/' );
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
				$product_category = $category_data->name;
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
			return $gtm4wp_product_terms[0]->name;
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
}
