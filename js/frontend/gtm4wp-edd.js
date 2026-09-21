/**
 * GTM4WP Easy Digital Downloads frontend tracker: view_item_list ([downloads]
 * grid and the EDD downloads block), select_item, add_to_cart (buy buttons
 * incl. Buy Now), remove_from_cart (cart row remove links plus quantity-edit
 * deltas), add_payment_info (gateway selection) and the view_item re-fire on
 * a variable-priced download's price option.
 *
 * Reads the markup emitted by ListTracking: span.gtm4wp_edd_productdata,
 * input[name=gtm4wp_product_data] and span.gtm4wp_edd_cartitemdata. The
 * classes differ from the WooCommerce tracker's on purpose (both plugins on
 * one site). jQuery is required: EDD triggers edd_gateway_loaded through
 * jQuery's own event system.
 */

// Double-init guard (#218, PA-9): the whole module body is wrapped (the
// gtm4wp-form-move-tracker.js shape), so a re-injected bundle runs nothing.
if ( ! window.gtm4wp_edd_inited ) {
	window.gtm4wp_edd_inited = true;

	// #222: typeof-guarded like the WooCommerce bundle; the value can be 0.
	if ( 'undefined' === typeof window.gtm4wp_first_container_id ) {
		window.gtm4wp_first_container_id = '';
	}

	// add_payment_info once per selected gateway.
	const gtm4wp_edd_payment_info_fired = [];

	// edd_gateway_loaded fires once while the checkout initializes; only a
	// later firing is a buyer choice.
	let gtm4wp_edd_gateway_events_seen = 0;

	/**
	 * Whether the opt-in list-attribution persistence (#405) is on. The flag is
	 * a top-level const (binds lexically): read it bare, never via `window.`.
	 *
	 * @return {boolean} Whether list attribution persistence is enabled.
	 */
	function gtm4wp_edd_list_attribution_enabled() {
		return (
			'undefined' !== typeof gtm4wp_list_attribution &&
			!! gtm4wp_list_attribution
		);
	}

	/**
	 * Collects one GA4 item per checked price option of a variable-priced
	 * purchase form: the option's price becomes the item price and its name the
	 * GA4 item_variant.
	 *
	 * @param {Element} form          The purchase form.
	 * @param {Object}  productdata   The base item data (without price_options).
	 * @param {Object}  price_options The price_id => {name, price} option map.
	 * @param {number}  quantity      The quantity to report on every item.
	 * @return {{items: Array, value: number}} The items and their summed value.
	 */
	function gtm4wp_edd_checked_price_option_items(
		form,
		productdata,
		price_options,
		quantity
	) {
		const items = [];
		let sum_value = 0;

		const checked_options = form.querySelectorAll(
			'.edd_price_options input:checked'
		);

		checked_options.forEach( function ( option_input ) {
			const price_option = price_options[ option_input.value ];
			if ( ! price_option ) {
				return;
			}

			const item = Object.assign( {}, productdata );
			item.price = gtm4wp_make_sure_is_float( price_option.price );
			if ( price_option.name ) {
				item.item_variant = price_option.name;
			}
			item.quantity = quantity;

			items.push( item );
			sum_value += item.price * item.quantity;
		} );

		return { items, value: sum_value };
	}

	/**
	 * Fires the GA4 add_to_cart event for an EDD purchase (buy button) form.
	 * Exposed on window for themes with a custom add-to-cart flow.
	 *
	 * @param {Element} trigger_element The clicked buy button (or a descendant).
	 * @return {boolean} Whether an add_to_cart event was tracked.
	 */
	function gtm4wp_edd_track_add_to_cart( trigger_element ) {
		if ( ! trigger_element || ! trigger_element.closest ) {
			return false;
		}

		const add_to_cart_button =
			trigger_element.closest( '.edd-add-to-cart' ) || trigger_element;

		if (
			add_to_cart_button.classList &&
			( add_to_cart_button.classList.contains( 'disabled' ) ||
				add_to_cart_button.disabled )
		) {
			// do not track clicks on disabled buttons
			return false;
		}

		const form =
			add_to_cart_button.closest( 'form.edd_download_purchase_form' ) ||
			add_to_cart_button.closest( 'form' );
		if ( ! form ) {
			return false;
		}

		const product_data_el = form.querySelector(
			'input[name=gtm4wp_product_data]'
		);
		if ( ! product_data_el ) {
			return false;
		}

		const productdata = gtm4wp_read_from_json( product_data_el.value );
		if ( ! productdata ) {
			return false;
		}

		// #405 (opt-in): the list is merged client-side from the cookie, keyed
		// by the download id (internal_id was excluded above).
		let list_lookup_id = 0;
		if ( gtm4wp_edd_list_attribution_enabled() ) {
			const rawdata = gtm4wp_read_from_json( product_data_el.value, [] );
			list_lookup_id = ( rawdata && rawdata.internal_id ) || 0;
		}

		let quantity = 1;
		const qty_el = form.querySelector(
			'input[name=edd_download_quantity],input.edd-item-quantity'
		);
		if ( qty_el && qty_el.value ) {
			quantity = parseInt( qty_el.value );
			if ( isNaN( quantity ) || quantity < 1 ) {
				quantity = 1;
			}
		}

		const price_options = productdata.price_options;
		delete productdata.price_options;

		let items = [];
		let sum_value = 0;

		if ( price_options ) {
			// One item per checked price option (radio or multi-mode checkbox).
			const checked = gtm4wp_edd_checked_price_option_items(
				form,
				productdata,
				price_options,
				quantity
			);
			items = checked.items;
			sum_value = checked.value;
		}

		if ( 0 === items.length ) {
			productdata.quantity = quantity;
			items.push( productdata );
			sum_value = productdata.price * quantity;
		}

		if ( list_lookup_id ) {
			items.forEach( function ( item ) {
				gtm4wp_apply_stored_item_list( item, list_lookup_id );
			} );
		}

		gtm4wp_push_ecommerce( 'add_to_cart', items, {
			currency: gtm4wp_currency,
			value: gtm4wp_make_sure_is_float( sum_value ),
		} );

		return true;
	}

	window.gtm4wp_edd_track_add_to_cart = gtm4wp_edd_track_add_to_cart;

	/**
	 * Re-fires view_item with the picked price option of a variable-priced
	 * download on its detail page (the EDD counterpart of found_variation). PHP
	 * sets window.gtm4wp_edd_variable_view_item on singular pages only, and
	 * forms inside grid items are ignored even there (list territory).
	 *
	 * @param {Element} option_input The changed price option input.
	 * @return {boolean} Whether a view_item event was tracked.
	 */
	function gtm4wp_edd_track_price_option_view( option_input ) {
		if ( ! window.gtm4wp_edd_variable_view_item ) {
			return false;
		}

		const form = option_input.closest( 'form.edd_download_purchase_form' );
		if (
			! form ||
			form.closest( '.edd_download' ) ||
			form.closest( '.edd-blocks__download' )
		) {
			return false;
		}

		const product_data_el = form.querySelector(
			'input[name=gtm4wp_product_data]'
		);
		if ( ! product_data_el ) {
			return false;
		}

		const productdata = gtm4wp_read_from_json( product_data_el.value );
		if ( ! productdata || ! productdata.price_options ) {
			return false;
		}

		const price_options = productdata.price_options;
		delete productdata.price_options;

		// The server-side view_item reports quantity 1; the re-fire mirrors it.
		const checked = gtm4wp_edd_checked_price_option_items(
			form,
			productdata,
			price_options,
			1
		);

		if ( 0 === checked.items.length ) {
			return false;
		}

		// #405 (opt-in): merge the originating list from the cookie.
		if ( gtm4wp_edd_list_attribution_enabled() ) {
			const rawdata = gtm4wp_read_from_json( product_data_el.value, [] );
			const list_lookup_id = ( rawdata && rawdata.internal_id ) || 0;
			if ( list_lookup_id ) {
				checked.items.forEach( function ( item ) {
					gtm4wp_apply_stored_item_list( item, list_lookup_id );
				} );
			}
		}

		gtm4wp_push_ecommerce( 'view_item', checked.items, {
			currency: gtm4wp_currency,
			value: gtm4wp_make_sure_is_float( checked.value ),
		} );

		return true;
	}

	/**
	 * Fires add_to_cart / remove_from_cart for the quantity delta of a cart row
	 * edit (Item Quantities setting). defaultValue is the previous quantity and
	 * is advanced after each report: EDD updates the cart over AJAX without
	 * re-rendering the rows.
	 *
	 * @param {Element} qty_el The changed quantity input.
	 * @return {boolean} Whether an event was tracked.
	 */
	function gtm4wp_edd_track_cart_quantity_change( qty_el ) {
		const cart_row = qty_el.closest( '.edd_cart_item' );
		const cartdata_el =
			cart_row && cart_row.querySelector( '.gtm4wp_edd_cartitemdata' );

		const previous_value = parseInt( qty_el.defaultValue, 10 );
		const current_value = parseInt( qty_el.value, 10 );

		if (
			isNaN( previous_value ) ||
			isNaN( current_value ) ||
			current_value < 1 ||
			current_value === previous_value
		) {
			return false;
		}

		const productdata = gtm4wp_read_json_from_node(
			cartdata_el,
			'gtm4wp_product_data',
			[ 'productlink', 'internal_id', 'cart_key' ]
		);
		if ( ! productdata ) {
			return false;
		}

		const delta = current_value - previous_value;
		productdata.quantity = Math.abs( delta );

		gtm4wp_push_ecommerce(
			delta > 0 ? 'add_to_cart' : 'remove_from_cart',
			[ productdata ],
			{
				currency: gtm4wp_currency,
				value: productdata.price * productdata.quantity,
			}
		);

		qty_el.defaultValue = String( current_value );

		return true;
	}

	/**
	 * Fires add_payment_info for the given gateway once, with the checkout
	 * products PHP exposed on window.
	 *
	 * @param {string} gateway The selected gateway slug.
	 * @return {void}
	 */
	function gtm4wp_edd_track_payment_info( gateway ) {
		const payment_type = gateway || '(payment type not found)';

		if ( gtm4wp_edd_payment_info_fired.indexOf( payment_type ) > -1 ) {
			return;
		}

		gtm4wp_push_ecommerce(
			'add_payment_info',
			window.gtm4wp_checkout_products || [],
			{
				currency: gtm4wp_currency,
				payment_type,
				value: window.gtm4wp_checkout_value,
			}
		);

		gtm4wp_edd_payment_info_fired.push( payment_type );
	}

	/**
	 * The selected gateway slug on the checkout page, or '' (a single-gateway
	 * checkout renders no selector).
	 *
	 * @return {string} The selected gateway slug or ''.
	 */
	function gtm4wp_edd_selected_gateway() {
		const gateway_el = document.querySelector(
			'input[name=payment-mode]:checked'
		);

		return ( gateway_el && gateway_el.value ) || '';
	}

	function gtm4wp_edd_process_pages() {
		// Track impressions of downloads in the [downloads] grid, chunked to stay
		// under the GA hit size limit.
		const productdata_els = document.querySelectorAll(
			'.gtm4wp_edd_productdata'
		);
		if ( productdata_els.length > 0 ) {
			const products = [];

			productdata_els.forEach( function ( productdata_el ) {
				const productdata = gtm4wp_read_json_from_node(
					productdata_el,
					'gtm4wp_product_data'
				);
				if ( ! productdata ) {
					return;
				}

				products.push( productdata );
			} );

			if ( gtm4wp_product_per_impression > 0 ) {
				let chunk;

				while ( products.length ) {
					chunk = products.splice( 0, gtm4wp_product_per_impression );

					gtm4wp_push_ecommerce( 'view_item_list', chunk, {
						currency: gtm4wp_currency,
					} );
				}
			} else {
				gtm4wp_push_ecommerce( 'view_item_list', products, {
					currency: gtm4wp_currency,
				} );
			}
		}

		// Manage events related to user clicks.
		document.addEventListener(
			'click',
			function ( e ) {
				const event_target_element = e.target;

				if ( ! event_target_element ) {
					return true;
				}

				// Track add to cart (and Buy Now) clicks on EDD purchase forms.
				if ( event_target_element.closest( '.edd-add-to-cart' ) ) {
					gtm4wp_edd_track_add_to_cart( event_target_element );
					return true;
				}

				// Cart row remove links: edd_cart_remove_item_btn (classic and
				// checkout block), edd-remove-from-cart (cart block, AJAX). Rows
				// are tr or div, both .edd_cart_item.
				const remove_link = event_target_element.closest(
					'a.edd_cart_remove_item_btn, a.edd-remove-from-cart'
				);
				if ( remove_link ) {
					const cart_row = remove_link.closest( '.edd_cart_item' );
					const cartdata_el =
						cart_row &&
						cart_row.querySelector( '.gtm4wp_edd_cartitemdata' );

					const productdata = gtm4wp_read_json_from_node(
						cartdata_el,
						'gtm4wp_product_data',
						[ 'productlink', 'internal_id', 'cart_key' ]
					);
					if ( ! productdata ) {
						return true;
					}

					gtm4wp_push_ecommerce(
						'remove_from_cart',
						[ productdata ],
						{
							currency: gtm4wp_currency,
							value:
								productdata.price *
								( productdata.quantity || 1 ),
						}
					);

					return true;
				}

				// select_item: [downloads] items are .edd_download, block items
				// article.edd-blocks__download.
				const matching_link_element = event_target_element.closest(
					'.edd_download a:not(.edd-add-to-cart), .edd-blocks__download a:not(.edd-add-to-cart)'
				);
				if ( ! matching_link_element ) {
					return true;
				}

				// Do nothing if GTM is blocked for some reason. If only a Google
				// Tag is loaded, it also populates the google_tag_manager object.
				if ( 'undefined' === typeof google_tag_manager ) {
					return true;
				}

				const download_el = matching_link_element.closest(
					'.edd_download, .edd-blocks__download'
				);
				const productdata_el =
					download_el &&
					download_el.querySelector( '.gtm4wp_edd_productdata' );

				const productdata = gtm4wp_read_json_from_node(
					productdata_el,
					'gtm4wp_product_data',
					[ 'internal_id' ]
				);
				if ( ! productdata ) {
					return true;
				}

				// Only act on links pointing to the download detail page.
				if (
					productdata.productlink !==
					matching_link_element.getAttribute( 'href' )
				) {
					return true;
				}

				// #405 (opt-in): persist the list attribution keyed by download
				// id; internal_id was excluded above, so read it from the node.
				if (
					gtm4wp_edd_list_attribution_enabled() &&
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

				// First container id: eventCallback runs once per container and
				// the redirect must run once.
				for ( const i in window.google_tag_manager ) {
					if ( 'gtm-' === i.substring( 0, 4 ).toLowerCase() ) {
						window.gtm4wp_first_container_id = i;
						break;
					}
				}

				// Do not do anything if GTM was not loaded. The google_tag_manager
				// object is still available if only Google Tag is loaded.
				if ( '' === window.gtm4wp_first_container_id ) {
					return true;
				}

				let datalayer_timeout = 2000;
				if ( 'undefined' !== typeof gtm4wp_datalayer_max_timeout ) {
					datalayer_timeout = gtm4wp_datalayer_max_timeout;
				}

				if ( datalayer_timeout > 0 ) {
					const ctrl_key_pressed = e.ctrlKey || e.metaKey;
					const target_new_tab =
						'_blank' === matching_link_element.target;

					// Save this info to prevent redirection if another plugin
					// already prevented the event for some reason.
					const event_already_prevented = e.defaultPrevented;
					if ( ! event_already_prevented ) {
						e.preventDefault();
					}

					if ( ctrl_key_pressed || target_new_tab ) {
						// The new tab has to be opened here so that the popup
						// blocker of the browser does not block it.
						window.productpage_window = window.open(
							'about:blank',
							'_blank'
						);
					}

					const productlink_to_redirect = productdata.productlink;
					delete productdata.productlink;

					gtm4wp_push_ecommerce(
						'select_item',
						[ productdata ],
						{
							currency: gtm4wp_currency,
						},
						function ( container_id ) {
							if (
								'undefined' !== typeof container_id &&
								window.gtm4wp_first_container_id !==
									container_id
							) {
								// only call this for the first loaded container
								return true;
							}

							if ( ! event_already_prevented ) {
								if (
									( target_new_tab || ctrl_key_pressed ) &&
									window.productpage_window
								) {
									window.productpage_window.location.href =
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
			},
			{ capture: true }
		);

		// Change events: price option view_item re-fire, cart quantity deltas.
		document.addEventListener(
			'change',
			function ( e ) {
				if ( ! e.target || ! e.target.closest ) {
					return;
				}

				const option_input = e.target.closest(
					'.edd_price_options input'
				);
				if ( option_input ) {
					gtm4wp_edd_track_price_option_view( option_input );
					return;
				}

				const qty_el = e.target.closest(
					'.edd_cart_item input.edd-item-quantity'
				);
				if ( qty_el ) {
					gtm4wp_edd_track_cart_quantity_change( qty_el );
				}
			},
			{ capture: true }
		);

		// Gateway tracking: EDD announces the AJAX-loaded gateway form with a
		// jQuery event on body.
		if ( window.jQuery ) {
			window
				.jQuery( document.body )
				.on( 'edd_gateway_loaded', function ( event, gateway ) {
					gtm4wp_edd_gateway_events_seen++;

					// First firing = page init, not a choice; the purchase-button
					// fallback still reports it.
					if ( gtm4wp_edd_gateway_events_seen < 2 ) {
						return;
					}

					gtm4wp_edd_track_payment_info(
						gateway || gtm4wp_edd_selected_gateway()
					);
				} );
		}

		// Fallback on purchase submit (single-gateway checkouts).
		document.addEventListener(
			'click',
			function ( e ) {
				if (
					e.target &&
					e.target.closest &&
					e.target.closest( '#edd-purchase-button' )
				) {
					gtm4wp_edd_track_payment_info(
						gtm4wp_edd_selected_gateway()
					);
				}
			},
			{ capture: true }
		);
	}

	function gtm4wp_edd_page_loading_completed() {
		document.removeEventListener(
			'DOMContentLoaded',
			gtm4wp_edd_page_loading_completed
		);
		window.removeEventListener( 'load', gtm4wp_edd_page_loading_completed );
		gtm4wp_edd_process_pages();
	}

	// code and idea borrowed from jQuery:
	// https://github.com/jquery/jquery/blob/main/src/core/ready.js
	if ( document.readyState !== 'loading' ) {
		window.setTimeout( gtm4wp_edd_process_pages );
	} else {
		document.addEventListener(
			'DOMContentLoaded',
			gtm4wp_edd_page_loading_completed
		);
		window.addEventListener( 'load', gtm4wp_edd_page_loading_completed );
	}
}
