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
	 * Pushes a gtm4wp.visitorData event carrying the given key => value map, under
	 * the same variable names the server used. No-op for an empty map.
	 *
	 * @param {Object} map The data layer keys to deliver.
	 * @return {void}
	 */
	function pushData( map ) {
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
	 * Tier 1: push the values the browser computes itself, no network.
	 *
	 * @return {void}
	 */
	function deliverClientFields() {
		const map = {};
		Object.keys( fields ).forEach( function ( key ) {
			const producer = producers[ fields[ key ] ];
			if ( 'function' !== typeof producer ) {
				return;
			}

			const value = producer();
			if ( '' !== value && undefined !== value && null !== value ) {
				map[ key ] = value;
			}
		} );

		pushData( map );
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
	 * Tier 2/3: deliver the server-only fields from the session endpoint, fetching
	 * only when needed. Tier 2 is fetched once per session (then replayed from the
	 * sessionStorage cache); each Tier 3 gate is fetched only when its cookie value
	 * differs from the one we last fetched with — so an unchanged gate (and an
	 * anonymous visitor, whose gate cookie is absent) triggers no request.
	 *
	 * @return {void}
	 */
	function deliverEndpointFields() {
		if ( ! config.endpoint || ! storageAvailable() ) {
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
			pushData( replay );
			return;
		}

		const headers = { Accept: 'application/json' };
		if ( config.nonce ) {
			// Sent per WP REST conventions so WordPress authenticates the caller's
			// cookie; a logged-out request stays anonymous and receives no user data.
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		fetch( config.endpoint, {
			credentials: 'same-origin',
			headers,
		} )
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

				// Cache the Tier 2 subset and each active gate's subset (tagged with the
				// cookie value it was fetched at) so later page views replay without a
				// request until the session ends or a gate cookie changes.
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

				pushData( data );
			} )
			.catch( function () {
				// Network error: stay silent; the next page view retries.
			} );
	}

	/**
	 * WooCommerce customer & cart: read the block WooCommerce carries on its
	 * cart-fragments response (a data attribute of the placeholder element) and push
	 * it. Reads once now and again whenever WooCommerce (re)applies the fragment,
	 * observed via a MutationObserver so a same-page cart change is picked up. The
	 * value is de-duplicated so an unchanged fragment is not pushed twice.
	 *
	 * @return {void}
	 */
	function deliverWooCartFragment() {
		let lastRaw = null;

		function read() {
			const element = document.querySelector( '.gtm4wp-wc-visitor-data' );
			if ( ! element ) {
				return;
			}

			const raw = element.getAttribute( 'data-gtm4wp-visitor-cart' );
			if ( ! raw || raw === lastRaw ) {
				return;
			}
			lastRaw = raw;

			let data;
			try {
				data = JSON.parse( raw );
			} catch ( e ) {
				return;
			}

			pushData( data );
		}

		read();

		// Only watch for fragment refreshes when the placeholder is actually on the
		// page (WooCommerce is delivering the block), so nothing is observed on the
		// pages that loaded this runtime for the endpoint fields alone.
		if (
			window.MutationObserver &&
			document.body &&
			document.querySelector( '.gtm4wp-wc-visitor-data' )
		) {
			new window.MutationObserver( read ).observe( document.body, {
				childList: true,
				subtree: true,
			} );
		}
	}

	deliverClientFields();
	deliverEndpointFields();
	deliverWooCartFragment();
} )();
