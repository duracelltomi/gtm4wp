let gtm4wp_last_selected_product_variation;
window.gtm4wp_view_item_fired_during_pageload = false;

window.gtm4wp_checkout_step_fired = []; // step 1 will be the billing section which is reported during pageload, no need to handle here

window.gtm4wp_first_container_id = "";
const gtm4wp_blocks_integration_enabled = ( typeof gtm4wp_blocks_add_to_cart !== 'undefined' && gtm4wp_blocks_add_to_cart );
const gtm4wp_rest_root_url = ( () => {
	if ( typeof gtm4wp_rest_root !== 'undefined' && gtm4wp_rest_root ) {
		return gtm4wp_rest_root;
	}

	if ( window.wpApiSettings && window.wpApiSettings.root ) {
		return window.wpApiSettings.root;
	}

	return window.location.origin.replace( /\/$/, '' ) + '/wp-json/';
} )();
const gtm4wp_rest_nonce_value = ( typeof gtm4wp_rest_nonce !== 'undefined' && gtm4wp_rest_nonce ) ?
	gtm4wp_rest_nonce :
	( window.wpApiSettings && window.wpApiSettings.nonce ? window.wpApiSettings.nonce : null );
const gtm4wp_blocks_ajax_endpoint = ( typeof gtm4wp_blocks_ajax_url !== 'undefined' && gtm4wp_blocks_ajax_url ) ?
	gtm4wp_blocks_ajax_url :
	( typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php' );
const gtm4wp_blocks_product_nonce_value = ( typeof gtm4wp_blocks_product_nonce !== 'undefined' && gtm4wp_blocks_product_nonce ) ?
	gtm4wp_blocks_product_nonce :
	null;
const gtm4wp_blocks_dedupe_window_value = ( () => {
	const configured = ( typeof gtm4wp_blocks_dedupe_window !== 'undefined' ) ? parseInt( gtm4wp_blocks_dedupe_window, 10 ) : NaN;
	return Number.isFinite( configured ) && configured > 0 ? configured : 800;
} )();
let gtm4wp_blocks_environment_warned = false;
const gtm4wp_blocks_cart_storage_key = 'gtm4wp_wc_blocks_cart_state';
const gtm4wp_blocks_cart_storage_version = 1;
let gtm4wp_session_storage_supported = null;

function gtm4wp_build_rest_url( path ) {
	const sanitizedRoot = gtm4wp_rest_root_url.replace( /\/+$/, '' );
	const sanitizedPath = String( path || '' ).replace( /^\/+/, '' );
	return sanitizedRoot + '/' + sanitizedPath;
}

function gtm4wp_blocks_warn_once( message ) {
	if ( gtm4wp_blocks_environment_warned ) {
		return;
	}

	gtm4wp_blocks_environment_warned = true;

	if ( window.console && window.console.warn ) {
		window.console.warn( '[GTM4WP][WC Blocks]', message );
	}
}

function gtm4wp_is_blocks_store_available() {
	return !! ( window.wp && window.wp.data && window.wp.data.select && window.wp.data.subscribe );
}

function gtm4wp_delay( duration ) {
	return new Promise( ( resolve ) => {
		window.setTimeout( resolve, duration );
	} );
}

function gtm4wp_is_session_storage_available() {
	if ( null !== gtm4wp_session_storage_supported ) {
		return gtm4wp_session_storage_supported;
	}

	try {
		const test_key = '__gtm4wp_ss__';
		window.sessionStorage.setItem( test_key, '1' );
		window.sessionStorage.removeItem( test_key );
		gtm4wp_session_storage_supported = true;
	} catch ( e ) {
		gtm4wp_session_storage_supported = false;
	}

	return gtm4wp_session_storage_supported;
}

function gtm4wp_normalize_key_value_pairs( source ) {
	if ( ! source ) {
		return '';
	}

	const entries = [];

	if ( Array.isArray( source ) ) {
		source.forEach( ( item ) => {
			if ( item && 'object' === typeof item ) {
				const name = item.name || item.attribute || item.key || '';
				const value = ( 'value' in item ) ? item.value : ( item.value_html || item.label || '' );

				if ( name || value ) {
					entries.push( name + ':' + value );
				}
			}
		} );
	} else if ( 'object' === typeof source ) {
		Object.keys( source )
			.sort()
			.forEach( ( key ) => {
				entries.push( key + ':' + source[ key ] );
			} );
	}

	return entries.join( '|' );
}

function gtm4wp_classic_add_to_cart_click_handler( e ) {
	let event_target_element = e.target;

	if ( !event_target_element ) {
		return true;
	}

	// track add to cart events for simple products in product lists.
	if ( event_target_element.closest( '.add_to_cart_button:not(.product_type_variable, .product_type_grouped, .single_add_to_cart_button)' ) ) {
		const product_el = event_target_element.closest( '.product,.wc-block-grid__product' );

		const productdata_el = product_el && product_el.querySelector( '.gtm4wp_productdata' );
		if ( !productdata_el ) {
			return true;
		}

		const productdata  = gtm4wp_read_json_from_node( productdata_el, "gtm4wp_product_data" );
		if ( !productdata ) {
			return true;
		}

		if ( "variable" === productdata.product_type || "grouped" === productdata.product_type ) {
			return true;
		}

		if ( productdata.productlink ) {
			delete productdata.productlink;
		}
		delete productdata.product_type;
		productdata.quantity = 1;

		gtm4wp_push_ecommerce( 'add_to_cart', [ productdata ], {
			'currency': gtm4wp_currency,
			'value': productdata.price
		});

		return true;
	}

	// track add to cart events for products on product detail pages.
	const add_to_cart_button = event_target_element.closest( '.single_add_to_cart_button' );
	if ( !add_to_cart_button ) {
		return true;
	}

	if ( add_to_cart_button.classList.contains( 'disabled' ) || add_to_cart_button.disabled ) {
		// do not track clicks on disabled buttons.
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

			gtm4wp_push_ecommerce( 'add_to_cart', [ gtm4wp_last_selected_product_variation ], {
				'currency': gtm4wp_currency,
				'value': (gtm4wp_last_selected_product_variation.price * gtm4wp_last_selected_product_variation.quantity).toFixed(2)
			});
		}

		return true;
	}

	if ( product_is_grouped ) {
		const products_in_group = document.querySelectorAll( '.grouped_form .gtm4wp_productdata' );
		let products = [];
		let sum_value = 0;

		products_in_group.forEach( function( product_data_el ) {
			const productdata = gtm4wp_read_json_from_node(product_data_el, 'gtm4wp_product_data', ['productlink']);
			if ( !productdata ) {
				return true;
			}

			let product_qty = 0;
			const product_qty_input = document.querySelectorAll( 'input[name=quantity\\[' + productdata.internal_id + '\\]]' );
			if ( product_qty_input.length > 0 ) {
				product_qty = (product_qty_input[0] && product_qty_input[0].value) || 1;
			} else {
				return true;
			}

			if ( 0 == product_qty ) {
				return true;
			}
			productdata.quantity = product_qty;

			delete productdata.internal_id;

			products.push( productdata );
			sum_value += productdata.price * productdata.quantity;
		});

		if ( 0 == products.length ) {
			return true;
		}

		gtm4wp_push_ecommerce( 'add_to_cart', products, {
			'currency': gtm4wp_currency,
			'value': sum_value.toFixed(2)
		});

		return true;
	}

	const product_data_el = product_form.querySelector( '[name=gtm4wp_product_data]' );
	if ( !product_data_el ) {
		return true;
	}

	let productdata = gtm4wp_read_from_json( product_data_el.value );
	productdata.quantity = product_form.querySelector( '[name=quantity]' ) && product_form.querySelector( '[name=quantity]' ).value;
	if ( isNaN( productdata.quantity ) ) {
		productdata.quantity = 1;
	}

	gtm4wp_push_ecommerce( 'add_to_cart', [ productdata ], {
		'currency': gtm4wp_currency,
		'value': productdata.price * productdata.quantity
	});

	return true;
}

