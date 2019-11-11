<?php
define( 'GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY', 'gtm4wp_eec_product_array' );
define( 'GTM4WP_WPFILTER_EEC_CART_ITEM', 'gtm4wp_eec_cart_item' );
define( 'GTM4WP_WPFILTER_EEC_ORDER_ITEM', 'gtm4wp_eec_order_item' );

$gtm4wp_product_counter   = 0;
$gtm4wp_last_widget_title = 'Sidebar Products';
if ( function_exists( 'WC' ) ) {
	$GLOBALS['gtm4wp_is_woocommerce3']   = version_compare( WC()->version, '3.0', '>=' );
	$GLOBALS['gtm4wp_is_woocommerce3_7'] = version_compare( WC()->version, '3.7', '>=' );
} else {
	$GLOBALS['gtm4wp_is_woocommerce3']   = false;
	$GLOBALS['gtm4wp_is_woocommerce3_7'] = false;
}
$GLOBALS['gtm4wp_grouped_product_ix'] = 1;

function gtm4wp_woocommerce_addjs( $js ) {
	$woo = WC();

	if ( version_compare( $woo->version, '2.1', '>=' ) ) {
		wc_enqueue_js( $js );
	} else {
		$woo->add_inline_js( $js );
	}
}

// from https://snippets.webaware.com.au/ramblings/php-really-doesnt-unicode/
function gtm4wp_untexturize( $fancy ) {
	$fixes = false;

	if ( $fixes === false ) {
		$fixes = array(
			json_decode( '"\u201C"' ) => '"', // left  double quotation mark
			json_decode( '"\u201D"' ) => '"', // right double quotation mark
			json_decode( '"\u2018"' ) => "'", // left  single quotation mark
			json_decode( '"\u2019"' ) => "'", // right single quotation mark
			json_decode( '"\u2032"' ) => "'", // prime (minutes, feet)
			json_decode( '"\u2033"' ) => '"', // double prime (seconds, inches)
			json_decode( '"\u2013"' ) => '-', // en dash
			json_decode( '"\u2014"' ) => '--', // em dash
		);
	}

	$normal = strtr( $fancy, $fixes );

	return $normal;
}

function gtm4wp_woocommerce_html_entity_decode( $val ) {
	return gtm4wp_untexturize( html_entity_decode( $val, ENT_QUOTES, 'utf-8' ) );
}

function gtm4wp_prefix_productid( $product_id ) {
	global $gtm4wp_options;

	if ( '' != $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX ] ) {
		return $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX ] . $product_id;
	} else {
		return $product_id;
	}
}

// from https://stackoverflow.com/questions/1252693/using-str-replace-so-that-it-only-acts-on-the-first-match
function gtm4wp_str_replace_first( $from, $to, $subject ) {
	$from = '/' . preg_quote( $from, '/' ) . '/';

	return preg_replace( $from, $to, $subject, 1 );
}

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

