/**
 * GTM4WP e-commerce generic helper functions.
 *
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

/**
 * Reads and parses the list-attribution cookie into a productId -> list-data map.
 *
 * @return {Object} The parsed map, or an empty object when absent/malformed.
 */
function gtm4wp_read_item_list_cookie() {
	try {
		const parts = ( '; ' + document.cookie ).split(
			'; ' + GTM4WP_LIST_ATTR_COOKIE + '='
		);
		if ( parts.length !== 2 ) {
			return {};
		}

		const raw = parts.pop().split( ';' ).shift();
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
 * Stores the list attribution for a product id, newest wins, capping the number of
 * entries so the cookie stays small. No-op without a product id or list name.
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

	// Drop any existing entry for this id, then evict oldest keys until there is
	// room, so the cookie never grows past the cap.
	delete map[ product_id ];
	const keys = Object.keys( map );
	while ( keys.length >= GTM4WP_LIST_ATTR_MAX_ENTRIES ) {
		delete map[ keys.shift() ];
	}

	map[ product_id ] = { item_list_name };
	if ( item_list_id ) {
		map[ product_id ].item_list_id = item_list_id;
	}

	const expires = new Date();
	expires.setTime(
		expires.getTime() + GTM4WP_LIST_ATTR_TTL_DAYS * 24 * 60 * 60 * 1000
	);

	document.cookie =
		GTM4WP_LIST_ATTR_COOKIE +
		'=' +
		encodeURIComponent( JSON.stringify( map ) ) +
		';expires=' +
		expires.toUTCString() +
		';path=/;SameSite=Lax';
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
