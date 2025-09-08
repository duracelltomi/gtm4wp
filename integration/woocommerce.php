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
define( 'GTM4WP_WPFILTER_EEC_ORDER_DATA', 'gtm4wp_eec_order_data' );
define( 'GTM4WP_WPFILTER_ECC_PURCHASE_DATALAYER', 'gtm4wp_purchase_datalayer' );
define( 'GTM4WP_WPFILTER_EEC_DATALAYER_PAGELOAD', 'gtm4wp_woocommerce_datalayer_on_pageload' );

require_once dirname( __FILE__ ) . '/ecommerce-generic.php';

$gtm4wp_product_counter   = 0;
$gtm4wp_last_widget_title = 'Sidebar Products';

$GLOBALS['gtm4wp_grouped_product_ix']               = 1;
$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = false;

/**
 * Function to be called on the gtm4wp_add_global_vars_array hook to output WooCommerce related global JavaScript variables.
 *
 * @param array $return The already added variables as key-value pairs in an associative array.
 * @return array The $return parameter with added global JavaScript variables as key-value pairs.
 */
function gtm4wp_woocommerce_add_global_vars( $return ) {
	global $gtm4wp_options;

	$return['gtm4wp_use_sku_instead']        = (int) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSESKU ] );
	$return['gtm4wp_currency']               = get_woocommerce_currency();
	$return['gtm4wp_product_per_impression'] = (int) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION ] );
	$return['gtm4wp_clear_ecommerce']        = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL ] );
	$return['gtm4wp_datalayer_max_timeout']  = (int) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT ] );

	return $return;
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
 * @return array|false The enhanced ecommerce product object of the WooCommerce product, or false if the product does not exist.
 */
function gtm4wp_woocommerce_process_product( $product, $additional_product_attributes, $attributes_used_for ) {
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
	$product_cat_parts = explode( '/', $product_cat );

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSESKU ] && ( '' !== $product_sku ) ) {
		$remarketing_id = $product_sku;
	}

	$_temp_productdata = array(
		'internal_id'              => $product_id,
		'item_id'                  => $remarketing_id,
		'item_name'                => $product->get_title(),
		'sku'                      => $product_sku ? $product_sku : $product_id,
		'price'                    => round( (float) wc_get_price_to_display( $product ), 2 ), // Unfortunately this does not force a .00 postfix for integers.
		'stocklevel'               => $product->get_stock_quantity(),
		'stockstatus'              => $product->get_stock_status(),
		'google_business_vertical' => $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ],
	);

	if ( 'variation' === $product_type ) {
		$_temp_productdata['item_group_id'] = $parent_product_id;
	}

	if ( 1 === count( $product_cat_parts ) ) {
		$_temp_productdata['item_category'] = $product_cat_parts[0];
	} elseif ( count( $product_cat_parts ) > 1 ) {
		$_temp_productdata['item_category'] = $product_cat_parts[0];

		$max_category_levels = min( 5, count( $product_cat_parts ) );
		for ( $i = 1; $i < $max_category_levels; $i++ ) {
			$_temp_productdata[ 'item_category' . ( $i + 1 ) ] = $product_cat_parts[ $i ];
		}
	}

	$_temp_productdata[ gtm4wp_get_gads_product_id_variable_name( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ] ) ] = gtm4wp_prefix_productid( $_temp_productdata['item_id'], $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX ] );

	if ( '' !== $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY ] ) {
		if ( isset( $parent_product_id ) && ( 0 !== $parent_product_id ) ) {
			$product_id_to_query = $parent_product_id;
		} else {
			$product_id_to_query = $product_id;
		}

		$_temp_productdata['item_brand'] = gtm4wp_get_product_term( $product_id_to_query, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY ] );
	}

	if ( 'variation' === $product_type ) {
		$_temp_productdata['item_variant'] = implode( ',', $product->get_variation_attributes() );
	}

	$_temp_productdata = array_merge( $_temp_productdata, $additional_product_attributes );

	/**
	 * Filters the ecommerce array before using it for tracking.
	 * Can be used to add custom dimensions and metrics on your own or to later existing product attributes based on your own logic.
	 *
	 * Called before outputting any of the following ecommerce action.
	 * The action can be identified using the attributes_used_for parameter of the filter.
	 *
	 * purchase: order received page
	 * cart: cart page
	 * checkout: checkout page
	 * productdetail: product detail page
	 * readdedtocart: user clicked on the “Undo” link on the cart page after removing an item
	 * addtocartsingle: product added to cart
	 * widgetproduct: product shown in a sidebar widget
	 * productlist: product shown in a product list (category page or special product list like ‘New products’)
	 * groupedproductlist: product shown on a product detail page of a grouped product
	 *
	 * @param array  $_temp_productdata   An associative array containing all GA4 product attributes as well as any custom attribute
	 * @param string $attributes_used_for The name of the ecommerce action where this product will be used
	 */
	return apply_filters( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY, $_temp_productdata, $attributes_used_for );
}