function gtm4wp_get_product_category( $product_id, $fullpath = false ) {
	$product_cat = '';

	$_product_cats = get_the_terms( $product_id, 'product_cat' );
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

function gtm4wp_woocommerce_getproductterm( $product_id, $taxonomy ) {
	$gtm4wp_product_terms = get_the_terms( $product_id, $taxonomy );
	if ( is_array( $gtm4wp_product_terms ) && ( count( $gtm4wp_product_terms ) > 0 ) ) {
		return $gtm4wp_product_terms[0]->name;
	}

	return "";
}

function gtm4wp_process_product( $product, $additional_product_attributes, $attributes_used_for ) {
	global $gtm4wp_options, $gtm4wp_is_woocommerce3;

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

	if ( 'variation' == $product_type ) {
		$parent_product_id = ( $gtm4wp_is_woocommerce3 ? $product->get_parent_id() : $product->id );
		$product_cat       = gtm4wp_get_product_category( $parent_product_id, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH ] );
	} else {
		$product_cat       = gtm4wp_get_product_category( $product_id, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH ] );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSESKU ] && ( '' != $product_sku ) ) {
		$remarketing_id = $product_sku;
	}

	$_temp_productdata = array(
		'id'         => $remarketing_id,
		'name'       => $product->get_title(),
		'sku'        => $product_sku ? $product_sku : $product_id,
		'category'   => $product_cat,
		'price'      => (float) wc_get_price_to_display( $product ),
		'stocklevel' => $product->get_stock_quantity()
	);

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY ] != "" ) {
		if ( isset( $parent_product_id ) && ( $parent_product_id !== 0 ) ) {
			$product_id_to_query = $parent_product_id;
		} else {
			$product_id_to_query = $product_id;
		}

		$_temp_productdata[ "brand" ] = gtm4wp_woocommerce_getproductterm( $product_id_to_query, $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY ] );
	}

	if ( 'variation' == $product_type ) {
		$_temp_productdata['variant'] = implode( ',', $product->get_variation_attributes() );
	}

	$_temp_productdata = array_merge( $_temp_productdata, $additional_product_attributes );

	return apply_filters( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY, $_temp_productdata, $attributes_used_for );
}

function gtm4wp_process_order_items( $order ) {
	global $gtm4wp_options, $gtm4wp_is_woocommerce3;

	$return_data = array(
		'products' => [],
		'sumprice' => 0,
		'product_ids' => []
	);

	if ( ! $order ) {
		return $return_data;
	}

	$order_items = $order->get_items();

	if ( $order_items ) {
		foreach ( $order_items as $item ) {
			if ( ! apply_filters( GTM4WP_WPFILTER_EEC_ORDER_ITEM, true, $item ) ) {
				continue;
			}

			$product = ( $gtm4wp_is_woocommerce3 ? $item->get_product() : $order->get_product_from_item( $item ) );
			$product_price = (float) $order->get_item_total( $item );
			$eec_product_array = gtm4wp_process_product( $product, array(
				'quantity' => $item->get_quantity(),
				'price'    => $product_price
			), 'purchase' );

			if ( $eec_product_array ) {
				$return_data['products'][]    = $eec_product_array;
				$return_data['sumprice']      += $product_price * $eec_product_array['quantity'];
				$return_data['product_ids'][] = gtm4wp_prefix_productid( $eec_product_array['id'] );
			}
		}
	}

	return $return_data;
}

function gtm4wp_woocommerce_addglobalvars( $return = '' ) {
	global $gtm4wp_options;

	$return .= '
	var gtm4wp_use_sku_instead        = ' . (int) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCUSESKU ] ) . ";
	var gtm4wp_id_prefix              = '" . esc_js( gtm4wp_prefix_productid( '' ) ) . "';
	var gtm4wp_remarketing            = " . gtm4wp_escjs_boolean( (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) ) . ';
	var gtm4wp_eec                    = ' . gtm4wp_escjs_boolean( (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) ) . ';
	var gtm4wp_classicec              = ' . gtm4wp_escjs_boolean( (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) ) . ";
	var gtm4wp_currency               = '" . esc_js( get_woocommerce_currency() ) . "';
	var gtm4wp_product_per_impression = " . (int) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION ] ) . ';';

	return $return;
}

