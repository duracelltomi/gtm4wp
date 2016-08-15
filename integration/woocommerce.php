<?php
$gtm4wp_product_counter = 0;
$gtm4wp_last_widget_title = "Sidebar Products";

function gtm4wp_woocommerce_addjs( $js ) {
  global $woocommerce;
  
	if ( version_compare( $woocommerce->version, "2.1", ">=" ) ) {
		wc_enqueue_js( $js );
	} else {
		$woocommerce->add_inline_js( $js );
	}
}

function gtm4wp_woocommerce_html_entity_decode( $val ) {
	return html_entity_decode( $val, ENT_QUOTES, "utf-8" );
}

function gtm4wp_woocommerce_datalayer_filter_items( $dataLayer ) {
	global $woocommerce, $gtm4wp_options, $wp_query, $gtm4wp_datalayer_name, $gtm4wp_product_counter;

	if ( is_product_category() || is_product_tag() || is_front_page() || is_shop() ) {
    if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
      $dataLayer["ecomm_prodid"] = array();
      $dataLayer["ecomm_pagetype"] = ( is_front_page() ? "home" : "category" );
      $dataLayer["ecomm_totalvalue"] = 0;
    }
	} else if ( is_product() ) {
		if ( ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) || ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) ) {
			$prodid        = get_the_ID();
			$product       = get_product( $prodid );
			$product_price = $product->get_price();
			$_product_cats = get_the_terms( $product->id, 'product_cat' );
			if ( ( is_array($_product_cats) ) && ( count( $_product_cats ) > 0 ) ) {
				$product_cat = array_pop( $_product_cats );
				$product_cat = $product_cat->name;
			} else {
				$product_cat = "";
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$remarketing_id = (string)$prodid;
				if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETINGSKU ] ) {
					$product_sku = $product->get_sku();
					if ( "" != $product_sku ) {
						$remarketing_id = $product_sku;
					}
				}

				$dataLayer["ecomm_prodid"] = $remarketing_id;
				$dataLayer["ecomm_pagetype"] = "product";
				$dataLayer["ecomm_totalvalue"] = (float)$product_price;
			}
			
			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				$dataLayer["ecommerce"] = array(
					"detail" => array(
						"products" => array(array(
							"name"     => gtm4wp_woocommerce_html_entity_decode( get_the_title() ),
							"id"       => $prodid,
							"price"    => $product_price,
							"category" => $product_cat,
						))
					)
				);
			}
		}
	} else if ( is_cart() ) {
		if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
			gtm4wp_woocommerce_addjs("
				$( document ).on( 'click', '[name=update_cart]', function() {
					$( '.product-quantity input.qty' ).each(function() {
						var _original_value = $( this ).prop( 'defaultValue' );

						var _current_value  = parseInt( $( this ).val() );
						if ( isNaN( _current_value ) ) {
							_current_value = _original_value;
						}

						if ( _original_value != _current_value ) {
							var productdata = $( this ).closest( '.cart_item' ).find( '.remove' );

							if ( _original_value < _current_value ) {
								". $gtm4wp_datalayer_name .".push({
									'event': 'gtm4wp.addProductToCartEEC',
									'ecommerce': {
										'currencyCode': '".get_woocommerce_currency()."',
										'add': {
											'products': [{
												'name':     productdata.data( 'gtm4wp_product_name' ),
												'id':       productdata.data( 'gtm4wp_product_id' ),
												'price':    productdata.data( 'gtm4wp_product_price' ),
												'category': productdata.data( 'gtm4wp_product_cat' ),
												'quantity': _current_value - _original_value
											}]
										}
									}
								});
							} else {
								". $gtm4wp_datalayer_name .".push({
									'event': 'gtm4wp.removeFromCartEEC',
									'ecommerce': {
										'currencyCode': '".get_woocommerce_currency()."',
										'remove': {
											'products': [{
												'name':     productdata.data( 'gtm4wp_product_name' ),
												'id':       productdata.data( 'gtm4wp_product_id' ),
												'price':    productdata.data( 'gtm4wp_product_price' ),
												'category': productdata.data( 'gtm4wp_product_cat' ),
												'quantity': _original_value - _current_value
											}]
										}
									}
								});
							}
						}
					});
				});
			");
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
			$products = $woocommerce->cart->get_cart();
			$product_ids = array();
			foreach( $products as $oneproduct ) {
				$remarketing_id = $oneproduct['product_id'];
				if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETINGSKU ] ) {
					$product_sku = $oneproduct['product_sku'];
					if ( "" != $product_sku ) {
						$remarketing_id = $product_sku;
					}
				}

				$product_ids[] = $remarketing_id;
			}

			$dataLayer["ecomm_prodid"] = $product_ids;
			$dataLayer["ecomm_pagetype"] = "cart";
			if ( ! $woocommerce->cart->prices_include_tax ) {
				$cart_total = $woocommerce->cart->cart_contents_total;
			} else {
				$cart_total = $woocommerce->cart->cart_contents_total + $woocommerce->cart->tax_total;
			}
			$dataLayer["ecomm_totalvalue"] = (float)$cart_total;
		}
	} else if ( is_order_received_page() ) {
		$order_id  = apply_filters( 'woocommerce_thankyou_order_id', empty( $_GET['order'] ) ? ($GLOBALS["wp"]->query_vars["order-received"] ? $GLOBALS["wp"]->query_vars["order-received"] : 0) : absint( $_GET['order'] ) );
		$order_key = apply_filters( 'woocommerce_thankyou_order_key', empty( $_GET['key'] ) ? '' : woocommerce_clean( $_GET['key'] ) );

		if ( $order_id > 0 ) {
			$order = new WC_Order( $order_id );
			if ( $order->order_key != $order_key )
				unset( $order );
		}

		if ( 1 == get_post_meta( $order_id, '_ga_tracked', true ) ) {
			unset( $order );
		}

		if ( isset( $order ) ) {
			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
				$dataLayer["transactionId"]             = $order->get_order_number();
				$dataLayer["transactionDate"]           = date("c");
				$dataLayer["transactionType"]           = "sale";
				$dataLayer["transactionAffiliation"]    = gtm4wp_woocommerce_html_entity_decode( get_bloginfo( 'name' ) );
				$dataLayer["transactionTotal"]          = $order->get_total();
				$dataLayer["transactionShipping"]       = $order->get_total_shipping();
				$dataLayer["transactionTax"]            = $order->get_total_tax();
				$dataLayer["transactionPaymentType"]    = $order->payment_method_title;
				$dataLayer["transactionCurrency"]       = get_woocommerce_currency();
				$dataLayer["transactionShippingMethod"] = $order->get_shipping_method();
				$dataLayer["transactionPromoCode"]      = implode( ", ", $order->get_used_coupons() );
			}

			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				$dataLayer["ecommerce"] = array(
					"currencyCode" => get_woocommerce_currency(),
					"purchase" => array(
						"actionField" => array(
							"id"          => $order->get_order_number(),
							"affiliation" => gtm4wp_woocommerce_html_entity_decode( get_bloginfo( 'name' ) ),
							"revenue"     => $order->get_total(),
							"tax"         => $order->get_total_tax(),
							"shipping"    => $order->get_total_shipping(),
							"coupon"      => implode( ", ", $order->get_used_coupons() )
						)
					)
				);
			}

			$_products = array();
			$_sumprice = 0;
			$_product_ids = array();

			if ( $order->get_items() ) {
				foreach ( $order->get_items() as $item ) {
					$_product = $order->get_product_from_item( $item );

          $variation_data = null;
          if (get_class($_product) == "WC_Product_Variation") {
            $variation_data = $_product->get_variation_attributes();
          }

          if ( isset( $variation_data ) ) {

						$_category = woocommerce_get_formatted_variation( $_product->variation_data, true );

					} else {
						$out = array();
						$categories = get_the_terms( $_product->id, 'product_cat' );
						if ( $categories ) {
							foreach ( $categories as $category ) {
								$out[] = $category->name;
							}
						}
					
						$_category = implode( " / ", $out );
					}

					$remarketing_id = $_product->id;
					$product_sku    = $_product->get_sku();
					if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETINGSKU ] && ( "" != $product_sku ) ) {
						$remarketing_id = $product_sku;
					}

					$_prodprice = $order->get_item_total( $item );
					$_products[] = array(
					  "id"       => $_product->id,
					  "name"     => $item['name'],
					  "sku"      => $product_sku ? __( 'SKU:', 'duracelltomi-google-tag-manager' ) . ' ' . $product_sku : $_product->id,
					  "category" => $_category,
					  "price"    => $_prodprice,
					  "currency" => get_woocommerce_currency(),
					  "quantity" => $item['qty']
					);
			
					$_sumprice += $_prodprice * $item['qty'];
					$_product_ids[] = $remarketing_id;
				}
			}

			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
				$dataLayer["transactionProducts"] = $_products;
				$dataLayer["event"] = "gtm4wp.orderCompleted";
			}

			if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
				$dataLayer["ecommerce"]["purchase"]["products"] = $_products;
				$dataLayer["event"] = "gtm4wp.orderCompletedEEC";
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$dataLayer["ecomm_prodid"] = $_product_ids;
				$dataLayer["ecomm_pagetype"] = "purchase";
				$dataLayer["ecomm_totalvalue"] = (float)$_sumprice;
			}

			update_post_meta( $order_id, '_ga_tracked', 1 );
		}
	} else if ( is_checkout() ) {
		if ( ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) || ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) ) {
			$gtm4wp_checkout_products             = array();
			$gtm4wp_checkout_products_remarketing = array();
			$gtm4wp_totalvalue                    = 0;
			
			foreach( $woocommerce->cart->get_cart() as $cart_item_id => $cart_item_data) {
				$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data["data"], $cart_item_data, $cart_item_id );

				$_product_cats = get_the_terms($product->id, 'product_cat');
				if ( ( is_array($_product_cats) ) && ( count( $_product_cats ) > 0 ) ) {
					$product_cat = array_pop( $_product_cats );
					$product_cat = $product_cat->name;
				} else {
					$product_cat = "";
				}
				
				$gtm4wp_product_price = $product->get_price();

				$gtm4wp_checkout_products[] = array(
					"id"       => $product->id,
					"name"     => $product->post->post_title,
					"price"    => $gtm4wp_product_price,
					"category" => $product_cat,
					"quantity" => $cart_item_data["quantity"]
				);
				
				$gtm4wp_checkout_products_remarketing[] = $product->id;
				$gtm4wp_totalvalue += $cart_item_data["quantity"] * $gtm4wp_product_price;
			} // end foreach cart item

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
				$dataLayer["ecomm_prodid"] = $gtm4wp_checkout_products_remarketing;
				$dataLayer["ecomm_pagetype"] = "cart";
				$dataLayer["ecomm_totalvalue"] = (float)$gtm4wp_totalvalue;
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
        $dataLayer["ecommerce"] = array(
          "checkout" => array(
            "actionField" => array(
              "step" => 1
            ),
            "products" => $gtm4wp_checkout_products
          )
        );

        gtm4wp_woocommerce_addjs("
          $( 'form[name=checkout]' ).on( 'submit', function() {
            var _checkout_option = [];

            var _shipping_el = $( '#shipping_method input:checked' );
            if ( _shipping_el.length > 0 ) {
              _checkout_option.push( 'Shipping: ' + _shipping_el.val() );
            }

            var _payment_el = $( '.payment_methods input:checked' );
            if ( _payment_el.length > 0 ) {
              _checkout_option.push( 'Payment: ' + _payment_el.val() );
            }

            if ( _checkout_option.length > 0 ) {
              ". $gtm4wp_datalayer_name .".push({
                'event': 'gtm4wp.checkoutOptionECC',
                'ecommerce': {
                  'checkout_option': {
                    'actionField': {
                      'step': 1,
                      'option': _checkout_option.join( ', ' )
                    }
                  }
                }
              });
            }

            return false;
          });");
      }
		}
	} else {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) {
			$dataLayer["ecomm_pagetype"] = "other";
		}
	}

	if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		gtm4wp_woocommerce_addjs("
	$( document ).on( 'click', '.mini_cart_item a.remove,.product-remove a.remove', function() {
		var productdata = $( this );

		var qty = 0;
		var qty_element = $( this ).closest( '.cart_item' ).find( '.product-quantity input.qty' );
		if ( 0 == qty_element.length ) {
			qty_element = $( this ).closest( '.mini_cart_item' ).find( '.quantity' );
			if ( qty_element.length > 0 ) {
				qty = parseInt( qty_element.text() );

				if ( isNaN( qty ) ) {
					qty = 0;
				}
			}
		} else {
			qty = qty_element.val();
		}

		if ( 0 == qty ) {
			return true;
		}
			
		". $gtm4wp_datalayer_name .".push({
			'event': 'gtm4wp.removeFromCartEEC',
			'ecommerce': {
				'remove': {
					'products': [{
						'name':     productdata.data( 'gtm4wp_product_name' ),
						'id':       productdata.data( 'gtm4wp_product_id' ),
						'price':    productdata.data( 'gtm4wp_product_price' ),
						'category': productdata.data( 'gtm4wp_product_cat' ),
						'quantity': qty
					}]
				}
			}
		});
	});
		");
	}

	return $dataLayer;
}