/**
 * Takes a WooCommerce order and returns an associative array that can be used
 * for enhanced ecommerce tracking and Google Ads dynamic remarketing (legacy version).
 *
 * @param WC_Order $order The order that needs to be processed.
 * @return array An array with an array of product data.
 */
function gtm4wp_woocommerce_process_order_items( $order ) {
	$order_data = array();

	if ( ! $order ) {
		return $order_data;
	}

	if ( ! ( $order instanceof WC_Order ) ) {
		return $order_data;
	}

	$order_items = $order->get_items();

	if ( $order_items ) {
		foreach ( $order_items as $order_item ) {
			/**
			 * This filter allows 3rd party code to exclude specific products from reporting.
			 *
			 * @param bool          true        Constant value telling 3rd party code that the order item will be included in reporting if not changed by the filter.
			 * @param WC_Order_Item $order_item The order item object retrived from WooCommerce.
			 *
			 * return bool If the filter returns false, the order item will be omitted from processing.
			 */
			if ( ! apply_filters( GTM4WP_WPFILTER_EEC_ORDER_ITEM, true, $order_item ) ) {
				continue;
			}

			$product       = $order_item->get_product();
			$inc_tax       = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) );
			$product_price = round( (float) $order->get_item_total( $order_item, $inc_tax ), 2 );

			$eec_product_array = gtm4wp_woocommerce_process_product(
				$product,
				array(
					'quantity' => $order_item->get_quantity(),
					'price'    => $product_price,
				),
				'purchase'
			);

			unset( $eec_product_array['internal_id'] );

			if ( $eec_product_array ) {
				$order_data[] = $eec_product_array;
			}
		}
	}

	// No need to apply a filter here since all products in the array have been already filtered in gtm4wp_woocommerce_process_product().
	return $order_data;
}

/**
 * Returns an associative array that can be used in the data layer to output the raw order data.
 *
 * @param WC_Order $order       The WooCommerce order object.
 * @param array    $order_items An array including product data generated with gtm4wp_woocommerce_process_product().
 * @return array
 */
function gtm4wp_woocommerce_get_raw_order_datalayer( $order, $order_items ) {
	$order_data = array();

	if ( ! ( $order instanceof WC_Order ) ) {
		return $order_data;
	}

	if ( ! is_array( $order_items ) ) {
		return $order_data;
	}

	$billing_email_hash = gtm4wp_normalize_and_hash_email_address( 'sha256', $order->get_billing_email() );
	$billing_first_hash = gtm4wp_normalize_and_hash( 'sha256', $order->get_billing_first_name(), false );
	$billing_last_hash  = gtm4wp_normalize_and_hash( 'sha256', $order->get_billing_last_name(), false );
	$billing_phone_hash = gtm4wp_normalize_and_hash( 'sha256', $order->get_billing_phone(), true );

	$order_data = array(
		'attributes' => array(
			'date'                 => $order->get_date_created()->date( 'c' ),

			'order_number'         => $order->get_order_number(),
			'order_key'            => $order->get_order_key(),

			'payment_method'       => esc_js( $order->get_payment_method() ),
			'payment_method_title' => esc_js( $order->get_payment_method_title() ),

			'shipping_method'      => esc_js( $order->get_shipping_method() ),

			'status'               => esc_js( $order->get_status() ),

			'coupons'              => implode( ', ', $order->get_coupon_codes() ),
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
				'first_name'      => esc_js( $order->get_billing_first_name() ),
				'first_name_hash' => esc_js( $billing_first_hash ),
				'last_name'       => esc_js( $order->get_billing_last_name() ),
				'last_name_hash'  => esc_js( $billing_last_hash ),
				'company'         => esc_js( $order->get_billing_company() ),
				'address_1'       => esc_js( $order->get_billing_address_1() ),
				'address_2'       => esc_js( $order->get_billing_address_2() ),
				'city'            => esc_js( $order->get_billing_city() ),
				'state'           => esc_js( $order->get_billing_state() ),
				'postcode'        => esc_js( $order->get_billing_postcode() ),
				'country'         => esc_js( $order->get_billing_country() ),
				'email'           => esc_js( $order->get_billing_email() ),
				'emailhash'       => esc_js( $billing_email_hash ), // deprecated.
				'email_hash'      => esc_js( $billing_email_hash ),
				'phone'           => esc_js( $order->get_billing_phone() ),
				'phone_hash'      => esc_js( $billing_phone_hash ),
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
		'items'      => $order_items,
	);

	/**
	 * Filters the orderData array before using it for tracking.
	 * Can be used to add custom order or even product data into the data layer.
	 *
	 * @param array  $order_data An associative array containing all data (head data and products) about the currently placed order.
	 * @param WC_Order $order       The WooCommerce order object.
	 */
	return apply_filters( GTM4WP_WPFILTER_EEC_ORDER_DATA, $order_data, $order );
}
/**
 * Takes a WooCommerce order and order items and generates the standard/classic and
 * enhanced ecommerce version of the purchase data layer codes for Universal Analytics.
 *
 * @param WC_Order $order The WooCommerce order that needs to be transformed into an enhanced ecommerce data layer.
 * @param array    $order_items The array returned by gtm4wp_woocommerce_process_order_items(). It not set, then function will call gtm4wp_woocommerce_process_order_items().
 * @return array The data layer content as an associative array that can be passed to json_encode() to product a JavaScript object used by GTM.
 */
function gtm4wp_woocommerce_get_purchase_datalayer( $order, $order_items = null ) {
	global $gtm4wp_options;

	$data_layer = array();

	if ( $order instanceof WC_Order ) {
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

		$data_layer['event']     = 'purchase';
		$data_layer['ecommerce'] = array(
			'currency'       => $order_currency,
			'transaction_id' => $order->get_order_number(),
			'affiliation'    => '',
			'value'          => $order_revenue,
			'tax'            => (float) $order->get_total_tax(),
			'shipping'       => (float) ( $order->get_shipping_total() ),
			'coupon'         => implode( ', ', $order->get_coupon_codes() ),
		);

		if ( isset( $order_items ) ) {
			$_order_items = $order_items;
		} else {
			$_order_items = gtm4wp_woocommerce_process_order_items( $order );
		}

		if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
			$data_layer['ecommerce']['items'] = $_order_items;
		}
	}

	/**
	 * Filters the ecommerce purchase data layer content.
	 * Can be used to add custom data to the data layer when the purhcase ecommerce action is included.
	 *
	 * @param array $data_layer An associative array containing the full data layer including purchase header attributes.
	 * @param WC_Order $order The WooCommerce order that needs to be transformed into an enhanced ecommerce data layer.
	 */
	return apply_filters( GTM4WP_WPFILTER_ECC_PURCHASE_DATALAYER, $data_layer, $order );
}