function gtm4wp_woocommerce_datalayer_filter_items( $dataLayer ) {
	global $gtm4wp_options, $wp_query, $gtm4wp_datalayer_name, $gtm4wp_product_counter, $gtm4wp_is_woocommerce3, $gtm4wp_is_woocommerce3_7;

	$woo = WC();

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA ] && $gtm4wp_is_woocommerce3 ) {
		if ( $woo->customer instanceof WC_Customer ) {
			// we need to use this instead of $woo->customer as this will load proper total order number and value from the database instead of the session
			$woo_customer = new WC_Customer( $woo->customer->get_id() );

			$dataLayer['customerTotalOrders']     = $woo_customer->get_order_count();
			$dataLayer['customerTotalOrderValue'] = $woo_customer->get_total_spent();

			$dataLayer['customerFirstName'] = $woo_customer->get_first_name();
			$dataLayer['customerLastName']  = $woo_customer->get_last_name();

			$dataLayer['customerBillingFirstName'] = $woo_customer->get_billing_first_name();
			$dataLayer['customerBillingLastName']  = $woo_customer->get_billing_last_name();
			$dataLayer['customerBillingCompany']   = $woo_customer->get_billing_company();
			$dataLayer['customerBillingAddress1']  = $woo_customer->get_billing_address_1();
			$dataLayer['customerBillingAddress2']  = $woo_customer->get_billing_address_2();
			$dataLayer['customerBillingCity']      = $woo_customer->get_billing_city();
			$dataLayer['customerBillingPostcode']  = $woo_customer->get_billing_postcode();
			$dataLayer['customerBillingCountry']   = $woo_customer->get_billing_country();
			$dataLayer['customerBillingEmail']     = $woo_customer->get_billing_email();
			$dataLayer['customerBillingPhone']     = $woo_customer->get_billing_phone();

			$dataLayer['customerShippingFirstName'] = $woo_customer->get_shipping_first_name();
			$dataLayer['customerShippingLastName']  = $woo_customer->get_shipping_last_name();
			$dataLayer['customerShippingCompany']   = $woo_customer->get_shipping_company();
			$dataLayer['customerShippingAddress1']  = $woo_customer->get_shipping_address_1();
			$dataLayer['customerShippingAddress2']  = $woo_customer->get_shipping_address_2();
			$dataLayer['customerShippingCity']      = $woo_customer->get_shipping_city();
			$dataLayer['customerShippingPostcode']  = $woo_customer->get_shipping_postcode();
			$dataLayer['customerShippingCountry']   = $woo_customer->get_shipping_country();
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL ] && version_compare( $woo->version, "3.2", ">=" ) ) {
		$current_cart = $woo->cart;
		$dataLayer["cartContent"] = array(
			"totals" => array(
				"applied_coupons" => $current_cart->get_applied_coupons(),
				"discount_total"  => $current_cart->get_discount_total(),
				"subtotal"        => $current_cart->get_subtotal(),
				"total"           => $current_cart->get_cart_contents_total()
			),
			"items" => array()
		);

		foreach( $current_cart->get_cart() as $cart_item_id => $cart_item_data) {
			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data["data"], $cart_item_data, $cart_item_id );
			if ( !apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
				continue;
			}

			$eec_product_array = gtm4wp_process_product( $product, array(
				'quantity' => $cart_item_data["quantity"]
			), 'cart' );

			$dataLayer["cartContent"]["items"][] = $eec_product_array;
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
			$dataLayer['ecomm_prodid']     = array();
			$dataLayer['ecomm_pagetype']   = $ecomm_pagetype;
			$dataLayer['ecomm_totalvalue'] = 0;
		}
	} elseif ( is_product() ) {
		if ( ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) || ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) ) {
			$postid     = get_the_ID();
			$product    = wc_get_product( $postid );

			$eec_product_array = gtm4wp_process_product( $product, array(), 'productdetail' );

			$dataLayer['productRatingCounts']  = $product->get_rating_counts();
			$dataLayer['productAverageRating'] = (float) $product->get_average_rating();
			$dataLayer['productReviewCount']   = (int) $product->get_review_count();
			$dataLayer['productType']          = $product->get_type();

			switch ( $dataLayer['productType'] ) {
				case 'variable': {
					$dataLayer['productIsVariable'] = 1;

					$dataLayer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array[ 'id' ] );
					$dataLayer['ecomm_pagetype']   = 'product';
					$dataLayer['ecomm_totalvalue'] = $eec_product_array[ 'price' ];

					break;
				}

				case 'grouped': {
					$dataLayer['productIsVariable'] = 0;

					break;
				}

				default: {
					$dataLayer['productIsVariable'] = 0;

					if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
						$dataLayer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array[ 'id' ] );
						$dataLayer['ecomm_pagetype']   = 'product';
						$dataLayer['ecomm_totalvalue'] = $eec_product_array['price'];
					}

					if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
						$dataLayer['ecommerce'] = array(
							'currencyCode' => get_woocommerce_currency(),
							'detail'       => array(
								'products' => array(
									$eec_product_array
								),
							),
						);
					}
				}
			}
		}
	} elseif ( is_cart() ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] || $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ] ) {
			$gtm4wp_cart_products             = array();
			$gtm4wp_cart_products_remarketing = array();

			foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
				$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

				if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
					continue;
				}

				$eec_product_array = gtm4wp_process_product( $product, array(
					'quantity' => $cart_item_data['quantity']
				), 'cart' );

				if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ] ) {
					$gtm4wp_cart_products[] = $eec_product_array;
				}

				$gtm4wp_cart_products_remarketing[] = gtm4wp_prefix_productid( $eec_product_array[ 'id' ] );
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$dataLayer['ecomm_prodid']   = $gtm4wp_cart_products_remarketing;
				$dataLayer['ecomm_pagetype'] = 'cart';
				if ( ! $woo->cart->prices_include_tax ) {
					$cart_total = $woo->cart->cart_contents_total;
				} else {
					$cart_total = $woo->cart->cart_contents_total + $woo->cart->tax_total;
				}
				$dataLayer['ecomm_totalvalue'] = (float) $cart_total;
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ] ) {
				$dataLayer['ecommerce'] = array(
					'currencyCode' => get_woocommerce_currency(),
					'checkout'     => array(
						'actionField' => array(
							'step' => 1,
						),
						'products'    => $gtm4wp_cart_products,
					),
				);
			}
		}
	} elseif ( is_order_received_page() ) {
		$do_not_flag_tracked_order = (bool) ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ] );
		$order_id                  = empty( $_GET['order'] ) ? ( $GLOBALS['wp']->query_vars['order-received'] ? $GLOBALS['wp']->query_vars['order-received'] : 0 ) : absint( $_GET['order'] );
		$order_id_filtered         = apply_filters( 'woocommerce_thankyou_order_id', $order_id );
		if ( '' != $order_id_filtered ) {
			$order_id = $order_id_filtered;
		}

		$order_key = apply_filters( 'woocommerce_thankyou_order_key', empty( $_GET['key'] ) ? '' : wc_clean( $_GET['key'] ) );

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				if ( $gtm4wp_is_woocommerce3 ) {
					$this_order_key = $order->get_order_key();
				} else {
					$this_order_key = $order->order_key;
				}

				if ( $this_order_key != $order_key ) {
					unset( $order );
				}
			} else {
				unset( $order );
			}
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCORDERDATA ] && $gtm4wp_is_woocommerce3 ) {
			$order_items = gtm4wp_process_order_items( $order );

			$dataLayer['orderData'] = array(
				'attributes' => array(
					'date' => $order->get_date_created()->date( 'c' ),

					'order_number' => $order->get_order_number(),
					'order_key'    => $order->get_order_key(),

					'payment_method'       => esc_js( $order->get_payment_method() ),
					'payment_method_title' => esc_js( $order->get_payment_method_title()  ),

					'shipping_method' => esc_js( $order->get_shipping_method() ),

					'status' => esc_js( $order->get_status() ),

					'coupons' => implode( ', ', ( $gtm4wp_is_woocommerce3_7 ? $order->get_coupon_codes() : $order->get_used_coupons() ) )
				),
				'totals' => array(
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
					'tax_totals'     => $order->get_tax_totals()
				),
				'customer' => array(
					'id' => $order->get_customer_id(),

					'billing' => array(
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
						'phone'      => esc_js( $order->get_billing_phone() )
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
						'country'    => esc_js( $order->get_shipping_country() )
					)

				),
				'items' => $order_items['products']
			);
		}

		if ( ( 1 == get_post_meta( $order_id, '_ga_tracked', true ) ) && ! $do_not_flag_tracked_order ) {
			unset( $order );
		}

		if ( isset( $_COOKIE[ 'gtm4wp_orderid_tracked' ] ) && ( $_COOKIE[ 'gtm4wp_orderid_tracked' ] == $order_id ) && ! $do_not_flag_tracked_order ) {
			unset( $order );
		}

		if ( isset( $order ) ) {
			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX ] ) {
				$order_revenue = (float)( $order->get_total() - $order->get_total_tax() );
			} else {
				$order_revenue = (float) $order->get_total();
			}

			if ( $gtm4wp_is_woocommerce3 ) {
				$order_shipping_cost = (float) $order->get_shipping_total();
			} else {
				$order_shipping_cost = (float) $order->get_total_shipping();
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING ] ) {
				$order_revenue -= $order_shipping_cost;
			}

			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
				$dataLayer['event']                     = 'gtm4wp.orderCompleted';
				$dataLayer['transactionId']             = $order->get_order_number();
				$dataLayer['transactionAffiliation']    = '';
				$dataLayer['transactionTotal']          = $order_revenue;
				$dataLayer['transactionShipping']       = $order_shipping_cost;
				$dataLayer['transactionTax']            = (float) $order->get_total_tax();
				$dataLayer['transactionCurrency']       = $order->get_currency();
			}

			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				$dataLayer['event']     = 'gtm4wp.orderCompletedEEC';
				$dataLayer['ecommerce'] = array(
					'currencyCode' => $order->get_currency(),
					'purchase'     => array(
						'actionField' => array(
							'id'          => $order->get_order_number(),
							'affiliation' => '',
							'revenue'     => $order_revenue,
							'tax'         => (float) $order->get_total_tax(),
							'shipping'    => (float)( $gtm4wp_is_woocommerce3 ? $order->get_shipping_total() : $order->get_total_shipping() ),
							'coupon'      => implode( ', ', ( $gtm4wp_is_woocommerce3_7 ? $order->get_coupon_codes() : $order->get_used_coupons() ) ),
						),
					),
				);
			}

			if ( ! isset( $order_items ) ) {
				$order_items = gtm4wp_process_order_items( $order );
			}

			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
				$dataLayer['transactionProducts'] = $order_items['products'];
			}

			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				$dataLayer['ecommerce']['purchase']['products'] = $order_items['products'];
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$dataLayer['ecomm_prodid']     = $order_items['product_ids'];
				$dataLayer['ecomm_pagetype']   = 'purchase';
				$dataLayer['ecomm_totalvalue'] = (float) $order_items['sumprice'];
			}

			if ( ! $do_not_flag_tracked_order ) {
				update_post_meta( $order_id, '_ga_tracked', 1 );
			}
		}
	} elseif ( is_checkout() ) {
		if ( ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) || ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) ) {
			$gtm4wp_checkout_products             = array();
			$gtm4wp_checkout_products_remarketing = array();
			$gtm4wp_totalvalue                    = 0;

			foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
				$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

				if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
					continue;
				}

				$eec_product_array = gtm4wp_process_product( $product, array(
					'quantity' => $cart_item_data['quantity']
				), 'cart' );

				$gtm4wp_checkout_products[] = $eec_product_array;

				$gtm4wp_checkout_products_remarketing[] = gtm4wp_prefix_productid( $eec_product_array[ 'id' ] );
				$gtm4wp_totalvalue                     += $eec_product_array['quantity'] * $eec_product_array['price'];
			} // end foreach cart item

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$dataLayer['ecomm_prodid']     = $gtm4wp_checkout_products_remarketing;
				$dataLayer['ecomm_pagetype']   = 'cart';
				$dataLayer['ecomm_totalvalue'] = (float) $gtm4wp_totalvalue;
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				$dataLayer['ecommerce'] = array(
					'currencyCode' => get_woocommerce_currency(),
					'checkout'     => array(
						'actionField' => array(
							'step' => 1 + (int) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ],
						),
						'products'    => $gtm4wp_checkout_products,
					),
				);

				gtm4wp_woocommerce_addjs('
					window.gtm4wp_checkout_products    = ' . json_encode( $gtm4wp_checkout_products ) . ';
					window.gtm4wp_checkout_step_offset = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECCARTASFIRSTSTEP ] . ';'
				);
			}
		}
	} else {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
			$dataLayer['ecomm_pagetype'] = 'other';
		}
	}

	if ( isset( $_COOKIE['gtm4wp_product_readded_to_cart'] ) ) {
		$cart_item = $woo->cart->get_cart_item( $_COOKIE['gtm4wp_product_readded_to_cart'] );
		if ( ! empty( $cart_item ) ) {
			$product = $cart_item['data'];

			$eec_product_array = gtm4wp_process_product( $product, array(
				'quantity' => $cart_item['quantity']
			), 'readdedtocart' );

			$dataLayer['ecommerce'] = array(
				'currencyCode' => get_woocommerce_currency(),
				'add' => array(
					'products' => array(
						$eec_product_array
					)
				)
			);
		}

		gtm4wp_woocommerce_addjs( "document.cookie = 'gtm4wp_product_readded_to_cart=; expires=Thu, 01 Jan 1970 00:00:01 GMT;';" );
		unset( $_COOKIE['gtm4wp_product_readded_to_cart'] );
	}

	return $dataLayer;
}

