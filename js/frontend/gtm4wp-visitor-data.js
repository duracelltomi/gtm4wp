/**
 * GTM4WP cache-safe data layer — client-side visitor data runtime (issue #398).
 *
 * On full-page-cached sites, values specific to the current visitor/session must
 * not be baked into the (shared) page HTML. This script delivers them client-side
 * under the SAME data layer variable names the server used, so existing Google
 * Tag Manager setups keep working, through three channels — none of which is an
 * unconditional per-page request:
 *
 * - Tier 1 (the browser already knows it): computed from a "producer" below with
 *   zero network (referrer, search term).
 * - Tier 2/3 (server-only): fetched from the first-party session endpoint, but
 *   only when needed — Tier 2 (IP, Cloudflare country) once per session (cached in
 *   sessionStorage); Tier 3 (logged-in user data) only when its gate cookie
 *   changed, so an anonymous visitor never fetches user data.
 * - WooCommerce customer & cart: read from the cart-fragments payload WooCommerce
 *   already refreshes on every cart change (no request of our own).
 * - WooCommerce one-shot events (Phase 3): the add_to_cart after a cart "Undo" and
 *   the reliable-purchase fallback. These ride the same endpoint but are delivered
 *   only while a short-lived event cookie is present, fired ONCE each (with a
 *   per-event de-dupe guard — the purchase reuses the same gtm4wp_orderid_tracked
 *   guard as the order-received page), and are their own data layer events rather
 *   than part of the merged gtm4wp.visitorData push; the event cookie is cleared
 *   after delivery so a later page makes no request. After the fallback purchase the
 *   client also fires a single authenticated POST beacon so the server flags the
 *   order _ga_tracked, closing the cross-device double-count while the GET stays
 *   read-only (issue #398).
 *
 * All of these load-time sources are gathered into a SINGLE gtm4wp.visitorData
 * push, so a GTM setup sees them arrive together. On a cached page view the
 * endpoint replays from the cache and the push is synchronous; on the first view
 * of a session the one push fires when the endpoint responds. Only a later cart
 * change fires an additional gtm4wp.visitorData event (the cart really changed).
 *
 * The PHP side (VisitorDataModule) bakes a small, cache-safe config into the page
 * telling this runtime which keys to build and how (Tier 1 producers, the endpoint
 * URL + nonce, the session/gate metadata). The config carries NO visitor value.
 */
import {
	gtm4wp_read_cookie,
	gtm4wp_write_cookie,
	gtm4wp_clear_cookie,
} from './lib/gtm4wp-cookies';

