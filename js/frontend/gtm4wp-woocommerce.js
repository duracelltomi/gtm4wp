let gtm4wp_last_selected_product_variation;
window.gtm4wp_view_item_fired_during_pageload = false;

window.gtm4wp_checkout_step_fired = []; // step 1 will be the billing section which is reported during pageload, no need to handle here

window.gtm4wp_first_container_id = '';

function gtm4wp_woocommerce_handle_cart_qty_change() {
	document
		.querySelectorAll( '.product-quantity input.qty' )
		.forEach( function ( qty_el ) {
			const original_value = qty_el.defaultValue;

			let current_value = parseInt( qty_el.value );
			if ( isNaN( current_value ) ) {
				current_value = original_value;
			}

			// is quantity changed changed?
			if ( original_value != current_value ) {
				const cart_item_temp = qty_el.closest( '.cart_item' );
				const productdata_el =
					cart_item_temp && cart_item_temp.querySelector( '.remove' );
				if ( ! productdata_el ) {
					return;
				}

				const productdata = gtm4wp_read_json_from_node(
					productdata_el,
					'gtm4wp_product_data'
				);
				if ( ! productdata ) {
					return true;
				}

				// does the quantity increase?
				if ( original_value < current_value ) {
					// yes => handle add to cart event
					productdata.quantity = current_value - original_value;
					productdata.price = productdata.price;

					gtm4wp_push_ecommerce( 'add_to_cart', [ productdata ], {
						currency: gtm4wp_currency, // ga4 version
						value: productdata.price * productdata.quantity,
					} );
				} else {
					// no => handle remove from cart event
					productdata.quantity = original_value - current_value;
					productdata.price = productdata.price;

					gtm4wp_push_ecommerce(
						'remove_from_cart',
						[ productdata ],
						{
							currency: gtm4wp_currency,
							value: productdata.price * productdata.quantity,
						}
					);
				}
			} // end if qty changed
		} ); // end each qty field
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
	if ( ! payment_el ) {
		payment_el = document.querySelector( 'input[name^=payment_method]' ); // select the first input element
	}
	if ( payment_el ) {
		payment_type = payment_el.value;
	}

	gtm4wp_push_ecommerce(
		'add_payment_info',
		window.gtm4wp_checkout_products,
		{
			currency: gtm4wp_currency,
			payment_type,
			value: window.gtm4wp_checkout_value,
		}
	);

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
	let shipping_el = document.querySelector(
		'input[name^=shipping_method]:checked'
	);
	if ( ! shipping_el ) {
		shipping_el = document.querySelector( 'input[name^=shipping_method]' ); // select the first input element
	}
	if ( shipping_el ) {
		shipping_tier = shipping_el.value;
	}

	gtm4wp_push_ecommerce(
		'add_shipping_info',
		window.gtm4wp_checkout_products,
		{
			currency: gtm4wp_currency,
			shipping_tier,
			value: window.gtm4wp_checkout_value,
		}
	);

	gtm4wp_checkout_step_fired.push( 'shipping_method' );
}

/**
 * Reads the step identifier from a CheckoutWC cfw_step_changed event (#385).
 *
 * CheckoutWC's event payload shape is not verified against a live install, so
 * this looks in the likely places (a plain string detail, or a detail object
 * keyed by step / current / to / name) and returns a lowercase string, or an
 * empty string when nothing recognizable is present.
 *
 * @param {Event} e The cfw_step_changed event.
 * @return {string} The lowercased step identifier, or '' if unknown.
 */
function gtm4wp_woocommerce_checkoutwc_step( e ) {
	let step = '';

	if ( e && e.detail ) {
		if ( typeof e.detail === 'string' ) {
			step = e.detail;
		} else {
			step =
				e.detail.step ||
				e.detail.current ||
				e.detail.to ||
				e.detail.name ||
				'';
		}
	}

	return ( '' + step ).toLowerCase();
}