function gtm4wp_woocommerce_single_add_to_cart_tracking() {
	global $product, $gtm4wp_datalayer_name, $gtm4wp_options;

	// exit early if there is nothing to do
	if ( ( false === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) && ( false === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) ) {
		return;
	}

	$eec_product_array = gtm4wp_process_product( $product, array(), 'addtocartsingle' );

	foreach ( $eec_product_array as $eec_product_array_key => $eec_product_array_value ) {
		echo '<input type="hidden" name="gtm4wp_' . esc_attr( $eec_product_array_key ) . '" value="' . esc_attr( $eec_product_array_value ) . '" />' . "\n";
	}
}

$GLOBALS['gtm4wp_cart_item_proddata'] = '';
function gtm4wp_woocommerce_cart_item_product_filter( $product, $cart_item = '', $cart_id = '' ) {
	global $gtm4wp_options, $gtm4wp_is_woocommerce3;

	$eec_product_array = gtm4wp_process_product( $product, array(
		'productlink' => apply_filters( 'the_permalink', get_permalink(), 0 )
	), 'cart' );

	$GLOBALS['gtm4wp_cart_item_proddata'] = $eec_product_array;

	return $product;
}

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

	$cartlink_with_data                   = sprintf(
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

function gtp4wp_woocommerce_reset_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = '';
}

