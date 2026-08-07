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

	// Debug aid: log the pushed event when the admin has left frontend
	// console output enabled (the site-wide "Do not use console.log()" option).
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

// GA4 list attribution carried across the funnel via a first-party cookie (#405).
// The cookie maps productId -> { item_list_name, item_list_id } and is written on a
// select_item list click, then read to enrich later client-fired events (variable
// view_item, product-page add_to_cart). The server reads the same cookie for the
// never-cached cart/checkout/purchase events. The name MUST match
// Helpers::LIST_ATTRIBUTION_COOKIE on the PHP side.
const GTM4WP_LIST_ATTR_COOKIE = 'gtm4wp_item_list_attr';
const GTM4WP_LIST_ATTR_MAX_ENTRIES = 20;
const GTM4WP_LIST_ATTR_TTL_DAYS = 3;

// Second, independent bound on the cookie, measured the way the browser measures
// it: name + value, in bytes, AFTER percent-encoding. The entry cap alone does not
// bound the size, because item_list_name is not ours - it can be a widget title, a
// long category name or a translation - and encodeURIComponent triples every {, },
// ", : and , in the JSON. Twenty entries of built-in list names come to ~2.6 KB,
// but twenty entries whose names run ~50 characters exceed the ~4096 bytes browsers
// accept for one cookie.
//
// The budget is set just under that ceiling on purpose: every byte withheld here is
// a product the visitor is no longer attributed for, so headroom is not free. 4096
// has two readings - Chromium enforces it over name + value alone
// (kMaxCookieNamePlusValueSize), while RFC 6265 6.1 measures name + value +
// attributes - and gtm4wp_write_cookie() emits a fixed 58 bytes of attributes
// (';expires=' + a fixed-width UTC date + ';path=/;SameSite=Lax'). 3900 clears the
// stricter of the two by 138 bytes, so it holds whichever reading an engine uses.
// Raise it only against a re-measured attribute length; do not round it up to 4096.
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

	// Drop any existing entry for this id, then evict the least recently stored
	// entries until there is room, so the cookie never grows past the cap.
	//
	// #68: this used to take Object.keys( map ).shift(), and the keys are product
	// ids - integer-like, so the spec makes Object.keys return them in ASCENDING
	// NUMERIC order regardless of insertion order. That evicted the lowest product
	// id, not the oldest entry, so on a store with a wide id range the same low-id
	// products were dropped over and over while genuinely stale high-id entries
	// survived. Each entry now carries the second it was stored and eviction sorts
	// on that. An entry written by an older version has no stamp and sorts as
	// oldest, so the cookie drains itself of legacy entries first.
	delete map[ product_id ];

	const stored_at = ( key ) =>
		( map[ key ] && parseInt( map[ key ].t, 10 ) ) || 0;
	const keys_by_age = Object.keys( map ).sort(
		( a, b ) => stored_at( a ) - stored_at( b )
	);
	while ( keys_by_age.length >= GTM4WP_LIST_ATTR_MAX_ENTRIES ) {
		delete map[ keys_by_age.shift() ];
	}

	// A sequence number rather than a clock: several products can be stored inside
	// the same millisecond (a list page wiring every tile at once), and tied stamps
	// would fall back to Object.keys order - the very numeric ordering this fix
	// exists to stop relying on. It also stays short, which matters because every
	// byte it costs is a byte the size cap below has to evict for. The PHP reader
	// takes only item_list_name and item_list_id from each entry, so this field is
	// ignored there and needs no coordinated change.
	const next_seq =
		Object.keys( map ).reduce(
			( max, key ) => Math.max( max, stored_at( key ) ),
			0
		) + 1;

	map[ product_id ] = { item_list_name, t: next_seq };
	if ( item_list_id ) {
		map[ product_id ].item_list_id = item_list_id;
	}

	// Now evict by measured size, oldest first, until the encoded cookie fits the
	// byte budget. An oversized cookie does not corrupt anything - the browser
	// rejects the whole assignment and keeps the previous value - but the failure
	// is silent and it does not clear itself: the next click re-reads the same
	// oversized map, drops one entry, adds one, arrives at the same size and is
	// rejected again, so the attribution freezes on whatever fitted last until the
	// TTL expires. keys_by_age no longer contains product_id (deleted above and
	// re-added since), so the entry the visitor just clicked is never a candidate -
	// it is the freshest attribution there is.
	const fits = ( value ) =>
		GTM4WP_LIST_ATTR_COOKIE.length + value.length <=
		GTM4WP_LIST_ATTR_MAX_BYTES;

	let encoded = encodeURIComponent( JSON.stringify( map ) );
	while ( ! fits( encoded ) && keys_by_age.length ) {
		delete map[ keys_by_age.shift() ];
		encoded = encodeURIComponent( JSON.stringify( map ) );
	}

	// Nothing left to evict and still over budget: one list name longer than the
	// whole cookie. Leave the previous cookie alone rather than write something the
	// browser would drop anyway. Truncating the name to make it fit would be worse
	// than skipping it - the same list would then reach GA4 under a full name from
	// the server-rendered events and a truncated one from the cookie-carried
	// events, splitting one list into two rows in the report.
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
 * Enriches a whole data layer event object with the stored list attribution,
 * then returns it. Companion of gtm4wp_apply_stored_item_list() for the events
 * PHP renders server-side: the product-detail view_item is baked into cacheable
 * HTML, so the visitor-specific list has to be merged in the browser instead.
 *
 * DataLayer::queue_push() emits a call to this by name off `window`, guarded by
 * an identity fallback, so it never has to exist for the event to fire. It
 * therefore has to be defensive in the other direction too: an event of an
 * unexpected shape is returned untouched rather than throwing inside the push.
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

// These helpers are the public 1.x JS API called by gtm4wp-woocommerce.js
// and third party code. webpack wraps this file into its own module scope,
// so they have to be attached to window explicitly - without this the
// bundler also drops the whole file as dead code.
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
