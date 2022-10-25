<?php
/**
 * GTM4WP WooCoommerce integration.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

define( 'GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY', 'gtm4wp_eec_product_array' );
define( 'GTM4WP_WPFILTER_EEC_CART_ITEM', 'gtm4wp_eec_cart_item' );
define( 'GTM4WP_WPFILTER_EEC_ORDER_ITEM', 'gtm4wp_eec_order_item' );

$gtm4wp_product_counter   = 0;
$gtm4wp_last_widget_title = 'Sidebar Products';
if ( function_exists( 'WC' ) ) {
	$GLOBALS['gtm4wp_is_woocommerce3_7'] = version_compare( WC()->version, '3.7', '>=' );
} else {
	$GLOBALS['gtm4wp_is_woocommerce3_7'] = false;
}
$GLOBALS['gtm4wp_grouped_product_ix']               = 1;
$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = false;

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

	$normal = strtr( $fancy, $fixes );

	return $normal;
}

/**
 * Takes a product ID and returns a string that has a prefix appended.
 * The prefix can be set on the GTM4WP options page under Integration->WooCommerce.
 *
 * This is needed in cases where the generated feed has IDs with some sort of constant prefix and
 * tracking needs to align with this ID in order for dynamic remarketing to work properly.
 *
 * @param int|string $product_id A product ID that has to be prefixed.
 * @return string. The product ID with the prefix strings.
 */
function gtm4wp_prefix_productid( $product_id ) {
	global $gtm4wp_options;

	if ( '' !== $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX ] ) {
		return $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX ] . $product_id;
	} else {
		return $product_id;
	}
}

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
 * Given a WooCommerce category ID, this function returns the full path to this category separated with the / character.
 *
 * @param int $category_id The ID of the WooCommerce category that needs to be scanned for parents.
 * @return string The category path. An example outout can be: Home / Clothing / Toddlers.
 */
function gtm4wp_get_product_category_hierarchy( $category_id ) {
	$cat_hierarchy = '';

	$category_parent_list = get_term_parents_list(
		$category_id,
		'product_cat',
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
 * Given a WooCommerce product ID, this function will return the first assigned category of the product.
 * Currently, it does not take into account the "primary category" option of various SEO plugins.
 *
 * @param int     $product_id A WooCommerce product ID whose first assigned category has to be returned.
 * @param boolean $fullpath Set this to true of you need to query the full path including parent categories. Defaults to false.
 * @return string The first category name of the product. Incluldes the name of parent categories if the $fullpath parameter is set to true.
 */
function gtm4wp_get_product_category( $product_id, $fullpath = false ) {
	$product_cat = '';

	$_product_cats = wp_get_post_terms(
		$product_id,
		'product_cat',
		array(
			'orderby' => 'parent',
			'order'   => 'ASC',
		)
	);

	if ( ( is_array( $_product_cats ) ) && ( count( $_product_cats ) > 0 ) ) {
		$first_product_cat = array_pop( $_product_cats );
		if ( $fullpath ) {
			$product_cat = gtm4wp_get_product_category_hierarchy( $first_product_cat->term_id );
		} else {
			$product_cat = $first_product_cat->name;
		}
	}

	return $product_cat;
}

/**
 * Given a WooCommerce product ID, this function returns the assigned value of a custom taxonomy like the brand name.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_get_post_terms/
 *
 * @param int    $product_id A WooCommerce product ID whose taxonomy assosiation needs to be queried.
 * @param string $taxonomy The taxonomy slug for which to retrieve terms.
 * @return string Returns the first assigned taxonomy value to the given WooCommerce product ID.
 */
function gtm4wp_woocommerce_getproductterm( $product_id, $taxonomy ) {
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
 * Given a WP_Product instane, this function returns an array of product attributes in the format of
 * Google Analytics enhanced ecommerce product data.
 *
 * @see https://developers.google.com/analytics/devguides/collection/ua/gtm/enhanced-ecommerce
 *
 * @param WP_Product $product An instance of WP_Product that needs to be transformed into an enhanced ecommerce product object.
 * @param array      $additional_product_attributes Any key-value pair that needs to be added into the enhanced ecommerce product object.
 * @param string     $attributes_used_for The placement ID of the product that is passed to the apply_filters hook so that 3rd party code can be notified where this product data is being used.
 * @return array The enhanced ecommerce product object of the WooCommerce product.
 */
function gtm4wp_process_product( $product, $additional_product_attributes, $attributes_used_for ) {
	global $gtm4wp_options;

	if ( ! $product ) {
		return false;
	}

	if ( ! ( $product instanceof WC_Product ) ) {
		return false;
	}

	$product_id     = $product->get_id();
	$product_type   = $product->get_type();
	$remarketing_id = $product_id;
	$product_sku    = $product->get_sku();

	if ( 'variation' === $product_type ) {
		$parent_product_id = $product->get_parent_id();
		$product_cat       = gtm4wp_get_product_category( $parent_product_id, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH ] );
	} else {
		$product_cat = gtm4wp_get_product_category( $product_id, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH ] );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSESKU ] && ( '' !== $product_sku ) ) {
		$remarketing_id = $product_sku;
	}

	$_temp_productdata = array(
		'id'         => $remarketing_id,
		'name'       => $product->get_title(),
		'sku'        => $product_sku ? $product_sku : $product_id,
		'category'   => $product_cat,
		'price'      => round( (float) wc_get_price_to_display( $product ), 2 ),
		'stocklevel' => $product->get_stock_quantity(),
	);

	if ( '' !== $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY ] ) {
		if ( isset( $parent_product_id ) && ( 0 !== $parent_product_id ) ) {
			$product_id_to_query = $parent_product_id;
		} else {
			$product_id_to_query = $product_id;
		}

		$_temp_productdata['brand'] = gtm4wp_woocommerce_getproductterm( $product_id_to_query, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY ] );
	}

	if ( 'variation' === $product_type ) {
		$_temp_productdata['variant'] = implode( ',', $product->get_variation_attributes() );
	}

	$_temp_productdata = array_merge( $_temp_productdata, $additional_product_attributes );

	return apply_filters( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY, $_temp_productdata, $attributes_used_for );
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
	} else {
		return 'id';
	}
}

/**
 * Takes a GA3 style enhanced ecommerce product object and transforms it into a GA4 product object.
 *
 * @param array $productdata WooCommerce product data in GA3 enhanced ecommerce product object format.
 * @return array WooCommerce product data in GA4 enhanced ecommerce product object format.
 */