function gtm4wp_woocommerce_add_related_to_loop( $arg ) {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Related Products', 'duracelltomi-google-tag-manager' );

	return $arg;
}

function gtm4wp_woocommerce_add_cross_sell_to_loop( $arg ) {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Cross-Sell Products', 'duracelltomi-google-tag-manager' );

	return $arg;
}

function gtm4wp_woocommerce_add_upsells_to_loop( $arg ) {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Upsell Products', 'duracelltomi-google-tag-manager' );

	return $arg;
}

function gtm4wp_woocommerce_before_template_part( $template_name ) {
	ob_start();
}

function gtm4wp_woocommerce_after_template_part( $template_name ) {
	global $product, $gtm4wp_product_counter, $gtm4wp_last_widget_title, $gtm4wp_options;

	$productitem = ob_get_contents();
	ob_end_clean();

	if ( 'content-widget-product.php' == $template_name ) {
		$eec_product_array = gtm4wp_process_product( $product, array(
			'productlink'  => apply_filters( 'the_permalink', get_permalink(), 0 ),
			'listname'     => $gtm4wp_last_widget_title,
			'listposition' => $gtm4wp_product_counter
		), 'widgetproduct' );

		if ( ! isset( $eec_product_array[ 'brand' ] ) ) {
			$eec_product_array[ 'brand' ] = '';
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
			esc_attr( $eec_product_array[ "brand" ] )
		);

		$gtm4wp_product_counter++;

		$productitem = str_replace( 'href="', $productlink_with_data, $productitem );
	}

	echo $productitem;
}

