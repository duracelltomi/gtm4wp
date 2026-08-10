/**
 * Pure helpers for the WooCommerce block (Store API) tracker.
 *
 * These have no dependency on @wordpress/data or the DOM so they can be unit
 * tested directly. The tracker feeds them the raw cart items from the
 * `wc/store/cart` data store and turns the result into dataLayer events.
 */

/**
 * Parses the GA4 item carried on a Store API line at
 * extensions.gtm4wp.item. Product lines expose it as an object, cart lines as a
 * JSON string (cart-item extension values are strings), so both are accepted.
 *
 * @param {Object|string|undefined} raw The extensions.gtm4wp.item value.
 * @return {Object|null} The parsed GA4 item, or null when absent/invalid.
 */
export function gtm4wp_parse_block_item( raw ) {
	if ( ! raw ) {
		return null;
	}

	if ( typeof raw === 'string' ) {
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	return raw;
}

/**
 * Normalizes the raw `wc/store/cart` items array into a compact list of
 * { key, quantity, item } entries, dropping any line without GA4 item data.
 *
 * @param {Array} items The getCartData().items array.
 * @return {Array<{key: string, quantity: number, item: Object}>} Normalized items.
 */
export function gtm4wp_normalize_cart_items( items ) {
	if ( ! Array.isArray( items ) ) {
		return [];
	}

	return items
		.map( ( cart_item ) => {
			const extensions =
				cart_item &&
				cart_item.extensions &&
				cart_item.extensions.gtm4wp;
			const item = extensions
				? gtm4wp_parse_block_item( extensions.item )
				: null;

			if ( ! item ) {
				return null;
			}

			return {
				key: cart_item.key,
				quantity: cart_item.quantity,
				item,
			};
		} )
		.filter( Boolean );
}

/**
 * Diffs two normalized cart snapshots (keyed by cart-item key) into the net
 * quantity added and removed, so a single cart change maps to one add_to_cart
 * and/or one remove_from_cart event.
 *
 * @param {Array} previous The previous normalized snapshot.
 * @param {Array} current  The current normalized snapshot.
 * @return {{added: Array, removed: Array}} Lists of { item, quantity } deltas.
 */
export function gtm4wp_diff_cart_items( previous, current ) {
	const previous_list = Array.isArray( previous ) ? previous : [];
	const current_list = Array.isArray( current ) ? current : [];

	// Keyed by the Store API cart-item key, so a null prototype - the same rule
	// the media trackers follow. WooCommerce generates that key as a hash, so no
	// real cart reaches an inherited Object member; on a plain object one that
	// did would resolve to a truthy non-entry, making `before.quantity`
	// undefined, the delta NaN and `NaN > 0` false - so the line would be
	// silently dropped from add_to_cart/remove_from_cart rather than throwing.
	const previous_by_key = Object.create( null );
	previous_list.forEach( ( entry ) => {
		previous_by_key[ entry.key ] = entry;
	} );

	const current_by_key = Object.create( null );
	current_list.forEach( ( entry ) => {
		current_by_key[ entry.key ] = entry;
	} );

	const added = [];
	const removed = [];

	current_list.forEach( ( entry ) => {
		const before = previous_by_key[ entry.key ];
		const before_qty = before ? before.quantity : 0;
		const delta = entry.quantity - before_qty;
		if ( delta > 0 ) {
			added.push( { item: entry.item, quantity: delta } );
		}
	} );

	previous_list.forEach( ( entry ) => {
		const now = current_by_key[ entry.key ];
		const now_qty = now ? now.quantity : 0;
		const delta = entry.quantity - now_qty;
		if ( delta > 0 ) {
			removed.push( { item: entry.item, quantity: delta } );
		}
	} );

	return { added, removed };
}

/**
 * Normalizes the raw `wc/store/cart` crossSells array (cross-sell products the
 * Cart block renders) into a compact list of { id, permalink, item } entries,
 * dropping any product without GA4 item data. Cross-sell products are serialized
 * through the Store API ProductSchema, so they carry the same
 * extensions.gtm4wp.item the product endpoint exposes.
 *
 * @param {Array} cross_sells The getCartData().crossSells array.
 * @return {Array<{id: number, permalink: string, item: Object}>} Normalized items.
 */
export function gtm4wp_normalize_crosssell_items( cross_sells ) {
	if ( ! Array.isArray( cross_sells ) ) {
		return [];
	}

	return cross_sells
		.map( ( product ) => {
			const extensions =
				product && product.extensions && product.extensions.gtm4wp;
			const item = extensions
				? gtm4wp_parse_block_item( extensions.item )
				: null;

			if ( ! item ) {
				return null;
			}

			return {
				id: product.id,
				permalink: product.permalink,
				item,
			};
		} )
		.filter( Boolean );
}

/**
 * Returns the name of the currently selected shipping rate from the cart data,
 * or an empty string when none is selected.
 *
 * @param {Object} cart_data The getCartData() object.
 * @return {string} The selected shipping rate name.
 */
export function gtm4wp_selected_shipping_tier( cart_data ) {
	const packages = ( cart_data && cart_data.shippingRates ) || [];

	for ( let i = 0; i < packages.length; i++ ) {
		const rates = ( packages[ i ] && packages[ i ].shipping_rates ) || [];
		for ( let j = 0; j < rates.length; j++ ) {
			if ( rates[ j ] && rates[ j ].selected ) {
				return rates[ j ].name || '';
			}
		}
	}

	return '';
}
