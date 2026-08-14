/**
 * GTM4WP Easy Digital Downloads frontend tracker.
 *
 * Fires the client-side GA4 e-commerce events of the EDD integration:
 * view_item_list (impressions of the [downloads] grid and the EDD downloads
 * block), select_item, add_to_cart (buy button clicks incl. Buy Now),
 * remove_from_cart (cart row remove links - classic templates, the checkout
 * block and the full cart block), add_payment_info (gateway selection) and
 * the view_item re-fire when the buyer picks a price option of a
 * variable-priced download on its detail page.
 *
 * Reads the hidden markup emitted by the ListTracking PHP class:
 * span.gtm4wp_edd_productdata (grid items), input[name=gtm4wp_product_data]
 * (purchase forms) and span.gtm4wp_edd_cartitemdata (checkout cart rows).
 * The classes deliberately differ from the WooCommerce tracker's so a site
 * running both store plugins never counts an element twice.
 *
 * The jQuery dependency is on purpose: EDD core triggers its gateway event
 * (edd_gateway_loaded) through jQuery's own event system, which vanilla
 * listeners can not observe.
 */

window.gtm4wp_first_container_id = window.gtm4wp_first_container_id || '';

// Payment info is reported once per selected gateway, mirroring the
// WooCommerce tracker's checkout-step dedupe.
const gtm4wp_edd_payment_info_fired = [];

// EDD fires edd_gateway_loaded once while the checkout page initializes;
// only a later firing means the buyer actively picked a gateway.
let gtm4wp_edd_gateway_events_seen = 0;

/**
 * Whether the opt-in GA4 list-attribution persistence (#405) is on. The flag
 * is printed as a top-level const, so it binds lexically and must be read as
 * a bare identifier - `window.gtm4wp_list_attribution` is permanently
 * undefined.
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
 * Exposed on window so a theme with a custom add-to-cart flow can fire the
 * event from its own handler without duplicating the tracker.
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

	// #405: the buy-button markup is baked into cacheable HTML, so the list
	// this visitor came from is merged client-side from the first-party
	// cookie, keyed by the download id (internal_id was excluded above, so
	// read it separately). Opt-in only.
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
		// Variable prices: one item per checked price option (radio in single
		// mode, checkboxes in multi mode), carrying the option's price and
		// name as the GA4 item_variant.
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
 * Re-fires the GA4 view_item event with the picked price option when the
 * buyer selects an option of a variable-priced download on its own detail
 * page - the EDD counterpart of the WooCommerce tracker's found_variation
 * handling. The PHP data layer sets window.gtm4wp_edd_variable_view_item on
 * singular download pages only, and forms inside grid items (the [downloads]
 * shortcode or the EDD downloads block) are ignored even there: interacting
 * with a grid item stays list territory (select_item / add_to_cart).
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

	// #405: merge the originating list onto the re-fired view_item from the
	// first-party cookie, keyed by the download id. Opt-in only.
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
 * Fires the GA4 add_payment_info event for the given gateway once. Uses the
 * checkout products the PHP data layer exposed on window.
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
 * Returns the currently selected gateway slug on the checkout page, or an
 * empty string when there is none (single-gateway checkouts render no
 * payment-mode selector).
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

			// Track remove links in cart rows. The classic checkout cart and
			// the EDD checkout block both render edd_cart_remove_item_btn
			// links; the cart block's full cart renders AJAX
			// edd-remove-from-cart links instead. Classic rows are tr
			// elements, block rows are divs - both carry .edd_cart_item.
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

				gtm4wp_push_ecommerce( 'remove_from_cart', [ productdata ], {
					currency: gtm4wp_currency,
					value: productdata.price * ( productdata.quantity || 1 ),
				} );

				return true;
			}

			// Track clicks in download grids (select_item): the [downloads]
			// shortcode wraps items in .edd_download, the EDD downloads block
			// in article.edd-blocks__download.
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

			// #405: persist this list attribution (keyed by download id) so
			// the later view_item / add_to_cart / checkout / purchase events
			// can be attributed back to the originating list. internal_id was
			// excluded above, so read it straight from the node. Opt-in only.
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

			// Look at the first GTM container ID in case there are multiple GTM
			// containers live on the page, since eventCallback is called once per
			// container and the redirect must only run once.
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
							window.gtm4wp_first_container_id !== container_id
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

	// Re-fire view_item with the picked price option of the page's main
	// download (variable-priced downloads on their own detail page).
	document.addEventListener(
		'change',
		function ( e ) {
			const option_input =
				e.target &&
				e.target.closest &&
				e.target.closest( '.edd_price_options input' );

			if ( option_input ) {
				gtm4wp_edd_track_price_option_view( option_input );
			}
		},
		{ capture: true }
	);

	// Checkout gateway tracking. EDD loads the selected gateway's form
	// fragment over AJAX and announces it with a jQuery event on body, so
	// jQuery is required to observe it.
	if ( window.jQuery ) {
		window
			.jQuery( document.body )
			.on( 'edd_gateway_loaded', function ( event, gateway ) {
				gtm4wp_edd_gateway_events_seen++;

				// The first firing is the checkout page initializing its
				// default gateway, not a buyer choice; the purchase-button
				// fallback below still reports it if the buyer submits without
				// ever switching.
				if ( gtm4wp_edd_gateway_events_seen < 2 ) {
					return;
				}

				gtm4wp_edd_track_payment_info(
					gateway || gtm4wp_edd_selected_gateway()
				);
			} );
	}

	// Fallback: report the payment info at the latest when the buyer submits
	// the purchase form (covers single-gateway checkouts where
	// edd_gateway_loaded only fires while the page initializes).
	document.addEventListener(
		'click',
		function ( e ) {
			if (
				e.target &&
				e.target.closest &&
				e.target.closest( '#edd-purchase-button' )
			) {
				gtm4wp_edd_track_payment_info( gtm4wp_edd_selected_gateway() );
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