function gtm4wp_woocommerce_handle_cart_qty_change() {
	document.querySelectorAll( '.product-quantity input.qty' ).forEach(function( qty_el ) {
		const original_value = qty_el.defaultValue;

		let current_value  = parseInt( qty_el.value );
		if ( isNaN( current_value ) ) {
			current_value = original_value;
		}

		// is quantity changed changed?
		if ( original_value != current_value ) {
			const cart_item_temp = qty_el.closest( '.cart_item' );
			const productdata_el = cart_item_temp && cart_item_temp.querySelector( '.remove' );
			if ( !productdata_el ) {
				return;
			}

			const productdata = gtm4wp_read_json_from_node( productdata_el, "gtm4wp_product_data");
			if ( !productdata ) {
				return true;
			}

			// does the quantity increase?
			if ( original_value < current_value ) {
				// yes => handle add to cart event
				productdata.quantity = current_value - original_value;
				productdata.price    = productdata.price;

				gtm4wp_push_ecommerce( 'add_to_cart', [ productdata ], {
					'currency': gtm4wp_currency, // ga4 version
					'value': productdata.price * productdata.quantity
				});
			} else {
				// no => handle remove from cart event
				productdata.quantity = original_value - current_value;
				productdata.price    = productdata.price;

				gtm4wp_push_ecommerce( 'remove_from_cart', [ productdata ], {
					'currency': gtm4wp_currency,
					'value': productdata.price * productdata.quantity
				});
			}
		} // end if qty changed
	}); // end each qty field
} // end gtm4wp_woocommerce_handle_cart_qty_change()

function gtm4wp_woocommerce_handle_payment_method_change() {
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

	gtm4wp_push_ecommerce( 'add_payment_info', window.gtm4wp_checkout_products, {
		'currency': gtm4wp_currency,
		'payment_type': payment_type,
		'value': window.gtm4wp_checkout_value
	});

	gtm4wp_checkout_step_fired.push( 'payment_method' );
} // end gtm4wp_woocommerce_handle_payment_method_change()

function gtm4wp_woocommerce_handle_shipping_method_change() {
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

	gtm4wp_push_ecommerce( 'add_shipping_info', window.gtm4wp_checkout_products, {
		'currency': gtm4wp_currency,
		'shipping_tier': shipping_tier,
		'value': window.gtm4wp_checkout_value
	});

	gtm4wp_checkout_step_fired.push( 'shipping_method' );
}

