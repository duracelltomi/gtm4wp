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

const CART_STORE = 'wc/store/cart';
const PAYMENT_STORE = 'wc/store/payment';

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