function gtm4wp_woocommerce_single_add_to_cart_tracking() {
	if ( ! is_single() ) {
		return;
	}

	global $product, $woocommerce, $gtm4wp_datalayer_name, $gtm4wp_options;

	if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
		gtm4wp_woocommerce_addjs("
		$( '.single_add_to_cart_button' ).click(function() {
			". $gtm4wp_datalayer_name .".push({
				'event': 'gtm4wp.addProductToCart',
				'productName': '". esc_js( $product->post->post_title ) ."',
				'productSKU': '". esc_js( $product->get_sku() ) ."',
				'productID': '". esc_js( $product->id ) ."'
			});
		});
		");
	}

	if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		$_product_cats = get_the_terms($product->id, 'product_cat');
		if ( ( is_array($_product_cats) ) && ( count( $_product_cats ) > 0 ) ) {
			$product_cat = array_pop( $_product_cats );
			$product_cat = $product_cat->name;
		} else {
			$product_cat = "";
		}

		gtm4wp_woocommerce_addjs("
		$( '.single_add_to_cart_button' ).click(function() {
			". $gtm4wp_datalayer_name .".push({
				'event': 'gtm4wp.addProductToCartEEC',
				'ecommerce': {
					'currencyCode': '".get_woocommerce_currency()."',
					'add': {
						'products': [{
							'name': '". esc_js( $product->post->post_title ) ."',
							'id': '". esc_js( $product->id ) ."',
							'price': '". esc_js( $product->get_price() ) ."',
							'category': '". esc_js( $product_cat ) ."',
							'quantity': jQuery( 'form.cart:first input[name=quantity]' ).val()
						}]
					}
				}
			});
		});
		");
	}
}

