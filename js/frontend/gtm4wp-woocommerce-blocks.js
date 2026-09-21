/**
 * WooCommerce Cart & Checkout block tracker. The React blocks fire none of
 * the classic jQuery events, so this bundle reads the block data stores
 * (wc/store/cart, wc/store/payment) and fires add_to_cart / remove_from_cart,
 * add_shipping_info, add_payment_info and the cross-sell view_item_list /
 * select_item. view_cart, begin_checkout and purchase are server-side. Items
 * come from extensions.gtm4wp.item (StoreApiData) with a float price.
 *
 * Contexts (window.gtm4wp_blocks_context, set by PHP):
 *   - "cart": owns the cart and cross-sell events, never the checkout steps
 *     (the payment store is registered on the Cart page too, #463);
 *   - "checkout": owns every event;
 *   - "minicart" (any other page): remove_from_cart only; the classic tracker
 *     keeps sole ownership of add_to_cart;
 *   - "cartcheckout": the merged 2.0.0 value, honored for cached pages, with
 *     the old payment-store heuristic for the checkout steps.
 */

import { select, subscribe } from '@wordpress/data';
import {
	gtm4wp_normalize_cart_items,
	gtm4wp_diff_cart_items,
	gtm4wp_normalize_crosssell_items,
	gtm4wp_selected_shipping_tier,
} from './lib/gtm4wp-blocks-cart-diff';
import { gtm4wp_read_cookie } from './lib/gtm4wp-cookies';

const CART_STORE = 'wc/store/cart';
const PAYMENT_STORE = 'wc/store/payment';

// Dispatched on window after every Interactivity API cart change; drives the
// fallback below.
const CART_SYNC_EVENT = 'wc-blocks_store_sync_required';

// Present while the cart holds something: "does this visitor have a cart"
// without reading the cart. The same pair PHP checks for the fragments channel.
const WC_CART_COOKIES = [
	'woocommerce_items_in_cart',
	'woocommerce_cart_hash',
];

// How long the fallback waits for wc/store/cart to appear (the block scripts
// register their stores after this deferred bundle runs), in milliseconds.
const CART_STORE_WAIT = 2000;

// GA4 list identity for the Cart block cross-sells.
const CROSS_SELL_LIST_NAME = 'Cross-Sells';
const CROSS_SELL_LIST_ID = 'cross-sells';

/**
 * Reads a data store, returning null instead of throwing when it is not (yet)
 * registered - the block scripts may register their stores after this deferred
 * bundle first runs.
 *
 * @param {string} store_name The data store name.
 * @return {Object|null} The store's selectors, or null.
 */
function gtm4wp_safe_select( store_name ) {
	try {
		return select( store_name ) || null;
	} catch ( e ) {
		return null;
	}
}

/**
 * Pushes an ecommerce event through the shared 1.x helper.
 *
 * @param {string} event_name   The GA4 event name.
 * @param {Array}  items        The GA4 items.
 * @param {Object} extra_params Currency and other event-level fields.
 * @return {void}
 */
function gtm4wp_blocks_push( event_name, items, extra_params ) {
	if ( typeof window.gtm4wp_push_ecommerce === 'function' ) {
		window.gtm4wp_push_ecommerce( event_name, items, extra_params );
	}
}

/**
 * Turns a normalized { item, quantity } entry into a GA4 item object.
 *
 * @param {{item: Object, quantity: number}} entry The normalized entry.
 * @return {Object} The GA4 item with its quantity.
 */
function gtm4wp_blocks_to_item( entry ) {
	const item = Object.assign( {}, entry.item, { quantity: entry.quantity } );
	delete item.internal_id;
	return item;
}

/**
 * Sums item.price * quantity across a list of normalized entries.
 *
 * @param {Array} list The normalized entries.
 * @return {number} The total value.
 */
function gtm4wp_blocks_value( list ) {
	return list.reduce(
		( sum, entry ) =>
			sum + ( parseFloat( entry.item.price ) || 0 ) * entry.quantity,
		0
	);
}

/**
 * Resolves the cart currency, falling back to the global set by the PHP side.
 *
 * @param {Object} cart_data The getCartData() object.
 * @return {string|undefined} The currency code.
 */
function gtm4wp_blocks_currency( cart_data ) {
	if ( cart_data && cart_data.totals && cart_data.totals.currency_code ) {
		return cart_data.totals.currency_code;
	}

	return typeof gtm4wp_currency !== 'undefined' ? gtm4wp_currency : undefined;
}

/**
 * Turns a normalized cross-sell entry into a GA4 item tagged with the cross-sell
 * list identity.
 *
 * @param {{item: Object}} entry The normalized cross-sell entry.
 * @return {Object} The GA4 item.
 */
function gtm4wp_blocks_to_crosssell_item( entry ) {
	const item = Object.assign( {}, entry.item, {
		item_list_name: CROSS_SELL_LIST_NAME,
		item_list_id: CROSS_SELL_LIST_ID,
	} );
	// internal_id must never reach GA4.
	delete item.internal_id;
	return item;
}

