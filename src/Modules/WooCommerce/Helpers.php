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

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers ported from integration/ecommerce-generic.php of 1.x.
 */
final class Helpers {

	/**
	 * Valid Google Ads business vertical ids. The labels shown on the
	 * admin page live in the AdminSchema class.
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
	 * Name of the first-party cookie that carries GA4 list attribution
	 * (item_list_name / item_list_id keyed by product id) across the funnel
	 * (#405). Written client-side by the WooCommerce tracker on a select_item
	 * list click; must match the literal used in js/frontend/gtm4wp-woocommerce.js.
	 */
	public const LIST_ATTRIBUTION_COOKIE = 'gtm4wp_item_list_attr';

	/**
	 * Hard caps on the list-attribution cookie so a crafted or bloated cookie
	 * can never make the reader do unbounded work: entries beyond the limit are
	 * dropped and an oversized cookie is ignored wholesale.
	 */
	public const LIST_ATTRIBUTION_MAX_ENTRIES      = 20;
	public const LIST_ATTRIBUTION_COOKIE_MAX_BYTES = 4096;

	/**
	 * Replace only the first occurrence of the search string with the replacement string.
	 *
	 * @param string $search The value being searched for, otherwise known as the needle.
	 * @param string $replace The replacement value that replaces found search values.
	 * @param string $subject The string being searched and replaced on, otherwise known as the haystack.
	 * @return string This function returns a string with the replaced values.
	 */
	public static function str_replace_first( string $search, string $replace, string $subject ): string {
		$search = '/' . preg_quote( $search, '/' ) . '/';

		return (string) preg_replace( $search, $replace, $subject, 1 );
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
	 * Reads and validates the first-party list-attribution cookie (#405) into a
	 * map of product id => array( item_list_name, item_list_id ). The cookie is
	 * untrusted client input, so every part is sanitized here: the id via absint,
	 * the list name via sanitize_text_field and the id via sanitize_title. The
	 * sanitized values are returned RAW (not entity-encoded) so the downstream
	 * wp_json_encode() dataLayer sink can escape them once and correctly. A
	 * malformed, non-JSON or oversized cookie yields an empty map, and no more
	 * than LIST_ATTRIBUTION_MAX_ENTRIES entries are ever processed.
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
	 * @param int    $product_id A WooCommerce product ID whose taxonomy association needs to be queried.
	 * @param string $taxonomy The taxonomy slug for which to retrieve terms.
	 * @return string Returns the first assigned taxonomy value of the given WooCommerce product ID.
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
	 * Returns the result of normalizing and hashing an email address. For this use case, Google
	 * Ads requires removal of any '.' characters preceding "gmail.com" or "googlemail.com".
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web#php
	 *
	 * @param string $hash_algorithm the hash algorithm to use.
	 * @param string $email_address the email address to normalize and hash.
	 * @return string the normalized and hashed email address.
	 */
	public static function normalize_and_hash_email_address( string $hash_algorithm, string $email_address ): string {
		$normalized_email = strtolower( $email_address );
		$email_parts      = explode( '@', $normalized_email );
		if (
			count( $email_parts ) > 1
			&& preg_match( '/^(gmail|googlemail)\.com\s*/', $email_parts[1] )
		) {
			// Removes any '.' characters from the portion of the email address before the domain
			// if the domain is gmail.com or googlemail.com.
			$email_parts[0]   = str_replace( '.', '', $email_parts[0] );
			$normalized_email = sprintf( '%s@%s', $email_parts[0], $email_parts[1] );
		}

		return self::normalize_and_hash( $hash_algorithm, $normalized_email, true );
	}
}
