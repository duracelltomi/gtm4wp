/**
 * WooCommerce Cart & Checkout block tracker.
 *
 * The React-based Cart & Checkout blocks never fire the classic jQuery events
 * the gtm4wp-woocommerce tracker relies on, so this bundle reads the block data
 * stores (wc/store/cart, wc/store/checkout, wc/store/payment) instead and fires
 * the interactive events that would otherwise be lost:
 *
 *   - add_to_cart / remove_from_cart  (cart quantity changes, block add-to-cart)
 *   - add_shipping_info               (shipping rate selected)
 *   - add_payment_info                (payment method selected)
 *   - view_item_list / select_item    (cart cross-sells)
 *
 * view_cart, begin_checkout and purchase are already emitted server-side, so
 * they are intentionally not duplicated here. The GA4 item for each line comes
 * from extensions.gtm4wp.item (registered by StoreApiData) as a proper float
 * price, so no minor-unit math is needed.
 *
 * The tracker runs in one of three contexts, set by the PHP side
 * (window.gtm4wp_blocks_context):
 *
 *   - "cart" (block Cart page): the classic tracker is skipped, so this one owns
 *     the cart and cross-sell events above, but never the checkout steps. The
 *     wc/store/payment store is registered on the Cart page too, so its presence
 *     must not decide them (#463).
 *   - "checkout" (block Checkout page): owns every event above, including
 *     add_shipping_info / add_payment_info.
 *   - "minicart" (any other page on a block store, where the Mini-Cart block is
 *     usually in the header): it fires remove_from_cart only. The classic tracker
 *     runs alongside and keeps sole ownership of add_to_cart, so an item added on
 *     a product page is never counted twice.
 *
 * "cartcheckout", the merged value 2.0.0 sent for both block pages, is still
 * honored so a cached page whose inline context predates the split keeps
 * tracking; it retains the old payment-store heuristic for the checkout steps.
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

// The event WooCommerce dispatches on window after every cart change made
// through the Interactivity API, which is what drives the fallback below.
const CART_SYNC_EVENT = 'wc-blocks_store_sync_required';

// WooCommerce writes these while the cart holds something and clears them when
// it is emptied, so their presence answers "does this visitor have a cart"
// without reading the cart. The same pair the PHP side checks before it loads
// the cart-fragments channel.
const WC_CART_COOKIES = [
	'woocommerce_items_in_cart',
	'woocommerce_cart_hash',
];

// How long the fallback waits for the wc/store/cart data store to appear before
// it takes over, in milliseconds. The block scripts register their stores after
// this deferred bundle first runs, so an immediate decision would be wrong on an
// ordinary block store.
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
	// internal_id is a server-side bookkeeping key that must never reach GA4;
	// strip it here exactly as gtm4wp_blocks_to_item() does for cart lines.
	delete item.internal_id;
	return item;
}

/**
 * Binds a delegated click listener that fires select_item when a cross-sell
 * product link inside the Cart block is clicked. The clicked link's href is
 * matched against the normalized cross-sell permalinks so the exact GA4 item is
 * reported.
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

		// #405: persist the cross-sell list attribution (keyed by product id) so a
		// later add_to_cart / checkout / purchase for this product can be attributed
		// to it, matching the classic select_item behavior. Opt-in only.
		// The opt-in flag is printed by the PHP side as a top-level `const` in a
		// classic inline script, which binds lexically and never lands on `window`
		// - so it has to be read as a bare identifier (see .eslintrc.js).
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
 * Whether this browser already has a WooCommerce cart, read from the cookies
 * WooCommerce maintains for exactly that question.
 *
 * @return {boolean} Whether a cart is present.
 */
function gtm4wp_blocks_visitor_has_cart() {
	return WC_CART_COOKIES.some( function ( cookie_name ) {
		return '' !== gtm4wp_read_cookie( cookie_name );
	} );
}

/**
 * Reads the current cart from the Store API.
 *
 * The response has the same shape as the wc/store/cart data store, GA4 item
 * extension included, so the same normalizer and diff serve both paths. The
 * session cookie identifies the cart, which is why the request is made with
 * credentials. Any failure resolves to null and the caller then leaves its
 * snapshot untouched, so a lost request costs an event rather than reporting a
 * wrong one.
 *
 * @return {Promise<Object|null>} The cart response, or null.
 */