function gtm4wp_woocommerce_wp_footer() {
	global $woocommerce, $gtm4wp_options, $gtm4wp_datalayer_name;

	if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ) {
		gtm4wp_woocommerce_addjs("
		$( '.add_to_cart_button:not(.product_type_variable, .product_type_grouped)' ).click(function() {
			var productdata = $( this ).closest( '.product' ).find( '.gtm4wp_productdata' );

			". $gtm4wp_datalayer_name .".push({
				'event': 'gtm4wp.addProductToCart',
				'productName': productdata.data( 'gtm4wp_product_name' ),
				'productSKU': $( this ).data( 'product_sku' ),
				'productID': $( this ).data( 'product_id' ),
			});
		});
		");
	}

	if ( ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ] ) && ( ! is_cart() ) ) {
		echo "
<script type='text/javascript'>
	(function($) {
		if ( $( '.gtm4wp_productdata' ).length > 0 ) {
			for( var i=0; i<". $gtm4wp_datalayer_name .".length; i++ ) {
				if ( ". $gtm4wp_datalayer_name ."[ i ][ 'ecomm_prodid' ] ) {
					break;
				}
			}

			if ( i == ". $gtm4wp_datalayer_name .".length ) {
				// no existing dyn remarketing data found in the datalayer
				i = 0;
				". $gtm4wp_datalayer_name ."[ i ][ 'ecomm_prodid' ] = [];
			}
			
			if ( typeof ". $gtm4wp_datalayer_name ."[ i ][ 'ecomm_prodid' ].push == 'undefined' ) {
				return false;
			}

			var productdata;
			$( '.gtm4wp_productdata' ).each( function() {
				productdata = jQuery( this );

				". $gtm4wp_datalayer_name ."[ i ][ 'ecomm_prodid' ].push( productdata.data( 'gtm4wp_product_id' ) );
			});
		}
	})(jQuery);
</script>";
	}

	if ( true === $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		echo "
<script type='text/javascript'>
	(function($) {
		if ( $( '.gtm4wp_productdata,.widget-product-item' ).length > 0 ) {
			for( var i=0; i<". $gtm4wp_datalayer_name .".length; i++ ) {
				if ( ". $gtm4wp_datalayer_name ."[ i ][ 'ecommerce' ] ) {
				
					if ( ! ". $gtm4wp_datalayer_name ."[ i ][ 'ecommerce' ][ 'impressions' ] ) {
						". $gtm4wp_datalayer_name ."[ i ][ 'ecommerce' ][ 'impressions' ] = [];
					}

					break;
				}
			}

			if ( i == ". $gtm4wp_datalayer_name .".length ) {
				// no existing ecommerce data found in the datalayer
				i = 0;
				". $gtm4wp_datalayer_name ."[ i ][ 'ecommerce' ] = {};
				". $gtm4wp_datalayer_name ."[ i ][ 'ecommerce' ][ 'impressions' ] = [];
			}
		
			". $gtm4wp_datalayer_name ."[ i ][ 'ecommerce' ][ 'currencyCode' ] = '".get_woocommerce_currency()."';

			var productdata;
			$( '.gtm4wp_productdata,.widget-product-item' ).each( function() {
				productdata = jQuery( this );

				". $gtm4wp_datalayer_name ."[ i ][ 'ecommerce' ][ 'impressions' ].push({
					'name':     productdata.data( 'gtm4wp_product_name' ),
					'id':       productdata.data( 'gtm4wp_product_id' ),
					'price':    productdata.data( 'gtm4wp_product_price' ),
					'category': productdata.data( 'gtm4wp_product_cat' ),
					'position': productdata.data( 'gtm4wp_product_listposition' ),
					'list':     productdata.data( 'gtm4wp_productlist_name' )
				});
			});
		}
  })(jQuery);
</script>";

		gtm4wp_woocommerce_addjs("
		$( '.add_to_cart_button:not(.product_type_variable, .product_type_grouped)' ).click(function() {
			var productdata = $( this ).closest( '.product' ).find( '.gtm4wp_productdata' );

			". $gtm4wp_datalayer_name .".push({
				'event': 'gtm4wp.addProductToCartEEC',
				'ecommerce': {
					'currencyCode': '".get_woocommerce_currency()."',
					'add': {
						'products': [{
							'name':     productdata.data( 'gtm4wp_product_name' ),
							'id':       productdata.data( 'gtm4wp_product_id' ),
							'price':    productdata.data( 'gtm4wp_product_price' ),
							'category': productdata.data( 'gtm4wp_product_cat' ),
							'quantity': 1
						}]
					}
				}
			});
		});
		");
	}
}

