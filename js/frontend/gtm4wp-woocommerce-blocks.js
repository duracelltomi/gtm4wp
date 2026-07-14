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
 *
 * view_cart, begin_checkout and purchase are already emitted server-side, so
 * they are intentionally not duplicated here. The GA4 item for each line comes
 * from extensions.gtm4wp.item (registered by StoreApiData) as a proper float
 * price, so no minor-unit math is needed.
 */

import { select, subscribe } from '@wordpress/data';
import {
	gtm4wp_normalize_cart_items,
	gtm4wp_diff_cart_items,
	gtm4wp_selected_shipping_tier,
} from './lib/gtm4wp-blocks-cart-diff';

const CART_STORE = 'wc/store/cart';
const PAYMENT_STORE = 'wc/store/payment';

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

function gtm4wp_blocks_init() {
	// Guard against a re-injected bundle double-subscribing.
	if ( window.gtm4wp_woocommerce_blocks_inited ) {
		return;
	}
	window.gtm4wp_woocommerce_blocks_inited = true;

	if ( typeof subscribe !== 'function' ) {
		return;
	}

	// Baseline of the last seen cart, so the first resolved snapshot does not
	// report already-present items as additions.
	let cart_baseline = null;
	let shipping_fired = false;
	let payment_fired = false;

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

			if ( added.length ) {
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

		// Checkout steps only fire when the payment store is present (i.e. on the
		// Checkout block), each once per checkout.
		const payment_store = gtm4wp_safe_select( PAYMENT_STORE );
		if ( ! payment_store || ! current.length ) {
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