function gtm4wp_map_eec_to_ga4( $productdata ) {
	global $gtm4wp_options;

	if ( ! is_array( $productdata ) ) {
		return;
	}

	$category_path  = array_key_exists( 'category', $productdata ) ? $productdata['category'] : '';
	$category_parts = explode( '/', $category_path );

	// Default, required parameters.
	$ga4_product = array(
		'item_id'    => array_key_exists( 'id', $productdata ) ? $productdata['id'] : '',
		'item_name'  => array_key_exists( 'name', $productdata ) ? $productdata['name'] : '',
		'item_brand' => array_key_exists( 'brand', $productdata ) ? $productdata['brand'] : '',
		'price'      => array_key_exists( 'price', $productdata ) ? $productdata['price'] : '',
	);

	// Category, also handle category path.
	if ( 1 === count( $category_parts ) ) {
		$ga4_product['item_category'] = $category_parts[0];
	} elseif ( count( $category_parts ) > 1 ) {
		$ga4_product['item_category'] = $category_parts[0];

		$num_category_parts = min( 5, count( $category_parts ) );
		for ( $i = 1; $i < $num_category_parts; $i++ ) {
			$ga4_product[ 'item_category' . (string) ( $i + 1 ) ] = $category_parts[ $i ];
		}
	}

	// Optional parameters which should not be included in the array if not set.
	if ( array_key_exists( 'variant', $productdata ) ) {
		$ga4_product['item_variant'] = $productdata['variant'];
	}
	if ( array_key_exists( 'listname', $productdata ) ) {
		$ga4_product['item_list_name'] = $productdata['listname'];
	}
	if ( array_key_exists( 'listposition', $productdata ) ) {
		$ga4_product['index'] = $productdata['listposition'];
	}
	if ( array_key_exists( 'quantity', $productdata ) ) {
		$ga4_product['quantity'] = $productdata['quantity'];
	}
	if ( array_key_exists( 'coupon', $productdata ) ) {
		$ga4_product['coupon'] = $productdata['coupon'];
	}

	$ga4_product['google_business_vertical'] = $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ];

	$ga4_product[ gtm4wp_get_gads_product_id_variable_name( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ] ) ] = gtm4wp_prefix_productid( $ga4_product['item_id'] );

	return $ga4_product;
}

/**
 * Takes a WooCommerce order and returns an associative array that can be used
 * for enhanced ecommerce tracking and Google Ads dynamic remarketing (legacy version).
 *
 * @param WC_Order $order The order that needs to be processed.
 * @return array An associative array with the keys:
 *               products - enhanced ecommerce (GA3) products
 *               sumprice - total order value based on item data
 *               product_ids - array of product IDs to be used in ecomm_prodid.
 */
function gtm4wp_process_order_items( $order ) {
	global $gtm4wp_options;

	$return_data = array(
		'products'    => array(),
		'sumprice'    => 0,
		'product_ids' => array(),
	);

	if ( ! $order ) {
		return $return_data;
	}

	if ( ! ( $order instanceof WC_Order ) ) {
		return $return_data;
	}

	$order_items = $order->get_items();

	if ( $order_items ) {
		foreach ( $order_items as $item ) {
			if ( ! apply_filters( GTM4WP_WPFILTER_EEC_ORDER_ITEM, true, $item ) ) {
				continue;
			}

			$product       = $item->get_product();
			$inc_tax       = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) );
			$product_price = round( (float) $order->get_item_total( $item, $inc_tax ), 2 );

			$eec_product_array = gtm4wp_process_product(
				$product,
				array(
					'quantity' => $item->get_quantity(),
					'price'    => $product_price,
				),
				'purchase'
			);

			if ( $eec_product_array ) {
				$return_data['products'][]    = $eec_product_array;
				$return_data['sumprice']     += $product_price * $eec_product_array['quantity'];
				$return_data['product_ids'][] = gtm4wp_prefix_productid( $eec_product_array['id'] );
			}
		}
	}

	return $return_data;
}

/**
 * Function to be called on the gtm4wp_add_global_vars hook to output WooCommerce related global JavaScript variables.
 *
 * @param array $return The already added variables as key-value pairs in an associative array.
 * @return array The $return parameter with added global JavaScript variables as key-value pairs.
 */
function gtm4wp_woocommerce_addglobalvars( $return ) {
	global $gtm4wp_options;

	if ( function_exists( 'WC' ) && WC()->cart ) {
		$gtm4wp_needs_shipping_address = (bool) WC()->cart->needs_shipping_address();
	} else {
		$gtm4wp_needs_shipping_address = false;
	}

	$return['gtm4wp_use_sku_instead']        = (int) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSESKU ] );
	$return['gtm4wp_id_prefix']              = gtm4wp_prefix_productid( '' );
	$return['gtm4wp_remarketing']            = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] );
	$return['gtm4wp_eec']                    = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] );
	$return['gtm4wp_classicec']              = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] );
	$return['gtm4wp_currency']               = get_woocommerce_currency();
	$return['gtm4wp_product_per_impression'] = (int) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION ] );
	$return['gtm4wp_needs_shipping_address'] = (bool) $gtm4wp_needs_shipping_address;
	$return['gtm4wp_business_vertical']      = esc_js( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ] );
	$return['gtm4wp_business_vertical_id']   = gtm4wp_get_gads_product_id_variable_name( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ] );

	return $return;
}

/**
 * Takes a WooCommerce order and order items and generates the standard/classic and
 * enhanced ecommerce version of the purchase data layer codes for Universal Analytics.
 *
 * @param WC_Order $order The WooCommerce order that needs to be transformed into an enhanced ecommerce data layer.
 * @param array    $order_items The array returned by gtm4wp_process_order_items(). It not set, then function will call gtm4wp_process_order_items().
 * @return array The data layer content as an associative array that can be passed to json_encode() to product a JavaScript object used by GTM.
 */
function gtm4wp_get_purchase_datalayer( $order, $order_items ) {
	global $gtm4wp_options, $gtm4wp_is_woocommerce3_7;

	$data_layer = array();

	if ( $order instanceof WC_Order ) {
		$woo = WC();

		/**
		 * Variable for Google Smart Shopping campaign new customer reporting.
		 *
		 * @see https://support.google.com/google-ads/answer/9917012?hl=en-AU#zippy=%2Cinstall-with-google-tag-manager
		 */
		if ( $woo->customer instanceof WC_Customer ) {
			// we need to use this instead of $woo->customer as this will load proper total order number and value from the database instead of the session.
			$woo_customer               = new WC_Customer( $woo->customer->get_id() );
			$data_layer['new_customer'] = $woo_customer->get_order_count() === 1;
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX ] ) {
			$order_revenue = (float) ( $order->get_total() - $order->get_total_tax() );
		} else {
			$order_revenue = (float) $order->get_total();
		}

		$order_shipping_cost = (float) $order->get_shipping_total();

		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING ] ) {
			$order_revenue -= $order_shipping_cost;
		}

		$order_currency = $order->get_currency();

		if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
			$data_layer['event']                  = 'gtm4wp.orderCompleted';
			$data_layer['transactionId']          = $order->get_order_number();
			$data_layer['transactionAffiliation'] = '';
			$data_layer['transactionTotal']       = $order_revenue;
			$data_layer['transactionShipping']    = $order_shipping_cost;
			$data_layer['transactionTax']         = (float) $order->get_total_tax();
			$data_layer['transactionCurrency']    = $order_currency;
		}

		if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
			$data_layer['event']     = 'gtm4wp.orderCompletedEEC';
			$data_layer['ecommerce'] = array(
				'currencyCode' => $order_currency,
				'purchase'     => array(
					'actionField' => array(
						'id'          => $order->get_order_number(),
						'affiliation' => '',
						'revenue'     => $order_revenue,
						'tax'         => (float) $order->get_total_tax(),
						'shipping'    => (float) ( $order->get_shipping_total() ),
						'coupon'      => implode( ', ', ( $gtm4wp_is_woocommerce3_7 ? $order->get_coupon_codes() : $order->get_used_coupons() ) ),
					),
				),
			);
		}

		if ( isset( $order_items ) ) {
			$_order_items = $order_items;
		} else {
			$_order_items = gtm4wp_process_order_items( $order );
		}

		if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
			$data_layer['transactionProducts'] = $_order_items['products'];
		}

		if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
			$data_layer['ecommerce']['purchase']['products'] = $_order_items['products'];
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
			$data_layer['ecomm_prodid']     = $_order_items['product_ids'];
			$data_layer['ecomm_pagetype']   = 'purchase';
			$data_layer['ecomm_totalvalue'] = (float) $_order_items['sumprice'];
		}
	}

	return $data_layer;
}