/**
 * Fires the GA4 add_to_cart event for a product added from its product detail
 * page - the variable, grouped and simple cases. Exposed on window so a theme
 * that handles add to cart with its own AJAX (and calls e.preventDefault() on the
 * button, which stops the built-in click tracking) can fire the event from its own
 * success handler without copying the tracker (#273).
 *
 * @param {Element} trigger_element The clicked add-to-cart button (or a descendant).
 * @param {Element} [product_form]  The product's form.cart; derived from the button when omitted.
 * @return {boolean} Whether an add_to_cart event was tracked.
 */
function gtm4wp_track_single_add_to_cart( trigger_element, product_form ) {
	if ( ! trigger_element || ! trigger_element.closest ) {
		return false;
	}

	const add_to_cart_button =
		trigger_element.closest( '.single_add_to_cart_button' ) ||
		trigger_element;

	if (
		add_to_cart_button.classList &&
		( add_to_cart_button.classList.contains( 'disabled' ) ||
			add_to_cart_button.disabled )
	) {
		// do not track clicks on disabled buttons
		return false;
	}

	const form = product_form || trigger_element.closest( 'form.cart' );
	if ( ! form ) {
		return false;
	}

	// Do not fire add_to_cart when the browser would block the form submit for
	// unfilled required fields (e.g. Product Add-ons): the item is not actually
	// added, so reporting it would be a false event (#274). Server-side-only
	// validation still cannot be seen from here.
	if ( typeof form.checkValidity === 'function' && ! form.checkValidity() ) {
		return false;
	}

	const product_variant_id = form.querySelectorAll( '[name=variation_id]' );
	const product_is_grouped =
		form.classList && form.classList.contains( 'grouped_form' );

	if ( product_variant_id.length > 0 ) {
		if ( gtm4wp_last_selected_product_variation ) {
			const qty_el = form.querySelector( '[name=quantity]' );
			gtm4wp_last_selected_product_variation.quantity =
				( qty_el && qty_el.value ) || 1;

			gtm4wp_push_ecommerce(
				'add_to_cart',
				[ gtm4wp_last_selected_product_variation ],
				{
					currency: gtm4wp_currency,
					value: (
						gtm4wp_last_selected_product_variation.price *
						gtm4wp_last_selected_product_variation.quantity
					).toFixed( 2 ),
				}
			);
		}
	} else if ( product_is_grouped ) {
		const products_in_group = document.querySelectorAll(
			'.grouped_form .gtm4wp_productdata'
		);
		const products = [];
		let sum_value = 0;

		products_in_group.forEach( function ( product_data_el ) {
			const productdata = gtm4wp_read_json_from_node(
				product_data_el,
				'gtm4wp_product_data',
				[ 'productlink' ]
			);
			if ( ! productdata ) {
				return true;
			}

			let product_qty = 0;
			const product_qty_input = document.querySelectorAll(
				'input[name=quantity\\[' + productdata.internal_id + '\\]]'
			);
			if ( product_qty_input.length > 0 ) {
				product_qty =
					( product_qty_input[ 0 ] &&
						product_qty_input[ 0 ].value ) ||
					1;
			} else {
				return true;
			}

			if ( 0 == product_qty ) {
				return true;
			}
			productdata.quantity = product_qty;

			// #405: carry the originating list onto this add_to_cart item (opt-in).
			if ( window.gtm4wp_list_attribution ) {
				gtm4wp_apply_stored_item_list(
					productdata,
					productdata.internal_id
				);
			}

			delete productdata.internal_id;

			products.push( productdata );
			sum_value += productdata.price * productdata.quantity;
		} );

		if ( 0 == products.length ) {
			return false;
		}

		gtm4wp_push_ecommerce( 'add_to_cart', products, {
			currency: gtm4wp_currency,
			value: sum_value.toFixed( 2 ),
		} );
	} else {
		const product_data_el = form.querySelector(
			'[name=gtm4wp_product_data]'
		);
		if ( ! product_data_el ) {
			return false;
		}

		// Keep internal_id from being excluded so #405 can look up the stored list
		// by product id; it is deleted again before the push below.
		const productdata = gtm4wp_read_from_json( product_data_el.value, [
			'productlink',
		] );
		productdata.quantity =
			form.querySelector( '[name=quantity]' ) &&
			form.querySelector( '[name=quantity]' ).value;
		if ( isNaN( productdata.quantity ) ) {
			productdata.quantity = 1;
		}

		// #405: carry the originating list onto this add_to_cart item (opt-in).
		if ( window.gtm4wp_list_attribution ) {
			gtm4wp_apply_stored_item_list(
				productdata,
				productdata.internal_id
			);
		}
		delete productdata.internal_id;

		gtm4wp_push_ecommerce( 'add_to_cart', [ productdata ], {
			currency: gtm4wp_currency,
			value: productdata.price * productdata.quantity,
		} );
	}

	return true;
}

