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
( function () {
	'use strict';

	const config = window.gtm4wp_visitordata_config || { fields: {} };
	const datalayerName = window.gtm4wp_datalayer_name || 'dataLayer';
	const eventName = config.event || 'gtm4wp.visitorData';
	const fields = config.fields || {};

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
	 * Reads a cookie value by name from document.cookie, or '' when absent.
	 *
	 * @param {string} name The cookie name.
	 * @return {string} The cookie value, or ''.
	 */
	function readCookie( name ) {
		const parts = ( '; ' + document.cookie ).split( '; ' + name + '=' );
		if ( 2 === parts.length ) {
			return parts.pop().split( ';' ).shift();
		}
		return '';
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
			const current = readCookie( gate.cookie );

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

		const headers = { Accept: 'application/json' };
		if ( config.nonce ) {
			// Sent per WP REST conventions so WordPress authenticates the caller's
			// cookie; a logged-out request stays anonymous and receives no user data.
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