function gtm4wp_widget_title_filter( $widget_title ) {
	global $gtm4wp_product_counter, $gtm4wp_last_widget_title;

	$gtm4wp_product_counter   = 1;
	$gtm4wp_last_widget_title = $widget_title . __( ' (widget)', 'duracelltomi-google-tag-manager' );

	return $widget_title;
}

function gtm4wp_before_recent_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Recent Products', 'duracelltomi-google-tag-manager' );
}

function gtm4wp_before_sale_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Sale Products', 'duracelltomi-google-tag-manager' );
}

function gtm4wp_before_best_selling_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Best Selling Products', 'duracelltomi-google-tag-manager' );
}

function gtm4wp_before_top_rated_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Top Rated Products', 'duracelltomi-google-tag-manager' );
}

function gtm4wp_before_featured_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Featured Products', 'duracelltomi-google-tag-manager' );
}

function gtm4wp_before_related_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop['listtype'] = __( 'Related Products', 'duracelltomi-google-tag-manager' );
}

function gtm4wp_woocommerce_before_shop_loop_item() {
	global $product, $woocommerce_loop, $wp_query, $gtm4wp_options;

	if ( ! isset( $product ) ) {
		return;
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
	} elseif ( isset( $woocommerce_loop['listtype'] ) && ( $woocommerce_loop['listtype'] != '' ) ) {
		$list_name = $woocommerce_loop['listtype'];
	} else {
		$list_name = __( 'General Product List', 'duracelltomi-google-tag-manager' );
	}

	$paged          = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
	$posts_per_page = get_query_var( 'posts_per_page' );
	if ( $posts_per_page < 1 ) {
		$posts_per_page = 1;
	}

	$eec_product_array = gtm4wp_process_product( $product, array(
		'productlink'  => apply_filters( 'the_permalink', get_permalink(), 0 ),
		'listname'     => $list_name,
		'listposition' => $woocommerce_loop['loop'] + ( $posts_per_page * ( $paged - 1 ) )
	), 'productlist' );

	if ( ! isset( $eec_product_array[ 'brand' ] ) ) {
		$eec_product_array[ 'brand' ] = '';
	}

	printf(
		'<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_product_listposition="%s" data-gtm4wp_productlist_name="%s" data-gtm4wp_product_stocklevel="%s" data-gtm4wp_product_brand="%s"></span>',
		esc_attr( $eec_product_array['id'] ),
		esc_attr( $eec_product_array['name'] ),
		esc_attr( $eec_product_array['price'] ),
		esc_attr( $eec_product_array['category'] ),
		esc_url( $eec_product_array['productlink'] ),
		esc_attr( $eec_product_array['listposition'] ),
		esc_attr( $eec_product_array['listname'] ),
		esc_attr( $eec_product_array['stocklevel'] ),
		esc_attr( $eec_product_array[ "brand" ] )
	);
}