function gtm4wp_woocommerce_enhanced_ecom_product_click() {
	global $woocommerce, $gtm4wp_datalayer_name;

	gtm4wp_woocommerce_addjs("
		$( '.products li:not(.product-category) a:not(.add_to_cart_button),.widget-product-item' ).click(function( event ) {
			var _productdata = $( this ).closest( '.product' ).length;
			if ( _productdata > 0 ) {
        var productdata = _productdata.find( '.gtm4wp_productdata' );
			}
			
			var ctrl_key_pressed = event.ctrlKey;

			if ( 0 == productdata.length ) {
				var productdata = jQuery( this );

				if ( '' == productdata.data( 'gtm4wp_product_id' ) ) {
					return true;
				}
			}

			event.preventDefault();
			if ( ctrl_key_pressed ) {
				// we need to open the new tab/page here so that popup blocker of the browser doesn't block our code
				var _productpage = window.open( 'about:blank', '_blank' );
			}

			". $gtm4wp_datalayer_name .".push({
				'event': 'gtm4wp.productClickEEC',
				'ecommerce': {
					'currencyCode': '".get_woocommerce_currency()."',
					'click': {
					  'actionField': {'list': productdata.data( 'gtm4wp_productlist_name' )},
						'products': [{
							'name':     productdata.data( 'gtm4wp_product_name' ),
							'id':       productdata.data( 'gtm4wp_product_id' ),
							'price':    productdata.data( 'gtm4wp_product_price' ),
							'category': productdata.data( 'gtm4wp_product_cat' ),
							'position': productdata.data( 'gtm4wp_product_listposition' )
						}]
					}
				},
				'eventCallback': function() {
					if ( ctrl_key_pressed && _productpage ) {
						_productpage.location.href= productdata.data( 'gtm4wp_product_url' );
					} else {
						document.location.href = productdata.data( 'gtm4wp_product_url' )
					}
				}
			});
		});
	");
}

function gtm4wp_woocommerce_add_prod_data( $add_to_cart_link ) {
	global $product, $woocommerce_loop;
	
	$product_price = $product->get_price();
	$_product_cats = get_the_terms($product->id, 'product_cat');
	if ( ( is_array($_product_cats) ) && ( count( $_product_cats ) > 0 ) ) {
		$product_cat = array_pop( $_product_cats );
		$product_cat = $product_cat->name;
	} else {
		$product_cat = "";
	}

	if ( is_search() ) {
		$list_name = __( "Search Results", "duracelltomi-google-tag-manager" );
	} else if ( isset( $woocommerce_loop[ "listtype" ] ) && ( $woocommerce_loop[ "listtype" ] != '' ) ) {
		$list_name = $woocommerce_loop[ "listtype" ];
	} else {
		$list_name = __( "General Product List", "duracelltomi-google-tag-manager" );
	}

	$cartlink_with_data = sprintf('data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_product_listposition="%s" data-gtm4wp_productlist_name="%s" href="',
		esc_attr( $product->id ),
		esc_attr( $product->get_title() ),
		esc_attr( $product_price ),
		esc_attr( $product_cat ),
		esc_url( get_permalink() ),
		esc_attr( $woocommerce_loop[ "loop" ] ),
		esc_attr( $list_name )
	);

	return str_replace( 'href="', $cartlink_with_data, $add_to_cart_link );
}

$GLOBALS["gtm4wp_cart_item_proddata"] = '';
function gtm4wp_woocommerce_cart_item_product_filter($product) {
	$product_price = $product->get_price();
	$_product_cats = get_the_terms($product->id, 'product_cat');
	if ( ( is_array( $_product_cats ) ) && ( count( $_product_cats ) > 0 ) ) {
		$product_cat = array_pop( $_product_cats );
		$product_cat = $product_cat->name;
	} else {
		$product_cat = "";
	}

	$GLOBALS["gtm4wp_cart_item_proddata"] = array(
		"id"          => $product->id,
		"name"        => $product->get_title(),
		"price"       => $product_price,
		"category"    => $product_cat,
		"productlink" => get_permalink()
	);

	return $product;
}

function gtm4wp_woocommerce_cart_item_remove_link_filter( $remove_from_cart_link ) {
  if ( ! isset( $GLOBALS["gtm4wp_cart_item_proddata"] ) ) {
    return $remove_from_cart_link;
  }

  if ( ! is_array( $GLOBALS["gtm4wp_cart_item_proddata"] ) ) {
    return $remove_from_cart_link;
  }

	$cartlink_with_data = sprintf('data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" href="',
		esc_attr( $GLOBALS["gtm4wp_cart_item_proddata"]["id"] ),
		esc_attr( $GLOBALS["gtm4wp_cart_item_proddata"]["name"] ),
		esc_attr( $GLOBALS["gtm4wp_cart_item_proddata"]["price"] ),
		esc_attr( $GLOBALS["gtm4wp_cart_item_proddata"]["category"] ),
		esc_url( $GLOBALS["gtm4wp_cart_item_proddata"]["productlink"] )
	);
	$GLOBALS["gtm4wp_cart_item_proddata"] = '';

	return str_replace( 'href="', $cartlink_with_data, $remove_from_cart_link );
}

function gtp4wp_woocommerce_reset_loop() {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = "";
}

function gtm4wp_woocommerce_add_related_to_loop( $arg ) {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = __( "Related Products", "duracelltomi-google-tag-manager" );

	return $arg;
}

function gtm4wp_woocommerce_before_template_part( $template_name ) {
	ob_start();
}

function gtm4wp_woocommerce_after_template_part( $template_name ) {
	global $product, $gtm4wp_product_counter, $gtm4wp_last_widget_title;

	$productitem = ob_get_contents();
	ob_end_clean();

	if ( "content-widget-product.php" == $template_name ) {
		$product_price = $product->get_price();
		$_product_cats = get_the_terms($product->id, 'product_cat');
		if ( ( is_array( $_product_cats ) ) && ( count( $_product_cats ) > 0 ) ) {
			$product_cat = array_pop( $_product_cats );
			$product_cat = $product_cat->name;
		} else {
			$product_cat = "";
		}

		$productlink_with_data = sprintf('data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_productlist_name="%s" data-gtm4wp_product_listposition="%s" class="widget-product-item" href="',
			esc_attr( $product->id ),
			esc_attr( $product->get_title() ),
			esc_attr( $product_price ),
			esc_attr( $product_cat ),
			esc_url( get_permalink() ),
			$gtm4wp_last_widget_title,
			esc_attr( $gtm4wp_product_counter )
		);

		$gtm4wp_product_counter++;

		$productitem = str_replace( 'href="', $productlink_with_data, $productitem );
	}

	echo $productitem;
}

function gtm4wp_widget_title_filter( $widget_title ) {
	global $gtm4wp_product_counter, $gtm4wp_last_widget_title;

	$gtm4wp_product_counter = 1;
	$gtm4wp_last_widget_title = $widget_title . __( " (widget)", "duracelltomi-google-tag-manager" );

	return $widget_title;
}

function gtm4wp_before_recent_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = __( "Recent Products", "duracelltomi-google-tag-manager" );
}