/**
 * Function executed when the main GTM4WP data layer generation happens.
 * Hooks into gtm4wp_compile_datalayer.
 *
 * @param array $data_layer An array of key-value pairs that will be converted into a JavaScript object on the frontend for GTM.
 * @return array Extended data layer content with WooCommerce data added.
 */
function gtm4wp_woocommerce_datalayer_filter_items( $data_layer ) {
	global $gtm4wp_options, $wp_query, $gtm4wp_datalayer_name, $gtm4wp_product_counter, $gtm4wp_is_woocommerce3_7;

	if ( array_key_exists( 'HTTP_X_REQUESTED_WITH', $_SERVER ) ) {
		return $data_layer;
	}

	$woo = WC();

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA ] ) {
		if ( $woo->customer instanceof WC_Customer ) {
			// we need to use this instead of $woo->customer as this will load proper total order number and value from the database instead of the session.
			$woo_customer = new WC_Customer( $woo->customer->get_id() );

			$data_layer['customerTotalOrders']     = $woo_customer->get_order_count();
			$data_layer['customerTotalOrderValue'] = $woo_customer->get_total_spent();

			$data_layer['customerFirstName'] = $woo_customer->get_first_name();
			$data_layer['customerLastName']  = $woo_customer->get_last_name();

			$data_layer['customerBillingFirstName'] = $woo_customer->get_billing_first_name();
			$data_layer['customerBillingLastName']  = $woo_customer->get_billing_last_name();
			$data_layer['customerBillingCompany']   = $woo_customer->get_billing_company();
			$data_layer['customerBillingAddress1']  = $woo_customer->get_billing_address_1();
			$data_layer['customerBillingAddress2']  = $woo_customer->get_billing_address_2();
			$data_layer['customerBillingCity']      = $woo_customer->get_billing_city();
			$data_layer['customerBillingPostcode']  = $woo_customer->get_billing_postcode();
			$data_layer['customerBillingCountry']   = $woo_customer->get_billing_country();
			$data_layer['customerBillingEmail']     = $woo_customer->get_billing_email();
			$data_layer['customerBillingEmailHash'] = hash( 'sha256', $woo_customer->get_billing_email() );
			$data_layer['customerBillingPhone']     = $woo_customer->get_billing_phone();

			$data_layer['customerShippingFirstName'] = $woo_customer->get_shipping_first_name();
			$data_layer['customerShippingLastName']  = $woo_customer->get_shipping_last_name();
			$data_layer['customerShippingCompany']   = $woo_customer->get_shipping_company();
			$data_layer['customerShippingAddress1']  = $woo_customer->get_shipping_address_1();
			$data_layer['customerShippingAddress2']  = $woo_customer->get_shipping_address_2();
			$data_layer['customerShippingCity']      = $woo_customer->get_shipping_city();
			$data_layer['customerShippingPostcode']  = $woo_customer->get_shipping_postcode();
			$data_layer['customerShippingCountry']   = $woo_customer->get_shipping_country();
		}
	}

	if (
		$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL ] &&
		version_compare( $woo->version, '3.2', '>=' ) &&
		isset( $woo ) &&
		isset( $woo->cart )
	) {
		$current_cart = $woo->cart;

		$data_layer['cartContent'] = array(
			'totals' => array(
				'applied_coupons' => $current_cart->get_applied_coupons(),
				'discount_total'  => $current_cart->get_discount_total(),
				'subtotal'        => $current_cart->get_subtotal(),
				'total'           => $current_cart->get_cart_contents_total(),
			),
			'items'  => array(),
		);

		foreach ( $current_cart->get_cart() as $cart_item_id => $cart_item_data ) {
			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );
			if (
				! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data )
				|| ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item_data, $cart_item_id )
				) {
				continue;
			}

			$eec_product_array = gtm4wp_process_product(
				$product,
				array(
					'quantity' => $cart_item_data['quantity'],
				),
				'cart'
			);

			$data_layer['cartContent']['items'][] = $eec_product_array;
		}
	}

	if ( is_product_category() || is_product_tag() || is_front_page() || is_shop() ) {
		$ecomm_pagetype = 'category';
		if ( is_front_page() ) {
			$ecomm_pagetype = 'home';
		} elseif ( is_search() ) {
			$ecomm_pagetype = 'searchresults';
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
			$data_layer['ecomm_prodid']     = array();
			$data_layer['ecomm_pagetype']   = $ecomm_pagetype;
			$data_layer['ecomm_totalvalue'] = 0;
		}
	} elseif ( is_product() ) {
		if (
			$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ]
			|| ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] )
		) {
			$postid  = get_the_ID();
			$product = wc_get_product( $postid );

			$eec_product_array = gtm4wp_process_product(
				$product,
				array(),
				'productdetail'
			);

			$data_layer['productRatingCounts']  = $product->get_rating_counts();
			$data_layer['productAverageRating'] = (float) $product->get_average_rating();
			$data_layer['productReviewCount']   = (int) $product->get_review_count();
			$data_layer['productType']          = $product->get_type();

			switch ( $data_layer['productType'] ) {
				case 'variable':
					$data_layer['productIsVariable'] = 1;

					$data_layer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array['id'] );
					$data_layer['ecomm_pagetype']   = 'product';
					$data_layer['ecomm_totalvalue'] = $eec_product_array['price'];

					break;

				case 'grouped':
					$data_layer['productIsVariable'] = 0;

					break;

				default:
					$data_layer['productIsVariable'] = 0;

					if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
						$data_layer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array['id'] );
						$data_layer['ecomm_pagetype']   = 'product';
						$data_layer['ecomm_totalvalue'] = $eec_product_array['price'];
					}

					if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
						$currency_code = get_woocommerce_currency();

						$data_layer['event']     = 'gtm4wp.changeDetailViewEEC';
						$data_layer['ecommerce'] = array(
							'currencyCode' => $currency_code,
							'detail'       => array(
								'products' => array(
									$eec_product_array,
								),
							),
						);
					}
			}
		}
	} elseif ( is_cart() ) {
		if (
			$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ]
			|| $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ]
			|| $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ]
		) {
			$gtm4wp_cart_products             = array();
			$gtm4wp_cart_products_remarketing = array();

			$gtm4wp_currency = get_woocommerce_currency();

			foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
				$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

				if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
					continue;
				}

				$eec_product_array = gtm4wp_process_product(
					$product,
					array(
						'quantity' => $cart_item_data['quantity'],
					),
					'cart'
				);

				$gtm4wp_cart_products[]             = $eec_product_array;
				$gtm4wp_cart_products_remarketing[] = gtm4wp_prefix_productid( $eec_product_array['id'] );
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$data_layer['ecomm_prodid']   = $gtm4wp_cart_products_remarketing;
				$data_layer['ecomm_pagetype'] = 'cart';
				if ( ! $woo->cart->prices_include_tax ) {
					$cart_total = $woo->cart->cart_contents_total;
				} else {
					$cart_total = $woo->cart->cart_contents_total + $woo->cart->tax_total;
				}
				$data_layer['ecomm_totalvalue'] = (float) $cart_total;
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ] ) {
					$data_layer['event']     = 'gtm4wp.checkoutStepEEC';
					$data_layer['ecommerce'] = array(
						'currencyCode' => $gtm4wp_currency,
						'checkout'     => array(
							'actionField' => array(
								'step' => 1,
							),
							'products'    => $gtm4wp_cart_products,
						),
					);
				} else {
					// add only ga4 products to populate view_cart event.
					$data_layer['ecommerce'] = array(
						'cart' => $gtm4wp_cart_products,
					);
				}
			}
		}
	} elseif ( is_order_received_page() ) {
		$do_not_flag_tracked_order = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ] );

		// Supressing 'Processing form data without nonce verification.' message as there is no nonce accesible in this case.
		$order_id = filter_var( wp_unslash( isset( $_GET['order'] ) ? $_GET['order'] : '' ), FILTER_VALIDATE_INT ); // phpcs:ignore
		if ( ! $order_id & isset( $GLOBALS['wp']->query_vars['order-received'] ) ) {
			$order_id = $GLOBALS['wp']->query_vars['order-received'];
		}
		$order_id = absint( $order_id );

		$order_id_filtered = apply_filters( 'woocommerce_thankyou_order_id', $order_id );
		if ( '' !== $order_id_filtered ) {
			$order_id = $order_id_filtered;
		}

		// Supressing 'Processing form data without nonce verification.' message as there is no nonce accesible in this case.
		$order_key = isset( $_GET['key'] ) ? wc_clean( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) : ''; // phpcs:ignore
		$order_key = apply_filters( 'woocommerce_thankyou_order_key', $order_key );

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				$this_order_key = $order->get_order_key();

				if ( $this_order_key !== $order_key ) {
					unset( $order );
				}
			} else {
				unset( $order );
			}
		}

		/*
		From this point if for any reason purchase data is not pushed
		that is because for a specific reason.
		In any other case woocommerce_thankyou hook will be the fallback if
		is_order_received_page does not work.
		*/
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		if ( isset( $order ) && $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ] ) {

			if ( $order->is_paid() && $order->get_date_paid() ) {
				$now     = new DateTime( 'now', $order->get_date_paid()->getTimezone() );
				$diff    = $now->diff( $order->get_date_paid() );
				$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;
			} else {
				$now     = new DateTime( 'now', $order->get_date_created()->getTimezone() );
				$diff    = $now->diff( $order->get_date_created() );
				$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;
			}

			if ( $minutes > $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ] ) {
				unset( $order );
			}
		}

		$order_items = null;
		if ( isset( $order ) && $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERDATA ] ) {
			$order_items = gtm4wp_process_order_items( $order );

			$data_layer['orderData'] = array(
				'attributes' => array(
					'date'                 => $order->get_date_created()->date( 'c' ),

					'order_number'         => $order->get_order_number(),
					'order_key'            => $order->get_order_key(),

					'payment_method'       => esc_js( $order->get_payment_method() ),
					'payment_method_title' => esc_js( $order->get_payment_method_title() ),

					'shipping_method'      => esc_js( $order->get_shipping_method() ),

					'status'               => esc_js( $order->get_status() ),

					'coupons'              => implode( ', ', ( $gtm4wp_is_woocommerce3_7 ? $order->get_coupon_codes() : $order->get_used_coupons() ) ),
				),
				'totals'     => array(
					'currency'       => esc_js( $order->get_currency() ),
					'discount_total' => esc_js( $order->get_discount_total() ),
					'discount_tax'   => esc_js( $order->get_discount_tax() ),
					'shipping_total' => esc_js( $order->get_shipping_total() ),
					'shipping_tax'   => esc_js( $order->get_shipping_tax() ),
					'cart_tax'       => esc_js( $order->get_cart_tax() ),
					'total'          => esc_js( $order->get_total() ),
					'total_tax'      => esc_js( $order->get_total_tax() ),
					'total_discount' => esc_js( $order->get_total_discount() ),
					'subtotal'       => esc_js( $order->get_subtotal() ),
					'tax_totals'     => $order->get_tax_totals(),
				),
				'customer'   => array(
					'id'       => $order->get_customer_id(),

					'billing'  => array(
						'first_name' => esc_js( $order->get_billing_first_name() ),
						'last_name'  => esc_js( $order->get_billing_last_name() ),
						'company'    => esc_js( $order->get_billing_company() ),
						'address_1'  => esc_js( $order->get_billing_address_1() ),
						'address_2'  => esc_js( $order->get_billing_address_2() ),
						'city'       => esc_js( $order->get_billing_city() ),
						'state'      => esc_js( $order->get_billing_state() ),
						'postcode'   => esc_js( $order->get_billing_postcode() ),
						'country'    => esc_js( $order->get_billing_country() ),
						'email'      => esc_js( $order->get_billing_email() ),
						'emailhash'  => esc_js( hash( 'sha256', $order->get_billing_email() ) ),
						'phone'      => esc_js( $order->get_billing_phone() ),
					),

					'shipping' => array(
						'first_name' => esc_js( $order->get_shipping_first_name() ),
						'last_name'  => esc_js( $order->get_shipping_last_name() ),
						'company'    => esc_js( $order->get_shipping_company() ),
						'address_1'  => esc_js( $order->get_shipping_address_1() ),
						'address_2'  => esc_js( $order->get_shipping_address_2() ),
						'city'       => esc_js( $order->get_shipping_city() ),
						'state'      => esc_js( $order->get_shipping_state() ),
						'postcode'   => esc_js( $order->get_shipping_postcode() ),
						'country'    => esc_js( $order->get_shipping_country() ),
					),

				),
				'items'      => $order_items['products'],
			);
		}

		if ( ( 1 === (int) get_post_meta( $order_id, '_ga_tracked', true ) ) && ! $do_not_flag_tracked_order ) {
			unset( $order );
		}

		if ( isset( $_COOKIE['gtm4wp_orderid_tracked'] ) ) {
			$tracked_order_id = filter_var( wp_unslash( $_COOKIE['gtm4wp_orderid_tracked'] ), FILTER_VALIDATE_INT );

			if ( $tracked_order_id && ( $tracked_order_id === $order_id ) && ! $do_not_flag_tracked_order ) {
				unset( $order );
			}
		}

		if ( isset( $order ) && ( 'failed' === $order->get_status() ) ) {
			// do not track order where payment failed.
			unset( $order );
		}

		if ( isset( $order ) ) {
			$data_layer = array_merge( $data_layer, gtm4wp_get_purchase_datalayer( $order, $order_items ) );

			if ( ! $do_not_flag_tracked_order ) {
				update_post_meta( $order_id, '_ga_tracked', 1 );
			}
		}
	} elseif ( is_checkout() ) {
		if (
			( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] )
			|| ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] )
		) {
			$gtm4wp_checkout_products             = array();
			$gtm4wp_checkout_products_remarketing = array();
			$gtm4wp_totalvalue                    = 0;

			foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
				$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

				if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
					continue;
				}

				$eec_product_array = gtm4wp_process_product(
					$product,
					array(
						'quantity' => $cart_item_data['quantity'],
					),
					'cart'
				);

				$gtm4wp_checkout_products[] = $eec_product_array;

				$gtm4wp_checkout_products_remarketing[] = gtm4wp_prefix_productid( $eec_product_array['id'] );
				$gtm4wp_totalvalue                     += $eec_product_array['quantity'] * $eec_product_array['price'];
			} // end foreach cart item

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$data_layer['ecomm_prodid']     = $gtm4wp_checkout_products_remarketing;
				$data_layer['ecomm_pagetype']   = 'cart';
				$data_layer['ecomm_totalvalue'] = (float) $gtm4wp_totalvalue;
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				$currency_code = get_woocommerce_currency();

				$ga4_products = array();
				$sum_value    = 0;

				foreach ( $gtm4wp_checkout_products as $oneproduct ) {
					$ga4_products[] = gtm4wp_map_eec_to_ga4( $oneproduct );
					$sum_value     += $oneproduct['price'] * $oneproduct['quantity'];
				}

				$data_layer['event']     = 'gtm4wp.checkoutStepEEC';
				$data_layer['ecommerce'] = array(
					'currencyCode' => $currency_code,
					'checkout'     => array(
						'actionField' => array(
							'step' => 1 + (int) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ],
						),
						'products'    => $gtm4wp_checkout_products,
					),
				);

				wc_enqueue_js(
					'
					window.gtm4wp_checkout_products     = ' . wp_json_encode( $gtm4wp_checkout_products ) . ';
					window.gtm4wp_checkout_products_ga4 = ' . wp_json_encode( $ga4_products ) . ';
					window.gtm4wp_checkout_value        = ' . (float) $sum_value . ';
					window.gtm4wp_checkout_step_offset  = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ] . ';'
				);
			}
		}
	} else {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
			$data_layer['ecomm_pagetype'] = 'other';
		}
	}

	if ( function_exists( 'WC' ) && WC()->session ) {
		$cart_readded_hash = WC()->session->get( 'gtm4wp_product_readded_to_cart' );
		if ( isset( $cart_readded_hash ) ) {
			$cart_item = $woo->cart->get_cart_item( $cart_readded_hash );
			if ( ! empty( $cart_item ) ) {
				$product = $cart_item['data'];

				$eec_product_array = gtm4wp_process_product(
					$product,
					array(
						'quantity' => $cart_item['quantity'],
					),
					'readdedtocart'
				);

				$currency_code = get_woocommerce_currency();

				$data_layer['event']     = 'gtm4wp.addProductToCartEEC';
				$data_layer['ecommerce'] = array(
					'currencyCode' => $currency_code,
					'add'          => array(
						'products' => array(
							$eec_product_array,
						),
					),
				);
			}

			WC()->session->set( 'gtm4wp_product_readded_to_cart', null );
		}
	}

	return $data_layer;
}

