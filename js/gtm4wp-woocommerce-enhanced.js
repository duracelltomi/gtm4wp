var gtm4wp_last_selected_product_variation;
var gtm4wp_changedetail_fired_during_pageload=false;

function gtm4wp_map_eec_to_ga4( productdata ) {
	if (!productdata) {
		return;
	}

	var category_path  = productdata.category ? productdata.category : '';
	var category_parts = category_path.toString().split('/');

	// default, required parameters
	var ga4_product = {
		'item_id': productdata.id ? productdata.id : '',
		'item_name': productdata.name ? productdata.name : '',
		'item_brand': productdata.brand ? productdata.brand : '',
		'price': productdata.price ? productdata.price : ""
	};

	// category, also handle category path
	if ( 1 == category_parts.length ) {
		ga4_product.item_category = category_parts[0];
	} else if ( category_parts.length > 1 ) {
		ga4_product.item_category = category_parts[0];
		for( var i=1; i < Math.min( 5, category_parts.length ); i++ ) {
			ga4_product[ 'item_category_' + (i+1) ] = category_parts[i];
		}
	}

	// optional parameters which should not be included in the array if not set
	if ( productdata.variant ) {
		ga4_product.item_variant = productdata.variant;
	}
	if ( productdata.list ) {
		ga4_product.item_list_name = productdata.list;
	}
	if ( productdata.position ) {
		ga4_product.index = productdata.position;
	}
	if ( productdata.quantity ) {
		ga4_product.quantity = productdata.quantity;
	}
	if ( productdata.coupon ) {
		ga4_product.coupon = productdata.coupon;
	}

	ga4_product.google_business_vertical = window.gtm4wp_business_vertical;
	ga4_product[ window.gtm4wp_business_vertical_id ] = gtm4wp_id_prefix + ga4_product[ "item_id" ];

	return ga4_product;
}

function gtm4wp_handle_cart_qty_change() {
	jQuery( '.product-quantity input.qty' ).each(function() {
		var _original_value = jQuery( this ).prop( 'defaultValue' );

		var _current_value  = parseInt( jQuery( this ).val() );
		if ( Number.isNaN( _current_value ) ) {
			_current_value = _original_value;
		}

		if ( _original_value != _current_value ) {
			var productdata = jQuery( this ).closest( '.cart_item' ).find( '.remove' );
			var productprice = productdata.data( 'gtm4wp_product_price' );

			if ( typeof productprice == "string" ) {
				productprice = parseFloat( productprice );
				if ( isNaN( productprice ) ) {
					productprice = 0;
				}
			} else if ( typeof productprice != "number" ) {
				productprice = 0;
			}

			if ( _original_value < _current_value ) {
				var product_data = {
					'name':       productdata.data( 'gtm4wp_product_name' ),
					'id':         productdata.data( 'gtm4wp_product_id' ),
					'price':      productprice.toFixed(2),
					'category':   productdata.data( 'gtm4wp_product_cat' ),
					'variant':    productdata.data( 'gtm4wp_product_variant' ),
					'stocklevel': productdata.data( 'gtm4wp_product_stocklevel' ),
					'brand':      productdata.data( 'gtm4wp_product_brand' ),
					'quantity':   _current_value - _original_value
				};

				// fire ga3 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.addProductToCartEEC',
					'ecommerce': {
						'currencyCode': gtm4wp_currency,
						'add': {
							'products': [ product_data ]
						}
					}
				});

				// fire ga4 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'add_to_cart',
					'ecommerce': {
						'currency': gtm4wp_currency, // ga4 version
						'value': productprice.toFixed(2) * (_current_value - _original_value),
						'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
					}
				});
			} else {
				var product_data = {
					'name':       productdata.data( 'gtm4wp_product_name' ),
					'id':         productdata.data( 'gtm4wp_product_id' ),
					'price':      productprice.toFixed(2),
					'category':   productdata.data( 'gtm4wp_product_cat' ),
					'variant':    productdata.data( 'gtm4wp_product_variant' ),
					'stocklevel': productdata.data( 'gtm4wp_product_stocklevel' ),
					'brand':      productdata.data( 'gtm4wp_product_brand' ),
					'quantity':   _original_value - _current_value
				};

				// fire ga3 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.removeFromCartEEC',
					'ecommerce': {
						'currencyCode': gtm4wp_currency,
						'remove': {
							'products': [ product_data ]
						}
					}
				});

				// fire ga4 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'remove_from_cart',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'value': productprice.toFixed(2) * (_original_value - _current_value),
						'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
					}
				});
			}
		} // end if qty changed
	}); // end each qty field
} // end gtm4wp_handle_cart_qty_change()