/**
 * Function executed when the main GTM4WP data layer generation happens.
 * Hooks into gtm4wp_compile_datalayer.
 *
 * @param array $data_layer An array of key-value pairs that will be converted into a JavaScript object on the frontend for GTM.
 * @return array Extended data layer content with WooCommerce data added.
 */
function gtm4wp_woocommerce_datalayer_filter_items( $data_layer ) {
	global $gtm4wp_options, $wp, $gtm4wp_woocommerce_purchase_data_pushed;

	if ( array_key_exists( 'HTTP_X_REQUESTED_WITH', $_SERVER ) ) {
		return $data_layer;
	}

	$woo = WC();

	// Customer data will be present on every pageview if feature is enabled.
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
			$data_layer['customerBillingState']     = $woo_customer->get_billing_state();
			$data_layer['customerBillingPostcode']  = $woo_customer->get_billing_postcode();
			$data_layer['customerBillingCountry']   = $woo_customer->get_billing_country();
			$data_layer['customerBillingEmail']     = $woo_customer->get_billing_email();
			$data_layer['customerBillingEmailHash'] = gtm4wp_normalize_and_hash_email_address( 'sha256', $woo_customer->get_billing_email() );
			$data_layer['customerBillingPhone']     = $woo_customer->get_billing_phone();

			$data_layer['customerShippingFirstName'] = $woo_customer->get_shipping_first_name();
			$data_layer['customerShippingLastName']  = $woo_customer->get_shipping_last_name();
			$data_layer['customerShippingCompany']   = $woo_customer->get_shipping_company();
			$data_layer['customerShippingAddress1']  = $woo_customer->get_shipping_address_1();
			$data_layer['customerShippingAddress2']  = $woo_customer->get_shipping_address_2();
			$data_layer['customerShippingCity']      = $woo_customer->get_shipping_city();
			$data_layer['customerShippingState']     = $woo_customer->get_shipping_state();
			$data_layer['customerShippingPostcode']  = $woo_customer->get_shipping_postcode();
			$data_layer['customerShippingCountry']   = $woo_customer->get_shipping_country();
		}
	}

	// Cart content will be present on every pageview if feature is enabled.
	if (
		$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL ] &&
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
			/**
			 * Applying WooCommerce's own woocommerce_cart_item_product filter here is essential in order to hide everything
			 * from tracking codes that is not visible to the user as well.
			 */
			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

			/**
			 * This filter allows 3rd party code to exclude specific products from reporting.
			 *
			 * @param bool  true            Constant value telling 3rd party code that the order item will be included in reporting if not changed by the filter.
			 * @param array $cart_item_data Associative array generated by WooCommerce returned by the WC()->cart->get_cart() function call.
			 *
			 * return bool If the filter returns false, the cart item will be omitted from processing.
			 */
			if (
				! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data )
				|| ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item_data, $cart_item_id )
				) {
				continue;
			}

			$eec_product_array = gtm4wp_woocommerce_process_product(
				$product,
				array(
					'quantity' => $cart_item_data['quantity'],
				),
				'cart'
			);

			unset( $eec_product_array['internal_id'] );

			$data_layer['cartContent']['items'][] = $eec_product_array;
		}
	}

	// Product detail view data layer content.
	if ( is_product() ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
			$postid  = get_the_ID();
			$product = wc_get_product( $postid );

			$eec_product_array = gtm4wp_woocommerce_process_product(
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

					if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT ] ) {
						$gtm4wp_currency = get_woocommerce_currency();
						unset( $eec_product_array['internal_id'] );

						gtm4wp_datalayer_push(
							'view_item',
							array(
								'ecommerce' => array(
									'currency' => $gtm4wp_currency,
									'value'    => $eec_product_array['price'],
									'items'    => array(
										$eec_product_array,
									),
								),
							)
						);
					}

					break;

				case 'grouped':
					$data_layer['productIsVariable'] = 0;

					break;

				default:
					$data_layer['productIsVariable'] = 0;

					$gtm4wp_currency = get_woocommerce_currency();
					unset( $eec_product_array['internal_id'] );

					gtm4wp_datalayer_push(
						'view_item',
						array(
							'ecommerce' => array(
								'currency' => $gtm4wp_currency,
								'value'    => $eec_product_array['price'],
								'items'    => array(
									$eec_product_array,
								),
							),
						)
					);
			}
		}
	} elseif ( is_cart() ) {
		// Cart page data layer content.

		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
			$gtm4wp_cart_products = array();
			$gtm4wp_cart_total    = 0;

			$gtm4wp_currency = get_woocommerce_currency();

			foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
				/**
				 * Applying WooCommerce's own woocommerce_cart_item_product filter here is essential in order to hide everything
				 * from tracking codes that is not visible to the user as well.
				 */
				$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

				/**
				 * This filter allows 3rd party code to exclude specific products from reporting.
				 *
				 * @param bool  true            Constant value telling 3rd party code that the order item will be included in reporting if not changed by the filter.
				 * @param array $cart_item_data Associative array generated by WooCommerce returned by the WC()->cart->get_cart() function call.
				 *
				 * return bool If the filter returns false, the cart item will be omitted from processing.
				 */
				if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
					continue;
				}

				$eec_product_array = gtm4wp_woocommerce_process_product(
					$product,
					array(
						'quantity' => $cart_item_data['quantity'],
					),
					'cart'
				);

				unset( $eec_product_array['internal_id'] );

				$gtm4wp_cart_products[] = $eec_product_array;
				$gtm4wp_cart_total     += $eec_product_array['price'] * $eec_product_array['quantity'];
			}

			// Do not fire GTM event if no products are in the cart.
			if ( count( $gtm4wp_cart_products ) > 0 ) {
				gtm4wp_datalayer_push(
					'view_cart',
					array(
						'ecommerce' => array(
							'currency' => $gtm4wp_currency,
							'value'    => $gtm4wp_cart_total,
							'items'    => $gtm4wp_cart_products,
						),
					)
				);
			}
		}
	} elseif ( is_order_received_page() ) {
		// Order received page data layer content.

		$do_not_flag_tracked_order = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ] );

		// Supressing 'Processing form data without nonce verification.' message as there is no nonce accesible in this case.
		$order_id = filter_var( wp_unslash( isset( $_GET['order'] ) ? $_GET['order'] : '' ), FILTER_VALIDATE_INT ); // phpcs:ignore
		if ( ! $order_id && isset( $wp->query_vars['order-received'] ) ) {
			$order_id = $wp->query_vars['order-received'];
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

		/**
		 * From this point if for any reason purchase data is not pushed
		 * that is because for a specific reason.
		 * In any other case woocommerce_thankyou hook will be the fallback if
		 * is_order_received_page does not work.
		 */
		$gtm4wp_woocommerce_purchase_data_pushed = true;

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

		// Raw order data will be outputted regardless of whether the purhcase has been already tracked previously, since this data is not meant to track using GA.
		if ( isset( $order ) && $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERDATA ] ) {
			$order_items = gtm4wp_woocommerce_process_order_items( $order );

			$data_layer['orderData'] = gtm4wp_woocommerce_get_raw_order_datalayer( $order, $order_items );
		}

		if ( isset( $order ) && ( 1 === (int) $order->get_meta( '_ga_tracked', true ) ) && ! $do_not_flag_tracked_order ) {
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
			/**
			 * Variable for Google Smart Shopping campaign new customer reporting.
			 *
			 * @see https://support.google.com/google-ads/answer/9917012?hl=en-AU#zippy=%2Cinstall-with-google-tag-manager
			 */
			$data_layer['new_customer'] = \Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::is_returning_customer( $order ) === false;

			$purchase_data_layer = gtm4wp_woocommerce_get_purchase_datalayer( $order, $order_items );

			$before_purchase_dl_push = '
			// Check whether this order has been already tracked in this browser.

			// Read order id already tracked from cookies or local storage.
			let gtm4wp_orderid_tracked = "";

			if ( !window.localStorage ) {
				let gtm4wp_cookie = "; " + document.cookie;
				let gtm4wp_cookie_parts = gtm4wp_cookie.split( "; gtm4wp_orderid_tracked=" );
				if ( gtm4wp_cookie_parts.length == 2 ) {
					gtm4wp_orderid_tracked = gtm4wp_cookie_parts.pop().split(";").shift();
				}
			} else {
				gtm4wp_orderid_tracked = window.localStorage.getItem( "gtm4wp_orderid_tracked" );
			}

			// Check whether this order has been already tracked before in this browser.
			let gtm4wp_order_already_tracked = false;
			if ( gtm4wp_orderid_tracked && ( "' . esc_js( $order->get_order_number() ) . '" == gtm4wp_orderid_tracked ) ) {
				gtm4wp_order_already_tracked = true;
			}

			// only push purchase action if not tracked already.
			if ( !gtm4wp_order_already_tracked ) {';

			$after_purchase_dl_push = '
			}
			
			// Store order ID to prevent tracking this purchase again.
			if ( !window.localStorage ) {
				var gtm4wp_orderid_cookie_expire = new Date();
				gtm4wp_orderid_cookie_expire.setTime( gtm4wp_orderid_cookie_expire.getTime() + (365*24*60*60*1000) );
				var gtm4wp_orderid_cookie_expires_part = "expires=" + gtm4wp_orderid_cookie_expire.toUTCString();
				document.cookie = "gtm4wp_orderid_tracked=" + "' . esc_js( $order->get_order_number() ) . '" + ";" + gtm4wp_orderid_cookie_expires_part + ";path=/";
			} else {
				window.localStorage.setItem( "gtm4wp_orderid_tracked", "' . esc_js( $order->get_order_number() ) . '" );
			}';

			gtm4wp_datalayer_push(
				$purchase_data_layer['event'],
				$purchase_data_layer,
				$before_purchase_dl_push,
				$after_purchase_dl_push
			);


			if ( ! $do_not_flag_tracked_order ) {
				$order->update_meta_data( '_ga_tracked', 1 );
				$order->save();
			}
		}
	} elseif ( is_checkout() ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
			$gtm4wp_checkout_products = array();
			$gtm4wp_checkout_total    = 0;

			$gtm4wp_currency = get_woocommerce_currency();

			foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
				/**
				 * Applying WooCommerce's own woocommerce_cart_item_product filter here is essential in order to hide everything
				 * from tracking codes that is not visible to the user as well.
				 */
				$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

				/**
				 * This filter allows 3rd party code to exclude specific products from reporting.
				 *
				 * @param bool  true            Constant value telling 3rd party code that the order item will be included in reporting if not changed by the filter.
				 * @param array $cart_item_data Associative array generated by WooCommerce returned by the WC()->cart->get_cart() function call.
				 *
				 * return bool If the filter returns false, the cart item will be omitted from processing.
				 */
				if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
					continue;
				}

				$eec_product_array = gtm4wp_woocommerce_process_product(
					$product,
					array(
						'quantity' => $cart_item_data['quantity'],
					),
					'checkout'
				);

				unset( $eec_product_array['internal_id'] );

				$gtm4wp_checkout_products[] = $eec_product_array;
				$gtm4wp_checkout_total     += $eec_product_array['quantity'] * $eec_product_array['price'];
			} // end foreach cart item

			// Do not fire GTM event if no products are in the cart.
			if ( count( $gtm4wp_checkout_products ) > 0 ) {
				gtm4wp_datalayer_push(
					'begin_checkout',
					array(
						'ecommerce' => array(
							'currency' => $gtm4wp_currency,
							'value'    => $gtm4wp_checkout_total,
							'items'    => $gtm4wp_checkout_products,
						),
					)
				);
			}

			wc_enqueue_js(
				'
				window.gtm4wp_checkout_products = ' . wp_json_encode( $gtm4wp_checkout_products ) . ';
				window.gtm4wp_checkout_value    = ' . (float) $gtm4wp_checkout_total . ';'
			);
		}
	}

	// Handle add_to_cart event when product was readded after removing from the cart.
	if ( $woo && $woo->session ) {
		$cart_readded_hash = $woo->session->get( 'gtm4wp_product_readded_to_cart' );

		if ( isset( $cart_readded_hash ) ) {
			$cart_item = $woo->cart->get_cart_item( $cart_readded_hash );

			if ( ! empty( $cart_item ) ) {
				$product = $cart_item['data'];

				$eec_product_array = gtm4wp_woocommerce_process_product(
					$product,
					array(
						'quantity' => $cart_item['quantity'],
					),
					'readdedtocart'
				);

				$gtm4wp_currency = get_woocommerce_currency();
				unset( $eec_product_array['internal_id'] );

				gtm4wp_datalayer_push(
					'add_to_cart',
					array(
						'ecommerce' => array(
							'currency' => $gtm4wp_currency,
							'value'    => $eec_product_array['price'] * $eec_product_array['quantity'],
							'items'    => array( $eec_product_array ),
						),
					)
				);
			}

			$woo->session->set( 'gtm4wp_product_readded_to_cart', null );
		}
	}

	gtm4wp_fire_additional_datalayer_pushes();

	return apply_filters( GTM4WP_WPFILTER_EEC_DATALAYER_PAGELOAD, $data_layer );
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
	global $gtm4wp_options, $gtm4wp_woocommerce_purchase_data_pushed;

	if ( function_exists('is_order_received_page') && is_order_received_page() ) {
		return;
	}

	/*
	If this flag is set to true, it means that the puchase event was fired
	when capturing the is_order_received_page template tag therefore
	no need to handle this here twice
	*/
	if ( $gtm4wp_woocommerce_purchase_data_pushed ) {
		return;
	}

	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );
	}

	$data_layer = array();

	if ( isset( $order ) && $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ] ) {
		$now = new DateTime( 'now', $order->get_date_created()->getTimezone() );
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

	$order_items = null;

	// Raw order data will be outputted regardless of whether the purhcase has been already tracked previously, since this data is not meant to track using GA.
	if ( isset( $order ) && $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERDATA ] ) {
		$order_items = gtm4wp_woocommerce_process_order_items( $order );

		$data_layer['orderData'] = gtm4wp_woocommerce_get_raw_order_datalayer( $order, $order_items );
	}

	$do_not_flag_tracked_order = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ] );
	if ( isset( $order ) && ( 1 === (int) $order->get_meta( '_ga_tracked', true ) ) && ! $do_not_flag_tracked_order ) {
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
		/**
		 * Variable for Google Smart Shopping campaign new customer reporting.
		 *
		 * @see https://support.google.com/google-ads/answer/9917012?hl=en-AU#zippy=%2Cinstall-with-google-tag-manager
		 */
		$data_layer['new_customer'] = \Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::is_returning_customer( $order ) === false;

		$purchase_data_layer = gtm4wp_woocommerce_get_purchase_datalayer( $order, $order_items );

		$data_layer = array_merge(
			$data_layer,
			$purchase_data_layer
		);

		if ( ! $do_not_flag_tracked_order ) {
			$order->update_meta_data( '_ga_tracked', 1 );
			$order->save();
		}
	}
}