/**
 * Executed during woocommerce_thankyou.
 * This is a fallback function to output purchase data layer on customized order received pages where
 * the is_order_received_page() template tag returns false for some reason.
 *
 * @param int $order_id The ID of the order placed by the user just recently.
 * @return void
 */
function gtm4wp_woocommerce_thankyou( $order_id ) {
	global $gtm4wp_options, $gtm4wp_datalayer_name;

	/*
	If this flag is set to true, it means that the puchase event was fired
	when capturing the is_order_received_page template tag therefore
	no need to handle this here twice
	*/
	if ( $GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] ) {
		return;
	}

	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );
	}

	if ( isset( $order ) && $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ] ) {
		$now = new DateTime();
		if ( $order->is_paid() && $order->get_date_paid() ) {
			$diff    = $now->diff( $order->get_date_paid() );
			$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;
		} else {
			$diff    = $now->diff( $order->get_date_created() );
			$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;
		}

		if ( $minutes > $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ] ) {
			unset( $order );
		}
	}

	$do_not_flag_tracked_order = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ] );
	if ( ( 1 === (int) get_post_meta( $order_id, '_ga_tracked', true ) ) && ! $do_not_flag_tracked_order ) {
		unset( $order );
	}

	if ( isset( $_COOKIE['gtm4wp_orderid_tracked'] ) ) {
		$tracked_order_id = filter_var( wp_unslash( $_COOKIE['gtm4wp_orderid_tracked'] ), FILTER_VALIDATE_INT );

		if ( $tracked_order_id && ( $tracked_order_id === $order_id ) && ! $do_not_flag_tracked_order ) {
			unset( $order );
		}
	}

	if ( isset( $order ) && ( 'failed' === $order->get_status() ) ) {
		// do not track order where payment failed.
		unset( $order );
	}

	if ( isset( $order ) ) {
		$data_layer = gtm4wp_get_purchase_datalayer( $order, null );

		$has_html5_support    = current_theme_supports( 'html5' );
		$add_cookiebot_ignore = $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ];

		echo '
