import { gtm4wp_parse_block_item } from './lib/gtm4wp-blocks-cart-diff';

let gtm4wp_last_selected_product_variation;

// #82: de-dupe state read by the document-level listeners, so it must survive a
// re-injected bundle (the #71 double-init guard lives inside
// gtm4wp_woocommerce_process_pages() and protects nothing above it). Initialize
// only when absent; `x = x || default` is wrong for gtm4wp_first_container_id,
// whose legitimate value can be 0.
if ( 'undefined' === typeof window.gtm4wp_view_item_fired_during_pageload ) {
	window.gtm4wp_view_item_fired_during_pageload = false;
}

// step 1 will be the billing section which is reported during pageload, no need to handle here
if ( 'undefined' === typeof window.gtm4wp_checkout_step_fired ) {
	window.gtm4wp_checkout_step_fired = [];
}

if ( 'undefined' === typeof window.gtm4wp_first_container_id ) {
	window.gtm4wp_first_container_id = '';
}

/**
 * Read a quantity out of the DOM as a number, or null when there is nothing usable.
 * Convert first, test the number after: the element may be absent (isNaN( null )
 * is false) and its value is a string (`|| 1` lets '0' through). Callers decide
 * what absent/zero means for their event (RI-16, #69 / #79).
 *
 * @param {HTMLElement|null} el   Element carrying the quantity, may be null.
 * @param {string}           prop Property to read - 'value' or 'textContent'.
 * @return {number|null} The parsed quantity, or null when it cannot be read.
 */
function gtm4wp_read_quantity( el, prop ) {
	const qty = parseInt( el && el[ prop ], 10 );

	return Number.isNaN( qty ) ? null : qty;
}

/**
 * Whether the #405 "Persist product list attribution across the funnel" opt-in is on.
 * PHP prints the flag (like every GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY entry) as a
 * top-level `const`, which binds lexically and never becomes a `window` property:
 * `window.gtm4wp_list_attribution` is always undefined (.eslintrc.js forbids that
 * spelling). The typeof guard covers a head block that never ran. Evaluated per
 * call, not at module scope, so the result depends on the option, not on script
 * order.
 *
 * @return {boolean} Whether list attribution should be stored and applied.
 */