/**
 * Function executed with the woocommerce_after_add_to_cart_button hook.
 *
 * @return void
 */
function gtm4wp_woocommerce_single_add_to_cart_tracking() {
	global $product, $gtm4wp_options;

	// exit early if there is nothing to do.
	if ( false === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
		return;
	}

	$eec_product_array = gtm4wp_woocommerce_process_product(
		$product,
		array(),
		'addtocartsingle'
	);

	echo '<input type="hidden" name="gtm4wp_product_data" value="' . esc_attr( wp_json_encode( $eec_product_array ) ) . '" />' . "\n";
}

/**
 * Ecommerce product array with the product that is currently shown in the cart.
 *
 * @var array
 */
$GLOBALS['gtm4wp_cart_item_proddata'] = '';

/**
 * Executed during woocommerce_cart_item_product for each product in the cart.
 * Stores the ecommerce product data into a global variable
 * to be processed when the cart item is rendered.
 *
 * @see https://woocommerce.github.io/code-reference/files/woocommerce-templates-cart-cart.html#source-view.41
 *
 * @param WC_Product $product A WooCommerce product that is shown in the cart.
 * @param string     $cart_item Not used by this hook.
 * @param string     $cart_id Not used by this hook.
 * @return array Ecommerce product data in an associative array.
 */