jQuery(function() {
	var is_cart     = jQuery( 'body' ).hasClass( 'woocommerce-cart' );
	var is_checkout = jQuery( 'body' ).hasClass( 'woocommerce-checkout' );

	// track impressions of products in product lists
	if ( jQuery( '.gtm4wp_productdata,.widget-product-item' ).length > 0 ) {
		var products = [];
		var ga4_products = [];
		var productdata, productprice=0;
		var product_data;

		jQuery( '.gtm4wp_productdata,.widget-product-item' ).each( function() {
			productdata = jQuery( this );
			productprice = productdata.data( 'gtm4wp_product_price' );

			if ( typeof productprice == "string" ) {
				productprice = parseFloat( productprice );
				if ( isNaN( productprice ) ) {
					productprice = 0;
				}
			} else if ( typeof productprice != "number" ) {
				productprice = 0;
			}

			product_data = {
				'name':       productdata.data( 'gtm4wp_product_name' ),
				'id':         productdata.data( 'gtm4wp_product_id' ),
				'price':      productprice.toFixed(2),
				'category':   productdata.data( 'gtm4wp_product_cat' ),
				'position':   productdata.data( 'gtm4wp_product_listposition' ),
				'list':       productdata.data( 'gtm4wp_productlist_name' ),
				'stocklevel': productdata.data( 'gtm4wp_product_stocklevel' ),
				'brand':      productdata.data( 'gtm4wp_product_brand' )
			};
			products.push(product_data);
			ga4_products.push( gtm4wp_map_eec_to_ga4( product_data ) );
		});

		if ( gtm4wp_product_per_impression > 0 ) {
			// Need to split the product submissions up into chunks in order to avoid the GA 8kb submission limit
			var chunk, ga4_chunk;
			while ( products.length ) {
				chunk = products.splice( 0, gtm4wp_product_per_impression );
				ga4_chunk = ga4_products.splice( 0, gtm4wp_product_per_impression );

				// fire ga3 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.productImpressionEEC',
					'ecommerce': {
						'currencyCode': gtm4wp_currency,
						'impressions': chunk
					}
				});

				// fire ga4 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'view_item_list',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'items': ga4_chunk
					}
				});
			}
		} else {
			for( var i=0; i<window[ gtm4wp_datalayer_name ].length; i++ ) {
				if ( window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ] ) {

					if ( ! window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ][ 'impressions' ] ) {
						window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ][ 'impressions' ] = products;
					} else {
						window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ][ 'impressions' ] = window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ][ 'impressions' ].concat( products );
					}

					break;
				}
			}

			if ( i == window[ gtm4wp_datalayer_name ].length ) {
				// no existing ecommerce data found in the datalayer
				i = 0;
				window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ] = {};
				window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ][ 'impressions' ] = products;
			}

			window[ gtm4wp_datalayer_name ][ i ][ 'ecommerce' ][ 'currencyCode' ] = gtm4wp_currency;
		}
	}

	// track add to cart events for simple products in product lists
	jQuery( document ).on( 'click', '.add_to_cart_button:not(.product_type_variable, .product_type_grouped, .single_add_to_cart_button)', function() {
		var productdata = jQuery( this ).closest( '.product' ).find( '.gtm4wp_productdata' );
		var productprice = productdata.data( 'gtm4wp_product_price' );

		if ( typeof productprice == "string" ) {
			productprice = parseFloat( productprice );
			if ( isNaN( productprice ) ) {
				productprice = 0;
			}
		} else if ( typeof productprice != "number" ) {
			productprice = 0;
		}

		var product_data = {
			'name':       productdata.data( 'gtm4wp_product_name' ),
			'id':         productdata.data( 'gtm4wp_product_id' ),
			'price':      productprice.toFixed(2),
			'category':   productdata.data( 'gtm4wp_product_cat' ),
			'stocklevel': productdata.data( 'gtm4wp_product_stocklevel' ),
			'brand':      productdata.data( 'gtm4wp_product_brand' ),
			'quantity':   1
		};

		// fire ga3 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.addProductToCartEEC',
			'ecommerce': {
				'currencyCode': gtm4wp_currency,
				'add': {
					'products': [ product_data ]
				}
			}
		});

		// fire ga4 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'add_to_cart',
			'ecommerce': {
				'currency': gtm4wp_currency,
				'value': productprice.toFixed(2),
				'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
			}
		});
	});

	// track add to cart events for products on product detail pages
	jQuery( document ).on( 'click', '.single_add_to_cart_button:not(.disabled)', function() {
		var product_form       = jQuery( this ).closest( 'form.cart' );
		var product_variant_id = jQuery( '[name=variation_id]', product_form );
		var product_is_grouped = jQuery( product_form ).hasClass( 'grouped_form' );

		if ( product_variant_id.length > 0 ) {
			if ( gtm4wp_last_selected_product_variation ) {
				gtm4wp_last_selected_product_variation.quantity = jQuery( '[name=quantity]', product_form ).val();

				// fire ga3 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.addProductToCartEEC',
					'ecommerce': {
						'currencyCode': gtm4wp_currency,
						'add': {
							'products': [gtm4wp_last_selected_product_variation]
						}
					}
				});

				// fire ga4 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'add_to_cart',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'value': gtm4wp_last_selected_product_variation.price * gtm4wp_last_selected_product_variation.quantity,
						'items': [ gtm4wp_map_eec_to_ga4( gtm4wp_last_selected_product_variation ) ]
					}
				});
			}
		} else if ( product_is_grouped ) {
			var products_in_group = jQuery( '.grouped_form .gtm4wp_productdata' );
			var products = [];
			var ga4_products = [];
			var sum_value = 0;

			products_in_group.each( function() {
				var productdata = jQuery( this );

				var product_qty_input = jQuery( 'input[name=quantity\\[' + productdata.data( 'gtm4wp_product_id' ) + '\\]]' );
				if ( product_qty_input.length > 0 ) {
					product_qty = product_qty_input.val();
				} else {
					return;
				}

				if ( 0 == product_qty ) {
					return;
				}

				var product_data = {
					'id':         gtm4wp_use_sku_instead ? productdata.data( 'gtm4wp_product_sku' ) : productdata.data( 'gtm4wp_product_id' ),
					'name':       productdata.data( 'gtm4wp_product_name' ),
					'price':      productdata.data( 'gtm4wp_product_price' ),
					'category':   productdata.data( 'gtm4wp_product_cat' ),
					'quantity':   product_qty,
					'stocklevel': productdata.data( 'gtm4wp_product_stocklevel' ),
					'brand':      productdata.data( 'gtm4wp_product_brand' )
				};

				products.push( product_data );
				ga4_products.push( gtm4wp_map_eec_to_ga4( product_data ) );
				sum_value += product_data.price * product_data.quantity;
			});

			if ( 0 == products.length ) {
				return;
			}

			// fire ga3 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.addProductToCartEEC',
				'ecommerce': {
					'currencyCode': gtm4wp_currency,
					'add': {
						'products': products
					}
				}
			});

			// fire ga4 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'add_to_cart',
				'ecommerce': {
					'currency': gtm4wp_currency,
					'value': sum_value,
					'items': ga4_products
				}
			});
		} else {
			var product_data = {
				'id':         gtm4wp_use_sku_instead ? jQuery( '[name=gtm4wp_sku]', product_form ).val() : jQuery( '[name=gtm4wp_id]', product_form ).val(),
				'name':       jQuery( '[name=gtm4wp_name]', product_form ).val(),
				'price':      jQuery( '[name=gtm4wp_price]', product_form ).val(),
				'category':   jQuery( '[name=gtm4wp_category]', product_form ).val(),
				'quantity':   jQuery( '[name=quantity]', product_form ).val(),
				'stocklevel': jQuery( '[name=gtm4wp_stocklevel]', product_form ).val(),
				'brand':      jQuery( '[name=gtm4wp_brand]', product_form ).val()
			};

			// fire ga3 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.addProductToCartEEC',
				'ecommerce': {
					'currencyCode': gtm4wp_currency,
					'add': {
						'products': [ product_data ]
					}
				}
			});

			// fire ga4 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'add_to_cart',
				'ecommerce': {
					'currency': gtm4wp_currency,
					'value': product_data.price * product_data.quantity,
					'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
				}
			});
		}
	});

	// track remove links in mini cart widget and on cart page
	jQuery( document ).on( 'click', '.mini_cart_item a.remove,.product-remove a.remove', function() {
		var productdata = jQuery( this );

		var qty = 0;
		var qty_element = jQuery( this ).closest( '.cart_item' ).find( '.product-quantity input.qty' );
		if ( qty_element.length === 0 ) {
			qty_element = jQuery( this ).closest( '.mini_cart_item' ).find( '.quantity' );
			if ( qty_element.length > 0 ) {
				qty = parseInt( qty_element.text() );

				if ( Number.isNaN( qty ) ) {
					qty = 0;
				}
			}
		} else {
			qty = qty_element.val();
		}

		if ( qty === 0 ) {
			return true;
		}

		var product_data = {
			'name':       productdata.data( 'gtm4wp_product_name' ),
			'id':         productdata.data( 'gtm4wp_product_id' ),
			'price':      productdata.data( 'gtm4wp_product_price' ),
			'category':   productdata.data( 'gtm4wp_product_cat' ),
			'variant':    productdata.data( 'gtm4wp_product_variant' ),
			'stocklevel': productdata.data( 'gtm4wp_product_stocklevel' ),
			'brand':      productdata.data( 'gtm4wp_product_brand' ),
			'quantity':   qty
		};

		// fire ga3 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.removeFromCartEEC',
			'ecommerce': {
				'currencyCode': gtm4wp_currency,
				'remove': {
					'products': [ product_data ]
				}
			}
		});

		// fire ga4 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'remove_from_cart',
			'ecommerce': {
				'currency': gtm4wp_currency,
				'value': product_data.price * product_data.quantity,
				'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
			}
		});
	});

	// track clicks in product lists
	jQuery( document ).on( 'click', '.products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),.products>div:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),.widget-product-item,.woocommerce-grouped-product-list-item__label a', function( event ) {
		// do nothing if GTM is blocked for some reason
		if ( 'undefined' == typeof google_tag_manager ) {
			return true;
		}

		var temp_selector = jQuery( this ).closest( '.product' );
		var dom_productdata = '';

		if ( temp_selector.length > 0 ) {
			dom_productdata = temp_selector.find( '.gtm4wp_productdata' );

		} else {
			temp_selector = jQuery( this ).closest( '.products li' );

			if ( temp_selector.length > 0 ) {
				dom_productdata = temp_selector.find( '.gtm4wp_productdata' );

			} else {
				temp_selector = jQuery( this ).closest( '.products>div' );

				if ( temp_selector.length > 0 ) {
					dom_productdata = temp_selector.find( '.gtm4wp_productdata' );

				} else {
					temp_selector = jQuery( this ).closest( '.woocommerce-grouped-product-list-item__label' );

					if ( temp_selector.length > 0 ) {
						dom_productdata = temp_selector.find( '.gtm4wp_productdata' );
					} else {
						dom_productdata = jQuery( this );
					}
				}
			}
		}

		if ( ( 'undefined' == typeof dom_productdata.data( 'gtm4wp_product_id' ) ) || ( '' == dom_productdata.data( 'gtm4wp_product_id' ) ) ) {
			return true;
		}

		// only act on links pointing to the product detail page
		if ( dom_productdata.data( 'gtm4wp_product_url' ) != jQuery( this ).attr( 'href' ) ) {
			return true;
		}

		var ctrl_key_pressed = event.ctrlKey || event.metaKey;

		event.preventDefault();
		if ( ctrl_key_pressed ) {
			// we need to open the new tab/page here so that popup blocker of the browser doesn't block our code
			var productpage_window = window.open( 'about:blank', '_blank' );
		}

		var product_data = {
			'id':         dom_productdata.data( 'gtm4wp_product_id' ),
			'name':       dom_productdata.data( 'gtm4wp_product_name' ),
			'price':      dom_productdata.data( 'gtm4wp_product_price' ),
			'category':   dom_productdata.data( 'gtm4wp_product_cat' ),
			'stocklevel': dom_productdata.data( 'gtm4wp_product_stocklevel' ),
			'brand':      dom_productdata.data( 'gtm4wp_product_brand' ),
			'position':   dom_productdata.data( 'gtm4wp_product_listposition' )
		};

		// fire ga3 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.productClickEEC',
			'ecommerce': {
				'currencyCode': gtm4wp_currency,
				'click': {
					'actionField': {'list': dom_productdata.data( 'gtm4wp_productlist_name' )},
					'products': [ product_data ]
				}
			},
			'eventCallback': function() {

				// do not fire this event multiple times
				if ( window[ "gtm4wp_select_item_" + product_data.id ] ) {
					return;
				}

				// fire ga4 version
				window[ gtm4wp_datalayer_name ].push({
					'event': 'select_item',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
					},
					'eventCallback': function() {

						if ( ctrl_key_pressed && productpage_window ) {
							productpage_window.location.href= dom_productdata.data( 'gtm4wp_product_url' );
						} else {
							document.location.href = dom_productdata.data( 'gtm4wp_product_url' );
						}

					},
					'eventTimeout': 2000
				});

				window[ "gtm4wp_select_item_" + product_data.id ] = true;

			},
			'eventTimeout': 2000
		});
	});

	// track variable products on their detail pages
	jQuery( document ).on( 'found_variation', function( event, product_variation ) {
		if ( "undefined" == typeof product_variation ) {
			// some ither plugins trigger this event without variation data
			return;
		}

		if ( (document.readyState === "interactive") && gtm4wp_changedetail_fired_during_pageload ) {
			// some custom attribute rendering plugins fire this event multiple times during page load
			return;
		}

		var product_form       = event.target;
		var product_variant_id = jQuery( '[name=variation_id]', product_form );
		var product_id         = jQuery( '[name=gtm4wp_id]', product_form ).val();
		var product_name       = jQuery( '[name=gtm4wp_name]', product_form ).val();
		var product_sku        = jQuery( '[name=gtm4wp_sku]', product_form ).val();
		var product_category   = jQuery( '[name=gtm4wp_category]', product_form ).val();
		var product_price      = jQuery( '[name=gtm4wp_price]', product_form ).val();
		var product_stocklevel = jQuery( '[name=gtm4wp_stocklevel]', product_form ).val();
		var product_brand      = jQuery( '[name=gtm4wp_brand]', product_form ).val();

		var current_product_detail_data = {
			name: product_name,
			id: 0,
			price: 0,
			category: product_category,
			stocklevel: product_stocklevel,
			brand: product_brand,
			variant: ''
		};

		current_product_detail_data.id = product_variation.variation_id;
		if ( gtm4wp_use_sku_instead && product_variation.sku && ('' !== product_variation.sku) ) {
			current_product_detail_data.id = product_variation.sku;
		}
		current_product_detail_data.price = product_variation.display_price;

		var _tmp = [];
		for( var attrib_key in product_variation.attributes ) {
			_tmp.push( product_variation.attributes[ attrib_key ] );
		}
		current_product_detail_data.variant = _tmp.join(',');
		gtm4wp_last_selected_product_variation = current_product_detail_data;

		// fire ga3 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.changeDetailViewEEC',
			'ecommerce': {
				'currencyCode': gtm4wp_currency,
				'detail': {
					'products': [ current_product_detail_data ]
				}
			},
			'ecomm_prodid': gtm4wp_id_prefix + current_product_detail_data.id,
			'ecomm_pagetype': 'product',
			'ecomm_totalvalue': current_product_detail_data.price,
		});

		// fire ga4 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'view_item',
			'ecommerce': {
				'currency': gtm4wp_currency,
				'items': [ gtm4wp_map_eec_to_ga4( current_product_detail_data ) ]
			}
		});

		if ( document.readyState === "interactive" ) {
			gtm4wp_changedetail_fired_during_pageload = true;
		}
	});
	jQuery( '.variations select' ).trigger( 'change' );

	// initiate codes in WooCommere Quick View
	jQuery( document ).ajaxSuccess( function( event, xhr, settings ) {
		if(typeof settings !== 'undefined') {
			if (settings.url.indexOf( 'wc-api=WC_Quick_View' ) > -1 ) {
			  setTimeout( function() {
					jQuery( ".woocommerce.quick-view" ).parent().find( "script" ).each( function(i) {
						eval( jQuery( this ).text() );
					});
				}, 500);
			}
		}
	});

	// codes for enhanced ecommerce events on cart page
	if ( is_cart ) {
		jQuery( document ).on( 'click', '[name=update_cart]', function() {
			gtm4wp_handle_cart_qty_change();
		});

		jQuery( document ).on( 'keypress', '.woocommerce-cart-form input[type=number]', function() {
			gtm4wp_handle_cart_qty_change();
		});
	}

	// codes for enhanced ecommerce events on checkout page
	if ( is_checkout ) {
		window.gtm4wp_checkout_step_offset  = window.gtm4wp_checkout_step_offset || 0;
		window.gtm4wp_checkout_value        = window.gtm4wp_checkout_value || 0;
		window.gtm4wp_checkout_products     = window.gtm4wp_checkout_products || [];
		window.gtm4wp_checkout_products_ga4 = window.gtm4wp_checkout_products_ga4 || [];

		var gtm4wp_shipping_payment_method_step_offset =  window.gtm4wp_needs_shipping_address ? 0 : -1;
		var gtm4wp_checkout_step_fired                 = []; // step 1 will be the billing section which is reported during pageload, no need to handle here

		// this checkout step is not reported to GA4 as currently there is no option to report in-between custom steps
		jQuery( document ).on( 'blur', 'input[name^=shipping_]:not(input[name^=shipping_method])', function() {
			// do not report checkout step if already reported
			if ( gtm4wp_checkout_step_fired.indexOf( 'shipping' ) > -1 ) {
				return;
			}

			// do not report checkout step if user is traversing through the section without filling in any data
			if ( jQuery( this ).val().trim() == '' ) {
				return;
			}

			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.checkoutStepEEC',
				'ecommerce': {
					'currencyCode': gtm4wp_currency, // ga3 version
					'checkout': {
						'actionField': {
							'step': 2 + window.gtm4wp_checkout_step_offset
						},
						'products': window.gtm4wp_checkout_products
					}
				}
			});

			gtm4wp_checkout_step_fired.push( 'shipping' );
		});

		jQuery( document ).on( 'change', 'input[name^=shipping_method]', function() {
			// do not report checkout step if already reported
			if ( gtm4wp_checkout_step_fired.indexOf( 'shipping_method' ) > -1 ) {
				return;
			}

			// do not fire event during page load
			if ( 'complete' != document.readyState ) {
				return;
			}

			var shipping_tier = '(shipping tier not found)';
			var shipping_el = jQuery( 'input[name^=shipping_method]:checked' );
			if ( shipping_el.length == 0 ) {
				shipping_el = jQuery( 'input[name^=shipping_method]:first' );
			}
			if ( shipping_el.length > 0 ) {
				shipping_tier = shipping_el.val();
			}

			// fire ga3 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.checkoutStepEEC',
				'ecommerce': {
					'currencyCode': gtm4wp_currency,
					'checkout': {
						'actionField': {
							'step': 3 + window.gtm4wp_checkout_step_offset + gtm4wp_shipping_payment_method_step_offset
						},
						'products': window.gtm4wp_checkout_products
					}
				}
			});

			// fire ga4 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'add_shipping_info',
				'ecommerce': {
					'currency': gtm4wp_currency,
					'shipping_tier': shipping_tier,
					'value': window.gtm4wp_checkout_value,
					'items': window.gtm4wp_checkout_products_ga4
				}
			});

			gtm4wp_checkout_step_fired.push( 'shipping_method' );
		});

		jQuery( document ).on( 'change', 'input[name=payment_method]', function() {
			// do not report checkout step if already reported
			if ( gtm4wp_checkout_step_fired.indexOf( 'payment_method' ) > -1 ) {
				return;
			}

			// do not fire event during page load
			if ( 'complete' != document.readyState ) {
				return;
			}

			var payment_type = '(payment type not found)';
			var payment_el = jQuery( '.payment_methods input:checked' );
			if ( payment_el.length == 0 ) {
				payment_el = jQuery( 'input[name^=payment_method]:first' );
			}
			if ( payment_el.length > 0 ) {
				payment_type = payment_el.val();
			}

			// fire ga3 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.checkoutStepEEC',
				'ecommerce': {
					'currencyCode': gtm4wp_currency,
					'checkout': {
						'actionField': {
							'step': 4 + window.gtm4wp_checkout_step_offset + gtm4wp_shipping_payment_method_step_offset
						},
						'products': window.gtm4wp_checkout_products
					}
				}
			});

			// fire ga4 version
			window[ gtm4wp_datalayer_name ].push({
				'event': 'add_payment_info',
				'ecommerce': {
					'currency': gtm4wp_currency,
					'payment_type': payment_type,
					'value': window.gtm4wp_checkout_value,
					'items': window.gtm4wp_checkout_products_ga4
				}
			});

			gtm4wp_checkout_step_fired.push( 'payment_method' );
		});

		jQuery( 'form[name=checkout]' ).on( 'submit', function() {
			if ( gtm4wp_checkout_step_fired.indexOf( 'shipping_method' ) == -1 ) {
				// shipping methods are not visible if only one is available
				// and if the user has already a pre-selected method, no click event will fire to report the checkout step
				var selected_shipping_method = jQuery( 'input[name^=shipping_method]:checked' );
				if ( selected_shipping_method.length == 0 ) {
					selected_shipping_method = jQuery( 'input[name^=shipping_method]:first' );
				}
				if ( selected_shipping_method.length > 0 ) {
					selected_shipping_method.trigger( 'change' );
				}
			}

			if ( gtm4wp_checkout_step_fired.indexOf( 'payment_method' ) == -1 ) {
				// if the user has already a pre-selected method, no click event will fire to report the checkout step
				jQuery( 'input[name=payment_method]:checked' ).trigger( 'change' );
			}

			var shipping_el = jQuery( 'input[name^=shipping_method]:checked' );
			if ( shipping_el.length == 0 ) {
				shipping_el = jQuery( 'input[name^=shipping_method]:first' );
			}
			if ( shipping_el.length > 0 ) {
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.checkoutOptionEEC',
					'ecommerce': {
						'checkout_option': {
							'actionField': {
								'step': 3 + window.gtm4wp_checkout_step_offset + gtm4wp_shipping_payment_method_step_offset,
								'option': 'Shipping: ' + shipping_el.val()
							}
						}
					}
				});
			}

			var payment_el = jQuery( '.payment_methods input:checked' );
			if ( payment_el.length == 0 ) {
				payment_el = jQuery( 'input[name^=payment_method]:first' );
			}
			if ( payment_el.length > 0 ) {
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.checkoutOptionEEC',
					'ecommerce': {
						'checkout_option': {
							'actionField': {
								'step': 4 + window.gtm4wp_checkout_step_offset + gtm4wp_shipping_payment_method_step_offset,
								'option': 'Payment: ' + payment_el.val()
							}
						}
					}
				});
			}
		});
	}

	// codes for Google Ads dynamic remarketing
	if ( window.gtm4wp_remarketing&& !is_cart && !is_checkout ) {
		if ( jQuery( '.gtm4wp_productdata' ).length > 0 ) {
			for( var i=0; i<window[ gtm4wp_datalayer_name ].length; i++ ) {
				if ( window[ gtm4wp_datalayer_name ][ i ][ 'ecomm_prodid' ] ) {
					break;
				}
			}

			if ( i == window[ gtm4wp_datalayer_name ].length ) {
				// no existing dyn remarketing data found in the datalayer
				i = 0;
				window[ gtm4wp_datalayer_name ][ i ][ 'ecomm_prodid' ] = [];
			}

			if ( 'undefined' !== typeof window[ gtm4wp_datalayer_name ][ i ][ 'ecomm_prodid' ].push ) {
				var productdata;
				jQuery( '.gtm4wp_productdata' ).each( function() {
					productdata = jQuery( this );

					window[ gtm4wp_datalayer_name ][ i ][ 'ecomm_prodid' ].push( gtm4wp_id_prefix + productdata.data( 'gtm4wp_product_id' ) );
				});
			}
		}
	}

	// loop through datalayer and fire GA4 version of EEC events
	if ( window[ gtm4wp_datalayer_name ] && window[ gtm4wp_datalayer_name ].forEach ) {
		window[ gtm4wp_datalayer_name ].forEach(function( item ) {

			if ( item && item.ecommerce && item.ecommerce.detail ) {
				window[ gtm4wp_datalayer_name ].push({
					'event': 'view_item',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'items': [ gtm4wp_map_eec_to_ga4( item.ecommerce.detail.products[0] ) ]
					}
				});
			}

			if ( item && item.ecommerce && ( item.ecommerce.cart || (item.ecommerce.checkout && is_cart) ) ) {
				var source_products = item.ecommerce.cart || item.ecommerce.checkout.products;
				var ga4_products = [];
				var sum_value = 0;

				source_products.forEach(function( product ) {
					ga4_products.push( gtm4wp_map_eec_to_ga4( product ) );
					sum_value += product.price * product.quantity;
				});

				window[ gtm4wp_datalayer_name ].push({
					'event': 'view_cart',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'value': sum_value.toFixed(2),
						'items': ga4_products
					}
				});
			}

			if ( item && item.ecommerce && item.ecommerce.checkout && !is_cart ) {
				var ga4_products = [];
				var sum_value = 0;

				item.ecommerce.checkout.products.forEach(function( product ) {
					ga4_products.push( gtm4wp_map_eec_to_ga4( product ) );
					sum_value += product.price * product.quantity;
				});

				window[ gtm4wp_datalayer_name ].push({
					'event': 'begin_checkout',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'value': sum_value,
						'items': ga4_products
					}
				});
			}

			// present if product is readded into cart just after removel
			if ( item && item.ecommerce && item.ecommerce.add ) {
				var ga4_products = [];
				var sum_value = 0;

				item.ecommerce.add.products.forEach(function( product ) {
					ga4_products.push( gtm4wp_map_eec_to_ga4( product ) );
					sum_value += product.price * product.quantity;
				});

				window[ gtm4wp_datalayer_name ].push({
					'event': 'add_to_cart',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'value': sum_value,
						'items': ga4_products
					}
				});
			}

			if ( item && item.ecommerce && item.ecommerce.purchase ) {
				var ga4_products = [];
				item.ecommerce.purchase.products.forEach(function( product ) {
					ga4_products.push( gtm4wp_map_eec_to_ga4( product ) );
				});

				window[ gtm4wp_datalayer_name ].push({
					'event': 'purchase',
					'ecommerce': {
						'currency': gtm4wp_currency,
						'items': ga4_products,
						'transaction_id': item.ecommerce.purchase.actionField.id,
						'affiliation': item.ecommerce.purchase.actionField.affiliation,
						'value': item.ecommerce.purchase.actionField.revenue,
						'tax': item.ecommerce.purchase.actionField.tax,
						'shipping': item.ecommerce.purchase.actionField.shipping,
						'coupon': item.ecommerce.purchase.actionField.coupon
					}
				});
			}
		});
	}
});