<script data-cfasync="false" data-pagespeed-no-defer' . ( $has_html5_support ? ' type="text/javascript"' : '' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
	window.' . esc_js( $gtm4wp_datalayer_name ) . ' = window.' . esc_js( $gtm4wp_datalayer_name ) . ' || [];
	window.' . esc_js( $gtm4wp_datalayer_name ) . '.push(' . wp_json_encode( $data_layer ) . ');
</script>';

		if ( ! $do_not_flag_tracked_order ) {
			update_post_meta( $order_id, '_ga_tracked', 1 );
		}
	}
}

/**
 * Function executed with the woocommerce_after_add_to_cart_button hook.
 *
 * @return void
 */
function gtm4wp_woocommerce_single_add_to_cart_tracking() {
	global $product, $gtm4wp_datalayer_name, $gtm4wp_options;

	// exit early if there is nothing to do.
	if ( ( false === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) && ( false === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) ) {
		return;
	}

	$eec_product_array = gtm4wp_process_product(
		$product,
		array(),
		'addtocartsingle'
	);

	foreach ( $eec_product_array as $eec_product_array_key => $eec_product_array_value ) {
		echo '<input type="hidden" name="gtm4wp_' . esc_attr( $eec_product_array_key ) . '" value="' . esc_attr( $eec_product_array_value ) . '" />' . "\n";
	}
}

/**
 * Universal Analytics enhanced ecommerce product array with the product that is currently shown in the cart.
 *
 * @var array
 */
$GLOBALS['gtm4wp_cart_item_proddata'] = '';