/**
 * Delegated click listener firing select_item for a cross-sell product link in
 * the Cart block; the href is matched against the normalized permalinks.
 *
 * @param {Function} get_items Returns the current normalized cross-sell list.
 * @return {void}
 */
function gtm4wp_blocks_bind_crosssell_clicks( get_items ) {
	if ( typeof document === 'undefined' || ! document.addEventListener ) {
		return;
	}

	document.addEventListener( 'click', ( e ) => {
		const target = e.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		const link = target.closest(
			'.wp-block-woocommerce-cart-cross-sells-block a[href], .wp-block-cart-cross-sells-product a[href]'
		);
		if ( ! link ) {
			return;
		}

		const href = link.getAttribute( 'href' );
		const match = get_items().find( ( entry ) => entry.permalink === href );
		if ( ! match ) {
			return;
		}

		// #405 (opt-in): persist the cross-sell list attribution like the classic
		// select_item does. The flag is a top-level `const` printed by PHP, so it
		// never lands on `window` and must be read bare (see .eslintrc.js).
		if (
			typeof gtm4wp_list_attribution !== 'undefined' &&
			gtm4wp_list_attribution &&
			match.id &&
			typeof window.gtm4wp_store_item_list_attribution === 'function'
		) {
			window.gtm4wp_store_item_list_attribution(
				match.id,
				CROSS_SELL_LIST_NAME,
				CROSS_SELL_LIST_ID
			);
		}

		gtm4wp_blocks_push(
			'select_item',
			[ gtm4wp_blocks_to_crosssell_item( match ) ],
			{ currency: gtm4wp_blocks_currency() }
		);
	} );
}

/**
 * Whether this browser already has a WooCommerce cart (from WC's own cookies).
 *
 * @return {boolean} Whether a cart is present.
 */
function gtm4wp_blocks_visitor_has_cart() {
	return WC_CART_COOKIES.some( function ( cookie_name ) {
		return '' !== gtm4wp_read_cookie( cookie_name );
	} );
}

/**
 * Reads the current cart from the Store API (same shape as wc/store/cart, GA4
 * extension included; the session cookie identifies the cart). Any failure
 * resolves to null and the caller keeps its snapshot: a lost request costs an
 * event, never reports a wrong one.
 *
 * @return {Promise<Object|null>} The cart response, or null.
 */
function gtm4wp_blocks_fetch_cart() {
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
		.then( function ( cart_data ) {
			// A body that is not a cart is no reading, not an empty cart (which
			// would diff as every item removed).
			return cart_data && Array.isArray( cart_data.items )
				? cart_data
				: null;
		} )
		.catch( function () {
			return null;
		} );
}

/**
 * Tracks cart changes on a store that never registers wc/store/cart: the
 * Interactivity API blocks keep their cart in a private store but announce
 * every change, so the cart is read back from the Store API and diffed like
 * the data store path. Same ownership: add_to_cart only on the block Cart and
 * Checkout pages.
 *
 * @param {boolean}  is_cartcheckout Whether this is the block Cart or Checkout page.
 * @param {Function} store_is_live   Tells whether the data store answered after all.
 * @return {void}
 */
function gtm4wp_blocks_init_store_api_fallback(
	is_cartcheckout,
	store_is_live
) {
	// Null = not established yet; the first refresh only records the cart.
	let cart_baseline = null;
	let refresh_running = false;
	let refresh_again = false;

	const refresh = function () {
		if ( store_is_live() ) {
			return;
		}

		if ( refresh_running ) {
			// A change during an in-flight read: read once more afterwards
			// instead of racing two reads.
			refresh_again = true;
			return;
		}

		refresh_running = true;

		gtm4wp_blocks_fetch_cart().then( function ( cart_data ) {
			refresh_running = false;

			if ( cart_data ) {
				const current = gtm4wp_normalize_cart_items( cart_data.items );

				if ( null === cart_baseline ) {
					cart_baseline = current;
				} else {
					const { added, removed } = gtm4wp_diff_cart_items(
						cart_baseline,
						current
					);
					cart_baseline = current;

					const currency = gtm4wp_blocks_currency( cart_data );

					if ( is_cartcheckout && added.length ) {
						gtm4wp_blocks_push(
							'add_to_cart',
							added.map( gtm4wp_blocks_to_item ),
							{ currency, value: gtm4wp_blocks_value( added ) }
						);
					}

					if ( removed.length ) {
						gtm4wp_blocks_push(
							'remove_from_cart',
							removed.map( gtm4wp_blocks_to_item ),
							{ currency, value: gtm4wp_blocks_value( removed ) }
						);
					}
				}
			}

			if ( refresh_again ) {
				refresh_again = false;
				refresh();
			}
		} );
	};

	// Read the baseline only for a visitor who already has a cart (nothing can
	// be removed from an empty one), keeping the request off every other page
	// view.
	window.setTimeout( function () {
		if ( ! store_is_live() && gtm4wp_blocks_visitor_has_cart() ) {
			refresh();
		}
	}, CART_STORE_WAIT );

	window.addEventListener( CART_SYNC_EVENT, refresh );
}