function gtm4wp_woocommerce_cart_item_restored( $cart_item_key ) {
	setcookie( 'gtm4wp_product_readded_to_cart', $cart_item_key );
}

function gtm4wp_woocommerce_enqueue_scripts() {
	global $gtm4wp_options, $gtp4wp_plugin_url;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
		$in_footer = apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC, false );
		wp_enqueue_script( 'gtm4wp-woocommerce-classic', $gtp4wp_plugin_url . 'js/gtm4wp-woocommerce-classic.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		$in_footer = apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC, false );
		wp_enqueue_script( 'gtm4wp-woocommerce-enhanced', $gtp4wp_plugin_url . 'js/gtm4wp-woocommerce-enhanced.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
	}
}

function gtm4wp_wc_quick_view_before_single_product() {
	global $gtm4wp_options, $gtm4wp_datalayer_name;

	$dataLayer = array(
		'event' => 'gtm4wp.changeDetailViewEEC',
	);

	if ( ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) || ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) ) {
		$postid     = get_the_ID();
		$product    = wc_get_product( $postid );

		$eec_product_array = gtm4wp_process_product( $product, array(), 'productdetail' );

		$dataLayer['productRatingCounts']  = $product->get_rating_counts();
		$dataLayer['productAverageRating'] = (float) $product->get_average_rating();
		$dataLayer['productReviewCount']   = (int) $product->get_review_count();
		$dataLayer['productType']          = $product->get_type();

		switch ( $dataLayer['productType'] ) {
			case 'variable': {
				$dataLayer['productIsVariable'] = 1;

				$dataLayer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array[ 'id' ] );
				$dataLayer['ecomm_pagetype']   = 'product';
				$dataLayer['ecomm_totalvalue'] = $eec_product_array[ 'price' ];

				break;
			}

			case 'grouped': {
				$dataLayer['productIsVariable'] = 0;

				break;
			}

			default: {
				$dataLayer['productIsVariable'] = 0;

				if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
					$dataLayer['ecomm_prodid']     = gtm4wp_prefix_productid( $eec_product_array[ 'id' ] );
					$dataLayer['ecomm_pagetype']   = 'product';
					$dataLayer['ecomm_totalvalue'] = $eec_product_array['price'];
				}

				if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
					$dataLayer['ecommerce'] = array(
						'currencyCode' => get_woocommerce_currency(),
						'detail'       => array(
							'products' => array(
								$eec_product_array
							),
						),
					);
				}
			}
		}
	}

	echo '
	<script>
		' . $gtm4wp_datalayer_name . '.push(' . json_encode( $dataLayer ) . ');
	</script>';
}

