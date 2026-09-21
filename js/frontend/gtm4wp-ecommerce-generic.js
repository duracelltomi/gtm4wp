/**
 * GTM4WP e-commerce generic helper functions.
 */
import { gtm4wp_read_cookie, gtm4wp_write_cookie } from './lib/gtm4wp-cookies';

/**
 * Casts a price-like value into a float with two decimals.
 *
 * @param {number|string} probably_float The raw value (price) to normalize.
 * @return {number} The value as a float, 0 when it can not be parsed.
 */
function gtm4wp_make_sure_is_float( probably_float ) {
	let will_be_float = probably_float;

	if ( typeof will_be_float === 'string' ) {
		will_be_float = parseFloat( will_be_float );
		if ( isNaN( will_be_float ) ) {
			will_be_float = 0;
		}
	} else if ( typeof will_be_float !== 'number' ) {
		will_be_float = 0;
	}
	will_be_float = parseFloat( will_be_float.toFixed( 2 ) );

	return will_be_float;
}

function gtm4wp_push_ecommerce(
	event_name,
	items,
	extra_params,
	event_callback = false,
	event_timeout = 2000
) {
	const ecom_obj = extra_params || {};
	ecom_obj.items = items;

	if ( gtm4wp_clear_ecommerce ) {
		window[ gtm4wp_datalayer_name ].push( {
			ecommerce: null,
		} );
	}

	const dl_obj = {
		event: event_name,
		ecommerce: ecom_obj,
	};

	if ( event_callback ) {
		dl_obj.eventCallback = event_callback;
		dl_obj.eventTimeout = event_timeout;
	}

	// Debug aid, gated by the "Do not use console.log()" option.
	if (
		typeof gtm4wp_console_log !== 'undefined' &&
		gtm4wp_console_log &&
		typeof console !== 'undefined' &&
		console.log
	) {
		console.log(
			'[GTM4WP] Pushing ecommerce event: ' + event_name,
			dl_obj
		);
	}

	window[ gtm4wp_datalayer_name ].push( dl_obj );
}

function gtm4wp_read_from_json(
	json_data,
	exclude_keys = [ 'productlink', 'internal_id' ]
) {
	try {
		const parsed_json = JSON.parse( json_data );
		if ( parsed_json ) {
			if ( parsed_json.price ) {
				parsed_json.price = gtm4wp_make_sure_is_float(
					parsed_json.price
				);
			}

			if ( exclude_keys && exclude_keys.length > 0 ) {
				const exclude_keys_length = exclude_keys.length;
				for ( let i = 0; i < exclude_keys_length; i++ ) {
					delete parsed_json[ exclude_keys[ i ] ];
				}
			}

			return parsed_json;
		}
	} catch ( e ) {
		console && console.error && console.error( e.message );
	}

	return false;
}

function gtm4wp_read_json_from_node(
	el,
	dataset_item_id,
	exclude_keys = [ 'productlink', 'internal_id' ]
) {
	if ( el && el.dataset && el.dataset[ dataset_item_id ] ) {
		return gtm4wp_read_from_json(
			el.dataset[ dataset_item_id ],
			exclude_keys
		);
	}

	return false;
}

function gtm4wp_update_json_in_node( el, dataset_item_id, new_key, new_value ) {
	if ( el && el.dataset && el.dataset[ dataset_item_id ] ) {
		try {
			const parsed_json = JSON.parse( el.dataset[ dataset_item_id ] );
			if ( parsed_json ) {
				if ( parsed_json.price ) {
					parsed_json.price = gtm4wp_make_sure_is_float(
						parsed_json.price
					);
				}

				parsed_json[ new_key ] = new_value;

				el.dataset[ dataset_item_id ] = JSON.stringify( parsed_json );

				return true;
			}
		} catch ( e ) {
			console && console.error && console.error( e.message );
		}
	}

	return false;
}

// GA4 list attribution across the funnel via a first-party cookie (#405):
// productId -> { item_list_name, item_list_id }, written on a select_item list
// click, read by the later client-fired events and by PHP for the never-cached
// cart/checkout/purchase events. The name MUST match
// Helpers::LIST_ATTRIBUTION_COOKIE.
const GTM4WP_LIST_ATTR_COOKIE = 'gtm4wp_item_list_attr';
const GTM4WP_LIST_ATTR_MAX_ENTRIES = 20;
const GTM4WP_LIST_ATTR_TTL_DAYS = 3;

// Second bound, in bytes of name + value AFTER percent-encoding: the entry cap
// alone does not bound the size (item_list_name is not ours, and encoding
// triples every JSON punctuation byte). Browsers accept ~4096; Chromium
// measures name + value, RFC 6265 6.1 adds the attributes, and
// gtm4wp_write_cookie() emits a fixed 58 bytes of them, so 3900 clears the
// stricter reading by 138. Every byte withheld is a lost attribution: raise
// only against a re-measured attribute length, never round up to 4096.
const GTM4WP_LIST_ATTR_MAX_BYTES = 3900;

/**
 * Reads and parses the list-attribution cookie into a productId -> list-data map.
 *
 * @return {Object} The parsed map, or an empty object when absent/malformed.
 */
function gtm4wp_read_item_list_cookie() {
	try {
		const raw = gtm4wp_read_cookie( GTM4WP_LIST_ATTR_COOKIE );
		if ( ! raw ) {
			return {};
		}

		const parsed = JSON.parse( decodeURIComponent( raw ) );
		return parsed && 'object' === typeof parsed ? parsed : {};
	} catch ( e ) {
		return {};
	}
}

