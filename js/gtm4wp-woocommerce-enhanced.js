window.gtm4wp_last_selected_product_variation;
window.gtm4wp_changedetail_fired_during_pageload=false;

window.gtm4wp_is_cart     = false;
window.gtm4wp_is_checkout = false;
window.gtm4wp_checkout_step_fired = []; // step 1 will be the billing section which is reported during pageload, no need to handle here
window.gtm4wp_shipping_payment_method_step_offset =  gtm4wp_needs_shipping_address ? 0 : -1;

window.gtm4wp_first_container_id = "";

function gtm4wp_map_eec_to_ga4( productdata ) {
	if ( !productdata ) {
		return;
	}

	const category_path  = productdata.category ? productdata.category : '';
	const category_parts = category_path.toString().split('/');

	// default, required parameters
	let ga4_product = {
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
		for( let i=1; i < Math.min( 5, category_parts.length ); i++ ) {
			ga4_product[ 'item_category' + (i+1) ] = category_parts[i];
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

	ga4_product.google_business_vertical = gtm4wp_business_vertical;
	ga4_product[ gtm4wp_business_vertical_id ] = gtm4wp_id_prefix + ga4_product[ "item_id" ];

	return ga4_product;
}

function gtm4wp_handle_cart_qty_change() {
	document.querySelectorAll( '.product-quantity input.qty' ).forEach(function( qty_el ) {
		const original_value = qty_el.defaultValue;

		let current_value  = parseInt( qty_el.value );
		if ( isNaN( current_value ) ) {
			current_value = original_value;
		}

		// is quantity changed changed?
		if ( original_value != current_value ) {
			const cart_item_temp = qty_el.closest( '.cart_item' );
			const productdata = cart_item_temp && cart_item_temp.querySelector( '.remove' );
			if ( !productdata ) {
				return;
			}

			let productprice = productdata.getAttribute( 'data-gtm4wp_product_price' );

			if ( typeof productprice == "string" ) {
				productprice = parseFloat( productprice );
				if ( isNaN( productprice ) ) {
					productprice = 0;
				}
			} else if ( typeof productprice != "number" ) {
				productprice = 0;
			}

			// does the quantity increase?
			if ( original_value < current_value ) {
				// yes => handle add to cart event
				const product_data = {
					'name':       productdata.getAttribute( 'data-gtm4wp_product_name' ),
					'id':         productdata.getAttribute( 'data-gtm4wp_product_id' ),
					'price':      productprice.toFixed(2),
					'category':   productdata.getAttribute( 'data-gtm4wp_product_cat' ),
					'variant':    productdata.getAttribute( 'data-gtm4wp_product_variant' ),
					'stocklevel': productdata.getAttribute( 'data-gtm4wp_product_stocklevel' ),
					'brand':      productdata.getAttribute( 'data-gtm4wp_product_brand' ),
					'quantity':   current_value - original_value
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
						'value': productprice.toFixed(2) * (current_value - original_value),
						'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
					}
				});
			} else {
				// no => handle remove from cart event
				const product_data = {
					'name':       productdata.getAttribute( 'data-gtm4wp_product_name' ),
					'id':         productdata.getAttribute( 'data-gtm4wp_product_id' ),
					'price':      productprice.toFixed(2),
					'category':   productdata.getAttribute( 'data-gtm4wp_product_cat' ),
					'variant':    productdata.getAttribute( 'data-gtm4wp_product_variant' ),
					'stocklevel': productdata.getAttribute( 'data-gtm4wp_product_stocklevel' ),
					'brand':      productdata.getAttribute( 'data-gtm4wp_product_brand' ),
					'quantity':   original_value - current_value
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
						'value': productprice.toFixed(2) * (original_value - current_value),
						'items': [ gtm4wp_map_eec_to_ga4( product_data ) ]
					}
				});
			}
		} // end if qty changed
	}); // end each qty field
} // end gtm4wp_handle_cart_qty_change()