( function () {
	'use strict';

	// Guard against double registration (#83). PA-9's rule was written as "a bundle
	// attaching document-level listeners needs a guard", and this file attaches none -
	// it pushes, fetches and observes from its module body instead, which is the same
	// class: a re-injected bundle (AJAX navigation, a page builder duplicating the
	// handle) would push gtm4wp.visitorData a second time, issue a second request to
	// the session endpoint, and leave a SECOND MutationObserver on document.body with
	// its own lastWooRaw - so every later cart change would push twice, permanently.
	if ( window.gtm4wp_visitordata_inited ) {
		return;
	}
	window.gtm4wp_visitordata_inited = true;

	const config = window.gtm4wp_visitordata_config || { fields: {} };
	const datalayerName = window.gtm4wp_datalayer_name || 'dataLayer';
	const eventName = config.event || 'gtm4wp.visitorData';
	const fields = config.fields || {};

	// The nonce the one-shot confirm beacons authenticate with. It starts as the
	// config nonce (baked into the page) but is replaced with the fresh nonce the
	// session endpoint returns on every response — the baked one goes stale after a
	// nonce tick (~24h), which on a long-lived cached page would 403 the beacon and
	// silently restore the cross-device purchase double-count (issue #398).
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
	 * The single accumulated visitor-data push. Every load-time source merges its
	 * keys here; it is flushed once, when the (possibly async) endpoint is ready.
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
	 * Pushes a gtm4wp.visitorData event carrying the given key => value map, under
	 * the same variable names the server used. No-op for an empty map.
	 *
	 * @param {Object} map The data layer keys to deliver.
	 * @return {void}
	 */
	function pushEvent( map ) {
		if ( ! map || 'object' !== typeof map ) {
			return;
		}

		const keys = Object.keys( map );
		if ( ! keys.length ) {
			return;
		}

		const push = { event: eventName };
		keys.forEach( function ( key ) {
			push[ key ] = map[ key ];
		} );

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
	 * Whether both Web Storage areas are usable. The endpoint delivery relies on
	 * them to gate the fetch (Tier 2 once per session, Tier 3 by cookie), so when
	 * they are unavailable (e.g. hardened privacy mode) the runtime does NOT fetch
	 * at all rather than fall back to a per-page request — the safe default is "no
	 * extra data", never hammering the origin from every cached page view.
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

	// The de-dupe storage keys. gtm4wp_orderid_tracked is shared verbatim with the
	// order-received page's inline purchase guard (PageDataLayer::purchase_dedupe_guard)
	// so a fallback fire and a real order-received purchase for the same order can
	// never both count; the read/write protocol below (localStorage else a one-year
	// cookie, keyed on the order NUMBER) mirrors that block exactly.
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
	 * Fires the authenticated POST beacon that asks the server to flag the delivered
	 * reliable-purchase-fallback order as tracked (issue #398), closing the
	 * cross-device double-count while the GET session endpoint stays read-only. Sends
	 * the shared wp_rest nonce (config.nonce) — as the X-WP-Nonce header on the fetch
	 * keepalive path, or as the _wpnonce query parameter for the sendBeacon fallback,
	 * which cannot set headers. Best-effort and fire-and-forget: it survives page
	 * navigation (keepalive / sendBeacon) and any failure is swallowed, so it degrades
	 * to the same-browser guard. Carries NO order id — the server resolves that from
	 * its own session marker.
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
	 * Fires the reliable-purchase fallback exactly once. De-dupes against the shared
	 * gtm4wp_orderid_tracked guard keyed on the order number (so it can never double
	 * with the order-received purchase), UNLESS the payload flag is false — the "Do
	 * not flag orders as being tracked" case, where no order-tracked state is read or
	 * written anywhere, matching the server path.
	 *
	 * @param {Object} payload    The resolver payload ({ push, orderNumber, flag }).
	 * @param {string} confirmUrl Optional authenticated POST-beacon URL fired after a
	 *                            fallback delivery to flag the order tracked server-side.
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

		// Cross-device dedupe (issue #398): after the fallback push, tell the server to
		// flag _ga_tracked on the order (and consume the delivery marker) so a later
		// order-received render on ANOTHER device is suppressed. The read-only GET made
		// no state change, so this authenticated POST is what performs it. Only when the
		// browser guard is in use (flag !== false); the "Do not flag orders as being
		// tracked" case writes no order-tracked state anywhere, so it also sends no
		// beacon — the server marker then lingers, but its only reader is this same
		// resolver and the client's per-order guard already stops a re-push.
		if ( useGuard && confirmUrl ) {
			fireConfirmBeacon( confirmUrl );
		}
	}

	/**
	 * Fires the re-added-to-cart add_to_cart exactly once, de-duped on the per-event
	 * token so a page reload does not re-push it.
	 *
	 * @param {Object} payload    The resolver payload ({ push, token }).
	 * @param {string} confirmUrl Authenticated POST-beacon URL fired after delivery so
	 *                            the server consumes the re-add's session marker (the
	 *                            read-only GET does not, issue #398).
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

		// The GET that delivered this re-add made no state change; this authenticated
		// POST tells the server to consume the session marker so a later page does not
		// re-resolve it. The client's per-token guard above already stops a re-push in
		// the meantime.
		if ( confirmUrl ) {
			fireConfirmBeacon( confirmUrl );
		}
	}

	/**
	 * One-shot event handlers (Phase 3), keyed by the field name the server resolver
	 * returns. Each fires its own data layer event under the SAME event name the
	 * server path used, with a de-dupe guard so it can never double-count; unlike the
	 * Tier 2/3 fields these values are never cached or replayed.
	 */
	const actionHandlers = {
		pendingPurchase: handlePendingPurchase,
		readdedToCart: handleReaddedToCart,
	};

	/**
	 * Tier 2/3: gather the server-only fields from the session endpoint into the
	 * pending push, fetching only when needed, then call done(). Tier 2 is fetched
	 * once per session (then replayed from the sessionStorage cache); each Tier 3
	 * gate is fetched only when its cookie value differs from the one we last
	 * fetched with — so an unchanged gate (and an anonymous visitor, whose gate
	 * cookie is absent) triggers no request. done() runs synchronously when no
	 * fetch is needed, so a cached page view yields one synchronous push.
	 *
	 * @param {Function} done Called (sync or async) once the endpoint data, if any,
	 *                        has been merged into the pending push.
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

		// The set of one-shot field names, so the fetched values can be routed to
		// their handlers and kept out of the merged visitorData push / the cache.
		// actionConfirm maps a one-shot key to the authenticated POST-beacon URL its
		// handler fires after delivery (e.g. the reliable-purchase fallback flagging
		// its order tracked, issue #398), so the GET response stays side-effect free.
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

		// One-shot events (Phase 3): fetched whenever their event cookie is present.
		// Unlike a gate they are never cached or replayed — the client fires each once,
		// de-dupes it, then clears the event cookie below, so a later page with no
		// cookie makes no request. An anonymous visitor never has the cookie.
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

		// Feature-detect like fireConfirmBeacon() does: an unguarded call throws a
		// ReferenceError where fetch is unavailable, and that throw escapes this
		// function, so done() never runs and even the already-collected Tier 1
		// values (search term, referrer) would be lost. Degrade to "no endpoint
		// data" instead and let the caller flush what it has.
		if ( 'function' !== typeof fetch ) {
			done();
			return;
		}

		const headers = { Accept: 'application/json' };
		// Send the (baked) nonce ONLY when there is identity-bound data to fetch — an
		// active Tier 3 gate cookie, i.e. a logged-in visitor. Their page is never
		// full-page cached, so the baked nonce is fresh and WordPress can authenticate
		// their auth cookie so the user fields resolve. An anonymous fetch (Tier 2, or
		// a guest one-shot) sends NO nonce: it needs none, and sending a stale nonce
		// baked into a long-lived cached page would 403 the read (issue #398). The
		// fresh nonce for any confirm beacon comes from the response below, not here.
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

				// Adopt the fresh nonce for the one-shot confirm beacons before any
				// action handler below fires one (issue #398).
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

				// Cache the Tier 2 subset and each active gate's subset (tagged with
				// the cookie value it was fetched at) so later page views replay
				// without a request until the session ends or a gate cookie changes.
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

				// One-shot events: hand each to its de-dupe+push handler and keep it
				// OUT of the merged visitorData push (they are their own events and are
				// never cached). Then clear the event cookie(s) so a later page — with
				// the cookie gone — makes no request.
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
	 * Parses a cart-fragment JSON string, or null when it is empty/invalid.
	 *
	 * @param {?string} raw The raw JSON string.
	 * @return {?Object} The parsed cart block, or null.
	 */
	function parseWoo( raw ) {
		if ( ! raw ) {
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	// The last cart block we pushed, so an unchanged fragment is not pushed twice.
	let lastWooRaw = null;

	/**
	 * Watches for WooCommerce re-applying/refreshing the cart fragment after the
	 * initial push (a same-page cart change), pushing the updated cart block as its
	 * own gtm4wp.visitorData event. Only wired on pages that carry the placeholder.
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
			const raw = currentWooRaw();
			if ( ! raw || raw === lastWooRaw ) {
				return;
			}
			lastWooRaw = raw;
			pushEvent( parseWoo( raw ) );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	// Gather the synchronous Tier 1 fields, then let the endpoint (sync replay or a
	// single gated fetch) complete the pending push. When it is ready, fold in the
	// WooCommerce cart the fragment has applied by then and flush everything as one
	// gtm4wp.visitorData event; from then on only real cart changes push again.
	collectClientFields();

	collectEndpointFields( function () {
		const raw = currentWooRaw();
		if ( raw ) {
			lastWooRaw = raw;
			collect( parseWoo( raw ) );
		}

		pushEvent( collected );
		observeWooChanges();
	} );
} )();