/**
 * Executed during woocommerce_cart_item_product for each product in the cart.
 * Stores the Universal Analytics enhanced ecommerce product data into a global variable
 * to be processed when the cart item is rendered.
 *
 * @see https://woocommerce.github.io/code-reference/files/woocommerce-templates-cart-cart.html#source-view.41
 *
 * @param WC_Product $product A WooCommerce product that is shown in the cart.
 * @param string     $cart_item Not used by this hook.
 * @param string     $cart_id Not used by this hook.
 * @return array Enhanced ecommerce product data in an associative array.
 */
function gtm4wp_woocommerce_cart_item_product_filter( $product, $cart_item = '', $cart_id = '' ) {
	global $gtm4wp_options;

	$eec_product_array = gtm4wp_process_product(
		$product,
		array(
			'productlink' => apply_filters( 'the_permalink', get_permalink(), 0 ),
		),
		'cart'
	);

	$GLOBALS['gtm4wp_cart_item_proddata'] = $eec_product_array;

	return $product;
}

/**
 * Executed during woocommerce_cart_item_remove_link.
 * Adds additional product data into the remove product link of the cart table to be able to track
 * enhanced ecommerce remove_from_cart action with product data.
 *
 * @global gtm4wp_cart_item_proddata The previously stored product array in gtm4wp_woocommerce_cart_item_product_filter.
 *
 * @param string $remove_from_cart_link The HTML code of the remove from cart link element.
 * @return string The updated remove product from cart link with product data added in data attributes.
 */
function gtm4wp_woocommerce_cart_item_remove_link_filter( $remove_from_cart_link ) {
	if ( ! isset( $GLOBALS['gtm4wp_cart_item_proddata'] ) ) {
		return $remove_from_cart_link;
	}

	if ( ! is_array( $GLOBALS['gtm4wp_cart_item_proddata'] ) ) {
		return $remove_from_cart_link;
	}

	if ( ! isset( $GLOBALS['gtm4wp_cart_item_proddata']['variant'] ) ) {
		$GLOBALS['gtm4wp_cart_item_proddata']['variant'] = '';
	}

	if ( ! isset( $GLOBALS['gtm4wp_cart_item_proddata']['brand'] ) ) {
		$GLOBALS['gtm4wp_cart_item_proddata']['brand'] = '';
	}

	$cartlink_with_data = sprintf(
		'data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_product_variant="%s" data-gtm4wp_product_stocklevel="%s" data-gtm4wp_product_brand="%s" href="',
		esc_attr( $GLOBALS['gtm4wp_cart_item_proddata']['id'] ),
		esc_attr( $GLOBALS['gtm4wp_cart_item_proddata']['name'] ),
		esc_attr( $GLOBALS['gtm4wp_cart_item_proddata']['price'] ),
		esc_attr( $GLOBALS['gtm4wp_cart_item_proddata']['category'] ),
		esc_url( $GLOBALS['gtm4wp_cart_item_proddata']['productlink'] ),
		esc_attr( $GLOBALS['gtm4wp_cart_item_proddata']['variant'] ),
		esc_attr( $GLOBALS['gtm4wp_cart_item_proddata']['stocklevel'] ),
		esc_attr( $GLOBALS['gtm4wp_cart_item_proddata']['brand'] )
	);

	$GLOBALS['gtm4wp_cart_item_proddata'] = '';

	return gtm4wp_str_replace_first( 'href="', $cartlink_with_data, $remove_from_cart_link );
}

/**
 * Executed during loop_end.
 * Resets the product impression list name after a specific product list ended rendering.
 *
 * @return void
 */
function gtp4wp_woocommerce_reset_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = '';
}

/**
 * Executed during woocommerce_related_products_args.
 * Sets the currently rendered product list impression name to Related Products.
 *
 * @param array $arg Not used by this hook.
 * @return array
 */
function gtm4wp_woocommerce_add_related_to_loop( $arg ) {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Related Products', 'duracelltomi-google-tag-manager' );

	return $arg;
}

/**
 * Executed during woocommerce_cross_sells_columns.
 * Sets the currently rendered product list impression name to Cross-Sell Products.
 *
 * @param array $arg Not used by this hook.
 * @return array
 */
function gtm4wp_woocommerce_add_cross_sell_to_loop( $arg ) {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Cross-Sell Products', 'duracelltomi-google-tag-manager' );

	return $arg;
}

/**
 * Executed during woocommerce_upsells_columns.
 * Sets the currently rendered product list impression name to Upsell Products.
 *
 * @param array $arg Not used by this hook.
 * @return array
 */
function gtm4wp_woocommerce_add_upsells_to_loop( $arg ) {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Upsell Products', 'duracelltomi-google-tag-manager' );

	return $arg;
}

/**
 * Executed during woocommerce_before_template_part.
 * Starts output buffering in order to be able to add product data attributes to the link element
 * of a product list (classic) widget.
 *
 * @param string $template_name The template part that is being rendered.
 * @return void
 */
function gtm4wp_woocommerce_before_template_part( $template_name ) {
	ob_start();
}

/**
 * Executed during woocommerce_after_template_part.
 * Stops output buffering and gets the generated content since woocommerce_before_template_part.
 * Adds data attributes into the product link to be able to track product list impression and
 * click actions with Google Tag Manager.
 *
 * @param string $template_name The template part that is being rendered. This functions looks for content-widget-product.php.
 * @return void
 */
function gtm4wp_woocommerce_after_template_part( $template_name ) {
	global $product, $gtm4wp_product_counter, $gtm4wp_last_widget_title, $gtm4wp_options;

	$productitem = ob_get_contents();
	ob_end_clean();

	if ( 'content-widget-product.php' === $template_name ) {
		$eec_product_array = gtm4wp_process_product(
			$product,
			array(
				'productlink'  => apply_filters( 'the_permalink', get_permalink(), 0 ),
				'listname'     => $gtm4wp_last_widget_title,
				'listposition' => $gtm4wp_product_counter,
			),
			'widgetproduct'
		);

		if ( ! isset( $eec_product_array['brand'] ) ) {
			$eec_product_array['brand'] = '';
		}

		$productlink_with_data = sprintf(
			'data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_productlist_name="%s" data-gtm4wp_product_listposition="%s" data-gtm4wp_product_stocklevel="%s" data-gtm4wp_product_brand="%s" href="',
			esc_attr( $eec_product_array['id'] ),
			esc_attr( $eec_product_array['name'] ),
			esc_attr( $eec_product_array['price'] ),
			esc_attr( $eec_product_array['category'] ),
			esc_url( $eec_product_array['productlink'] ),
			esc_attr( $eec_product_array['listname'] ),
			esc_attr( $eec_product_array['listposition'] ),
			esc_attr( $eec_product_array['stocklevel'] ),
			esc_attr( $eec_product_array['brand'] )
		);

		$gtm4wp_product_counter++;

		$productitem = str_replace( 'href="', $productlink_with_data, $productitem );
	}

	/*
	$productitem is initialized as the template itself outputs a product item.
	Therefore I can not pass this to wp_kses() as it can include eventually any HTML.
	This filter function only adds additional attributes to the link element that points
	to a product detail page. Attribute values are escaped above.
	*/
	echo $productitem; // phpcs:ignore
}