function gtm4wp_blocks_fetch_cart() {
	const cart_url =
		'string' === typeof window.gtm4wp_blocks_cart_url
			? window.gtm4wp_blocks_cart_url
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
		.catch( function () {
			return null;
		} );
}

/**
 * Tracks cart changes on a store that never registers the wc/store/cart data
 * store.
 *
 * WooCommerce is moving its blocks from React and @wordpress/data to the
 * Interactivity API, and the blocks built that way keep their cart in a private
 * store this tracker must not read. What they do announce publicly is that the
 * cart changed, so the cart itself is read back from the Store API and diffed
 * the same way the data store path diffs it. Without this, a store whose product
 * and cart blocks are all Interactivity API based reported no add_to_cart and no
 * remove_from_cart at all.
 *
 * Ownership matches the data store path exactly: add_to_cart only on the block
 * Cart and Checkout pages, because everywhere else the classic tracker owns it.
 *
 * @param {boolean}  is_cartcheckout Whether this is the block Cart or Checkout page.
 * @param {Function} store_is_live   Tells whether the data store answered after all.
 * @return {void}
 */
function gtm4wp_blocks_init_store_api_fallback(
	is_cartcheckout,
	store_is_live
) {
	// The last cart this tracker knows about. Null means "not established yet",
	// and the first refresh in that state only records the cart, since a diff
	// against nothing would report the whole cart as just added.
	let cart_baseline = null;
	let refresh_running = false;
	let refresh_again = false;

	const refresh = function () {
		if ( store_is_live() ) {
			return;
		}

		if ( refresh_running ) {
			// A second change arrived while the first read was in flight. Cart
			// changes queue up behind each other, so read once more afterwards
			// instead of racing two reads against the same cart.
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

	// The baseline is only worth reading for a visitor who already has a cart:
	// with an empty cart there is nothing that could be removed yet, and the
	// first change establishes the baseline on its own. That keeps the request
	// off every page view of every other visitor.
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

	// Which surface the PHP side says this is (the contexts are described in the
	// header comment). The merged "cartcheckout" value is also the default, for
	// back compatibility.
	const context =
		typeof window.gtm4wp_blocks_context === 'string'
			? window.gtm4wp_blocks_context
			: 'cartcheckout';
	// #463: only the explicit checkout context may fire the checkout steps; the
	// payment store exists on the Cart page too, so its presence does not identify
	// the Checkout block. The legacy merged value keeps its old heuristic.
	const is_checkout_context =
		'checkout' === context || 'cartcheckout' === context;
	const is_cartcheckout = is_checkout_context || 'cart' === context;

	// Baseline of the last seen cart, so the first resolved snapshot does not
	// report already-present items as additions.
	let cart_baseline = null;
	let shipping_fired = false;
	let payment_fired = false;

	// Cart cross-sells: the latest normalized list (kept fresh for the click
	// listener) and a guard so view_item_list is reported once per page.
	let crosssell_items = [];
	let crosssell_list_fired = false;

	if ( is_cartcheckout ) {
		gtm4wp_blocks_bind_crosssell_clicks( () => crosssell_items );
	}

	// Whether the wc/store/cart data store ever answered. It stays false on a
	// store whose blocks are built on the Interactivity API, and that is what
	// hands the cart events to the fallback below. Both paths are registered
	// because nothing here can tell the two kinds of store apart up front, and
	// the one that is not in use does nothing.
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

			// add_to_cart is owned by the classic tracker on minicart pages, so
			// only the cartcheckout context reports it (avoids double counting).
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

		// Cart cross-sells (Cart block only). Fire view_item_list once when they
		// first resolve and keep the list fresh for the select_item click listener.
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

		// Checkout steps fire only in the checkout context, each once per checkout.
		// The payment store is still required: the active payment method is read
		// from it, and the legacy merged context has nothing else to tell the two
		// block pages apart.
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