function gtm4wp_handle_payment_method_change() {
	// do not report checkout step if already reported
	if ( gtm4wp_checkout_step_fired.indexOf( 'payment_method' ) > -1 ) {
		return;
	}

	// do not fire event during page load
	if ( 'complete' != document.readyState ) {
		return;
	}

	let payment_type = '(payment type not found)';
	let payment_el = document.querySelector( '.payment_methods input:checked' );
	if ( !payment_el ) {
		payment_el = document.querySelector( 'input[name^=payment_method]' ); // select the first input element
	}
	if ( payment_el ) {
		payment_type = payment_el.value;
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
} // end gtm4wp_handle_payment_method_change()

function gtm4wp_handle_shipping_method_change() {
	// do not report checkout step if already reported
	if ( gtm4wp_checkout_step_fired.indexOf( 'shipping_method' ) > -1 ) {
		return;
	}

	// do not fire event during page load
	if ( 'complete' != document.readyState ) {
		return;
	}

	let shipping_tier = '(shipping tier not found)';
	let shipping_el = document.querySelector( 'input[name^=shipping_method]:checked' );
	if ( !shipping_el ) {
		shipping_el = document.querySelector( 'input[name^=shipping_method]' ); // select the first input element
	}
	if ( shipping_el ) {
		shipping_tier = shipping_el.value;
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
}

function gtm4wp_process_woocommerce_pages() {
	window.gtm4wp_is_cart     = false;
	window.gtm4wp_is_checkout = false;

	const doc_body = document.querySelector( 'body' );
	if ( doc_body ) {
		window.gtm4wp_is_cart     = doc_body.classList && doc_body.classList.contains( 'woocommerce-cart' );
		window.gtm4wp_is_checkout = doc_body.classList && doc_body.classList.contains( 'woocommerce-checkout' );
	}

	// loop through WC blocks to set proper listname and position parameters
	const gtm4wp_product_block_names = {
		'wp-block-handpicked-products': {
			'displayname': 'Handpicked Products',
			'counter': 1
		},
		'wp-block-product-best-sellers': {
			'displayname': 'Best Selling Products',
			'counter': 1
		},
		'wp-block-product-category': {
			'displayname': 'Product Category List',
			'counter': 1
		},
		'wp-block-product-new': {
			'displayname': 'New Products',
			'counter': 1
		},
		'wp-block-product-on-sale': {
			'displayname': 'Sale Products',
			'counter': 1
		},
		'wp-block-products-by-attribute': {
			'displayname': 'Products By Attribute',
			'counter': 1
		},
		'wp-block-product-tag': {
			'displayname': 'Products By Tag',
			'counter': 1
		},
		'wp-block-product-top-rated': {
			'displayname': 'Top Rated Products',
			'counter': 1
		},
	}
	document.querySelectorAll( '.wc-block-grid .wc-block-grid__product' ).forEach( function( product_grid_item ) {

		const product_grid_container = product_grid_item.closest( '.wc-block-grid' );
		const product_data = product_grid_item.querySelector( '.gtm4wp_productdata' );

		if ( product_grid_container && product_data ) {

			const product_grid_container_classes = product_grid_container.classList;

			if ( product_grid_container_classes ) {

				for(let i in gtm4wp_product_block_names) {
					if ( product_grid_container_classes.contains( i ) ) {
						product_data.setAttribute("data-gtm4wp_productlist_name", gtm4wp_product_block_names[i].displayname);
						product_data.setAttribute("data-gtm4wp_product_listposition", gtm4wp_product_block_names[i].counter);

						gtm4wp_product_block_names[i].counter++;
					}
				}
			}
		}
	});

	// track impressions of products in product lists
	if ( document.querySelectorAll( '.gtm4wp_productdata,.widget-product-item' ).length > 0 ) {
		let products = [];
		let ga4_products = [];
		let productprice = 0;
		let product_data;

		document.querySelectorAll( '.gtm4wp_productdata,.widget-product-item' ).forEach( function( dom_productdata ) {
			productprice = dom_productdata.getAttribute( 'data-gtm4wp_product_price' );

			if ( typeof productprice == "string" ) {
				productprice = parseFloat( productprice );
				if ( isNaN( productprice ) ) {
					productprice = 0;
				}
			} else if ( typeof productprice != "number" ) {
				productprice = 0;
			}

			product_data = {
				'name':       dom_productdata.getAttribute( 'data-gtm4wp_product_name' ),
				'id':         dom_productdata.getAttribute( 'data-gtm4wp_product_id' ),
				'price':      productprice.toFixed(2),
				'category':   dom_productdata.getAttribute( 'data-gtm4wp_product_cat' ),
				'position':   dom_productdata.getAttribute( 'data-gtm4wp_product_listposition' ),
				'list':       dom_productdata.getAttribute( 'data-gtm4wp_productlist_name' ),
				'stocklevel': dom_productdata.getAttribute( 'data-gtm4wp_product_stocklevel' ),
				'brand':      dom_productdata.getAttribute( 'data-gtm4wp_product_brand' )
			};

			products.push(product_data);
			ga4_products.push( gtm4wp_map_eec_to_ga4( product_data ) );
		});

		if ( gtm4wp_product_per_impression > 0 ) {
			// Need to split the product submissions up into chunks in order to avoid the GA 8kb submission limit
			let chunk
			let ga4_chunk;

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
	document.addEventListener( 'click', function( e ) {
		let event_target_element = e.target;

		if ( !event_target_element ) {
			// for some reason event target is not specificed
			return true;
		}

		try {
			if ( !event_target_element.closest( '.add_to_cart_button:not(.product_type_variable, .product_type_grouped, .single_add_to_cart_button)' ) ) {
				return true;
			}
		} catch (e) {
			// during beta testing, closest() sometimes threw SyntaxError which is thrown if selector is invalid. But the selector above should be valid in all cases
			// assumption was that perhaps event_target_element was not set or not a proper DOM node for some reasons
			return true;
		}

		const product_el = event_target_element.closest( '.product,.wc-block-grid__product' );
		const productdata = product_el && product_el.querySelector( '.gtm4wp_productdata' );
		if ( !productdata ) {
			return true;
		}

		let productprice = productdata.getAttribute( 'data-gtm4wp_product_price' );

		if ( typeof productprice == "string" ) {
			productprice = parseFloat( productprice );
			if ( isNaN( productprice ) ) {
				productprice = 0;
			}
		} else if ( typeof productprice != "number" ) {
			productprice = 0;
		}

		const product_data = {
			'name':       productdata.getAttribute( 'data-gtm4wp_product_name' ),
			'id':         productdata.getAttribute( 'data-gtm4wp_product_id' ),
			'price':      productprice.toFixed(2),
			'category':   productdata.getAttribute( 'data-gtm4wp_product_cat' ),
			'stocklevel': productdata.getAttribute( 'data-gtm4wp_product_stocklevel' ),
			'brand':      productdata.getAttribute( 'data-gtm4wp_product_brand' ),
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
	document.addEventListener( 'click', function( e ) {
		let event_target_element = e.target;

		if ( !event_target_element ) {
			// for some reason event target is not specificed
			return true;
		}

		try {
			if ( !event_target_element.closest( '.single_add_to_cart_button:not(.disabled)' ) ) {
				return true;
			}
		} catch (e) {
			// during beta testing, closest() sometimes threw SyntaxError which is thrown if selector is invalid. But the selector above should be valid in all cases
			// assumption was that perhaps event_target_element was not set or not a proper DOM node for some reasons
			return true;
		}

		const product_form = event_target_element.closest( 'form.cart' );
		if ( !product_form ) {
			return true;
		}

		let product_variant_id = product_form.querySelectorAll( '[name=variation_id]' );
		let product_is_grouped = product_form.classList && product_form.classList.contains( 'grouped_form' );

		if ( product_variant_id.length > 0 ) {
			if ( gtm4wp_last_selected_product_variation ) {
				const qty_el = product_form.querySelector( '[name=quantity]' );
				gtm4wp_last_selected_product_variation.quantity = (qty_el && qty_el.value) || 1;

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
			const products_in_group = document.querySelectorAll( '.grouped_form .gtm4wp_productdata' );
			let products = [];
			let ga4_products = [];
			let sum_value = 0;

			products_in_group.forEach( function( dom_productdata ) {
				const product_qty_input = document.querySelectorAll( 'input[name=quantity\\[' + dom_productdata.getAttribute( 'data-gtm4wp_product_id' ) + '\\]]' );
				if ( product_qty_input.length > 0 ) {
					product_qty = (product_qty_input[0] && product_qty_input[0].value) || 1;
				} else {
					return true;
				}

				if ( 0 == product_qty ) {
					return true;
				}

				const product_data = {
					'id':         gtm4wp_use_sku_instead ? dom_productdata.getAttribute( 'data-gtm4wp_product_sku' ) : dom_productdata.getAttribute( 'data-gtm4wp_product_id' ),
					'name':       dom_productdata.getAttribute( 'data-gtm4wp_product_name' ),
					'price':      dom_productdata.getAttribute( 'data-gtm4wp_product_price' ),
					'category':   dom_productdata.getAttribute( 'data-gtm4wp_product_cat' ),
					'quantity':   product_qty,
					'stocklevel': dom_productdata.getAttribute( 'data-gtm4wp_product_stocklevel' ),
					'brand':      dom_productdata.getAttribute( 'data-gtm4wp_product_brand' )
				};

				products.push( product_data );
				ga4_products.push( gtm4wp_map_eec_to_ga4( product_data ) );
				sum_value += product_data.price * product_data.quantity;
			});

			if ( 0 == products.length ) {
				return true;
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
			const product_id_el = gtm4wp_use_sku_instead ? product_form.querySelector( '[name=gtm4wp_sku]' ) : product_form.querySelector( '[name=gtm4wp_id]' );
			const product_data = {
				'id':         product_id_el && product_id_el.value,
				'name':       product_form.querySelector( '[name=gtm4wp_name]' ) && product_form.querySelector( '[name=gtm4wp_name]' ).value,
				'price':      product_form.querySelector( '[name=gtm4wp_price]' ) && product_form.querySelector( '[name=gtm4wp_price]' ).value,
				'category':   product_form.querySelector( '[name=gtm4wp_category]' ) && product_form.querySelector( '[name=gtm4wp_category]' ).value,
				'quantity':   product_form.querySelector( '[name=quantity]' ) && product_form.querySelector( '[name=quantity]' ).value,
				'stocklevel': product_form.querySelector( '[name=gtm4wp_stocklevel]' ) && product_form.querySelector( '[name=gtm4wp_stocklevel]' ).value,
				'brand':      product_form.querySelector( '[name=gtm4wp_brand]' ) && product_form.querySelector( '[name=gtm4wp_brand]' ).value
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
	document.addEventListener( 'click', function( e ) {
		const dom_productdata = e.target;

		if ( !dom_productdata || !dom_productdata.closest( '.mini_cart_item a.remove,.product-remove a.remove' ) ) {
			return true;
		}

		let qty = 0;
		const cart_item_el = dom_productdata.closest( '.cart_item' );
		let qty_element = cart_item_el && cart_item_el.querySelectorAll( '.product-quantity input.qty' );
		if ( !qty_element || ( qty_element.length === 0 ) ) {
			const mini_cart_item_el = dom_productdata.closest( '.mini_cart_item' );
			qty_element = mini_cart_item_el && mini_cart_item_el.querySelectorAll( '.quantity' );
			if ( qty_element && ( qty_element.length > 0 ) ) {
				qty = parseInt( qty_element[0].textContent );

				if ( Number.isNaN( qty ) ) {
					qty = 0;
				}
			}
		} else {
			qty = qty_element[0].value;
		}

		if ( qty === 0 ) {
			return true;
		}

		const product_data = {
			'name':       dom_productdata.getAttribute( 'data-gtm4wp_product_name' ),
			'id':         dom_productdata.getAttribute( 'data-gtm4wp_product_id' ),
			'price':      dom_productdata.getAttribute( 'data-gtm4wp_product_price' ),
			'category':   dom_productdata.getAttribute( 'data-gtm4wp_product_cat' ),
			'variant':    dom_productdata.getAttribute( 'data-gtm4wp_product_variant' ),
			'stocklevel': dom_productdata.getAttribute( 'data-gtm4wp_product_stocklevel' ),
			'brand':      dom_productdata.getAttribute( 'data-gtm4wp_product_brand' ),
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
	let productlist_item_selector = '.products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
		+'.wc-block-grid__products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
		+'.products>div:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
		+'.widget-product-item,'
		+'.woocommerce-grouped-product-list-item__label a'
	document.addEventListener( 'click', function( e ) {
		// do nothing if GTM is blocked for some reason
		if ( 'undefined' == typeof google_tag_manager ) {
			return true;
		}

		const event_target_element = e.target;
		const matching_link_element = event_target_element.closest( productlist_item_selector );

		if ( !matching_link_element ) {
			return true;
		}

		let temp_selector = event_target_element.closest( '.product,.wc-block-grid__product' );
		let dom_productdata;

		if ( temp_selector ) {
			dom_productdata = temp_selector.querySelector( '.gtm4wp_productdata' );

		} else {
			temp_selector = event_target_element.closest( '.products li' );

			if ( temp_selector ) {
				dom_productdata = temp_selector.querySelector( '.gtm4wp_productdata' );

			} else {
				temp_selector = event_target_element.closest( '.products>div' );

				if ( temp_selector ) {
					dom_productdata = temp_selector.querySelector( '.gtm4wp_productdata' );

				} else {
					temp_selector = event_target_element.closest( '.woocommerce-grouped-product-list-item__label' );

					if ( temp_selector ) {
						dom_productdata = temp_selector.querySelector( '.gtm4wp_productdata' );
					} else {
						dom_productdata = event_target_element;
					}
				}
			}
		}

		if ( ( 'undefined' == typeof dom_productdata.getAttribute( 'data-gtm4wp_product_id' ) ) || ( '' == dom_productdata.getAttribute( 'data-gtm4wp_product_id' ) ) ) {
			return true;
		}

		// only act on links pointing to the product detail page
		if ( dom_productdata.getAttribute( 'data-gtm4wp_product_url' ) != matching_link_element.getAttribute( 'href' ) ) {
			return true;
		}

		const product_data = {
			'id':         dom_productdata.getAttribute( 'data-gtm4wp_product_id' ),
			'name':       dom_productdata.getAttribute( 'data-gtm4wp_product_name' ),
			'price':      dom_productdata.getAttribute( 'data-gtm4wp_product_price' ),
			'category':   dom_productdata.getAttribute( 'data-gtm4wp_product_cat' ),
			'stocklevel': dom_productdata.getAttribute( 'data-gtm4wp_product_stocklevel' ),
			'brand':      dom_productdata.getAttribute( 'data-gtm4wp_product_brand' ),
			'position':   dom_productdata.getAttribute( 'data-gtm4wp_product_listposition' )
		};

		for (let i in window.google_tag_manager) {
			if (i.substring(0,4).toLowerCase() == "gtm-") {
				window.gtm4wp_first_container_id = i;
				break;
			}
		}

		// do not do anything if GTM was not loaded for any reason
		if ( "" === window.gtm4wp_first_container_id ) {
			return true;
		}

		const ctrl_key_pressed = e.ctrlKey || e.metaKey;

		e.preventDefault();
		if ( ctrl_key_pressed ) {
			// we need to open the new tab/page here so that popup blocker of the browser doesn't block our code
			window.productpage_window = window.open( 'about:blank', '_blank' );
		}

		// fire ga3 version
		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.productClickEEC',
			'ecommerce': {
				'currencyCode': gtm4wp_currency,
				'click': {
					'actionField': {'list': dom_productdata.getAttribute( 'data-gtm4wp_productlist_name' )},
					'products': [ product_data ]
				}
			},
			'eventCallback': function( container_id ) {
				if ( "undefined" !== typeof container_id && window.gtm4wp_first_container_id != container_id) {
					// only call this for the first loaded container
					return true;
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
							productpage_window.location.href = dom_productdata.getAttribute( 'data-gtm4wp_product_url' );
						} else {
							document.location.href = dom_productdata.getAttribute( 'data-gtm4wp_product_url' );
						}

					},
					'eventTimeout': 2000
				});
			},
			'eventTimeout': 2000
		});
	});

	// track variable products on their detail pages
	// currently, we need to use jQuery here since WooCommerce is firing this event using jQuery
	// that can not be catched using vanilla JS
	jQuery( document ).on( 'found_variation', function( event, product_variation ) {
		if ( "undefined" == typeof product_variation ) {
			// some ither plugins trigger this event without variation data
			return;
		}

		if ( (document.readyState === "interactive") && gtm4wp_changedetail_fired_during_pageload ) {
			// some custom attribute rendering plugins fire this event multiple times during page load
			return;
		}

		const product_form       = event.target;
		const product_variant_id = product_form.querySelector( '[name=variation_id]' ) && product_form.querySelector( '[name=variation_id]' ).value;
		const product_id         = product_form.querySelector( '[name=gtm4wp_id]' ) && product_form.querySelector( '[name=gtm4wp_id]' ).value;
		const product_name       = product_form.querySelector( '[name=gtm4wp_name]' ) && product_form.querySelector( '[name=gtm4wp_name]' ).value;
		const product_sku        = product_form.querySelector( '[name=gtm4wp_sku]' ) && product_form.querySelector( '[name=gtm4wp_sku]' ).value;
		const product_category   = product_form.querySelector( '[name=gtm4wp_category]' ) && product_form.querySelector( '[name=gtm4wp_category]' ).value;
		const product_price      = product_form.querySelector( '[name=gtm4wp_price]' ) && product_form.querySelector( '[name=gtm4wp_price]' ).value;
		const product_stocklevel = product_form.querySelector( '[name=gtm4wp_stocklevel]' ) && product_form.querySelector( '[name=gtm4wp_stocklevel]' ).value;
		const product_brand      = product_form.querySelector( '[name=gtm4wp_brand]' ) && product_form.querySelector( '[name=gtm4wp_brand]' ).value;

		let current_product_detail_data = {
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

		let product_variation_attribute_values = [];
		for( let attrib_key in product_variation.attributes ) {
			product_variation_attribute_values.push( product_variation.attributes[ attrib_key ] );
		}
		current_product_detail_data.variant = product_variation_attribute_values.join(',');
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
				'value': current_product_detail_data.price,
				'items': [ gtm4wp_map_eec_to_ga4( current_product_detail_data ) ]
			}
		});

		if ( document.readyState === "interactive" ) {
			gtm4wp_changedetail_fired_during_pageload = true;
		}
	});
	jQuery( '.variations select' ).trigger( 'change' );

	// initiate codes in WooCommere Quick View
	// currently, we need to use jQuery here since WooCommerce Quick View is showing the popup using
	// jQuery AJAX calls that can not be catched using vanilla JS
	jQuery( document ).ajaxSuccess( function( event, xhr, settings ) {
		if(typeof settings !== 'undefined') {
			if (settings.url.indexOf( 'wc-api=WC_Quick_View' ) > -1 ) {
				setTimeout( function() {

					const dl_data = document.querySelector('#gtm4wp_quickview_data');
					if ( dl_data && dl_data.dataset && dl_data.dataset.gtm4wp_datalayer ) {
						try {
							const dl_data_obj = JSON.parse( dl_data.dataset.gtm4wp_datalayer );
							if ( dl_data_obj && window.dataLayer ) {
								window.dataLayer.push(dl_data_obj);
							}
						} catch(e) {
							console && console.error && console.error( e.message );
						}
					}

				}, 500);
			}
		}
	});

	// codes for enhanced ecommerce events on cart page
	if ( gtm4wp_is_cart ) {
		document.addEventListener( 'click', function( e ) {
			let event_target_element = e.target;

			if ( !event_target_element ) {
				// for some reason event target is not specificed
				return true;
			}

			if ( !event_target_element.closest( '[name=update_cart]' ) ) {
				return true;
			}

			gtm4wp_handle_cart_qty_change();
		});

		document.addEventListener( 'keypress', function( e ) {
			let event_target_element = e.target;

			if ( !event_target_element ) {
				// for some reason event target is not specificed
				return true;
			}

			if ( !event_target_element.closest( '.woocommerce-cart-form input[type=number]' ) ) {
				return true;
			}

			gtm4wp_handle_cart_qty_change();
		});
	}

	// codes for enhanced ecommerce events on checkout page
	if ( gtm4wp_is_checkout ) {
		window.gtm4wp_checkout_step_offset  = window.gtm4wp_checkout_step_offset || 0;
		window.gtm4wp_checkout_value        = window.gtm4wp_checkout_value || 0;
		window.gtm4wp_checkout_products     = window.gtm4wp_checkout_products || [];
		window.gtm4wp_checkout_products_ga4 = window.gtm4wp_checkout_products_ga4 || [];

		// this checkout step is not reported to GA4 as currently there is no option to report in-between custom steps
		document.addEventListener( 'focusout', function( e ) {
			let event_target_element = e.target;

			if ( !event_target_element ) {
				// for some reason event target is not specificed
				return true;
			}

			if ( !event_target_element.closest || !event_target_element.closest( 'input[name^=shipping_]:not(input[name^=shipping_method])' ) ) {
				return true;
			}

			// do not report checkout step if already reported
			if ( gtm4wp_checkout_step_fired.indexOf( 'shipping' ) > -1 ) {
				return;
			}

			// do not report checkout step if user is traversing through the section without filling in any data
			if ( event_target_element.value.trim() == '' ) {
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

		document.addEventListener( 'change', function( e ) {
			let event_target_element = e.target;

			if ( !event_target_element ) {
				// for some reason event target is not specificed
				return true;
			}

			if ( !event_target_element.closest( 'input[name^=shipping_method]' ) ) {
				return true;
			}

			gtm4wp_handle_shipping_method_change();
		});

		document.addEventListener( 'change', function( e ) {
			let event_target_element = e.target;

			if ( !event_target_element ) {
				// for some reason event target is not specificed
				return true;
			}

			if ( !event_target_element.closest( 'input[name=payment_method]' ) ) {
				return true;
			}

			gtm4wp_handle_payment_method_change();
		});

		document.addEventListener( 'submit', function( e ) {
			let event_target_element = e.target;

			if ( !event_target_element ) {
				// for some reason event target is not specificed
				return true;
			}

			if ( !event_target_element.closest( 'form[name=checkout]' ) ) {
				return true;
			}

			if ( gtm4wp_checkout_step_fired.indexOf( 'shipping_method' ) == -1 ) {
				// shipping methods are not visible if only one is available
				// and if the user has already a pre-selected method, no click event will fire to report the checkout step
				gtm4wp_handle_shipping_method_change();
			}

			if ( gtm4wp_checkout_step_fired.indexOf( 'payment_method' ) == -1 ) {
				// if the user has already a pre-selected method, no click event will fire to report the checkout step
				gtm4wp_handle_payment_method_change();
			}

			let shipping_el = document.querySelector( 'input[name^=shipping_method]:checked' );
			if ( !shipping_el ) {
				shipping_el = document.querySelector( 'input[name^=shipping_method]' ); // select the first input element
			}
			if ( shipping_el ) {
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.checkoutOptionEEC',
					'ecommerce': {
						'checkout_option': {
							'actionField': {
								'step': 3 + window.gtm4wp_checkout_step_offset + gtm4wp_shipping_payment_method_step_offset,
								'option': 'Shipping: ' + shipping_el.value
							}
						}
					}
				});
			}

			let payment_el = document.querySelector( '.payment_methods input:checked' );
			if ( !payment_el ) {
				payment_el = document.querySelector( 'input[name^=payment_method]' ); // select the first input element
			}
			if ( payment_el ) {
				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.checkoutOptionEEC',
					'ecommerce': {
						'checkout_option': {
							'actionField': {
								'step': 4 + window.gtm4wp_checkout_step_offset + gtm4wp_shipping_payment_method_step_offset,
								'option': 'Payment: ' + payment_el.value
							}
						}
					}
				});
			}
		});
	}

	// codes for Google Ads dynamic remarketing
	// this part of the code is deprecated and will be removed in a later version
	// therefore jQuery usage will be not rewritten
	// turn of the deprecated Google Ads remarketing feature and this code will not execute
	if ( window.gtm4wp_remarketing&& !gtm4wp_is_cart && !gtm4wp_is_checkout ) {
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
						'value': item.ecommerce.detail.products[0].price,
						'items': [ gtm4wp_map_eec_to_ga4( item.ecommerce.detail.products[0] ) ]
					}
				});
			}

			if ( item && item.ecommerce && ( item.ecommerce.cart || (item.ecommerce.checkout && gtm4wp_is_cart) ) ) {
				let source_products = item.ecommerce.cart || item.ecommerce.checkout.products;
				let ga4_products = [];
				let sum_value = 0;

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

			if ( item && item.ecommerce && item.ecommerce.checkout && !gtm4wp_is_cart ) {
				let ga4_products = [];
				let sum_value = 0;

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
				let ga4_products = [];
				let sum_value = 0;

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
				let ga4_products = [];
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
};

function gtm4wp_page_loading_completed() {
	document.removeEventListener( "DOMContentLoaded", gtm4wp_page_loading_completed );
	window.removeEventListener( "load", gtm4wp_page_loading_completed );
	gtm4wp_process_woocommerce_pages();
}

// code and idea borrowed from jQuery:
// https://github.com/jquery/jquery/blob/main/src/core/ready.js
if ( document.readyState !== "loading" ) {
	window.setTimeout( gtm4wp_process_woocommerce_pages );
} else {
	document.addEventListener( "DOMContentLoaded", gtm4wp_page_loading_completed );
	window.addEventListener( "load", gtm4wp_page_loading_completed );
}