function gtm4wp_before_sale_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = __( "Sale Products", "duracelltomi-google-tag-manager" );
}

function gtm4wp_before_best_selling_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = __( "Best Selling Products", "duracelltomi-google-tag-manager" );
}

function gtm4wp_before_top_rated_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = __( "Top Rated Products", "duracelltomi-google-tag-manager" );
}

function gtm4wp_before_featured_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = __( "Featured Products", "duracelltomi-google-tag-manager" );
}

function gtm4wp_before_related_products_loop() {
	global $woocommerce_loop;

	$woocommerce_loop[ "listtype" ] = __( "Related Products", "duracelltomi-google-tag-manager" );
}

function gtm4wp_woocommerce_before_shop_loop_item() {
	global $product, $woocommerce_loop;
	
	$product_price = $product->get_price();
	$product_cat = "";
	if ( is_product_category() ) {
		global $wp_query;
		$cat_obj = $wp_query->get_queried_object();
		$product_cat = $cat_obj->name;
	} else {
		$_product_cats = get_the_terms($product->id, 'product_cat');
		if ( ( is_array($_product_cats) ) && ( count( $_product_cats ) > 0 ) ) {
			$last_product_cat = array_pop( $_product_cats );
			$product_cat = $last_product_cat->name;
		}
	}

	if ( is_search() ) {
		$list_name = __( "Search Results", "duracelltomi-google-tag-manager" );
	} else if ( isset( $woocommerce_loop[ "listtype" ] ) && ( $woocommerce_loop[ "listtype" ] != '' ) ) {
		$list_name = $woocommerce_loop[ "listtype" ];
	} else {
		$list_name = __( "General Product List", "duracelltomi-google-tag-manager" );
	}

	$productlink = get_permalink();

	printf('<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_id="%s" data-gtm4wp_product_name="%s" data-gtm4wp_product_price="%s" data-gtm4wp_product_cat="%s" data-gtm4wp_product_url="%s" data-gtm4wp_product_listposition="%s" data-gtm4wp_productlist_name="%s"></span>',
		esc_attr( $product->id ),
		esc_attr( $product->get_title() ),
		esc_attr( $product_price ),
		esc_attr( $product_cat ),
		esc_url( $productlink ),
		esc_attr( $woocommerce_loop[ "loop" ] ),
		esc_attr( $list_name )
	);
}