/**
 * Executed during widget_title.
 * This hook is used for any custom (classic) product list widget with custom title.
 * The widget title will be used to report a custom product list name into Google Analytics.
 * This function also resets the $gtm4wp_product_counter global variable to report the first
 * product in the widget in the proper position.
 *
 * @param string $widget_title The title of the widget being rendered.
 * @return string The updated widget title which is not changed by this function.
 */
function gtm4wp_widget_title_filter( $widget_title ) {
	global $gtm4wp_product_counter, $gtm4wp_last_widget_title;

	$gtm4wp_product_counter   = 1;
	$gtm4wp_last_widget_title = $widget_title . __( ' (widget)', 'duracelltomi-google-tag-manager' );

	return $widget_title;
}

/**
 * Executed during woocommerce_shortcode_before_recent_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_before_recent_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Recent Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_sale_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_before_sale_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Sale Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_best_selling_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_before_best_selling_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Best Selling Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_top_rated_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_before_top_rated_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Top Rated Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_featured_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_before_featured_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Featured Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_related_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_before_related_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Related Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Generates a <span> element that can be used as a hidden addition to the DOM to be able to report
 * product list impressions and clicks on list pages like product category or tag pages.
 *
 * @param WC_Product $product A WooCommerce product object.
 * @param string     $listtype The name of the product list where the product is currently shown.
 * @param string     $itemix The index of the product in the product list. The first product should have the index no. 1.
 * @param string     $permalink The link where the click should land when a users clicks on this product element.
 * @return string A hidden <span> element that includes all product data needed for enhanced ecommerce reporting in product lists.
 */
function gtm4wp_woocommerce_get_product_list_item_extra_tag( $product, $listtype, $itemix, $permalink ) {
	global $wp_query, $gtm4wp_options;

	if ( ! isset( $product ) ) {
		return;
	}

	if ( ! ( $product instanceof WC_Product ) ) {
		return false;
	}

	$product_id = $product->get_id();

	$product_cat = '';
	if ( is_product_category() ) {
		global $wp_query;
		$cat_obj = $wp_query->get_queried_object();
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH ] ) {
			$product_cat = gtm4wp_get_product_category_hierarchy( $cat_obj->term_id );
		} else {
			$product_cat = $cat_obj->name;
		}
	} else {
		$product_cat = gtm4wp_get_product_category( $product_id, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH ] );
	}

	if ( is_search() ) {
		$list_name = __( 'Search Results', 'duracelltomi-google-tag-manager' );
	} elseif ( '' !== $listtype ) {
		$list_name = $listtype;
	} else {
		$list_name = __( 'General Product List', 'duracelltomi-google-tag-manager' );
	}

	$paged          = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
	$posts_per_page = get_query_var( 'posts_per_page' );
	if ( $posts_per_page < 1 ) {
		$posts_per_page = 1;
	}

	$eec_product_array = gtm4wp_process_product(
		$product,
		array(
			'productlink'  => $permalink,
			'listname'     => $list_name,
			'listposition' => (int) $itemix + ( $posts_per_page * ( $paged - 1 ) ),
		),
		'productlist'
	);

	if ( ! isset( $eec_product_array['brand'] ) ) {
		$eec_product_array['brand'] = '';
	}

	return sprintf(
		'<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_product_listposition="%s" data-gtm4wp_productlist_name="%s" data-gtm4wp_product_stocklevel="%s" data-gtm4wp_product_brand="%s"></span>',
		esc_attr( $eec_product_array['id'] ),
		esc_attr( $eec_product_array['name'] ),
		esc_attr( $eec_product_array['price'] ),
		esc_attr( $eec_product_array['category'] ),
		esc_url( $eec_product_array['productlink'] ),
		esc_attr( $eec_product_array['listposition'] ),
		esc_attr( $eec_product_array['listname'] ),
		esc_attr( $eec_product_array['stocklevel'] ),
		esc_attr( $eec_product_array['brand'] )
	);
}

/**
 * Executed during woocommerce_after_shop_loop_item.
 * Shows a hidden <span> element with product data to report enhanced ecommerce
 * product impression and click actions in product lists.
 *
 * @return void
 */
function gtm4wp_woocommerce_after_shop_loop_item() {
	global $product, $woocommerce_loop;

	$listtype = '';
	if ( isset( $woocommerce_loop['listtype'] ) && ( '' !== $woocommerce_loop['listtype'] ) ) {
		$listtype = $woocommerce_loop['listtype'];
	}

	$itemix = '';
	if ( isset( $woocommerce_loop['loop'] ) && ( '' !== $woocommerce_loop['loop'] ) ) {
		$itemix = $woocommerce_loop['loop'];
	}

	// no need to escape here as everthing is handled within the function call with esc_attr() and esc_url().
	echo gtm4wp_woocommerce_get_product_list_item_extra_tag( //phpcs:ignore
		$product,
		$listtype,
		$itemix,
		apply_filters(
			'the_permalink',
			get_permalink(),
			0
		)
	);
}

/**
 * Executed during woocommerce_cart_item_restored.
 * When the user restores the just removed cart item, this function stores the cart item key to
 * be able to generate an add_to_cart event after restoration completes.
 *
 * @param string $cart_item_key A unique cart item key.
 * @return void
 */
function gtm4wp_woocommerce_cart_item_restored( $cart_item_key ) {
	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( 'gtm4wp_product_readded_to_cart', $cart_item_key );
	}
}

/**
 * Executes during wp_enqueue_scripts.
 * Loads classic/standard and enhanced ecommerce frontend JavaScript codes to track on site events and interactions.
 *
 * @return void
 */
function gtm4wp_woocommerce_enqueue_scripts() {
	global $gtm4wp_options, $gtp4wp_plugin_url;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC, false );
		wp_enqueue_script( 'gtm4wp-woocommerce-classic', $gtp4wp_plugin_url . 'js/gtm4wp-woocommerce-classic.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC, false );
		wp_enqueue_script( 'gtm4wp-woocommerce-enhanced', $gtp4wp_plugin_url . 'js/gtm4wp-woocommerce-enhanced.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
	}
}

/**
 * Executed during wc_quick_view_before_single_product.
 * This function makes GTM4WP compatible with the WooCommerce Quick View plugin.
 * It allows GTM4WP to fire product detail action when quick view is opened.
 *
 * @return void
 */