function gtm4wp_woocommerce_process_pages() {
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
		const product_data_el = product_grid_item.querySelector( '.gtm4wp_productdata' );

		if ( product_grid_container && product_data_el ) {

			const product_grid_container_classes = product_grid_container.classList;

			if ( product_grid_container_classes ) {

				for(let i in gtm4wp_product_block_names) {
					if ( product_grid_container_classes.contains( i ) ) {
						gtm4wp_update_json_in_node( product_data_el, 'gtm4wp_product_data', 'item_list_name', gtm4wp_product_block_names[i].displayname );
						gtm4wp_update_json_in_node( product_data_el, 'gtm4wp_product_data', 'index', gtm4wp_product_block_names[i].counter );

						gtm4wp_product_block_names[i].counter++;
					}
				}
			}
		}

	});

	// track impressions of products in product lists
	if ( document.querySelectorAll( '.gtm4wp_productdata,.widget-product-item' ).length > 0 ) {
		let products = [];

		document.querySelectorAll( '.gtm4wp_productdata,.widget-product-item' ).forEach( function( productdata_el ) {
			const productdata = gtm4wp_read_json_from_node( productdata_el, "gtm4wp_product_data");
			if ( !productdata ) {
				return true;
			}

			products.push(productdata);
		});

		if ( gtm4wp_product_per_impression > 0 ) {
			// Need to split the product submissions up into chunks in order to avoid the GA 16kb hit size limit
			let chunk;

			while ( products.length ) {
				chunk = products.splice( 0, gtm4wp_product_per_impression );

				gtm4wp_push_ecommerce( 'view_item_list', chunk, {
					'currency': gtm4wp_currency
				});
			}
		} else {
			// push everything in one event and let's hope the best :-)
			gtm4wp_push_ecommerce( 'view_item_list', products, {
				'currency': gtm4wp_currency
			});
		}
	}

	// manage events related to user clicks
	document.addEventListener( 'click', function( e ) {
		let event_target_element = e.target;

		if ( !event_target_element ) {
			// for some reason event target is not specificed
			return true;
		}

		// track remove links in mini cart widget and on cart page
		if ( event_target_element.closest( '.mini_cart_item a.remove,.product-remove a.remove' ) ) {
			const click_el = event_target_element;

			const productdata_el = click_el && click_el.closest( '.mini_cart_item a.remove,.product-remove a.remove' );
			if ( !productdata_el ) {
				return true;
			}

			const productdata = gtm4wp_read_json_from_node( productdata_el, "gtm4wp_product_data");
			if ( !productdata ) {
				return true;
			}

			let qty = 0;
			const cart_item_el = productdata_el.closest( '.cart_item' );
			let qty_element = cart_item_el && cart_item_el.querySelectorAll( '.product-quantity input.qty' );
			if ( !qty_element || ( 0 === qty_element.length ) ) {
				const mini_cart_item_el = productdata_el.closest( '.mini_cart_item' );
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

			if ( 0 === qty ) {
				return true;
			}

			productdata.quantity = qty;

			gtm4wp_push_ecommerce( 'remove_from_cart', [ productdata ], {
				'currency': gtm4wp_currency,
				'value': productdata.price * productdata.quantity
			});
		}

		// track clicks in product lists
		if ( event_target_element.closest(
			'.products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
			+'.wc-block-grid__products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
			+'.products>div:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
			+'.widget-product-item,'
			+'.woocommerce-grouped-product-list-item__label a' )
		) {
			// do nothing if GTM is blocked for some reason
			if ( 'undefined' == typeof google_tag_manager ) {
				return true;
			}

			const event_target_element = e.target;
			const matching_link_element = event_target_element.closest(
				'.products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
				+'.wc-block-grid__products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
				+'.products>div:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),'
				+'.widget-product-item,'
				+'.woocommerce-grouped-product-list-item__label a'
			);

			if ( !matching_link_element ) {
				return true;
			}

			let temp_selector = event_target_element.closest( '.product,.wc-block-grid__product' );
			let productdata_el;

			if ( temp_selector ) {
				productdata_el = temp_selector.querySelector( '.gtm4wp_productdata' );

			} else {
				temp_selector = event_target_element.closest( '.products li' );

				if ( temp_selector ) {
					productdata_el = temp_selector.querySelector( '.gtm4wp_productdata' );

				} else {
					temp_selector = event_target_element.closest( '.products>div' );

					if ( temp_selector ) {
						productdata_el = temp_selector.querySelector( '.gtm4wp_productdata' );

					} else {
						temp_selector = event_target_element.closest( '.woocommerce-grouped-product-list-item__label' );

						if ( temp_selector ) {
							productdata_el = temp_selector.querySelector( '.gtm4wp_productdata' );
						} else {
							productdata_el = event_target_element;
						}
					}
				}
			}

			const productdata = gtm4wp_read_json_from_node( productdata_el, 'gtm4wp_product_data', ['internal_id'] );
			if ( !productdata ) {
				return true;
			}

			// only act on links pointing to the product detail page
			if ( productdata.productlink != matching_link_element.getAttribute( 'href' ) ) {
				return true;
			}

			// Look at first GTM container ID in case there are multiple GTM containers live on the page
			// since eventCallback is called on every container and we only need this executed once in this case.
			for (let i in window.google_tag_manager) {
				if (i.substring(0,4).toLowerCase() == "gtm-") {
					window.gtm4wp_first_container_id = i;
					break;
				}
			}

			// do not do anything if GTM was not loaded
			// and window.google_tag_manager is for some reason initialized (GA4 only setup?)
			if ( "" === window.gtm4wp_first_container_id ) {
				return true;
			}

			const ctrl_key_pressed = e.ctrlKey || e.metaKey;
			const target_new_tab = ( '_blank' === matching_link_element.target );

			// save this info to prevent redirection if another plugin already prevented to event for some reason
			let event_already_prevented = e.defaultPrevented;
			if ( !event_already_prevented ) {
				e.preventDefault();
			}

			if ( ctrl_key_pressed || target_new_tab ) {
				// we need to open the new tab/page here so that popup blocker of the browser doesn't block our code
				window.productpage_window = window.open( 'about:blank', '_blank' );
			}

			const productlink_to_redirect = productdata.productlink;
			delete productdata.productlink;

			let datalayer_timeout = 2000;
			if (window.gtm4wp_datalayer_max_timeout) {
				datalayer_timeout = window.gtm4wp_datalayer_max_timeout;
			}

			// fire ga4 version
			gtm4wp_push_ecommerce( 'select_item', [ productdata ], {
				'currency': gtm4wp_currency
			}, function( container_id ) {
				if ( "undefined" !== typeof container_id && window.gtm4wp_first_container_id != container_id) {
					// only call this for the first loaded container
					return true;
				}

				if ( !event_already_prevented ) {
					if ( ( target_new_tab || ctrl_key_pressed ) && productpage_window ) {
						productpage_window.location.href = productlink_to_redirect;
					} else {
						document.location.href = productlink_to_redirect;
					}
				}
			},
			datalayer_timeout);
		}
	}, { capture: true } );

	if ( !gtm4wp_blocks_integration_enabled ) {
		document.addEventListener( 'click', gtm4wp_classic_add_to_cart_click_handler, { capture: true } );
	}

	// track variable products on their detail pages
	// currently, we need to use jQuery here since WooCommerce is firing this event using jQuery
	// that can not be catched using vanilla JS
	jQuery( document ).on( 'found_variation', function( event, product_variation ) {
		if ( "undefined" == typeof product_variation ) {
			// some ither plugins trigger this event without variation data
			return;
		}

		if ( (document.readyState === "interactive") && gtm4wp_view_item_fired_during_pageload ) {
			// some custom attribute rendering plugins fire this event multiple times during page load
			return;
		}

		// event target is the <form> element of the add to cart button.
		const product_form    = event.target;
		if ( !product_form ) {
			return true;
		}

		const product_data_el = product_form.querySelector( '[name=gtm4wp_product_data]' );
		if ( !product_data_el ) {
			return true;
		}

		let current_product_detail_data;
		try {
			current_product_detail_data = JSON.parse( product_data_el.value );
		} catch(e) {
			console && console.error && console.error( e.message );
			return true;
		}

		current_product_detail_data.price = gtm4wp_make_sure_is_float( current_product_detail_data.price );

		current_product_detail_data.item_group_id = current_product_detail_data.id;
		current_product_detail_data.id = product_variation.variation_id;
		current_product_detail_data.item_id = product_variation.variation_id;
		current_product_detail_data.sku = product_variation.sku;
		if ( gtm4wp_use_sku_instead && product_variation.sku && ('' !== product_variation.sku) ) {
			current_product_detail_data.id = product_variation.sku;
			current_product_detail_data.item_id = product_variation.sku;
		}
		current_product_detail_data.price = gtm4wp_make_sure_is_float( product_variation.display_price );

		let product_variation_attribute_values = [];
		for( let attrib_key in product_variation.attributes ) {
			product_variation_attribute_values.push( product_variation.attributes[ attrib_key ] );
		}
		current_product_detail_data.item_variant = product_variation_attribute_values.join(',');
		gtm4wp_last_selected_product_variation = current_product_detail_data;

		delete current_product_detail_data.internal_id;

		// fire ga4 version
		gtm4wp_push_ecommerce( 'view_item', [ current_product_detail_data ], {
			'currency': gtm4wp_currency,
			'value': current_product_detail_data.price
		});

		if ( document.readyState === "interactive" ) {
			gtm4wp_view_item_fired_during_pageload = true;
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

	let gtm4wp_is_cart     = false;
	let gtm4wp_is_checkout = false;

	const doc_body = document.querySelector( 'body' );
	if ( doc_body ) {
		gtm4wp_is_cart     = doc_body.classList && doc_body.classList.contains( 'woocommerce-cart' );
		gtm4wp_is_checkout = doc_body.classList && doc_body.classList.contains( 'woocommerce-checkout' );
	}

	// codes for ecommerce events on cart page
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

			gtm4wp_woocommerce_handle_cart_qty_change();
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

			gtm4wp_woocommerce_handle_cart_qty_change();
		});
	}

	// codes for ecommerce events on checkout page
	if ( gtm4wp_is_checkout ) {
		window.gtm4wp_checkout_value        = window.gtm4wp_checkout_value || 0;
		window.gtm4wp_checkout_products     = window.gtm4wp_checkout_products || [];
		window.gtm4wp_checkout_products_ga4 = window.gtm4wp_checkout_products_ga4 || [];

		document.addEventListener( 'change', function( e ) {
			let event_target_element = e.target;

			if ( !event_target_element ) {
				// for some reason event target is not specificed
				return true;
			}

			if ( !event_target_element.closest( 'input[name^=shipping_method]' ) ) {
				return true;
			}

			gtm4wp_woocommerce_handle_shipping_method_change();
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

			gtm4wp_woocommerce_handle_payment_method_change();
		});

		// We need to use jQuery where since the checkout_place_order event is only triggered using jQuery
		const checkout_form = jQuery('form.checkout');
		checkout_form.on('checkout_place_order', function () {
			if ( gtm4wp_checkout_step_fired.indexOf( 'shipping_method' ) == -1 ) {
				// shipping methods are not visible if only one is available
				// and if the user has already a pre-selected method, no click event will fire to report the checkout step
				gtm4wp_woocommerce_handle_shipping_method_change();
			}

			if ( gtm4wp_checkout_step_fired.indexOf( 'payment_method' ) == -1 ) {
				// if the user has already a pre-selected method, no click event will fire to report the checkout step
				gtm4wp_woocommerce_handle_payment_method_change();
			}
		});
	}
}

