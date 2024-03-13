<?php
/**
 * GTM4WP generic ecommerce helper functions.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

/**
 * Replace only the first occurrence of the search string with the replacement string.
 *
 * @see https://stackoverflow.com/questions/1252693/using-str-replace-so-that-it-only-acts-on-the-first-match
 *
 * TODO: replace regexp usage.
 *
 * @param string $search The value being searched for, otherwise known as the needle. Must be a string.
 * @param string $replace The replacement value that replaces found search values. Must be a string.
 * @param string $subject The string being searched and replaced on, otherwise known as the haystack.
 * @return string This function returns a string with the replaced values.
 */
function gtm4wp_str_replace_first( $search, $replace, $subject ) {
	$search = '/' . preg_quote( $search, '/' ) . '/';

	return preg_replace( $search, $replace, $subject, 1 );
}

/**
 * Convert special unicode quotation and dash characters to normal version.
 *
 * @see https://snippets.webaware.com.au/ramblings/php-really-doesnt-unicode/
 *
 * @param string $fancy Input string with special unicode quotes and dash characters.
 * @return string All kind of quotes and dash characters replaced with normal version.
 */
function gtm4wp_untexturize( $fancy ) {
	$fixes = array(
		json_decode( '"\u201C"' ) => '"', // left  double quotation mark.
		json_decode( '"\u201D"' ) => '"', // right double quotation mark.
		json_decode( '"\u2018"' ) => "'", // left  single quotation mark.
		json_decode( '"\u2019"' ) => "'", // right single quotation mark.
		json_decode( '"\u2032"' ) => "'", // prime (minutes, feet).
		json_decode( '"\u2033"' ) => '"', // double prime (seconds, inches).
		json_decode( '"\u2013"' ) => '-', // en dash.
		json_decode( '"\u2014"' ) => '--', // em dash.
	);

	return strtr( $fancy, $fixes );
}

/**
 * Takes a product ID and returns a string that has a prefix appended.
 * The prefix can be set on the GTM4WP options page under Integration->WooCommerce.
 *
 * This is needed in cases where the generated feed has IDs with some sort of constant prefix and
 * tracking needs to align with this ID in order for dynamic remarketing to work properly.
 *
 * @param int|string $product_id A product ID that has to be prefixed.
 * @param string     $prefix The prefix that needs to be added in front of the product ID.
 * @return string.   The product ID with the prefix strings.
 */
function gtm4wp_prefix_productid( $product_id, $prefix ) {
	if ( '' !== $prefix ) {
		return $prefix . $product_id;
	}

	return $product_id;
}

/**
 * Given a category ID, this function returns the full path to this category separated with the / character.
 *
 * @param int    $category_id The ID of the category that needs to be scanned for parents.
 * @param string $category_taxonomy The name of the WordPress taxonomy where the category ID needs to be searched. Defaults to 'product_cat'.
 * @return string The category path. An example outout can be: Home / Clothing / Toddlers.
 */
function gtm4wp_get_product_category_hierarchy( $category_id, $category_taxonomy = 'product_cat' ) {
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
 * Currently, it does not take into account the "primary category" option of various SEO plugins.
 *
 * @param int     $product_id A product ID whose first assigned category has to be returned.
 * @param boolean $fullpath Set this to true of you need to query the full path including parent categories. Defaults to false.
 * @param string  $category_taxonomy The name of the WordPress taxonomy where the category ID needs to be searched. Defaults to 'product_cat'.
 * @return string The first category name of the product. Incluldes the name of parent categories if the $fullpath parameter is set to true.
 */
function gtm4wp_get_product_category( $product_id, $fullpath = false, $category_taxonomy = 'product_cat' ) {
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
			$product_category = gtm4wp_get_product_category_hierarchy( $category_data->term_id, $category_taxonomy );
		} elseif ( isset( $category_data->name ) ) {
			$product_category = $category_data->name;
		}
	}

	return $product_category;
}

/**
 * Given a product ID, this function returns the assigned value of a custom taxonomy like the brand name.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_get_post_terms/
 *
 * @param int    $product_id A WooCommerce product ID whose taxonomy assosiation needs to be queried.
 * @param string $taxonomy The taxonomy slug for which to retrieve terms.
 * @return string Returns the first assigned taxonomy value to the given WooCommerce product ID.
 */
function gtm4wp_get_product_term( $product_id, $taxonomy ) {
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
 * Given a Google Business vertical ID, this function returns the name of the "ID" field in tagging Google Ads dynamic remarketing.
 * This "id" in most cases, but sometimes "destination".
 *
 * @param string $vertical_id The Google Business vertical ID (like retail, flights, etc.).
 * @return string The name of the "ID" field for tagging.
 */
function gtm4wp_get_gads_product_id_variable_name( $vertical_id ) {
	global $gtm4wp_business_verticals_ids;

	if ( array_key_exists( $vertical_id, $gtm4wp_business_verticals_ids ) ) {
		return $gtm4wp_business_verticals_ids[ $vertical_id ];
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
 * @param bool   $trim_intermediate_spaces wether to remove all spaces before hashing (true) or only leading and trailing spaces (false).
 * @return string the normalized and hashed email address
 */
function gtm4wp_normalize_and_hash( $hash_algorithm, $value, $trim_intermediate_spaces ) {
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
 * @return string the normalized and hashed email address
 */
function gtm4wp_normalize_and_hash_email_address( $hash_algorithm, $email_address ) {
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

	return gtm4wp_normalize_and_hash( $hash_algorithm, $normalized_email, true );
}