function gtm4wp_wc_quick_view_before_single_product() {
	global $gtm4wp_options, $gtm4wp_datalayer_name;

	$data_layer = array(
		'event' => 'gtm4wp.changeDetailViewEEC',
	);

	if ( ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) || ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) ) {
		$postid  = get_the_ID();
		$product = wc_get_product( $postid );

		$eec_product_array = gtm4wp_process_product(
			$product,
			array(),
			'productdetail'
		);

		$data_layer['productRatingCounts']  = $product->get_rating_counts();
		$data_layer['productAverageRating'] = (float) $product->get_average_rating();
		$data_layer['productReviewCount']   = (int) $product->get_review_count();
		$data_layer['productType']          = $product->get_type();

		switch ( $data_layer['productType'] ) {
			case 'variable':
				$data_layer['productIsVariable'] = 1;

				$data_layer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array['id'] );
				$data_layer['ecomm_pagetype']   = 'product';
				$data_layer['ecomm_totalvalue'] = $eec_product_array['price'];

				break;

			case 'grouped':
				$data_layer['productIsVariable'] = 0;

				break;

			default:
				$data_layer['productIsVariable'] = 0;

				if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
					$data_layer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array['id'] );
					$data_layer['ecomm_pagetype']   = 'product';
					$data_layer['ecomm_totalvalue'] = $eec_product_array['price'];
				}

				if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
					$currency_code = get_woocommerce_currency();

					$data_layer['ecommerce'] = array(
						'currencyCode' => $currency_code,
						'detail'       => array(
							'products' => array(
								$eec_product_array,
							),
						),
					);
				}
		}
	}

	echo '
	<span style="display: none;" id="gtm4wp_quickview_data" data-gtm4wp_datalayer="' . esc_attr( wp_json_encode( $data_layer ) ) . '"></span>';
}

/**
 * Executed during woocommerce_grouped_product_list_column_label.
 * Adds product list impression info into every product listed on a grouped product detail page to
 * track product list impression and click interactions for individual products in the grouped product.
 *
 * @param string     $labelvalue Not used by this function, returns the value without modifying it.
 * @param WC_Product $product The WooCommerce product object being shown.
 * @return string The string that has been passed to the $labelvalue parameter without any modification.
 */
function gtm4wp_woocommerce_grouped_product_list_column_label( $labelvalue, $product ) {
	global $gtm4wp_options, $gtm4wp_grouped_product_ix;

	if ( ! isset( $product ) ) {
		return $labelvalue;
	}

	$list_name = __( 'Grouped Product Detail Page', 'duracelltomi-google-tag-manager' );

	$eec_product_array = gtm4wp_process_product(
		$product,
		array(
			'productlink'  => $product->get_permalink(),
			'listname'     => $list_name,
			'listposition' => $gtm4wp_grouped_product_ix,
		),
		'groupedproductlist'
	);

	$gtm4wp_grouped_product_ix++;

	if ( ! isset( $eec_product_array['brand'] ) ) {
		$eec_product_array['brand'] = '';
	}

	$labelvalue .=
		sprintf(
			'<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_id="%s" data-gtm4wp_product_sku="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_product_listposition="%s" data-gtm4wp_productlist_name="%s" data-gtm4wp_product_stocklevel="%s" data-gtm4wp_product_brand="%s"></span>',
			esc_attr( $eec_product_array['id'] ),
			esc_attr( $eec_product_array['sku'] ),
			esc_attr( $eec_product_array['name'] ),
			esc_attr( $eec_product_array['price'] ),
			esc_attr( $eec_product_array['category'] ),
			esc_url( $eec_product_array['productlink'] ),
			esc_attr( $eec_product_array['listposition'] ),
			esc_attr( $eec_product_array['listname'] ),
			esc_attr( $eec_product_array['stocklevel'] ),
			esc_attr( $eec_product_array['brand'] )
		);

	return $labelvalue;
}

/**
 * Executed during woocommerce_blocks_product_grid_item_html.
 * Adds product list impression data into a product list that has been generated using the block templates
 * provided by WooCommerce. This allows proper tracking ot WooCommerce Blocks with product list
 * impression and click actions.
 *
 * @param string     $content Product grid item HTML.
 * @param object     $data Product data passed to the template.
 * @param WC_Product $product Product object.
 * @return string The product grid item HTML with added hidden <span> element for ecommerce tracking.
 */
function gtm4wp_add_productdata_to_wc_block( $content, $data, $product ) {
	$product_data_tag = gtm4wp_woocommerce_get_product_list_item_extra_tag( $product, '', 0, $data->permalink );

	return preg_replace( '/<li.+class=("|"[^"]+)wc-block-grid__product("|[^"]+")[^<]*>/i', '$0' . $product_data_tag, $content );
}

// do not add filter if someone enabled WooCommerce integration without an activated WooCommerce plugin.
if ( function_exists( 'WC' ) ) {
	add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, 'gtm4wp_woocommerce_datalayer_filter_items' );

	add_filter( 'loop_end', 'gtp4wp_woocommerce_reset_loop' );
	add_action( 'woocommerce_after_shop_loop_item', 'gtm4wp_woocommerce_after_shop_loop_item' );
	add_action( 'woocommerce_after_add_to_cart_button', 'gtm4wp_woocommerce_single_add_to_cart_tracking' );

	add_action( 'wp_enqueue_scripts', 'gtm4wp_woocommerce_enqueue_scripts' );
	add_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, 'gtm4wp_woocommerce_addglobalvars' );

	add_filter( 'woocommerce_blocks_product_grid_item_html', 'gtm4wp_add_productdata_to_wc_block', 10, 3 );

	add_action( 'woocommerce_thankyou', 'gtm4wp_woocommerce_thankyou' );

	if ( true === $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		add_action( 'woocommerce_before_template_part', 'gtm4wp_woocommerce_before_template_part' );
		add_action( 'woocommerce_after_template_part', 'gtm4wp_woocommerce_after_template_part' );
		add_filter( 'widget_title', 'gtm4wp_widget_title_filter' );
		add_action( 'wc_quick_view_before_single_product', 'gtm4wp_wc_quick_view_before_single_product' );
		add_filter( 'woocommerce_grouped_product_list_column_label', 'gtm4wp_woocommerce_grouped_product_list_column_label', 10, 2 );

		add_filter( 'woocommerce_cart_item_product', 'gtm4wp_woocommerce_cart_item_product_filter' );
		add_filter( 'woocommerce_cart_item_remove_link', 'gtm4wp_woocommerce_cart_item_remove_link_filter' );
		add_action( 'woocommerce_cart_item_restored', 'gtm4wp_woocommerce_cart_item_restored' );

		add_filter( 'woocommerce_related_products_args', 'gtm4wp_woocommerce_add_related_to_loop' );
		add_filter( 'woocommerce_related_products_columns', 'gtm4wp_woocommerce_add_related_to_loop' );
		add_filter( 'woocommerce_cross_sells_columns', 'gtm4wp_woocommerce_add_cross_sell_to_loop' );
		add_filter( 'woocommerce_upsells_columns', 'gtm4wp_woocommerce_add_upsells_to_loop' );

		add_action( 'woocommerce_shortcode_before_recent_products_loop', 'gtm4wp_before_recent_products_loop' );
		add_action( 'woocommerce_shortcode_before_sale_products_loop', 'gtm4wp_before_sale_products_loop' );
		add_action( 'woocommerce_shortcode_before_best_selling_products_loop', 'gtm4wp_before_best_selling_products_loop' );
		add_action( 'woocommerce_shortcode_before_top_rated_products_loop', 'gtm4wp_before_top_rated_products_loop' );
		add_action( 'woocommerce_shortcode_before_featured_products_loop', 'gtm4wp_before_featured_products_loop' );
		add_action( 'woocommerce_shortcode_before_related_products_loop', 'gtm4wp_before_related_products_loop' );
	}
}