/**
 * Fires the GA4 add_to_cart event for a simple product added from a product list
 * (category/shop page, block grid, or the [add_to_cart] shortcode). Exposed on
 * window for the same reason as gtm4wp_track_single_add_to_cart() (#273).
 *
 * @param {Element} trigger_element The clicked add-to-cart button (or a descendant).
 * @return {boolean} Whether an add_to_cart event was tracked.
 */
function gtm4wp_track_list_add_to_cart( trigger_element ) {
	if ( ! trigger_element || ! trigger_element.closest ) {
		return false;
	}

	const product_el = trigger_element.closest(
		'.product,.wc-block-grid__product,.wc-block-product'
	);
	const productdata_el =
		product_el && product_el.querySelector( '.gtm4wp_productdata' );

	let productdata;
	if ( productdata_el ) {
		productdata = gtm4wp_read_json_from_node(
			productdata_el,
			'gtm4wp_product_data'
		);
	} else {
		// Standalone [add_to_cart] shortcode button: it has no list markup, so
		// the product data is attached to the button itself (#110).
		const shortcode_button = trigger_element.closest(
			'.add_to_cart_button'
		);
		productdata =
			shortcode_button &&
			gtm4wp_read_json_from_node(
				shortcode_button,
				'gtm4wp_product_data'
			);
	}
	if ( ! productdata ) {
		return false;
	}

	if (
		'variable' === productdata.product_type ||
		'grouped' === productdata.product_type
	) {
		return false;
	}

	if ( productdata.productlink ) {
		delete productdata.productlink;
	}
	delete productdata.product_type;
	productdata.quantity = 1;

	gtm4wp_push_ecommerce( 'add_to_cart', [ productdata ], {
		currency: gtm4wp_currency,
		value: productdata.price,
	} );

	return true;
}

// Expose the add-to-cart trackers so a theme with custom / AJAX add to cart can
// fire the events without duplicating the tracker code (#273).
window.gtm4wp_track_single_add_to_cart = gtm4wp_track_single_add_to_cart;
window.gtm4wp_track_list_add_to_cart = gtm4wp_track_list_add_to_cart;

