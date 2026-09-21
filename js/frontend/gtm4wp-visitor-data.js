/**
 * GTM4WP cache-safe data layer: client-side visitor data runtime (issue #398).
 *
 * Delivers the visitor/session values a full-page cache must not bake into the
 * HTML, under the SAME data layer variable names the server used, with no
 * unconditional per-page request:
 *
 * - Tier 1 (the browser knows it): computed by a producer, zero network.
 * - Tier 2/3 (server-only): the first-party session endpoint, Tier 2 once per
 *   session (sessionStorage), Tier 3 only when its gate cookie changed, so an
 *   anonymous visitor never fetches user data.
 * - WooCommerce customer & cart: read from the cart-fragments payload
 *   WooCommerce already refreshes.
 * - WooCommerce one-shots (the add_to_cart after a cart "Undo", the
 *   reliable-purchase fallback): the same endpoint, only while the event
 *   cookie is present, fired ONCE with a de-dupe guard (the purchase reuses
 *   gtm4wp_orderid_tracked), then the cookie is cleared and a POST beacon
 *   lets the server flag the order while the GET stays read-only.
 *
 * Each family is its own event so a GTM setup can tell from the name alone
 * which keys arrived: gtm4wp.visitorData (Tier 1 + endpoint fields, ONE push,
 * synchronous when replayed from the cache), gtm4wp.customerData and
 * gtm4wp.cartData. The WooCommerce families arrive later than the flush (the
 * placeholder in the HTML is empty by design) and are re-pushed on a cart
 * change, each gated on ITS OWN half having changed; an absent family fires
 * no event, but an empty cart IS delivered, with items: [].
 *
 * The config VisitorDataModule bakes into the page carries no visitor value.
 */
import {
	gtm4wp_read_cookie,
	gtm4wp_write_cookie,
	gtm4wp_clear_cookie,
} from './lib/gtm4wp-cookies';