function gtm4wp_woocommerce_grouped_product_list_column_label( $labelvalue, $product ) {
	global $gtm4wp_options, $gtm4wp_grouped_product_ix;

	if ( ! isset( $product ) ) {
		return $labelvalue;
	}

	$list_name = __( 'Grouped Product Detail Page', 'duracelltomi-google-tag-manager' );

	$eec_product_array = gtm4wp_process_product( $product, array(
		'productlink'  => $product->get_permalink(),
		'listname'     => $list_name,
		'listposition' => $gtm4wp_grouped_product_ix
	), 'groupedproductlist' );

	$gtm4wp_grouped_product_ix++;

	if ( ! isset( $eec_product_array[ 'brand' ] ) ) {
		$eec_product_array[ 'brand' ] = '';
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

// do not add filter if someone enabled WooCommerce integration without an activated WooCommerce plugin
if ( function_exists( 'WC' ) ) {
	add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, 'gtm4wp_woocommerce_datalayer_filter_items' );

	add_filter( 'loop_end', 'gtp4wp_woocommerce_reset_loop' );
	add_action( 'woocommerce_before_shop_loop_item', 'gtm4wp_woocommerce_before_shop_loop_item' );
	add_action( 'woocommerce_after_add_to_cart_button', 'gtm4wp_woocommerce_single_add_to_cart_tracking' );

	// add_action( "wp_footer", "gtm4wp_woocommerce_wp_footer" );
	add_action( 'wp_enqueue_scripts', 'gtm4wp_woocommerce_enqueue_scripts' );
	add_filter( GTM4WP_WPACTION_ADDGLOBALVARS, 'gtm4wp_woocommerce_addglobalvars' );

	if ( true === $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		// add_action( "wp_footer", "gtm4wp_woocommerce_enhanced_ecom_product_click" );
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