function gtm4wp_woocommerce_page_loading_completed() {
	document.removeEventListener( "DOMContentLoaded", gtm4wp_woocommerce_page_loading_completed );
	window.removeEventListener( "load", gtm4wp_woocommerce_page_loading_completed );
	gtm4wp_woocommerce_process_pages();
}

// code and idea borrowed from jQuery:
// https://github.com/jquery/jquery/blob/main/src/core/ready.js
if ( document.readyState !== "loading" ) {
	window.setTimeout( gtm4wp_woocommerce_process_pages );
} else {
	document.addEventListener( "DOMContentLoaded", gtm4wp_woocommerce_page_loading_completed );
	window.addEventListener( "load", gtm4wp_woocommerce_page_loading_completed );
}

// WooCommerce Blocks add-to-cart tracking
// Only enable if both the option is enabled AND ecommerce tracking is enabled
if ( typeof gtm4wp_blocks_add_to_cart !== 'undefined' && gtm4wp_blocks_add_to_cart ) {
	if ( ! window.wc ) {
		gtm4wp_blocks_warn_once( 'WooCommerce Blocks scripts are not detected; block add_to_cart events might be unavailable.' );
	}
	// Store previous cart state to detect newly added/removed items
	let gtm4wp_previous_cart_items = {};
	const gtm4wp_previous_cart_snapshots = {};
	const gtm4wp_product_api_cache = {};
	let gtm4wp_last_cart_signature = null;
	let gtm4wp_snapshot_in_progress = false;
	let gtm4wp_dom_event_in_progress = false;
	const gtm4wp_recent_deltas = {};
	let gtm4wp_pending_cart_snapshot = null;
	let gtm4wp_blocks_initialization = null;
	let gtm4wp_blocks_initial_state_ready = false;
	const gtm4wp_cart_fetch_ttl = 300;
	let gtm4wp_cart_fetch_inflight = null;
	let gtm4wp_cart_fetch_cache = null;
	let gtm4wp_cart_fetch_cache_timestamp = 0;
	function gtm4wp_bootstrap_blocks_state_complete( force_ready ) {
		if ( force_ready || ! gtm4wp_blocks_initial_state_ready ) {
			gtm4wp_blocks_initial_state_ready = true;
		}
	}

	function gtm4wp_restore_cart_state_from_storage() {
		if ( ! gtm4wp_is_session_storage_available() ) {
			return false;
		}

		try {
			const stored_value = window.sessionStorage.getItem( gtm4wp_blocks_cart_storage_key );

			if ( ! stored_value ) {
				return false;
			}

			const parsed = JSON.parse( stored_value );

			if ( ! parsed || parsed.version !== gtm4wp_blocks_cart_storage_version || ! Array.isArray( parsed.items ) ) {
				return false;
			}

			gtm4wp_previous_cart_items = {};
			Object.keys( gtm4wp_previous_cart_snapshots ).forEach( ( snapshot_key ) => {
				delete gtm4wp_previous_cart_snapshots[ snapshot_key ];
			} );

			parsed.items.forEach( ( entry ) => {
				if ( ! entry || ! entry.key ) {
					return;
				}

				const item_key = entry.key;
				const quantity = parseInt( entry.quantity || ( entry.product && entry.product.quantity ) || 0 );

				if ( isNaN( quantity ) || quantity < 0 ) {
					return;
				}

				gtm4wp_previous_cart_items[ item_key ] = quantity;

				if ( entry.product ) {
					const snapshot = Object.assign( {}, entry.product );
					snapshot.quantity = quantity;
					gtm4wp_previous_cart_snapshots[ item_key ] = snapshot;
				}
			} );

			gtm4wp_last_cart_signature = parsed.signature || null;

			gtm4wp_bootstrap_blocks_state_complete( true );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	function gtm4wp_persist_cart_state_to_storage() {
		if ( ! gtm4wp_is_session_storage_available() ) {
			return;
		}

		try {
			const payload = {
				version: gtm4wp_blocks_cart_storage_version,
				signature: gtm4wp_last_cart_signature,
				items: Object.keys( gtm4wp_previous_cart_items ).map( ( item_key ) => {
					const quantity = gtm4wp_previous_cart_items[ item_key ];
					const snapshot = gtm4wp_previous_cart_snapshots[ item_key ] || null;

					return {
						key: item_key,
						quantity: quantity,
						product: snapshot,
					};
				} ),
				timestamp: Date.now(),
			};

			if ( payload.items.length > 0 || payload.signature ) {
				window.sessionStorage.setItem( gtm4wp_blocks_cart_storage_key, JSON.stringify( payload ) );
			} else {
				window.sessionStorage.removeItem( gtm4wp_blocks_cart_storage_key );
			}
		} catch ( e ) {
			// Suppress storage errors (private mode, quota, etc.)
		}
	}

	async function gtm4wp_process_pending_snapshot_if_any() {
		if ( ! gtm4wp_pending_cart_snapshot ) {
			return;
		}

		const pending_snapshot = gtm4wp_pending_cart_snapshot;
		gtm4wp_pending_cart_snapshot = null;
		await gtm4wp_process_cart_snapshot( pending_snapshot );
	}

async function gtm4wp_mark_initial_state_ready() {
		gtm4wp_bootstrap_blocks_state_complete( true );
		await gtm4wp_process_pending_snapshot_if_any();
	}

	// Function to fetch cart data from WooCommerce Store API
	function gtm4wp_fetch_cart_items() {
		const now = Date.now();

		if ( gtm4wp_cart_fetch_cache && ( now - gtm4wp_cart_fetch_cache_timestamp ) < gtm4wp_cart_fetch_ttl ) {
			return Promise.resolve( gtm4wp_cart_fetch_cache );
		}

		if ( gtm4wp_cart_fetch_inflight ) {
			return gtm4wp_cart_fetch_inflight;
		}

		// Try to get cart data from the Store API
		const apiUrl = gtm4wp_build_rest_url( 'wc/store/v1/cart' );
		const headers = {};

		if ( gtm4wp_rest_nonce_value ) {
			headers['X-WP-Nonce'] = gtm4wp_rest_nonce_value;
		}

		gtm4wp_cart_fetch_inflight = fetch( apiUrl, {
			method: 'GET',
			headers,
			credentials: 'same-origin'
		} )
			.then( response => {
				if ( !response.ok ) {
					// If Store API fails, try to get from dataLayer if available
					if ( window.wc && window.wc.store && window.wc.store.cart ) {
						return window.wc.store.cart.getCartData();
					}
					throw new Error( 'Failed to fetch cart data' );
				}
				return response.json();
			} )
			.catch( error => {
				console && console.error && console.error( 'GTM4WP: Error fetching cart:', error );
				// Fallback: try to get from WooCommerce store if available
				if ( window.wc && window.wc.store && window.wc.store.cart ) {
					return window.wc.store.cart.getCartData();
				}
				return null;
			} )
			.then( ( data ) => {
				gtm4wp_cart_fetch_cache = data;
				gtm4wp_cart_fetch_cache_timestamp = Date.now();
				return data;
			} )
			.finally( () => {
				gtm4wp_cart_fetch_inflight = null;
			} );

		return gtm4wp_cart_fetch_inflight;
	}

	function gtm4wp_get_cart_item_key( cart_item ) {
		const primary_product_id =
			cart_item.product_id ||
			( cart_item.product && cart_item.product.id ) ||
			( cart_item.product && cart_item.product.product_id ) ||
			cart_item.id ||
			'';

		const variation_id =
			cart_item.variation_id ||
			( cart_item.variation && cart_item.variation.id ) ||
			( cart_item.variation && cart_item.variation.variation_id ) ||
			'';

		const attribute_source =
			( cart_item.variation && cart_item.variation.attributes ) ||
			cart_item.attributes ||
			( cart_item.variation && cart_item.variation.attributes_data ) ||
			null;

		const customization_source = cart_item.item_data || cart_item.custom_data || null;

		const attributes_signature = gtm4wp_normalize_key_value_pairs( attribute_source );
		const customization_signature = gtm4wp_normalize_key_value_pairs( customization_source );

		let signature = primary_product_id + ':' + variation_id + ':' + attributes_signature + ':' + customization_signature;

		if ( 'function' === typeof window.gtm4wp_cart_item_signature_override ) {
			try {
				const override = window.gtm4wp_cart_item_signature_override( signature, cart_item );
				if ( override ) {
					signature = override;
				}
			} catch ( e ) {
				// Ignore override errors
			}
		}

		return signature;
	}

	function gtm4wp_should_register_delta( item_key, delta_qty, delta_type ) {
		const absolute_delta = Math.abs( delta_qty );

		if ( absolute_delta <= 0 ) {
			return false;
		}

		const type       = delta_type || ( delta_qty > 0 ? 'add' : 'remove' );
		const dedupe_key = type + ':' + item_key + ':' + absolute_delta;
		const now        = Date.now();
		const last_ts    = gtm4wp_recent_deltas[ dedupe_key ] || 0;

		if ( now - last_ts < gtm4wp_blocks_dedupe_window_value ) {
			return false;
		}

		gtm4wp_recent_deltas[ dedupe_key ] = now;
		return true;
	}

	function gtm4wp_store_cart_item_snapshot( item_key, product_data, quantity ) {
		if ( ! item_key || ! product_data ) {
			return;
		}

		const snapshot = Object.assign( {}, product_data );
		snapshot.quantity = quantity;
		gtm4wp_previous_cart_snapshots[ item_key ] = snapshot;
	}

	function gtm4wp_bootstrap_blocks_state() {
		if ( ! gtm4wp_blocks_initialization ) {
			gtm4wp_restore_cart_state_from_storage();

			gtm4wp_blocks_initialization = gtm4wp_init_blocks_cart_tracking()
				.catch( () => {} )
				.finally( () => {
					gtm4wp_mark_initial_state_ready();
				} );
		}

		return gtm4wp_blocks_initialization;
	}

	async function gtm4wp_ensure_blocks_initialized() {
		if ( ! gtm4wp_blocks_initialization ) {
			return;
		}

		try {
			await gtm4wp_blocks_initialization;
		} catch ( e ) {
			// Ignore initialization errors here; fallbacks will handle missing data.
		}
	}

	async function gtm4wp_fetch_product_details_via_ajax( product_id ) {
		if ( ! gtm4wp_blocks_ajax_endpoint || ! gtm4wp_blocks_product_nonce_value ) {
			return null;
		}

		const formData = new FormData();
		formData.append( 'action', 'gtm4wp_product_data' );
		formData.append( 'product_id', product_id );
		formData.append( 'nonce', gtm4wp_blocks_product_nonce_value );

		try {
			const response = await fetch( gtm4wp_blocks_ajax_endpoint, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} );

			if ( ! response.ok ) {
				return null;
			}

			const data = await response.json();
			const product_payload = ( data && data.success && data.data && data.data.product ) ? data.data.product : null;

			if ( product_payload ) {
				gtm4wp_product_api_cache[ product_id ] = product_payload;
			}

			return product_payload;
		} catch ( ajaxError ) {
			return null;
		}
	}

	async function gtm4wp_fetch_product_details_from_api( product_id ) {
		if ( ! product_id ) {
			return null;
		}

		if ( gtm4wp_product_api_cache[ product_id ] ) {
			return gtm4wp_product_api_cache[ product_id ];
		}

		try {
			const response = await fetch( gtm4wp_build_rest_url( `gtm4wp/v1/product/${product_id}` ), {
				method: 'GET',
				credentials: 'same-origin',
				headers: gtm4wp_rest_nonce_value ? { 'X-WP-Nonce': gtm4wp_rest_nonce_value } : undefined,
			} );

			if ( response.ok ) {
				const data = await response.json();

				if ( data && data.product ) {
					gtm4wp_product_api_cache[ product_id ] = data.product;
					return data.product;
				}
			} else if ( response.status !== 404 ) {
				return await gtm4wp_fetch_product_details_via_ajax( product_id );
			}
		} catch ( error ) {
			console && console.error && console.error( 'GTM4WP: Error fetching product detail', error );
			return await gtm4wp_fetch_product_details_via_ajax( product_id );
		}

		return null;
	}

	async function gtm4wp_resolve_parent_identifier( parent_raw_id ) {
		if ( ! parent_raw_id ) {
			return null;
		}

		if ( gtm4wp_use_sku_instead ) {
			const parent_details = await gtm4wp_fetch_product_details_from_api( parent_raw_id );

			if ( parent_details && parent_details.item_id ) {
				return parent_details.item_id;
			}
		}

		return parent_raw_id;
	}

	// Function to convert WooCommerce cart item to GA4 product format
	async function gtm4wp_convert_cart_item_to_product( cart_item ) {
		// Handle different cart item structures from Store API
		const product = cart_item.variation || cart_item.product || cart_item;
		
		// Get product ID - use variation ID if available, otherwise product ID
		let product_id = product.id || cart_item.id;
		let item_id = product_id;
		
		// Check if this is a variation
		if ( cart_item.variation && cart_item.variation.id ) {
			product_id = cart_item.variation.id;
			item_id = cart_item.variation.id;
		} else if ( cart_item.variation_id ) {
			product_id = cart_item.variation_id;
			item_id = cart_item.variation_id;
		}
		
		// Use SKU if configured
		const sku = product.sku || cart_item.sku || '';
		if ( gtm4wp_use_sku_instead && sku && ( '' !== sku ) ) {
			item_id = sku;
		}

		// Get price - handle different price formats (cents vs dollars)
		let price = 0;
		if ( product.prices && product.prices.price ) {
			price = parseFloat( product.prices.price ) / 100; // Convert from cents
		} else if ( product.price ) {
			price = parseFloat( product.price );
		} else if ( cart_item.prices && cart_item.prices.price ) {
			price = parseFloat( cart_item.prices.price ) / 100;
		} else if ( cart_item.price ) {
			price = parseFloat( cart_item.price );
		}

		// Build product data object matching the format used by gtm4wp_woocommerce_process_product
		const product_type = product.type || cart_item.type || '';
		const internal_id = product.id || cart_item.id || product_id;

		const product_data = {
			item_id: item_id,
			item_name: product.name || cart_item.name || '',
			price: gtm4wp_make_sure_is_float( price ),
			quantity: cart_item.quantity || 1,
			item_type: product_type || 'simple',
		};

		// Add SKU (fallback to product_id if no SKU)
		product_data.sku = sku || product_id;

		// Add item_group_id for variations (parent product ID)
		if ( ( cart_item.variation && cart_item.variation.id ) || cart_item.variation_id ) {
			const parent_raw_id = cart_item.id || product.id || product_id;
			const resolved_parent_id = await gtm4wp_resolve_parent_identifier( parent_raw_id );

			if ( resolved_parent_id && resolved_parent_id !== item_id ) {
				product_data.item_group_id = resolved_parent_id;
			}
		}

		// Add grouped parent ID if available.
		if ( ! product_data.item_group_id ) {
			const grouped_parent_raw_id =
				( cart_item.group_id )
				|| ( cart_item.parent && cart_item.parent.id )
				|| cart_item.parent_id
				|| product.parent_id
				|| ( product.parent && product.parent.id )
				|| ( cart_item.extensions && cart_item.extensions.grouped && cart_item.extensions.grouped.parent_id );

			const resolved_group_parent_id = await gtm4wp_resolve_parent_identifier( grouped_parent_raw_id );

			if ( resolved_group_parent_id && resolved_group_parent_id !== item_id ) {
				product_data.item_group_id = resolved_group_parent_id;
			}
		}

		const api_product_details = await gtm4wp_fetch_product_details_from_api( internal_id );
		if ( api_product_details ) {
			const mergeable_fields = [
				'item_category',
				'item_category2',
				'item_category3',
				'item_category4',
				'item_category5',
				'item_brand',
				'google_business_vertical',
				'stockstatus',
				'stocklevel',
				'item_group_id',
				'item_type',
				'item_variant',
				'item_group_sku',
			];

			mergeable_fields.forEach( ( field ) => {
				if ( ! product_data[field] && api_product_details[field] ) {
					product_data[field] = api_product_details[field];
				}
			} );
		}

		// Add categories if available
		const categories = product.categories || cart_item.categories || [];
		if ( categories.length > 0 ) {
			product_data.item_category = categories[0].name || categories[0] || '';
			if ( categories.length > 1 ) {
				product_data.item_category2 = categories[1].name || categories[1] || '';
			}
			if ( categories.length > 2 ) {
				product_data.item_category3 = categories[2].name || categories[2] || '';
			}
			if ( categories.length > 3 ) {
				product_data.item_category4 = categories[3].name || categories[3] || '';
			}
			if ( categories.length > 4 ) {
				product_data.item_category5 = categories[4].name || categories[4] || '';
			}
		}

		// Add brand if available
		if ( product.brand || cart_item.brand ) {
			product_data.item_brand = product.brand || cart_item.brand;
		}

		// Add variant attributes if available
		if ( cart_item.variation && cart_item.variation.attributes ) {
			const variant_attrs = [];
			for ( const key in cart_item.variation.attributes ) {
				if ( cart_item.variation.attributes.hasOwnProperty( key ) ) {
					variant_attrs.push( cart_item.variation.attributes[key] );
				}
			}
			if ( variant_attrs.length > 0 ) {
				product_data.item_variant = variant_attrs.join( ',' );
			}
		} else if ( cart_item.variation && cart_item.variation.attributes ) {
			// Alternative structure
			const variant_attrs = Object.values( cart_item.variation.attributes );
			if ( variant_attrs.length > 0 ) {
				product_data.item_variant = variant_attrs.join( ',' );
			}
		}

		return product_data;
	}

	async function gtm4wp_process_cart_snapshot( cart_data ) {
		if ( ! cart_data ) {
			return false;
		}

		if ( ! gtm4wp_blocks_initial_state_ready ) {
			gtm4wp_pending_cart_snapshot = cart_data;
		gtm4wp_bootstrap_blocks_state();
			return false;
		}

		const cart_items = cart_data.items || cart_data.cartItems || [];
		const cart_items_map = {};
		const cart_signature_components = [];

		cart_items.forEach( ( cart_item ) => {
			const item_key = gtm4wp_get_cart_item_key( cart_item );

			if ( ! item_key ) {
				return;
			}

			let quantity_raw = cart_item.quantity;
			if ( 'object' === typeof quantity_raw && null !== quantity_raw ) {
				quantity_raw = quantity_raw.value || quantity_raw.count || quantity_raw.qty || 0;
			}

			const quantity = parseInt( quantity_raw || cart_item.quantity_total || cart_item.qty || 0 );

			cart_items_map[ item_key ] = {
				cart_item,
				quantity,
			};

			cart_signature_components.push( item_key + ':' + quantity );
		} );

		const cart_signature = cart_signature_components.sort().join( '|' );

		if ( cart_signature === gtm4wp_last_cart_signature ) {
			return false;
		}

		const new_items = [];
		const removed_items = [];
		let total_value = 0;
		let removed_value = 0;
		const processed_keys = {};

		const processPromises = Object.keys( cart_items_map ).map( async ( item_key ) => {
			const entry = cart_items_map[ item_key ];
			const current_qty = entry.quantity;
			const previous_qty = gtm4wp_previous_cart_items[ item_key ] || 0;

			if ( current_qty === previous_qty ) {
				processed_keys[ item_key ] = true;
				return;
			}

			const product_data = await gtm4wp_convert_cart_item_to_product( entry.cart_item );

			if ( product_data ) {
				gtm4wp_store_cart_item_snapshot( item_key, product_data, current_qty );

				if ( current_qty > previous_qty ) {
					const delta = current_qty - previous_qty;
					const addition_payload = Object.assign( {}, product_data, { quantity: delta } );

					if ( gtm4wp_should_register_delta( item_key, delta, 'add' ) ) {
						new_items.push( addition_payload );
						total_value += parseFloat( addition_payload.price || 0 ) * addition_payload.quantity;
					}
				} else if ( previous_qty > current_qty ) {
					const delta = previous_qty - current_qty;
					const removal_payload = Object.assign( {}, product_data, { quantity: delta } );

					if ( gtm4wp_should_register_delta( item_key, delta, 'remove' ) ) {
						removed_items.push( removal_payload );
						removed_value += parseFloat( removal_payload.price || 0 ) * removal_payload.quantity;
					}
				}
			}

			gtm4wp_previous_cart_items[ item_key ] = current_qty;
			processed_keys[ item_key ] = true;
		} );

		await Promise.all( processPromises );

		const previous_keys = Object.keys( gtm4wp_previous_cart_items );

		previous_keys.forEach( ( item_key ) => {
			if ( processed_keys[ item_key ] || cart_items_map[ item_key ] ) {
				return;
			}

			const previous_qty = gtm4wp_previous_cart_items[ item_key ] || 0;

			if ( previous_qty <= 0 ) {
				delete gtm4wp_previous_cart_items[ item_key ];
				delete gtm4wp_previous_cart_snapshots[ item_key ];
				return;
			}

			const snapshot = gtm4wp_previous_cart_snapshots[ item_key ];

			if ( snapshot ) {
				const removal_payload = Object.assign( {}, snapshot, { quantity: previous_qty } );

				if ( gtm4wp_should_register_delta( item_key, previous_qty, 'remove' ) ) {
					removed_items.push( removal_payload );
					removed_value += parseFloat( removal_payload.price || 0 ) * removal_payload.quantity;
				}
			}

			delete gtm4wp_previous_cart_items[ item_key ];
			delete gtm4wp_previous_cart_snapshots[ item_key ];
		} );

		let changes_detected = false;

		if ( new_items.length > 0 ) {
			gtm4wp_push_ecommerce( 'add_to_cart', new_items, {
				'currency': gtm4wp_currency,
				'value': total_value.toFixed(2)
			} );
			changes_detected = true;
		}

		if ( removed_items.length > 0 ) {
			gtm4wp_push_ecommerce( 'remove_from_cart', removed_items, {
				'currency': gtm4wp_currency,
				'value': removed_value.toFixed(2)
			} );
			changes_detected = true;
		}

		gtm4wp_last_cart_signature = cart_signature;
		gtm4wp_persist_cart_state_to_storage();
		return changes_detected;
	}

	// Function to process add-to-cart event
	async function gtm4wp_process_blocks_add_to_cart( event ) {
		if ( gtm4wp_dom_event_in_progress ) {
			return;
		}

		gtm4wp_bootstrap_blocks_state();
		await gtm4wp_ensure_blocks_initialized();

		gtm4wp_dom_event_in_progress = true;

		if ( gtm4wp_snapshot_in_progress ) {
			gtm4wp_dom_event_in_progress = false;
			return;
		}

		gtm4wp_snapshot_in_progress = true;

		try {
			const retrySchedule = [ 0, 250, 600 ];
			let processedSnapshot = false;

			for ( let retryIndex = 0; retryIndex < retrySchedule.length; retryIndex++ ) {
				const delay = retrySchedule[ retryIndex ];

				if ( delay > 0 ) {
					await gtm4wp_delay( delay );
				}

				const cart_data = await gtm4wp_fetch_cart_items();
				processedSnapshot = await gtm4wp_process_cart_snapshot( cart_data );

				if ( processedSnapshot ) {
					break;
				}
			}
		} finally {
			gtm4wp_snapshot_in_progress = false;
			gtm4wp_dom_event_in_progress = false;
		}
	}

	// Initialize: Fetch initial cart state when page loads
	async function gtm4wp_init_blocks_cart_tracking() {
		const cart_data = await gtm4wp_fetch_cart_items();

		if ( ! cart_data ) {
			await gtm4wp_mark_initial_state_ready();
			return;
		}

		gtm4wp_previous_cart_items = {};
		Object.keys( gtm4wp_previous_cart_snapshots ).forEach( ( snapshot_key ) => {
			delete gtm4wp_previous_cart_snapshots[ snapshot_key ];
		} );

		const cart_items = cart_data.items || cart_data.cartItems || [];

		await Promise.all( cart_items.map( async ( cart_item ) => {
			const item_key = gtm4wp_get_cart_item_key( cart_item );

			if ( ! item_key ) {
				return;
			}

			const quantity = parseInt( cart_item.quantity || 0 );
			gtm4wp_previous_cart_items[item_key] = quantity;

			const product_data = await gtm4wp_convert_cart_item_to_product( cart_item );

			if ( product_data ) {
				gtm4wp_store_cart_item_snapshot( item_key, product_data, quantity );
			}
		} ) );

		gtm4wp_last_cart_signature = cart_items
			.map( ( cart_item ) => gtm4wp_get_cart_item_key( cart_item ) + ':' + parseInt( cart_item.quantity || 0 ) )
			.sort()
			.join( '|' );

		await gtm4wp_mark_initial_state_ready();
		gtm4wp_persist_cart_state_to_storage();
	}

	function gtm4wp_register_blocks_add_to_cart_listener( target ) {
		if ( !target || target._gtm4wp_wc_blocks_listener_registered ) {
			return;
		}

		target.addEventListener( 'wc-blocks_added_to_cart', function( event ) {
			if ( event._gtm4wp_handled ) {
				return;
			}

			event._gtm4wp_handled = true;

			// Small delay to ensure cart is updated on the server
			setTimeout( function() {
				gtm4wp_process_blocks_add_to_cart( event );
			}, 200 );
		}, true );

		target._gtm4wp_wc_blocks_listener_registered = true;
	}

	function gtm4wp_subscribe_to_cart_changes() {
		if ( ! gtm4wp_is_blocks_store_available() ) {
			gtm4wp_blocks_warn_once( 'WooCommerce Blocks data store unavailable; mini-cart add_to_cart tracking is disabled.' );
			return;
		}

		const cartStoreHasData = () => {
			const cartStore = wp.data.select( 'wc/store/cart' );
			return cartStore && cartStore.getCartData ? cartStore.getCartData() : null;
		};

		wp.data.subscribe( async () => {
			const cartData = cartStoreHasData();
			if ( ! cartData ) {
				return;
			}

			await gtm4wp_ensure_blocks_initialized();

			if ( ! gtm4wp_blocks_initial_state_ready ) {
				return;
			}

			const cart_items = cartData.items || cartData.cartItems || [];
			const current_signature = cart_items
				.map( ( cart_item ) => gtm4wp_get_cart_item_key( cart_item ) + ':' + parseInt( cart_item.quantity || 0 ) )
				.sort()
				.join( '|' );

			if ( current_signature === gtm4wp_last_cart_signature || gtm4wp_snapshot_in_progress ) {
				return;
			}

			if ( gtm4wp_blocks_initial_state_ready && ! gtm4wp_dom_event_in_progress ) {
				await gtm4wp_process_cart_snapshot( cartData );
			} else {
				gtm4wp_bootstrap_blocks_state_complete( true );
				gtm4wp_last_cart_signature = current_signature;
			}
		} );
	}

	function gtm4wp_start_blocks_tracking() {
		gtm4wp_bootstrap_blocks_state();
		gtm4wp_register_blocks_add_to_cart_listener( document );
		gtm4wp_register_blocks_add_to_cart_listener( document.body );
		gtm4wp_subscribe_to_cart_changes();
	}

	// Listen for WooCommerce Blocks add-to-cart DOM event
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', gtm4wp_start_blocks_tracking );
	} else {
		gtm4wp_start_blocks_tracking();
	}
}