function gtm4wp_woocommerce_cart_item_product_filter( $product, $cart_item = '', $cart_id = '' ) {
	global $gtm4wp_cart_item_proddata;

	$eec_product_array = gtm4wp_woocommerce_process_product(
		$product,
		array(
			'productlink' => apply_filters( 'the_permalink', get_permalink(), 0 ),
		),
		'cart'
	);

	$gtm4wp_cart_item_proddata = $eec_product_array;

	return $product;
}

/**
 * Executed during woocommerce_cart_item_remove_link.
 * Adds additional product data into the remove product link of the cart table to be able to track
 * ecommerce remove_from_cart action with product data.
 *
 * @global gtm4wp_cart_item_proddata The previously stored product array in gtm4wp_woocommerce_cart_item_product_filter.
 *
 * @param string $remove_from_cart_link The HTML code of the remove from cart link element.
 * @return string The updated remove product from cart link with product data added in data attributes.
 */
function gtm4wp_woocommerce_cart_item_remove_link_filter( $remove_from_cart_link ) {
	global $gtm4wp_cart_item_proddata;

	if ( ! isset( $gtm4wp_cart_item_proddata ) ) {
		return $remove_from_cart_link;
	}

	if ( ! is_array( $gtm4wp_cart_item_proddata ) ) {
		return $remove_from_cart_link;
	}

	if ( ! isset( $gtm4wp_cart_item_proddata['item_variant'] ) ) {
		$gtm4wp_cart_item_proddata['item_variant'] = '';
	}

	if ( ! isset( $gtm4wp_cart_item_proddata['item_brand'] ) ) {
		$gtm4wp_cart_item_proddata['item_brand'] = '';
	}

	$cartlink_with_data = sprintf(
		'data-gtm4wp_product_data="%s" href="',
		esc_attr( wp_json_encode( $gtm4wp_cart_item_proddata ) )
	);

	$gtm4wp_cart_item_proddata = '';

	return gtm4wp_str_replace_first( 'href="', $cartlink_with_data, $remove_from_cart_link );
}