function gtm4wp_list_attribution_enabled() {
	return (
		'undefined' !== typeof gtm4wp_list_attribution &&
		!! gtm4wp_list_attribution
	);
}

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

					gtm4wp_push_ecommerce( 'add_to_cart', [ productdata ], {
						currency: gtm4wp_currency, // ga4 version
						value: productdata.price * productdata.quantity,
					} );
				} else {
					// no => handle remove from cart event
					productdata.quantity = original_value - current_value;

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
 * Reads the step identifier from a CheckoutWC cfw_step_changed event (#385). The
 * payload shape is not verified against a live install, so the likely places
 * (string detail, or detail.step / current / to / name) are tried.
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
 * page (variable, grouped and simple). Exposed on window so a theme with its own
 * AJAX add to cart (whose preventDefault() stops the click tracking) can fire it
 * from its success handler (#273).
 *
 * @param {Element} trigger_element The clicked add-to-cart button (or a descendant).
 * @param {Element} [product_form]  The product's form.cart; derived from the button when omitted.
 * @param {Object}  [options]       Optional { emit }: replaces the dataLayer push so
 *                                  the block path can hold the event until the add
 *                                  is confirmed.
 * @return {boolean} Whether an add_to_cart event was tracked.
 */
function gtm4wp_track_single_add_to_cart(
	trigger_element,
	product_form,
	options
) {
	if ( ! trigger_element || ! trigger_element.closest ) {
		return false;
	}

	const emit =
		options && 'function' === typeof options.emit
			? options.emit
			: gtm4wp_push_ecommerce;

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

	// No add_to_cart when the browser would block the submit for unfilled
	// required fields (Product Add-ons): nothing was added (#274).
	if ( typeof form.checkValidity === 'function' && ! form.checkValidity() ) {
		return false;
	}

	const product_variant_id = form.querySelectorAll( '[name=variation_id]' );
	const product_is_grouped =
		form.classList && form.classList.contains( 'grouped_form' );

	if ( product_variant_id.length > 0 ) {
		if ( gtm4wp_last_selected_product_variation ) {
			const variation_qty = gtm4wp_read_quantity(
				form.querySelector( '[name=quantity]' ),
				'value'
			);
			// No quantity field, or zero: one unit.
			gtm4wp_last_selected_product_variation.quantity =
				null === variation_qty || variation_qty < 1 ? 1 : variation_qty;

			emit( 'add_to_cart', [ gtm4wp_last_selected_product_variation ], {
				currency: gtm4wp_currency,
				value: (
					gtm4wp_last_selected_product_variation.price *
					gtm4wp_last_selected_product_variation.quantity
				).toFixed( 2 ),
			} );
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
				product_qty = gtm4wp_read_quantity(
					product_qty_input[ 0 ],
					'value'
				);
			} else {
				return true;
			}

			// A row left at zero was not ordered; an unreadable field is one unit.
			if ( 0 === product_qty ) {
				return true;
			}
			productdata.quantity =
				null === product_qty || product_qty < 1 ? 1 : product_qty;

			// #405: carry the originating list onto this add_to_cart item (opt-in).
			if ( gtm4wp_list_attribution_enabled() ) {
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

		emit( 'add_to_cart', products, {
			currency: gtm4wp_currency,
			value: sum_value.toFixed( 2 ),
		} );
	} else {
		// The hidden span is the current markup: an input inside the form flips
		// WooCommerce's blockified add-to-cart form into legacy POST mode (#462).
		// The input is still read so a cached page from an older version tracks.
		const product_data_el = form.querySelector(
			'.gtm4wp_single_productdata,[name=gtm4wp_product_data]'
		);
		if ( ! product_data_el ) {
			return false;
		}

		// internal_id is kept for the #405 list lookup and deleted before the push.
		const productdata = gtm4wp_read_from_json(
			( product_data_el.dataset &&
				product_data_el.dataset.gtm4wp_product_data ) ||
				product_data_el.value,
			[ 'productlink' ]
		);
		// #190: false when the payload cannot be parsed (empty attribute when
		// wp_json_encode() refused the array, "null" from a null-returning filter).
		if ( ! productdata ) {
			return false;
		}
		// #69: a form without a quantity field is one unit, not quantity: null.
		const simple_qty = gtm4wp_read_quantity(
			form.querySelector( '[name=quantity]' ),
			'value'
		);
		productdata.quantity =
			null === simple_qty || simple_qty < 1 ? 1 : simple_qty;

		// #405: carry the originating list onto this add_to_cart item (opt-in).
		if ( gtm4wp_list_attribution_enabled() ) {
			gtm4wp_apply_stored_item_list(
				productdata,
				productdata.internal_id
			);
		}
		delete productdata.internal_id;

		emit( 'add_to_cart', [ productdata ], {
			currency: gtm4wp_currency,
			value: productdata.price * productdata.quantity,
		} );
	}

	return true;
}

/**
 * How long a queued block add_to_cart waits for WooCommerce to confirm the add
 * before it is thrown away, in milliseconds.
 */
const GTM4WP_BLOCK_ADD_TO_CART_TIMEOUT = 10000;

// The add_to_cart of the most recent block add-to-cart click, held back until
// WooCommerce confirms the add; a failed click is replaced by the next one.
let gtm4wp_pending_block_add_to_cart = null;
let gtm4wp_pending_block_add_to_cart_timer = null;

/**
 * The product form around an add-to-cart button when WooCommerce rendered it
 * with the Interactivity API (Add to Cart + Options block): the classic POST
 * form carries the `cart` class, the interactive one does not, which is why the
 * `form.cart` lookup of the classic path finds nothing there.
 *
 * @param {Element} trigger_element The clicked add-to-cart button.
 * @return {Element|null} The interactive product form, or null for the classic one.
 */
function gtm4wp_interactive_product_form( trigger_element ) {
	const form = trigger_element.closest( 'form' );

	if ( ! form || ( form.classList && form.classList.contains( 'cart' ) ) ) {
		return null;
	}

	return form;
}

/**
 * Resolves the GA4 item of a product just added by reading the cart back from
 * the Store API and taking its line. Used for a variable product on an
 * interactive product page: that form dispatches no found_variation event, and
 * the cart line carries the server-built item of the variation itself.
 *
 * @param {number} product_id The product or variation id whose cart line to find.
 * @return {Promise<Object|null>} The GA4 item, or null when it cannot be resolved.
 */
function gtm4wp_fetch_cart_item( product_id ) {
	const cart_url =
		'string' === typeof window.gtm4wp_store_api_cart_url
			? window.gtm4wp_store_api_cart_url
			: '';

	if ( '' === cart_url || 'function' !== typeof window.fetch ) {
		return Promise.resolve( null );
	}

	return window
		.fetch( cart_url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		} )
		.then( function ( response ) {
			return response && response.ok ? response.json() : null;
		} )
		.then( function ( cart ) {
			if ( ! cart || ! Array.isArray( cart.items ) ) {
				return null;
			}

			const line = cart.items.find( function ( cart_item ) {
				return (
					cart_item && String( cart_item.id ) === String( product_id )
				);
			} );

			const extensions =
				line && line.extensions && line.extensions.gtm4wp;

			return extensions
				? gtm4wp_parse_block_item( extensions.item )
				: null;
		} )
		.catch( function () {
			return null;
		} );
}

/**
 * Drops the queued block add_to_cart, if there is one.
 *
 * @return {void}
 */
function gtm4wp_clear_pending_block_add_to_cart() {
	if ( gtm4wp_pending_block_add_to_cart_timer ) {
		window.clearTimeout( gtm4wp_pending_block_add_to_cart_timer );
	}

	gtm4wp_pending_block_add_to_cart = null;
	gtm4wp_pending_block_add_to_cart_timer = null;
}

/**
 * Builds the add_to_cart event for a click on a block add-to-cart button and
 * holds it until wc-blocks_added_to_cart confirms the Store API add (the item
 * can be refused, or the block's validation can stop the submit). An add that
 * never succeeds is dropped after GTM4WP_BLOCK_ADD_TO_CART_TIMEOUT.
 *
 * @param {Element} trigger_element The clicked add-to-cart button.
 * @param {Element} product_form    The interactive product form around it.
 * @return {boolean} Whether an event was queued.
 */
function gtm4wp_queue_block_add_to_cart( trigger_element, product_form ) {
	gtm4wp_clear_pending_block_add_to_cart();

	const queued = [];
	gtm4wp_track_single_add_to_cart( trigger_element, product_form, {
		emit( event_name, items, extra_params ) {
			queued.push( [ event_name, items, extra_params ] );
		},
	} );

	let pending = queued.length ? { pushes: queued } : null;

	if ( ! pending ) {
		// Nothing built from the page = a variable product (no found_variation on
		// an interactive form): complete the event from the cart line afterwards.
		const variation_el = product_form.querySelector(
			'[name=variation_id]'
		);
		const variation_id = parseInt( variation_el && variation_el.value, 10 );

		if ( ! variation_id ) {
			return false;
		}

		const variation_qty = gtm4wp_read_quantity(
			product_form.querySelector( '[name=quantity]' ),
			'value'
		);

		pending = {
			lookup: {
				product_id: variation_id,
				quantity:
					null === variation_qty || variation_qty < 1
						? 1
						: variation_qty,
			},
		};
	}

	gtm4wp_pending_block_add_to_cart = pending;
	gtm4wp_pending_block_add_to_cart_timer = window.setTimeout(
		gtm4wp_clear_pending_block_add_to_cart,
		GTM4WP_BLOCK_ADD_TO_CART_TIMEOUT
	);

	return true;
}

/**
 * Pushes the queued block add_to_cart, now that WooCommerce has confirmed it.
 *
 * @return {void}
 */
function gtm4wp_flush_pending_block_add_to_cart() {
	const pending = gtm4wp_pending_block_add_to_cart;
	gtm4wp_clear_pending_block_add_to_cart();

	if ( ! pending ) {
		return;
	}

	if ( pending.pushes ) {
		pending.pushes.forEach( function ( push_arguments ) {
			gtm4wp_push_ecommerce(
				push_arguments[ 0 ],
				push_arguments[ 1 ],
				push_arguments[ 2 ]
			);
		} );

		return;
	}

	// Variable product: item from the cart line, quantity from the form (the line
	// also counts what was already in the cart).
	gtm4wp_fetch_cart_item( pending.lookup.product_id ).then(
		function ( item ) {
			if ( ! item ) {
				return;
			}

			item.quantity = pending.lookup.quantity;
			delete item.internal_id;

			gtm4wp_push_ecommerce( 'add_to_cart', [ item ], {
				currency: gtm4wp_currency,
				value: ( item.price * item.quantity ).toFixed( 2 ),
			} );
		}
	);
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
	// Double-init guard (#71): a re-injected bundle would attach every
	// document-level listener below twice and double-push every event.
	if ( window.gtm4wp_woocommerce_inited ) {
		return;
	}
	window.gtm4wp_woocommerce_inited = true;

	// GA4 list identity of WooCommerce's legacy product grid blocks (U99).
	// woocommerce_blocks_product_grid_item_html carries no block context, so
	// ListTracking::add_productdata_to_wc_block() writes the generic "General
	// Product List" pair; the container's wp-block-{block_name} class
	// (AbstractProductGrid::get_container_classes()) resolves it here. BOTH
	// halves are written back: a name without its matching id collapses every
	// grid onto one id in GA4. listid is sanitize_title( displayname ) computed
	// once, never slugified in JS, so it stays byte-identical to the id the
	// Product Collection path gives the same list.
	const gtm4wp_product_block_names = {
		'wp-block-handpicked-products': {
			displayname: 'Handpicked Products',
			listid: 'handpicked-products',
			counter: 1,
		},
		'wp-block-product-best-sellers': {
			displayname: 'Best Selling Products',
			listid: 'best-selling-products',
			counter: 1,
		},
		'wp-block-product-category': {
			displayname: 'Product Category List',
			listid: 'product-category-list',
			counter: 1,
		},
		'wp-block-product-new': {
			displayname: 'New Products',
			listid: 'new-products',
			counter: 1,
		},
		'wp-block-product-on-sale': {
			displayname: 'Sale Products',
			listid: 'sale-products',
			counter: 1,
		},
		'wp-block-products-by-attribute': {
			displayname: 'Products By Attribute',
			listid: 'products-by-attribute',
			counter: 1,
		},
		'wp-block-product-tag': {
			displayname: 'Products By Tag',
			listid: 'products-by-tag',
			counter: 1,
		},
		'wp-block-product-top-rated': {
			displayname: 'Top Rated Products',
			listid: 'top-rated-products',
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
							// Always written together with the name.
							gtm4wp_update_json_in_node(
								product_data_el,
								'gtm4wp_product_data',
								'item_list_id',
								gtm4wp_product_block_names[ i ].listid
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
	if ( document.querySelectorAll( '.gtm4wp_productdata' ).length > 0 ) {
		const products = [];

		document
			.querySelectorAll( '.gtm4wp_productdata' )
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

	// WooCommerce dispatches this on document.body once the Store API accepted
	// the item; it releases the held-back add_to_cart. Listened on document
	// because body may be swapped during the page's life.
	document.addEventListener(
		'wc-blocks_added_to_cart',
		gtm4wp_flush_pending_block_add_to_cart
	);

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
				// A list add is reported on the click and confirmed by the same
				// product-less wc-blocks_added_to_cart the held-back form event
				// waits for, so drop that one or it would be released here.
				gtm4wp_clear_pending_block_add_to_cart();
				gtm4wp_track_list_add_to_cart( event_target_element );
			}

			// track add to cart events for products on product detail pages
			const add_to_cart_button = event_target_element.closest(
				'.single_add_to_cart_button'
			);
			if ( add_to_cart_button ) {
				// A block product page adds in the background (wait for the
				// confirmation); the classic form posts the page (the click is
				// the last moment to report).
				const interactive_form =
					gtm4wp_interactive_product_form( add_to_cart_button );

				if ( interactive_form ) {
					gtm4wp_queue_block_add_to_cart(
						add_to_cart_button,
						interactive_form
					);
				} else {
					gtm4wp_track_single_add_to_cart( add_to_cart_button );
				}
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

				// #79: cart page (input value) and mini-cart (textContent) resolve
				// through the same parse, so both report a number and both
				// suppress a zero line.
				let qty = null;
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
						qty = gtm4wp_read_quantity(
							qty_element[ 0 ],
							'textContent'
						);
					}
				} else {
					qty = gtm4wp_read_quantity( qty_element[ 0 ], 'value' );
				}

				// Nothing readable, or a line already at zero: not a removal event.
				if ( null === qty || qty < 1 ) {
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

				// #405 (opt-in): persist the list attribution keyed by product id
				// for the later funnel events; internal_id was excluded above, so
				// read it from the node.
				if (
					gtm4wp_list_attribution_enabled() &&
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
				if ( 'undefined' !== typeof gtm4wp_datalayer_max_timeout ) {
					datalayer_timeout = gtm4wp_datalayer_max_timeout;
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

			// Same span-first, input-fallback pair as the simple product path (#462).
			const product_data_el = product_form.querySelector(
				'.gtm4wp_single_productdata,[name=gtm4wp_product_data]'
			);
			if ( ! product_data_el ) {
				return true;
			}

			let current_product_detail_data;
			try {
				current_product_detail_data = JSON.parse(
					( product_data_el.dataset &&
						product_data_el.dataset.gtm4wp_product_data ) ||
						product_data_el.value
				);
			} catch ( e ) {
				console && console.error && console.error( e.message );
				return true;
			}

			// #190: the parse SUCCEEDS with null when a site filter returned null
			// (the attribute is the literal "null"); treat it as no product data.
			if ( ! current_product_detail_data ) {
				return true;
			}

			current_product_detail_data.price = gtm4wp_make_sure_is_float(
				current_product_detail_data.price
			);

			current_product_detail_data.item_group_id =
				current_product_detail_data.id;
			// Re-apply the remarketing product-id prefix to the variation id the
			// server-prefixed parent id is swapped for (#383); item_id stays
			// unprefixed (server contract), and an unprefixed id keeps its type.
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

			// #405: the list stored the attribution under the parent product id
			// (internal_id); it enriches this view_item and the add_to_cart that
			// reuses this object.
			const list_product_id = current_product_detail_data.internal_id;

			delete current_product_detail_data.internal_id;

			// A product view is one unit (#348); add_to_cart overwrites it later.
			current_product_detail_data.quantity = 1;

			if ( gtm4wp_list_attribution_enabled() ) {
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
							// #405: the server-built Quick View view_item arrives
							// without the list; this payload keeps internal_id.
							if (
								gtm4wp_list_attribution_enabled() &&
								dl_data_obj &&
								dl_data_obj.ecommerce &&
								dl_data_obj.ecommerce.item
							) {
								gtm4wp_apply_stored_item_list(
									dl_data_obj.ecommerce.item,
									dl_data_obj.ecommerce.item.internal_id
								);
							}

							// #66: the data layer name is an option; never
							// hardcode window.dataLayer.
							if (
								dl_data_obj &&
								window[ gtm4wp_datalayer_name ]
							) {
								window[ gtm4wp_datalayer_name ].push(
									dl_data_obj
								);
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

	// CheckoutWC (#385): its multi-step template does not reliably dispatch the
	// classic change / checkout_place_order events, so add_shipping_info and
	// add_payment_info bind to its cfw_step_changed instead. Separate branch:
	// gtm4wp_is_checkout may be false there. The step handlers are idempotent
	// and read the DOM, which CheckoutWC keeps on WooCommerce's field names.
	if ( 'undefined' !== typeof gtm4wp_checkoutwc && gtm4wp_checkoutwc ) {
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