// do not add filter if someone enabled WooCommerce integration without an activated WooCommerce plugin
if ( isset ( $GLOBALS["woocommerce"] ) ) {
	add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, "gtm4wp_woocommerce_datalayer_filter_items" );

	add_filter( 'loop_end', 'gtp4wp_woocommerce_reset_loop' );

	add_action( "woocommerce_before_shop_loop_item", "gtm4wp_woocommerce_before_shop_loop_item" );

//	add_filter( "woocommerce_loop_add_to_cart_link", "gtm4wp_woocommerce_add_prod_data" );
	add_action( "woocommerce_after_add_to_cart_button", "gtm4wp_woocommerce_single_add_to_cart_tracking" );
	add_action( "wp_footer", "gtm4wp_woocommerce_wp_footer" );
	
	if ( true === $GLOBALS[ "gtm4wp_options" ][ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) {
		add_action( 'wp_footer', 'gtm4wp_woocommerce_enhanced_ecom_product_click' );
		add_action( 'woocommerce_before_template_part', 'gtm4wp_woocommerce_before_template_part' );
		add_action( 'woocommerce_after_template_part', 'gtm4wp_woocommerce_after_template_part' );
		add_filter( 'widget_title', 'gtm4wp_widget_title_filter' );

		add_filter( "woocommerce_cart_item_product", "gtm4wp_woocommerce_cart_item_product_filter" );
		add_filter( "woocommerce_cart_item_remove_link", "gtm4wp_woocommerce_cart_item_remove_link_filter" );

		add_filter( "woocommerce_related_products_args", "gtm4wp_woocommerce_add_related_to_loop" );

		add_action( 'woocommerce_shortcode_before_recent_products_loop',       'gtm4wp_before_recent_products_loop' );
		add_action( 'woocommerce_shortcode_before_sale_products_loop',         'gtm4wp_before_sale_products_loop' );
		add_action( 'woocommerce_shortcode_before_best_selling_products_loop', 'gtm4wp_before_best_selling_products_loop' );
		add_action( 'woocommerce_shortcode_before_top_rated_products_loop',    'gtm4wp_before_top_rated_products_loop' );
		add_action( 'woocommerce_shortcode_before_featured_products_loop',     'gtm4wp_before_featured_products_loop' );
		add_action( 'woocommerce_shortcode_before_related_products_loop',      'gtm4wp_before_related_products_loop' );
	}
}