/**
 * Executed during loop_end.
 * Resets the product impression list name after a specific product list ended rendering.
 *
 * @return void
 */
function gtm4wp_woocommerce_reset_loop() {
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
 * Executed during widget_title.
 * This hook is used for any custom (classic) product list widget with custom title.
 * The widget title will be used to report a custom product list name into Google Analytics.
 * This function also resets the $gtm4wp_product_counter global variable to report the first
 * product in the widget in the proper position.
 *
 * @param string $widget_title The title of the widget being rendered.
 * @return string The updated widget title which is not changed by this function.
 */
function gtm4wp_woocommerce_widget_title_filter( $widget_title ) {
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
function gtm4wp_woocommerce_before_recent_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Recent Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_sale_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_woocommerce_before_sale_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Sale Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_best_selling_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_woocommerce_before_best_selling_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Best Selling Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_top_rated_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_woocommerce_before_top_rated_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Top Rated Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_featured_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_woocommerce_before_featured_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Featured Products', 'duracelltomi-google-tag-manager' );
}

/**
 * Executed during woocommerce_shortcode_before_related_products_loop.
 * Sets the product list title for product list impression reporting.
 *
 * @return void
 */
function gtm4wp_woocommerce_before_related_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Related Products', 'duracelltomi-google-tag-manager' );
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
	global $product, $gtm4wp_product_counter, $gtm4wp_last_widget_title;

	$productitem = ob_get_contents();
	ob_end_clean();

	if ( 'content-widget-product.php' === $template_name ) {
		$eec_product_array = gtm4wp_woocommerce_process_product(
			$product,
			array(
				'productlink'    => apply_filters( 'the_permalink', get_permalink(), 0 ),
				'item_list_name' => $gtm4wp_last_widget_title,
				'index'          => $gtm4wp_product_counter,
			),
			'widgetproduct'
		);

		if ( ! isset( $eec_product_array['item_brand'] ) ) {
			$eec_product_array['item_brand'] = '';
		}

		$productlink_with_data = sprintf(
			'data-gtm4wp_product_data="%s" href="',
			esc_attr( wp_json_encode( $eec_product_array ) )
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
	if ( ! isset( $product ) ) {
		return;
	}

	if ( ! ( $product instanceof WC_Product ) ) {
		return false;
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

	$eec_product_array = gtm4wp_woocommerce_process_product(
		$product,
		array(
			'productlink'    => $permalink,
			'item_list_name' => $list_name,
			'index'          => (int) $itemix + ( $posts_per_page * ( $paged - 1 ) ),
			'product_type'   => $product->get_type(),
		),
		'productlist'
	);

	if ( false === $eec_product_array ) {
		return false;
	}

	if ( ! isset( $eec_product_array['item_brand'] ) ) {
		$eec_product_array['item_brand'] = '';
	}

	return sprintf(
		'<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_data="%s"></span>',
		esc_attr( wp_json_encode( $eec_product_array ) )
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
	$woo = WC();

	if ( $woo && $woo->session ) {
		$woo->session->set( 'gtm4wp_product_readded_to_cart', $cart_item_key );
	}
}

/**
 * Executed during wp_enqueue_scripts.
 * Loads ecommerce frontend JavaScript codes to track on site events and interactions.
 *
 * @return void
 */
function gtm4wp_woocommerce_enqueue_scripts() {
	global $gtm4wp_options, $gtp4wp_script_path;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE, true );
		wp_enqueue_script( 'gtm4wp-ecommerce-generic', $gtp4wp_script_path . 'gtm4wp-ecommerce-generic.js', array(), GTM4WP_VERSION, $in_footer );
		wp_enqueue_script( 'gtm4wp-woocommerce', $gtp4wp_script_path . 'gtm4wp-woocommerce.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
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
	global $gtm4wp_options;

	$data_layer = array(
		'event' => 'view_item',
	);

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
		$postid  = get_the_ID();
		$product = wc_get_product( $postid );

		$eec_product_array = gtm4wp_woocommerce_process_product(
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

				break;

			case 'grouped':
				$data_layer['productIsVariable'] = 0;

				break;

			default:
				$data_layer['productIsVariable'] = 0;

				$gtm4wp_currency = get_woocommerce_currency();

				$data_layer['ecommerce'] = array(
					'currency' => $gtm4wp_currency,
					'value'    => $eec_product_array['price'],
					'item'     => $eec_product_array,
				);
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
	global $gtm4wp_grouped_product_ix;

	if ( ! isset( $product ) ) {
		return $labelvalue;
	}

	$list_name = __( 'Grouped Product Detail Page', 'duracelltomi-google-tag-manager' );

	$eec_product_array = gtm4wp_woocommerce_process_product(
		$product,
		array(
			'productlink'    => $product->get_permalink(),
			'item_list_name' => $list_name,
			'index'          => $gtm4wp_grouped_product_ix,
		),
		'groupedproductlist'
	);

	$gtm4wp_grouped_product_ix++;

	if ( ! isset( $eec_product_array['item_brand'] ) ) {
		$eec_product_array['item_brand'] = '';
	}

	$labelvalue .=
		sprintf(
			'<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_data="%s"></span>',
			esc_attr( wp_json_encode( $eec_product_array ) )
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
function gtm4wp_woocommerce_add_productdata_to_wc_block( $content, $data, $product ) {
	$product_data_tag = gtm4wp_woocommerce_get_product_list_item_extra_tag( $product, '', 0, $data->permalink );

	return preg_replace( '/<li.+class=("|"[^"]+)wc-block-grid__product("|[^"]+")[^<]*>/i', '$0' . $product_data_tag, $content );
}

add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, 'gtm4wp_woocommerce_datalayer_filter_items' );

add_filter( 'loop_end', 'gtm4wp_woocommerce_reset_loop' );
add_action( 'woocommerce_after_shop_loop_item', 'gtm4wp_woocommerce_after_shop_loop_item' );
add_action( 'woocommerce_after_add_to_cart_button', 'gtm4wp_woocommerce_single_add_to_cart_tracking' );

add_action( 'wp_enqueue_scripts', 'gtm4wp_woocommerce_enqueue_scripts' );
add_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, 'gtm4wp_woocommerce_add_global_vars' );

add_filter( 'woocommerce_blocks_product_grid_item_html', 'gtm4wp_woocommerce_add_productdata_to_wc_block', 10, 3 );

add_action( 'woocommerce_thankyou', 'gtm4wp_woocommerce_thankyou' );

if ( true === $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ] ) {
	add_action( 'woocommerce_before_template_part', 'gtm4wp_woocommerce_before_template_part' );
	add_action( 'woocommerce_after_template_part', 'gtm4wp_woocommerce_after_template_part' );
	add_filter( 'widget_title', 'gtm4wp_woocommerce_widget_title_filter' );
	add_action( 'wc_quick_view_before_single_product', 'gtm4wp_wc_quick_view_before_single_product' );
	add_filter( 'woocommerce_grouped_product_list_column_label', 'gtm4wp_woocommerce_grouped_product_list_column_label', 10, 2 );

	add_filter( 'woocommerce_cart_item_product', 'gtm4wp_woocommerce_cart_item_product_filter' );
	add_filter( 'woocommerce_cart_item_remove_link', 'gtm4wp_woocommerce_cart_item_remove_link_filter' );
	add_action( 'woocommerce_cart_item_restored', 'gtm4wp_woocommerce_cart_item_restored' );

	add_filter( 'woocommerce_related_products_args', 'gtm4wp_woocommerce_add_related_to_loop' );
	add_filter( 'woocommerce_related_products_columns', 'gtm4wp_woocommerce_add_related_to_loop' );
	add_filter( 'woocommerce_cross_sells_columns', 'gtm4wp_woocommerce_add_cross_sell_to_loop' );
	add_filter( 'woocommerce_upsells_columns', 'gtm4wp_woocommerce_add_upsells_to_loop' );

	add_action( 'woocommerce_shortcode_before_recent_products_loop', 'gtm4wp_woocommerce_before_recent_products_loop' );
	add_action( 'woocommerce_shortcode_before_sale_products_loop', 'gtm4wp_woocommerce_before_sale_products_loop' );
	add_action( 'woocommerce_shortcode_before_best_selling_products_loop', 'gtm4wp_woocommerce_before_best_selling_products_loop' );
	add_action( 'woocommerce_shortcode_before_top_rated_products_loop', 'gtm4wp_woocommerce_before_top_rated_products_loop' );
	add_action( 'woocommerce_shortcode_before_featured_products_loop', 'gtm4wp_woocommerce_before_featured_products_loop' );
	add_action( 'woocommerce_shortcode_before_related_products_loop', 'gtm4wp_woocommerce_before_related_products_loop' );
}