function gtm4wp_woocommerce_process_pages() {
	// loop through WC blocks to set proper listname and position parameters
	const gtm4wp_product_block_names = {
		'wp-block-handpicked-products': {
			displayname: 'Handpicked Products',
			counter: 1,
		},
		'wp-block-product-best-sellers': {
			displayname: 'Best Selling Products',
			counter: 1,
		},
		'wp-block-product-category': {
			displayname: 'Product Category List',
			counter: 1,
		},
		'wp-block-product-new': {
			displayname: 'New Products',
			counter: 1,
		},
		'wp-block-product-on-sale': {
			displayname: 'Sale Products',
			counter: 1,
		},
		'wp-block-products-by-attribute': {
			displayname: 'Products By Attribute',
			counter: 1,
		},
		'wp-block-product-tag': {
			displayname: 'Products By Tag',
			counter: 1,
		},
		'wp-block-product-top-rated': {
			displayname: 'Top Rated Products',
			counter: 1,
		},
	};

	document
		.querySelectorAll( '.wc-block-grid .wc-block-grid__product' )
		.forEach( function ( product_grid_item ) {
			const product_grid_container =
				product_grid_item.closest( '.wc-block-grid' );
			const product_data_el = product_grid_item.querySelector(
				'.gtm4wp_productdata'
			);

			if ( product_grid_container && product_data_el ) {
				const product_grid_container_classes =
					product_grid_container.classList;

				if ( product_grid_container_classes ) {
					for ( const i in gtm4wp_product_block_names ) {
						if ( product_grid_container_classes.contains( i ) ) {
							gtm4wp_update_json_in_node(
								product_data_el,
								'gtm4wp_product_data',
								'item_list_name',
								gtm4wp_product_block_names[ i ].displayname
							);
							gtm4wp_update_json_in_node(
								product_data_el,
								'gtm4wp_product_data',
								'index',
								gtm4wp_product_block_names[ i ].counter
							);

							gtm4wp_product_block_names[ i ].counter++;
						}
					}
				}
			}
		} );

	// track impressions of products in product lists
	if (
		document.querySelectorAll( '.gtm4wp_productdata,.widget-product-item' )
			.length > 0
	) {
		const products = [];

		document
			.querySelectorAll( '.gtm4wp_productdata,.widget-product-item' )
			.forEach( function ( productdata_el ) {
				const productdata = gtm4wp_read_json_from_node(
					productdata_el,
					'gtm4wp_product_data'
				);
				if ( ! productdata ) {
					return true;
				}

				products.push( productdata );
			} );

		if ( gtm4wp_product_per_impression > 0 ) {
			// Need to split the product submissions up into chunks in order to avoid the GA 16kb hit size limit
			let chunk;

			while ( products.length ) {
				chunk = products.splice( 0, gtm4wp_product_per_impression );

				gtm4wp_push_ecommerce( 'view_item_list', chunk, {
					currency: gtm4wp_currency,
				} );
			}
		} else {
			// push everything in one event and let's hope the best :-)
			gtm4wp_push_ecommerce( 'view_item_list', products, {
				currency: gtm4wp_currency,
			} );
		}
	}

	// manage events related to user clicks
	document.addEventListener(
		'click',
		function ( e ) {
			const event_target_element = e.target;

			if ( ! event_target_element ) {
				// for some reason event target is not specified
				return true;
			}

			// track add to cart events for simple products in product lists
			if (
				event_target_element.closest(
					'.add_to_cart_button:not(.product_type_variable, .product_type_grouped, .product_type_bundle_input_required, .single_add_to_cart_button)'
				)
			) {
				gtm4wp_track_list_add_to_cart( event_target_element );
			}

			// track add to cart events for products on product detail pages
			const add_to_cart_button = event_target_element.closest(
				'.single_add_to_cart_button'
			);
			if ( add_to_cart_button ) {
				gtm4wp_track_single_add_to_cart( add_to_cart_button );
			}

			// track remove links in mini cart widget and on cart page
			if (
				event_target_element.closest(
					'.mini_cart_item a.remove,.product-remove a.remove'
				)
			) {
				const click_el = event_target_element;

				const productdata_el =
					click_el &&
					click_el.closest(
						'.mini_cart_item a.remove,.product-remove a.remove'
					);
				if ( ! productdata_el ) {
					return true;
				}

				const productdata = gtm4wp_read_json_from_node(
					productdata_el,
					'gtm4wp_product_data'
				);
				if ( ! productdata ) {
					return true;
				}

				let qty = 0;
				const cart_item_el = productdata_el.closest( '.cart_item' );
				let qty_element =
					cart_item_el &&
					cart_item_el.querySelectorAll(
						'.product-quantity input.qty'
					);
				if ( ! qty_element || 0 === qty_element.length ) {
					const mini_cart_item_el =
						productdata_el.closest( '.mini_cart_item' );
					qty_element =
						mini_cart_item_el &&
						mini_cart_item_el.querySelectorAll( '.quantity' );
					if ( qty_element && qty_element.length > 0 ) {
						qty = parseInt( qty_element[ 0 ].textContent );

						if ( Number.isNaN( qty ) ) {
							qty = 0;
						}
					}
				} else {
					qty = qty_element[ 0 ].value;
				}

				if ( 0 === qty ) {
					return true;
				}

				productdata.quantity = qty;

				gtm4wp_push_ecommerce( 'remove_from_cart', [ productdata ], {
					currency: gtm4wp_currency,
					value: productdata.price * productdata.quantity,
				} );
			}

			// track clicks in product lists
			const matching_link_element = event_target_element.closest(
				'.products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),' +
					'.wc-block-grid__products li:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),' +
					'.wc-block-product-template li.wc-block-product a:not(.add_to_cart_button):not(.quick-view-button),' +
					'.products>div:not(.product-category) a:not(.add_to_cart_button):not(.quick-view-button),' +
					'.widget-product-item,' +
					'.woocommerce-grouped-product-list-item__label a'
			);
			if ( matching_link_element ) {
				// Do nothing if GTM is blocked for some reason.
				// At this point, we only know that Google Tag has been loaded.
				// If only a Google Tag is loaded, it also populates the google_tag_manager object.
				if ( 'undefined' === typeof google_tag_manager ) {
					return true;
				}

				const event_target_element = e.target;

				// try to find product data as it is in different places depending on the clicked element.
				let temp_selector = event_target_element.closest(
					'.product,.wc-block-grid__product,.wc-block-product'
				);
				let productdata_el;

				if ( temp_selector ) {
					productdata_el = temp_selector.querySelector(
						'.gtm4wp_productdata'
					);
				} else {
					temp_selector =
						event_target_element.closest( '.products li' );

					if ( temp_selector ) {
						productdata_el = temp_selector.querySelector(
							'.gtm4wp_productdata'
						);
					} else {
						temp_selector =
							event_target_element.closest( '.products>div' );

						if ( temp_selector ) {
							productdata_el = temp_selector.querySelector(
								'.gtm4wp_productdata'
							);
						} else {
							temp_selector = event_target_element.closest(
								'.woocommerce-grouped-product-list-item__label'
							);

							if ( temp_selector ) {
								productdata_el = temp_selector.querySelector(
									'.gtm4wp_productdata'
								);
							} else {
								productdata_el = event_target_element;
							}
						}
					}
				}

				// Extract product data from the found DOM node.
				const productdata = gtm4wp_read_json_from_node(
					productdata_el,
					'gtm4wp_product_data',
					[ 'internal_id' ]
				);
				if ( ! productdata ) {
					return true;
				}

				// Only act on links pointing to the product detail page
				if (
					productdata.productlink !=
					matching_link_element.getAttribute( 'href' )
				) {
					return true;
				}

				// #405: persist this list attribution (keyed by product id) so the
				// later view_item / add_to_cart / checkout / purchase events can be
				// attributed back to the originating list. internal_id was excluded
				// above, so read it straight from the node. Opt-in only.
				if (
					window.gtm4wp_list_attribution &&
					productdata.item_list_name
				) {
					const list_source = gtm4wp_read_json_from_node(
						productdata_el,
						'gtm4wp_product_data',
						[]
					);
					if ( list_source && list_source.internal_id ) {
						gtm4wp_store_item_list_attribution(
							list_source.internal_id,
							productdata.item_list_name,
							productdata.item_list_id
						);
					}
				}

				// Look at first GTM container ID in case there are multiple GTM containers live on the page
				// since eventCallback is called on every container and we only need this executed once in this case.
				for ( const i in window.google_tag_manager ) {
					if ( i.substring( 0, 4 ).toLowerCase() == 'gtm-' ) {
						window.gtm4wp_first_container_id = i;
						break;
					}
				}

				// Do not do anything if GTM was not loaded.
				// The google_tag_manager object is still available if only Google Tag is loaded.
				if ( '' === window.gtm4wp_first_container_id ) {
					return true;
				}

				let datalayer_timeout = 2000;
				if (
					typeof window.gtm4wp_datalayer_max_timeout !== 'undefined'
				) {
					datalayer_timeout = window.gtm4wp_datalayer_max_timeout;
				}

				if ( datalayer_timeout > 0 ) {
					const ctrl_key_pressed = e.ctrlKey || e.metaKey;
					const target_new_tab =
						'_blank' === matching_link_element.target;

					// save this info to prevent redirection if another plugin already prevented to event for some reason
					const event_already_prevented = e.defaultPrevented;
					if ( ! event_already_prevented ) {
						e.preventDefault();
					}

					if ( ctrl_key_pressed || target_new_tab ) {
						// we need to open the new tab/page here so that popup blocker of the browser doesn't block our code
						window.productpage_window = window.open(
							'about:blank',
							'_blank'
						);
					}

					const productlink_to_redirect = productdata.productlink;
					delete productdata.productlink;
					// fire ga4 version
					gtm4wp_push_ecommerce(
						'select_item',
						[ productdata ],
						{
							currency: gtm4wp_currency,
						},
						function ( container_id ) {
							if (
								'undefined' !== typeof container_id &&
								window.gtm4wp_first_container_id != container_id
							) {
								// only call this for the first loaded container
								return true;
							}

							if ( ! event_already_prevented ) {
								if (
									( target_new_tab || ctrl_key_pressed ) &&
									productpage_window
								) {
									productpage_window.location.href =
										productlink_to_redirect;
								} else {
									document.location.href =
										productlink_to_redirect;
								}
							}
						},
						datalayer_timeout
					);
				} else {
					delete productdata.productlink;
					gtm4wp_push_ecommerce( 'select_item', [ productdata ], {
						currency: gtm4wp_currency,
					} );
				}
			}
		},
		{ capture: true }
	);

	// track variable products on their detail pages
	// currently, we need to use jQuery here since WooCommerce is firing this event using jQuery
	// that can not be caught using vanilla JS
	jQuery( document ).on(
		'found_variation',
		function ( event, product_variation ) {
			if ( 'undefined' === typeof product_variation ) {
				// some ither plugins trigger this event without variation data
				return;
			}

			if (
				document.readyState === 'interactive' &&
				gtm4wp_view_item_fired_during_pageload
			) {
				// some custom attribute rendering plugins fire this event multiple times during page load
				return;
			}

			// event target is the <form> element of the add to cart button.
			const product_form = event.target;
			if ( ! product_form ) {
				return true;
			}

			const product_data_el = product_form.querySelector(
				'[name=gtm4wp_product_data]'
			);
			if ( ! product_data_el ) {
				return true;
			}

			let current_product_detail_data;
			try {
				current_product_detail_data = JSON.parse(
					product_data_el.value
				);
			} catch ( e ) {
				console && console.error && console.error( e.message );
				return true;
			}

			current_product_detail_data.price = gtm4wp_make_sure_is_float(
				current_product_detail_data.price
			);

			current_product_detail_data.item_group_id =
				current_product_detail_data.id;
			// Re-apply the dynamic-remarketing product-id prefix to the selected
			// variation's id: the server prefixes the parent id, but here we swap in
			// the variation id, so the prefix would otherwise be lost (#383). item_id
			// stays unprefixed (server contract), and the prefix is only prepended
			// when set, so an unprefixed id keeps its original type.
			current_product_detail_data.id = gtm4wp_remarketing_prod_id_prefix
				? gtm4wp_remarketing_prod_id_prefix +
				  product_variation.variation_id
				: product_variation.variation_id;
			current_product_detail_data.item_id =
				product_variation.variation_id;
			current_product_detail_data.sku = product_variation.sku;
			if (
				gtm4wp_use_sku_instead &&
				product_variation.sku &&
				'' !== product_variation.sku
			) {
				current_product_detail_data.id =
					gtm4wp_remarketing_prod_id_prefix
						? gtm4wp_remarketing_prod_id_prefix +
						  product_variation.sku
						: product_variation.sku;
				current_product_detail_data.item_id = product_variation.sku;
			}
			current_product_detail_data.price = gtm4wp_make_sure_is_float(
				product_variation.display_price
			);

			const product_variation_attribute_values = [];
			for ( const attrib_key in product_variation.attributes ) {
				product_variation_attribute_values.push(
					product_variation.attributes[ attrib_key ]
				);
			}
			current_product_detail_data.item_variant =
				product_variation_attribute_values.join( ',' );
			gtm4wp_last_selected_product_variation =
				current_product_detail_data;

			// #405: the parent product id (internal_id, about to be deleted) is what
			// the list stored the attribution under; use it to enrich this view_item
			// (and the add_to_cart that reuses this object) from the cookie. Opt-in.
			const list_product_id = current_product_detail_data.internal_id;

			delete current_product_detail_data.internal_id;

			// GA4 expects a quantity on the view_item item; a product view is a single
			// unit (#348). add_to_cart later overwrites this with the chosen quantity.
			current_product_detail_data.quantity = 1;

			if ( window.gtm4wp_list_attribution ) {
				gtm4wp_apply_stored_item_list(
					current_product_detail_data,
					list_product_id
				);
			}

			// fire ga4 version
			gtm4wp_push_ecommerce(
				'view_item',
				[ current_product_detail_data ],
				{
					currency: gtm4wp_currency,
					value: current_product_detail_data.price,
				}
			);

			if ( document.readyState === 'interactive' ) {
				gtm4wp_view_item_fired_during_pageload = true;
			}
		}
	);
	jQuery( '.variations select' ).trigger( 'change' );

	// initiate codes in WooCommerce Quick View
	// currently, we need to use jQuery here since WooCommerce Quick View is showing the popup using
	// jQuery AJAX calls that can not be caught using vanilla JS
	jQuery( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( typeof settings !== 'undefined' ) {
			if ( settings.url.indexOf( 'wc-api=WC_Quick_View' ) > -1 ) {
				setTimeout( function () {
					const dl_data = document.querySelector(
						'#gtm4wp_quickview_data'
					);
					if (
						dl_data &&
						dl_data.dataset &&
						dl_data.dataset.gtm4wp_datalayer
					) {
						try {
							const dl_data_obj = JSON.parse(
								dl_data.dataset.gtm4wp_datalayer
							);
							if ( dl_data_obj && window.dataLayer ) {
								window.dataLayer.push( dl_data_obj );
							}
						} catch ( e ) {
							console &&
								console.error &&
								console.error( e.message );
						}
					}
				}, 500 );
			}
		}
	} );

	let gtm4wp_is_cart = false;
	let gtm4wp_is_checkout = false;

	const doc_body = document.querySelector( 'body' );
	if ( doc_body ) {
		gtm4wp_is_cart =
			doc_body.classList &&
			doc_body.classList.contains( 'woocommerce-cart' );
		gtm4wp_is_checkout =
			doc_body.classList &&
			doc_body.classList.contains( 'woocommerce-checkout' );
	}

	// codes for ecommerce events on cart page
	if ( gtm4wp_is_cart ) {
		document.addEventListener( 'click', function ( e ) {
			const event_target_element = e.target;

			if ( ! event_target_element ) {
				// for some reason event target is not specified
				return true;
			}

			if ( ! event_target_element.closest( '[name=update_cart]' ) ) {
				return true;
			}

			gtm4wp_woocommerce_handle_cart_qty_change();
		} );

		document.addEventListener( 'keypress', function ( e ) {
			const event_target_element = e.target;

			if ( ! event_target_element ) {
				// for some reason event target is not specified
				return true;
			}

			if (
				! event_target_element.closest(
					'.woocommerce-cart-form input[type=number]'
				)
			) {
				return true;
			}

			gtm4wp_woocommerce_handle_cart_qty_change();
		} );
	}

	// codes for ecommerce events on checkout page
	if ( gtm4wp_is_checkout ) {
		window.gtm4wp_checkout_value = window.gtm4wp_checkout_value || 0;
		window.gtm4wp_checkout_products = window.gtm4wp_checkout_products || [];
		window.gtm4wp_checkout_products_ga4 =
			window.gtm4wp_checkout_products_ga4 || [];

		document.addEventListener( 'change', function ( e ) {
			const event_target_element = e.target;

			if ( ! event_target_element ) {
				// for some reason event target is not specified
				return true;
			}

			if (
				! event_target_element.closest( 'input[name^=shipping_method]' )
			) {
				return true;
			}

			gtm4wp_woocommerce_handle_shipping_method_change();
		} );

		document.addEventListener( 'change', function ( e ) {
			const event_target_element = e.target;

			if ( ! event_target_element ) {
				// for some reason event target is not specified
				return true;
			}

			if (
				! event_target_element.closest( 'input[name=payment_method]' )
			) {
				return true;
			}

			gtm4wp_woocommerce_handle_payment_method_change();
		} );

		// We need to use jQuery where since the checkout_place_order event is only triggered using jQuery
		const checkout_form = jQuery( 'form.checkout' );
		checkout_form.on( 'checkout_place_order', function () {
			if (
				gtm4wp_checkout_step_fired.indexOf( 'shipping_method' ) == -1
			) {
				// shipping methods are not visible if only one is available
				// and if the user has already a pre-selected method, no click event will fire to report the checkout step
				gtm4wp_woocommerce_handle_shipping_method_change();
			}

			if (
				gtm4wp_checkout_step_fired.indexOf( 'payment_method' ) == -1
			) {
				// if the user has already a pre-selected method, no click event will fire to report the checkout step
				gtm4wp_woocommerce_handle_payment_method_change();
			}
		} );
	}

	// CheckoutWC compatibility (#385): CheckoutWC replaces the checkout with its
	// own multi-step template, so the classic shipping/payment change events and
	// the jQuery checkout_place_order event above are not reliably dispatched and
	// add_shipping_info / add_payment_info are missed. Bind those steps to
	// CheckoutWC's own cfw_step_changed event instead. cfw_step_changed only fires
	// on CheckoutWC's checkout, so this block is inert on every other page (and
	// gtm4wp_is_checkout may be false there, hence a separate branch). The step
	// handlers are idempotent (gtm4wp_checkout_step_fired dedup) and read the
	// selected method straight from the DOM, which CheckoutWC keeps on
	// WooCommerce's standard field names.
	if ( window.gtm4wp_checkoutwc ) {
		window.gtm4wp_checkout_value = window.gtm4wp_checkout_value || 0;
		window.gtm4wp_checkout_products = window.gtm4wp_checkout_products || [];
		window.gtm4wp_checkout_products_ga4 =
			window.gtm4wp_checkout_products_ga4 || [];

		document.addEventListener( 'cfw_step_changed', function ( e ) {
			const checkoutwc_step = gtm4wp_woocommerce_checkoutwc_step( e );

			// Reaching the payment (or final review) step means the shipping
			// selection is behind the visitor, so report both.
			if (
				checkoutwc_step.indexOf( 'payment' ) > -1 ||
				checkoutwc_step.indexOf( 'review' ) > -1
			) {
				gtm4wp_woocommerce_handle_shipping_method_change();
				gtm4wp_woocommerce_handle_payment_method_change();
			} else if ( checkoutwc_step.indexOf( 'shipping' ) > -1 ) {
				gtm4wp_woocommerce_handle_shipping_method_change();
			}
		} );

		// Fallback: report any step not yet fired when the order is submitted, so
		// a pre-selected/skipped step (or an unrecognized step name) is not lost.
		jQuery( document.body ).on(
			'cfw_before_submit checkout_place_order',
			function () {
				gtm4wp_woocommerce_handle_shipping_method_change();
				gtm4wp_woocommerce_handle_payment_method_change();
			}
		);
	}
}

function gtm4wp_woocommerce_page_loading_completed() {
	document.removeEventListener(
		'DOMContentLoaded',
		gtm4wp_woocommerce_page_loading_completed
	);
	window.removeEventListener(
		'load',
		gtm4wp_woocommerce_page_loading_completed
	);
	gtm4wp_woocommerce_process_pages();
}

// code and idea borrowed from jQuery:
// https://github.com/jquery/jquery/blob/main/src/core/ready.js
if ( document.readyState !== 'loading' ) {
	window.setTimeout( gtm4wp_woocommerce_process_pages );
} else {
	document.addEventListener(
		'DOMContentLoaded',
		gtm4wp_woocommerce_page_loading_completed
	);
	window.addEventListener(
		'load',
		gtm4wp_woocommerce_page_loading_completed
	);
}