/**
 * Stores the list attribution for a product id, newest wins, evicting the least
 * recently stored entries until the cookie is within BOTH caps: the entry count and
 * the encoded byte size. No-op without a product id or list name.
 *
 * @param {number|string} product_id     The product (or parent) id the list was shown for.
 * @param {string}        item_list_name The GA4 list name.
 * @param {string}        item_list_id   The GA4 list id (optional).
 * @return {void}
 */
function gtm4wp_store_item_list_attribution(
	product_id,
	item_list_name,
	item_list_id
) {
	if ( ! product_id || ! item_list_name ) {
		return;
	}

	const map = gtm4wp_read_item_list_cookie();

	// Evict oldest first by the stored stamp `t` (#68: Object.keys() orders
	// integer-like keys numerically, so keys order evicted the lowest product
	// id, not the oldest entry). An unstamped legacy entry sorts as oldest.
	delete map[ product_id ];

	const stored_at = ( key ) =>
		( map[ key ] && parseInt( map[ key ].t, 10 ) ) || 0;
	const keys_by_age = Object.keys( map ).sort(
		( a, b ) => stored_at( a ) - stored_at( b )
	);
	while ( keys_by_age.length >= GTM4WP_LIST_ATTR_MAX_ENTRIES ) {
		delete map[ keys_by_age.shift() ];
	}

	// A sequence number, not a clock: tied millisecond stamps would fall back
	// to the numeric key order, and it stays short. PHP ignores `t`.
	const next_seq =
		Object.keys( map ).reduce(
			( max, key ) => Math.max( max, stored_at( key ) ),
			0
		) + 1;

	map[ product_id ] = { item_list_name, t: next_seq };
	if ( item_list_id ) {
		map[ product_id ].item_list_id = item_list_id;
	}

	// Evict by measured size, oldest first: the browser silently rejects an
	// oversized assignment, and that state never clears itself. keys_by_age no
	// longer contains product_id, so the freshest entry is never a candidate.
	const fits = ( value ) =>
		GTM4WP_LIST_ATTR_COOKIE.length + value.length <=
		GTM4WP_LIST_ATTR_MAX_BYTES;

	let encoded = encodeURIComponent( JSON.stringify( map ) );
	while ( ! fits( encoded ) && keys_by_age.length ) {
		delete map[ keys_by_age.shift() ];
		encoded = encodeURIComponent( JSON.stringify( map ) );
	}

	// One list name longer than the whole cookie: keep the previous cookie.
	// Truncating the name would split one list into two GA4 rows.
	if ( ! fits( encoded ) ) {
		return;
	}

	gtm4wp_write_cookie(
		GTM4WP_LIST_ATTR_COOKIE,
		encoded,
		GTM4WP_LIST_ATTR_TTL_DAYS,
		true
	);
}

/**
 * Merges the stored list attribution onto a GA4 item by product id, but only when
 * the item does not already belong to a rendered list. Mutates and returns the item.
 *
 * @param {Object}        item       The GA4 item to enrich.
 * @param {number|string} product_id The product (or parent) id to look up.
 * @return {Object} The (possibly enriched) item.
 */
function gtm4wp_apply_stored_item_list( item, product_id ) {
	if ( ! item || ! product_id || item.item_list_name ) {
		return item;
	}

	const stored = gtm4wp_read_item_list_cookie()[ product_id ];
	if ( stored && stored.item_list_name ) {
		item.item_list_name = stored.item_list_name;
		if ( stored.item_list_id ) {
			item.item_list_id = stored.item_list_id;
		}
	}

	return item;
}

/**
 * Enriches a whole server-rendered data layer event (the cacheable
 * product-detail view_item) with the stored list attribution and returns it.
 * DataLayer::queue_push() calls it by name off `window` with an identity
 * fallback; an unexpected shape is returned untouched, never thrown on.
 *
 * @param {Object}        event_object The data layer object about to be pushed.
 * @param {number|string} product_id   The product (or parent) id to look up.
 * @return {Object} The same object, enriched when there was something to add.
 */
function gtm4wp_apply_stored_item_list_to_event( event_object, product_id ) {
	try {
		const item =
			event_object &&
			event_object.ecommerce &&
			Array.isArray( event_object.ecommerce.items )
				? event_object.ecommerce.items[ 0 ]
				: null;

		if ( item ) {
			gtm4wp_apply_stored_item_list( item, product_id );
		}
	} catch ( e ) {
		// Never let enrichment cost the event itself.
	}

	return event_object;
}

// The public 1.x JS API (third-party code calls these); webpack scopes the
// module, so they are attached to window explicitly.
window.gtm4wp_make_sure_is_float = gtm4wp_make_sure_is_float;
window.gtm4wp_push_ecommerce = gtm4wp_push_ecommerce;
window.gtm4wp_read_from_json = gtm4wp_read_from_json;
window.gtm4wp_read_json_from_node = gtm4wp_read_json_from_node;
window.gtm4wp_update_json_in_node = gtm4wp_update_json_in_node;
window.gtm4wp_read_item_list_cookie = gtm4wp_read_item_list_cookie;
window.gtm4wp_store_item_list_attribution = gtm4wp_store_item_list_attribution;
window.gtm4wp_apply_stored_item_list = gtm4wp_apply_stored_item_list;
window.gtm4wp_apply_stored_item_list_to_event =
	gtm4wp_apply_stored_item_list_to_event;