function gtm4wp_blocks_init() {
	// Guard against a re-injected bundle double-subscribing.
	if ( window.gtm4wp_woocommerce_blocks_inited ) {
		return;
	}
	window.gtm4wp_woocommerce_blocks_inited = true;

	if ( typeof subscribe !== 'function' ) {
		return;
	}

	// The surface (see the header); the merged "cartcheckout" is the default
	// for back compatibility.
	const context =
		typeof window.gtm4wp_blocks_context === 'string'
			? window.gtm4wp_blocks_context
			: 'cartcheckout';
	// #463: only the explicit checkout context fires the checkout steps.
	const is_checkout_context =
		'checkout' === context || 'cartcheckout' === context;
	const is_cartcheckout = is_checkout_context || 'cart' === context;

	// Last seen cart, so the first snapshot does not report additions.
	let cart_baseline = null;
	let shipping_fired = false;
	let payment_fired = false;

	// Latest normalized cross-sells (for the click listener); view_item_list
	// once per page.
	let crosssell_items = [];
	let crosssell_list_fired = false;

	if ( is_cartcheckout ) {
		gtm4wp_blocks_bind_crosssell_clicks( () => crosssell_items );
	}

	// Stays false on an Interactivity API store, which hands the cart events to
	// the fallback; both paths are registered since the two kinds of store
	// cannot be told apart up front.
	let cart_store_answered = false;

	gtm4wp_blocks_init_store_api_fallback(
		is_cartcheckout,
		() => cart_store_answered
	);

	subscribe( () => {
		const cart_store = gtm4wp_safe_select( CART_STORE );
		if ( ! cart_store || typeof cart_store.getCartData !== 'function' ) {
			return;
		}

		if (
			typeof cart_store.hasFinishedResolution === 'function' &&
			! cart_store.hasFinishedResolution( 'getCartData' )
		) {
			return;
		}

		const cart_data = cart_store.getCartData();
		if ( ! cart_data ) {
			return;
		}

		cart_store_answered = true;

		const current = gtm4wp_normalize_cart_items( cart_data.items );
		const currency = gtm4wp_blocks_currency( cart_data );

		// add_to_cart / remove_from_cart from the net cart diff.
		if ( cart_baseline === null ) {
			cart_baseline = current;
		} else {
			const { added, removed } = gtm4wp_diff_cart_items(
				cart_baseline,
				current
			);
			cart_baseline = current;

			// The classic tracker owns add_to_cart on minicart pages.
			if ( is_cartcheckout && added.length ) {
				gtm4wp_blocks_push(
					'add_to_cart',
					added.map( gtm4wp_blocks_to_item ),
					{ currency, value: gtm4wp_blocks_value( added ) }
				);
			}

			if ( removed.length ) {
				gtm4wp_blocks_push(
					'remove_from_cart',
					removed.map( gtm4wp_blocks_to_item ),
					{ currency, value: gtm4wp_blocks_value( removed ) }
				);
			}
		}

		// Cross-sells: view_item_list once, list kept fresh for select_item.
		if ( is_cartcheckout ) {
			crosssell_items = gtm4wp_normalize_crosssell_items(
				cart_data.crossSells
			);

			if ( ! crosssell_list_fired && crosssell_items.length ) {
				crosssell_list_fired = true;
				gtm4wp_blocks_push(
					'view_item_list',
					crosssell_items.map( gtm4wp_blocks_to_crosssell_item ),
					{ currency }
				);
			}
		}

		// Checkout steps: checkout context only, once each. The payment store is
		// still required (active method, and the legacy context's heuristic).
		const payment_store = gtm4wp_safe_select( PAYMENT_STORE );
		if ( ! is_checkout_context || ! payment_store || ! current.length ) {
			return;
		}

		const value = gtm4wp_blocks_value( current );

		if ( ! shipping_fired ) {
			const shipping_tier = gtm4wp_selected_shipping_tier( cart_data );
			if ( shipping_tier ) {
				shipping_fired = true;
				gtm4wp_blocks_push(
					'add_shipping_info',
					current.map( gtm4wp_blocks_to_item ),
					{ currency, shipping_tier, value }
				);
			}
		}

		if (
			! payment_fired &&
			typeof payment_store.getActivePaymentMethod === 'function'
		) {
			const payment_type = payment_store.getActivePaymentMethod();
			if ( payment_type ) {
				payment_fired = true;
				gtm4wp_blocks_push(
					'add_payment_info',
					current.map( gtm4wp_blocks_to_item ),
					{ currency, payment_type, value }
				);
			}
		}
	} );
}

gtm4wp_blocks_init();