( function () {
	'use strict';

	// Guard against double registration (#83, PA-9): a re-injected bundle would
	// push twice, fetch twice and leave a second MutationObserver behind.
	if ( window.gtm4wp_visitordata_inited ) {
		return;
	}
	window.gtm4wp_visitordata_inited = true;

	const config = window.gtm4wp_visitordata_config || { fields: {} };
	const datalayerName = window.gtm4wp_datalayer_name || 'dataLayer';
	const fields = config.fields || {};

	/**
	 * Resolves one data layer event name from the config. After an update the
	 * realistic state is NEW script + OLD cached config, hence the chain: the
	 * per-family map, the pre-split `event` key (visitor only), the literal. The
	 * literals mirror VisitorDataModule::EVENT_*, the authority; they exist
	 * because WooCommerceModule can load this handle with no config.
	 *
	 * @param {string} family   The config.events key ('visitor', 'customer', 'cart').
	 * @param {string} fallback The documented event name for that family.
	 * @return {string} The event name to push under.
	 */
	function resolveEventName( family, fallback ) {
		const events = config.events;

		if ( events && 'object' === typeof events ) {
			const configured = events[ family ];
			if ( 'string' === typeof configured && '' !== configured ) {
				return configured;
			}
		}

		if (
			'visitor' === family &&
			'string' === typeof config.event &&
			'' !== config.event
		) {
			return config.event;
		}

		return fallback;
	}

	const visitorEventName = resolveEventName(
		'visitor',
		'gtm4wp.visitorData'
	);
	const customerEventName = resolveEventName(
		'customer',
		'gtm4wp.customerData'
	);
	const cartEventName = resolveEventName( 'cart', 'gtm4wp.cartData' );

	// The beacon nonce: the baked config nonce until the endpoint returns a
	// fresh one (the baked one goes stale on a long-lived cached page).
	let beaconNonce = config.nonce || '';

	/**
	 * Returns the data layer array, creating it if needed.
	 *
	 * @return {Array} The data layer.
	 */
	function dataLayer() {
		window[ datalayerName ] = window[ datalayerName ] || [];
		return window[ datalayerName ];
	}

	/**
	 * The single accumulated visitor push (Tier 1 + endpoint fields), flushed
	 * once when the endpoint is ready. The WooCommerce families are not
	 * collected here.
	 */
	const collected = {};

	/**
	 * Merges a key => value map into the pending single push.
	 *
	 * @param {Object} map The keys to add.
	 * @return {void}
	 */
	function collect( map ) {
		if ( ! map || 'object' !== typeof map ) {
			return;
		}

		Object.keys( map ).forEach( function ( key ) {
			collected[ key ] = map[ key ];
		} );
	}

	/**
	 * Pushes one data layer event carrying the given key => value map. No-op for
	 * an empty or non-object map (an absent family fires no event) and for a
	 * name that is not a non-empty string (no `{ event: undefined }`).
	 *
	 * @param {string} name The data layer event name.
	 * @param {Object} map  The data layer keys to deliver.
	 * @return {void}
	 */
	function pushEvent( name, map ) {
		if ( 'string' !== typeof name || '' === name ) {
			return;
		}

		if ( ! map || 'object' !== typeof map ) {
			return;
		}

		const keys = Object.keys( map );
		if ( ! keys.length ) {
			return;
		}

		// A copy, never the accumulator (GTM would alias an object a later push
		// mutates); the event name is assigned AFTER the keys so a payload key
		// called `event` cannot overwrite it.
		const push = {};
		keys.forEach( function ( key ) {
			push[ key ] = map[ key ];
		} );
		push.event = name;

		dataLayer().push( push );
	}

	/**
	 * Producers compute one Tier 1 visitor-scoped value from a browser source,
	 * returning an empty string when it cannot be determined. Keyed by the source
	 * token the PHP side sends in config.fields.
	 */
	const producers = {
		/**
		 * The site search term from the current URL query string (?s=...),
		 * mirroring get_search_query() / the siteSearchTerm server value.
		 *
		 * @return {string} The search term, or '' when absent.
		 */
		searchTerm() {
			try {
				return (
					new URLSearchParams( window.location.search ).get( 's' ) ||
					''
				);
			} catch ( e ) {
				return '';
			}
		},

		/**
		 * The referring page URL, normalized like the 1.x siteSearchFrom value:
		 * the part before the query string, and — when a query string is present —
		 * the query re-appended URL-encoded so it stays a single opaque token.
		 *
		 * @return {string} The normalized referrer, or '' when there is none.
		 */
		searchReferrer() {
			const ref = document.referrer || '';
			if ( '' === ref ) {
				return '';
			}

			const parts = ref.split( '?' );
			if ( parts.length < 2 ) {
				return ref;
			}

			return parts[ 0 ] + '?' + encodeURIComponent( parts[ 1 ] );
		},
	};

	/**
	 * Tier 1: gather the values the browser computes itself, no network.
	 *
	 * @return {void}
	 */
	function collectClientFields() {
		Object.keys( fields ).forEach( function ( key ) {
			const producer = producers[ fields[ key ] ];
			if ( 'function' !== typeof producer ) {
				return;
			}

			const value = producer();
			if ( '' !== value && undefined !== value && null !== value ) {
				collected[ key ] = value;
			}
		} );
	}

	/**
	 * Whether Web Storage is usable. Without it the runtime does NOT fetch at
	 * all: the safe default is no extra data, never a per-page request from
	 * every cached view.
	 *
	 * @return {boolean} True when sessionStorage can be read and written.
	 */
	function storageAvailable() {
		try {
			const probe = '__gtm4wp_vd_probe';
			window.sessionStorage.setItem( probe, '1' );
			window.sessionStorage.removeItem( probe );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	// The de-dupe storage keys. gtm4wp_orderid_tracked is shared verbatim with
	// the order-received page's inline guard (Ecommerce\Helpers::purchase_dedupe_guard):
	// localStorage else a one-year cookie, keyed on the order NUMBER.
	const ORDER_TRACKED_KEY = 'gtm4wp_orderid_tracked';
	const READDED_TOKENS_KEY = 'gtm4wp_readded_to_cart';
	const READDED_TOKENS_MAX = 20;

	/**
	 * Reads the order number recorded as already tracked in this browser, from
	 * localStorage or (when Web Storage is unavailable) the cookie — the read half of
	 * the shared gtm4wp_orderid_tracked de-dupe guard.
	 *
	 * @return {string} The tracked order number, or ''.
	 */
	function readOrderTracked() {
		if ( ! window.localStorage ) {
			return gtm4wp_read_cookie( ORDER_TRACKED_KEY );
		}
		try {
			return window.localStorage.getItem( ORDER_TRACKED_KEY ) || '';
		} catch ( e ) {
			return gtm4wp_read_cookie( ORDER_TRACKED_KEY );
		}
	}

	/**
	 * Records an order number as tracked in this browser (localStorage, else a
	 * one-year cookie) — the write half of the shared gtm4wp_orderid_tracked guard.
	 *
	 * @param {string} orderNumber The order number to record.
	 * @return {void}
	 */
	function writeOrderTracked( orderNumber ) {
		if ( window.localStorage ) {
			try {
				window.localStorage.setItem( ORDER_TRACKED_KEY, orderNumber );
				return;
			} catch ( e ) {}
		}
		gtm4wp_write_cookie( ORDER_TRACKED_KEY, orderNumber, 365 );
	}

	/**
	 * The list of re-add tokens already fired in this browser (localStorage), so a
	 * page reload does not re-fire the same restored cart item.
	 *
	 * @return {Array} The stored token list (possibly empty).
	 */
	function readReaddedTokens() {
		if ( ! window.localStorage ) {
			return [];
		}
		try {
			const parsed = JSON.parse(
				window.localStorage.getItem( READDED_TOKENS_KEY ) || '[]'
			);
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	}

	/**
	 * Records a re-add token as fired, capping the stored list so a long-lived
	 * browser cannot grow it without bound.
	 *
	 * @param {string} token The re-add token.
	 * @return {void}
	 */
	function recordReaddedToken( token ) {
		if ( ! window.localStorage ) {
			return;
		}
		const tokens = readReaddedTokens();
		if ( -1 !== tokens.indexOf( token ) ) {
			return;
		}
		tokens.push( token );
		while ( tokens.length > READDED_TOKENS_MAX ) {
			tokens.shift();
		}
		try {
			window.localStorage.setItem(
				READDED_TOKENS_KEY,
				JSON.stringify( tokens )
			);
		} catch ( e ) {}
	}

	/**
	 * Fires the POST beacon that lets the server flag a delivered one-shot
	 * (issue #398): the nonce as X-WP-Nonce on fetch keepalive, or as _wpnonce
	 * for the sendBeacon fallback, which cannot set headers. Fire-and-forget;
	 * carries NO order id (the server resolves it from its session marker).
	 *
	 * @param {string} url The confirm route URL baked into the config.
	 * @return {void}
	 */
	function fireConfirmBeacon( url ) {
		if ( ! url ) {
			return;
		}

		try {
			if ( 'function' === typeof fetch ) {
				const headers = {};
				if ( beaconNonce ) {
					headers[ 'X-WP-Nonce' ] = beaconNonce;
				}

				const request = fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: true,
					headers,
				} );
				if ( request && 'function' === typeof request.catch ) {
					request.catch( function () {} );
				}
				return;
			}

			if (
				'undefined' !== typeof navigator &&
				'function' === typeof navigator.sendBeacon
			) {
				const separator = -1 === url.indexOf( '?' ) ? '?' : '&';
				navigator.sendBeacon(
					beaconNonce
						? url +
								separator +
								'_wpnonce=' +
								encodeURIComponent( beaconNonce )
						: url
				);
			}
		} catch ( e ) {
			// Swallow: the same-browser guard still holds.
		}
	}

	/**
	 * Fires the reliable-purchase fallback exactly once, de-duped against the
	 * shared gtm4wp_orderid_tracked guard unless payload.flag is false ("Do not
	 * flag orders as being tracked", matching the server path).
	 *
	 * @param {Object} payload    The resolver payload ({ push, orderNumber, flag }).
	 * @param {string} confirmUrl Optional POST-beacon URL fired after delivery.
	 * @return {void}
	 */
	function handlePendingPurchase( payload, confirmUrl ) {
		if ( ! payload || 'object' !== typeof payload || ! payload.push ) {
			return;
		}

		const orderNumber =
			undefined === payload.orderNumber || null === payload.orderNumber
				? ''
				: String( payload.orderNumber );
		const useGuard = false !== payload.flag;

		if ( useGuard && orderNumber && readOrderTracked() === orderNumber ) {
			return;
		}

		dataLayer().push( payload.push );

		if ( useGuard && orderNumber ) {
			writeOrderTracked( orderNumber );
		}

		// Cross-device dedupe (issue #398): the beacon flags _ga_tracked server
		// side. Only with the browser guard in use; "Do not flag orders as being
		// tracked" writes no tracked state anywhere, by contract.
		if ( useGuard && confirmUrl ) {
			fireConfirmBeacon( confirmUrl );
		}
	}

	/**
	 * Fires the re-added-to-cart add_to_cart exactly once, de-duped on the per-event
	 * token so a page reload does not re-push it.
	 *
	 * @param {Object} payload    The resolver payload ({ push, token }).
	 * @param {string} confirmUrl POST-beacon URL fired after delivery so the server
	 *                            consumes the session marker (issue #398).
	 * @return {void}
	 */
	function handleReaddedToCart( payload, confirmUrl ) {
		if ( ! payload || 'object' !== typeof payload || ! payload.push ) {
			return;
		}

		const token =
			undefined === payload.token || null === payload.token
				? ''
				: String( payload.token );

		if ( token && -1 !== readReaddedTokens().indexOf( token ) ) {
			return;
		}

		dataLayer().push( payload.push );

		if ( token ) {
			recordReaddedToken( token );
		}

		// The read-only GET consumed nothing; the beacon consumes the session marker.
		if ( confirmUrl ) {
			fireConfirmBeacon( confirmUrl );
		}
	}

	/**
	 * One-shot event handlers, keyed by the resolver field name. Each fires its
	 * own event with a de-dupe guard; never cached or replayed.
	 */
	const actionHandlers = {
		pendingPurchase: handlePendingPurchase,
		readdedToCart: handleReaddedToCart,
	};

	/**
	 * Tier 2/3: gather the server-only fields into the pending push, fetching
	 * only when needed (Tier 2 once per session, a Tier 3 gate only when its
	 * cookie value changed), then call done() - synchronously when no fetch is
	 * needed, so a cached view yields one synchronous push.
	 *
	 * @param {Function} done Called (sync or async) once any endpoint data is merged.
	 * @return {void}
	 */
	function collectEndpointFields( done ) {
		if ( ! config.endpoint || ! storageAvailable() ) {
			done();
			return;
		}

		const sessionKey = config.sessionKey || 'gtm4wp_visitor_session';
		const sessionFields = Array.isArray( config.session )
			? config.session
			: [];
		const gates = Array.isArray( config.gates ) ? config.gates : [];
		const actions = Array.isArray( config.actions ) ? config.actions : [];

		// One-shot field names (routed to their handlers, kept out of the merged
		// push and the cache) and their per-key beacon URLs.
		const actionKeys = {};
		const actionConfirm = {};
		actions.forEach( function ( action ) {
			const confirm = action.confirm || {};
			( action.keys || [] ).forEach( function ( key ) {
				actionKeys[ key ] = true;
				if ( confirm[ key ] ) {
					actionConfirm[ key ] = confirm[ key ];
				}
			} );
		} );

		let store;
		try {
			store = JSON.parse(
				window.sessionStorage.getItem( sessionKey ) || '{}'
			);
		} catch ( e ) {
			store = {};
		}
		if ( ! store || 'object' !== typeof store ) {
			store = {};
		}
		if ( ! store.gates || 'object' !== typeof store.gates ) {
			store.gates = {};
		}

		let needFetch = false;
		let dirty = false;
		const replay = {};
		const activeGates = [];

		// Tier 2: fetched once per session, then replayed from the cache.
		if ( sessionFields.length ) {
			if ( store.session && 'object' === typeof store.session ) {
				Object.assign( replay, store.session );
			} else {
				needFetch = true;
			}
		}

		// Tier 3: fetched only when a gate cookie changed since the last fetch.
		gates.forEach( function ( gate ) {
			const current = gtm4wp_read_cookie( gate.cookie );

			if ( current ) {
				activeGates.push( { cookie: gate.cookie, value: current } );

				const cached = store.gates[ gate.cookie ];
				if (
					cached &&
					cached.v === current &&
					cached.data &&
					'object' === typeof cached.data
				) {
					Object.assign( replay, cached.data );
				} else {
					needFetch = true;
				}
			} else if ( store.gates[ gate.cookie ] ) {
				// The gate cookie is gone (e.g. the visitor logged out): drop the
				// cached identity data so it is never replayed, and do not fetch.
				delete store.gates[ gate.cookie ];
				dirty = true;
			}
		} );

		// One-shot events: fetched whenever their event cookie is present, never
		// cached; the cookie is cleared after delivery below.
		const activeActionCookies = [];
		actions.forEach( function ( action ) {
			if ( gtm4wp_read_cookie( action.cookie ) ) {
				if ( -1 === activeActionCookies.indexOf( action.cookie ) ) {
					activeActionCookies.push( action.cookie );
				}
				needFetch = true;
			}
		} );

		if ( ! needFetch ) {
			if ( dirty ) {
				try {
					window.sessionStorage.setItem(
						sessionKey,
						JSON.stringify( store )
					);
				} catch ( e ) {}
			}
			collect( replay );
			done();
			return;
		}

		// Without fetch a ReferenceError would escape and lose the Tier 1 values
		// too; degrade to "no endpoint data" instead.
		if ( 'function' !== typeof fetch ) {
			done();
			return;
		}

		const headers = { Accept: 'application/json' };
		// The baked nonce ONLY for a logged-in visitor (active Tier 3 gate; their
		// page is never cached, so it is fresh). An anonymous fetch sends none: a
		// stale nonce from a long-lived cached page would 403 the read.
		if ( config.nonce && activeGates.length ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		fetch( config.endpoint, { credentials: 'same-origin', headers } )
			.then( function ( response ) {
				return response && response.ok ? response.json() : null;
			} )
			.then( function ( body ) {
				if ( ! body || 'string' !== typeof body.payload ) {
					return;
				}

				// The fresh beacon nonce, before any handler below fires one.
				if ( 'string' === typeof body.nonce && body.nonce ) {
					beaconNonce = body.nonce;
				}

				let data;
				try {
					data = JSON.parse( body.payload );
				} catch ( e ) {
					return;
				}
				if ( ! data || 'object' !== typeof data ) {
					return;
				}

				// Cache the Tier 2 subset and each active gate's subset, tagged with
				// the cookie value it was fetched at.
				const next = { gates: {} };
				if ( sessionFields.length ) {
					next.session = {};
					sessionFields.forEach( function ( key ) {
						if ( key in data ) {
							next.session[ key ] = data[ key ];
						}
					} );
				}
				gates.forEach( function ( gate ) {
					const active = activeGates.filter( function ( entry ) {
						return entry.cookie === gate.cookie;
					} )[ 0 ];
					if ( ! active ) {
						return;
					}

					const subset = {};
					( gate.keys || [] ).forEach( function ( key ) {
						if ( key in data ) {
							subset[ key ] = data[ key ];
						}
					} );
					next.gates[ gate.cookie ] = {
						v: active.value,
						data: subset,
					};
				} );

				try {
					window.sessionStorage.setItem(
						sessionKey,
						JSON.stringify( next )
					);
				} catch ( e ) {}

				// One-shots go to their handlers, stay OUT of the merged push, and
				// their event cookies are cleared so a later page makes no request.
				Object.keys( actionKeys ).forEach( function ( key ) {
					if ( key in data ) {
						const handler = actionHandlers[ key ];
						if ( 'function' === typeof handler ) {
							handler( data[ key ], actionConfirm[ key ] );
						}
						delete data[ key ];
					}
				} );
				activeActionCookies.forEach( gtm4wp_clear_cookie );

				collect( data );
			} )
			.catch( function () {
				// Network error: stay silent; the next page view retries.
			} )
			.then( done );
	}

	/**
	 * The raw customer/cart block currently on the cart-fragments placeholder, or
	 * null when there is none.
	 *
	 * @return {?string} The raw data attribute value, or null.
	 */
	function currentWooRaw() {
		const element = document.querySelector( '.gtm4wp-wc-visitor-data' );
		if ( ! element ) {
			return null;
		}
		return element.getAttribute( 'data-gtm4wp-visitor-cart' ) || null;
	}

	/**
	 * Parses a cart-fragment JSON string into the { customer, cart } block PHP
	 * encodes, or null for anything not a usable object: the attribute can hold
	 * '', '[]', a scalar, or a pre-split flat payload replayed from WooCommerce's
	 * fragment cache, and JSON.parse SUCCEEDS on 'null' and '5', so the type
	 * check is the guard, before any property read. A flat payload delivers
	 * nothing until the next cart change: sniffing key prefixes would put a copy
	 * of today's naming in the client.
	 *
	 * @param {?string} raw The raw JSON string.
	 * @return {?Object} The parsed two-part block, or null.
	 */
	function parseWoo( raw ) {
		if ( ! raw ) {
			return null;
		}

		let parsed;
		try {
			parsed = JSON.parse( raw );
		} catch ( e ) {
			return null;
		}

		if (
			! parsed ||
			'object' !== typeof parsed ||
			Array.isArray( parsed )
		) {
			return null;
		}

		return parsed;
	}

	// Last delivered state per family, so each event fires only when ITS OWN
	// half changed (WooCommerce re-applies the whole fragment as one blob).
	let lastCustomerJson = null;
	let lastCartJson = null;

	/**
	 * Pushes one WooCommerce family under its own event name when it changed
	 * since the last delivery. Compared as JSON (a fresh object arrives on every
	 * refresh); an absent part is left alone, never "changed to nothing".
	 *
	 * @param {*}       part The family payload from the fragment, if any.
	 * @param {string}  name The data layer event name for this family.
	 * @param {?string} last The serialization delivered last time.
	 * @return {?string} The serialization to remember as delivered.
	 */
	function deliverWooFamily( part, name, last ) {
		if ( ! part || 'object' !== typeof part || Array.isArray( part ) ) {
			return last;
		}

		let json;
		try {
			json = JSON.stringify( part );
		} catch ( e ) {
			return last;
		}

		if ( json === last ) {
			return last;
		}

		pushEvent( name, part );

		return json;
	}

	/**
	 * Pushes the WooCommerce customer and cart families from a raw fragment payload,
	 * each under its own event name and each only when that half changed. Used for
	 * both the initial read and every later cart change.
	 *
	 * @param {?string} raw The raw data attribute value.
	 * @return {void}
	 */
	function deliverWooBlock( raw ) {
		const parsed = parseWoo( raw );
		if ( ! parsed ) {
			return;
		}

		lastCustomerJson = deliverWooFamily(
			parsed.customer,
			customerEventName,
			lastCustomerJson
		);
		lastCartJson = deliverWooFamily(
			parsed.cart,
			cartEventName,
			lastCartJson
		);
	}

	/**
	 * Watches for WooCommerce re-applying/refreshing the cart fragment after the
	 * initial delivery (a same-page cart change), pushing whichever family changed as
	 * its own event. Only wired on pages that carry the placeholder.
	 *
	 * @return {void}
	 */
	function observeWooChanges() {
		if (
			! window.MutationObserver ||
			! document.body ||
			! document.querySelector( '.gtm4wp-wc-visitor-data' )
		) {
			return;
		}

		new window.MutationObserver( function () {
			deliverWooBlock( currentWooRaw() );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	// Tier 1, then the endpoint completes the push, flushed as one visitor
	// event, and only then the WooCommerce families: the visitor event must
	// land FIRST so a tag triggered on customerData/cartData can read
	// visitorId from GTM's model. The initial read and the observer stay in
	// one synchronous block, read first, so no fragment is missed or
	// re-delivered in between.
	collectClientFields();

	collectEndpointFields( function () {
		pushEvent( visitorEventName, collected );

		deliverWooBlock( currentWooRaw() );
		observeWooChanges();
	} );
} )();
