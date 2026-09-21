/**
 * GTM4WP Google Data Manager attribution capture: the GA client and session
 * IDs, the Google Ads click IDs of the visit and the consent state, held in
 * memory and written into two first-party cookies the order-creation hooks
 * read. Three rules:
 *
 * 1. Ask Google's API (`gtag('get', …)`), never parse the `_ga` / `_gcl_*`
 *    cookies, whose formats carry no stability promise.
 * 2. Queue the `get` commands once; the callbacks fire when the tag core
 *    replays the queue, even seconds later. No polling, no timers.
 * 3. Persist only what consent allows, decided as late as possible: capture
 *    into a pending buffer, write when consent permits, re-run on every
 *    consent change (a banner answered on the landing page still persists
 *    the click ID it arrived with).
 */
import {
	gtm4wp_clear_cookie,
	gtm4wp_read_cookie,
	gtm4wp_write_cookie,
} from './lib/gtm4wp-cookies';

( function () {
	'use strict';

	// Double-init guard (#83): a second copy would queue the get commands twice
	// and wrap the wrapper.
	if ( window.gtm4wp_gdm_attribution_inited ) {
		return;
	}
	window.gtm4wp_gdm_attribution_inited = true;

	const config = window.gtm4wp_gdm_attribution_config;

	// No config = capture is off. No literal defaults: the cookie contract must
	// not be guessed apart from the PHP that reads it (RI-14).
	if (
		! config ||
		! config.measurementIds ||
		! config.measurementIds.length
	) {
		return;
	}

	// Printed as `var` by the module, so it is a real window property (a
	// top-level `const` would bind lexically).
	const datalayerName = window.gtm4wp_datalayer_name || 'dataLayer';

	const CONSENT_GRANTED = 'granted';
	const SIGNAL_ANALYTICS = 'analytics_storage';
	const SIGNAL_ADS = 'ad_storage';

	/**
	 * Everything captured this pageview, waiting for consent to allow storing
	 * it. Written to cookies by flush(), never read from them.
	 */
	const pending = {
		clientId: '',
		sessions: {},
		clickIds: {},
	};

	/**
	 * The consent-mode signals observed so far, or null while none has been
	 * seen: no consent regime is the site owner's posture (writing allowed),
	 * an observed denial must be respected.
	 *
	 * @type {Object|null}
	 */
	let consentSignals = null;

	/**
	 * Value keys the confirmation-page backfill has already posted. Keyed, not
	 * a "sent once" flag: the client id and the session ids arrive in separate
	 * callbacks, so a later arrival is posted too while nothing is sent twice.
	 *
	 * @type {Object}
	 */
	const backfillSentKeys = {};

	/**
	 * Whether a backfill POST is already queued for the end of this task.
	 *
	 * @type {boolean}
	 */
	let backfillScheduled = false;

	/**
	 * The push wrapper we last installed, so a re-install can tell "still ours"
	 * from "something replaced it" without stacking wrappers.
	 *
	 * @type {Function|null}
	 */
	let installedPush = null;

	window[ datalayerName ] = window[ datalayerName ] || [];

	/**
	 * Pushes a gtag command (the arguments, verbatim) through an existing
	 * gtag() when the page has one, otherwise as the arguments object onto the
	 * configured data layer, exactly like the standard gtag shim.
	 *
	 * @return {void}
	 */
	function gtagCommand() {
		if ( 'function' === typeof window.gtag ) {
			window.gtag.apply( null, arguments );
			return;
		}

		window[ datalayerName ].push( arguments );
	}

	/**
	 * Reads the consent state out of the Google tag's own consent engine
	 * (`google_tag_data.ics`, undocumented internal, registered as U133). It is
	 * the authority when it exists: a consent tool built as a GTM template
	 * calls the sandboxed updateConsentState(), which writes here and pushes
	 * nothing onto the data layer, so a data-layer scan cannot tell "no consent
	 * mode" from "a consent mode I cannot see". Anything unexpected falls back
	 * to the scan.
	 *
	 * Per-signal shape (measured 2026-09-04): `{implicit?, default, update?,
	 * quiet}`. The discriminator is the TYPE of `update` (defined-but-undefined
	 * when no update was made); `update || default` would keep reporting
	 * granted after a withdrawal.
	 *
	 * @return {Object|null} The signal map in gtag vocabulary, or null when the engine is unreadable.
	 */
	function readTagConsent() {
		let entries;

		try {
			entries =
				window.google_tag_data &&
				window.google_tag_data.ics &&
				window.google_tag_data.ics.entries;
		} catch ( e ) {
			return null;
		}

		if ( ! entries || 'object' !== typeof entries ) {
			return null;
		}

		const found = {};

		Object.keys( entries ).forEach( function ( name ) {
			const entry = entries[ name ];

			if ( ! entry || 'object' !== typeof entry ) {
				return;
			}

			const state =
				'boolean' === typeof entry.update
					? entry.update
					: entry.default;

			if ( 'boolean' !== typeof state ) {
				return;
			}

			found[ name ] = state ? CONSENT_GRANTED : 'denied';
		} );

		return 0 < Object.keys( found ).length ? found : null;
	}

	/**
	 * Reads the consent state out of the data layer: the fallback before the
	 * tag's engine exists. Consent commands (['consent', 'default'|'update',
	 * {signal: 'granted'|'denied'}]) stay in the array, so one scan sees the
	 * default and every later update in order; events only say when to look.
	 *
	 * @return {Object|null} The merged signal map, or null when no consent command exists.
	 */
	function scanConsent() {
		const queue = window[ datalayerName ];
		let found = null;

		if ( ! queue || ! queue.length ) {
			return null;
		}

		for ( let i = 0; i < queue.length; i++ ) {
			const entry = queue[ i ];

			if ( ! entry || 'consent' !== entry[ 0 ] ) {
				continue;
			}

			if ( 'default' !== entry[ 1 ] && 'update' !== entry[ 1 ] ) {
				continue;
			}

			const signals = entry[ 2 ];
			if ( ! signals || 'object' !== typeof signals ) {
				continue;
			}

			found = found || {};

			Object.keys( signals ).forEach( function ( name ) {
				// The whole map: which signal gates a send is the consumer's
				// decision.
				if ( 'string' === typeof signals[ name ] ) {
					found[ name ] = signals[ name ];
				}
			} );
		}

		return found;
	}

	/**
	 * The consent state from the best source: the tag's engine when readable
	 * (it holds everything the data layer holds and more), else the scan.
	 *
	 * @return {Object|null} The signal map, or null when nothing has been observed at all.
	 */
	function currentConsent() {
		return readTagConsent() || scanConsent();
	}

	/**
	 * Whether a signal is granted. No observed regime answers true (the site
	 * owner's posture, not a denial); an observed state must say "granted".
	 *
	 * @param {string} signal The consent-mode signal name.
	 * @return {boolean} Whether persisting data under that signal is allowed.
	 */
	function isGranted( signal ) {
		if ( null === consentSignals ) {
			return true;
		}

		return CONSENT_GRANTED === consentSignals[ signal ];
	}

	/**
	 * Writes one cookie, JSON then URL encoded (a cookie value may not carry
	 * commas/semicolons raw), refusing an oversized payload the server-side
	 * parser would drop. Asymmetry: this script decodes its own read, PHP gets
	 * $_COOKIE already decoded and must NOT decode again.
	 *
	 * @param {string} name    Cookie name.
	 * @param {Object} payload Payload object; the format version is added here.
	 * @return {void}
	 */
	function writeCookie( name, payload ) {
		payload.v = config.version;

		const value = encodeURIComponent( JSON.stringify( payload ) );

		if ( value.length > config.maxBytes ) {
			return;
		}

		gtm4wp_write_cookie( name, value, config.lifetimeDays, true );
	}

	/**
	 * Persists whatever the current consent state allows. Runs on every
	 * capture and every consent change; the cookie is re-written from the
	 * pending buffer (the fuller picture), not merged.
	 *
	 * @return {void}
	 */
	function flush() {
		// Freshest state, not the boot-time one: the tag's engine usually
		// appears after this script, together with the `get` callbacks, and
		// GTM has just replaced push by then, so take the hook back here too.
		observeDataLayer();
		refreshConsent();

		// Written in every case, a denial included: it is the record of the
		// choice the server-side consent gate needs.
		writeCookie( config.consentCookie, {
			signals: consentSignals || {},
			captured_at: Math.floor( Date.now() / 1000 ),
		} );

		const ids = {};
		let hasIds = false;

		if ( isGranted( SIGNAL_ANALYTICS ) ) {
			if ( pending.clientId ) {
				ids.client_id = pending.clientId;
				hasIds = true;
			}

			if ( Object.keys( pending.sessions ).length ) {
				ids.sessions = pending.sessions;
				hasIds = true;
			}
		}

		if ( isGranted( SIGNAL_ADS ) ) {
			Object.keys( pending.clickIds ).forEach( function ( name ) {
				ids[ name ] = pending.clickIds[ name ];
				hasIds = true;
			} );
		}

		if ( ! hasIds ) {
			// Not "nothing to do": a withdrawal, or a late consent tool pushing
			// its denied default after a no-regime write, leaves values in the
			// cookie the current answer does not allow.
			gtm4wp_clear_cookie( config.idsCookie );

			return;
		}

		writeCookie( config.idsCookie, ids );

		// Offer the same values to an order created before they resolved.
		scheduleBackfill();
	}

	/**
	 * Re-reads the consent state into `consentSignals`.
	 *
	 * @return {boolean} Whether it differs from what was held before.
	 */
	function refreshConsent() {
		const observed = currentConsent();
		const changed =
			JSON.stringify( observed ) !== JSON.stringify( consentSignals );

		consentSignals = observed;

		return changed;
	}

	function onConsentMaybeChanged() {
		if ( refreshConsent() ) {
			flush();
		}
	}

	/**
	 * Installs, or re-installs, the data layer observer: wraps push() so any
	 * later activity re-reads the consent state (the tag's consent engine
	 * fires no event of its own). Re-installable because GTM replaces `push`
	 * when the container initialises, AFTER this deferred bundle ran; the
	 * identity check keeps wrappers from stacking.
	 *
	 * @return {void}
	 */
	function observeDataLayer() {
		const queue = window[ datalayerName ];

		if ( ! queue || queue.push === installedPush ) {
			return;
		}

		const underlying = queue.push;

		const wrapper = function () {
			const result = underlying.apply( queue, arguments );

			try {
				onConsentMaybeChanged();
			} catch ( e ) {
				// Must never break the push for everybody else.
			}

			return result;
		};

		queue.push = wrapper;
		installedPush = wrapper;
	}

	/**
	 * Captures the Google Ads click IDs from the URL (never from the `_gcl_*`
	 * cookies; works on a cached page), kept verbatim.
	 *
	 * @return {void}
	 */
	function captureClickIds() {
		let params;

		try {
			params = new URLSearchParams( window.location.search );
		} catch ( e ) {
			return;
		}

		config.clickIds.forEach( function ( name ) {
			const value = params.get( name );

			if ( value ) {
				pending.clickIds[ name ] = value;
			}
		} );
	}

	/**
	 * Queues the ID lookups: one client_id (shared across the property's
	 * streams) and one session_id per measurement ID. Queued exactly once; if
	 * no GA tag ever loads the core, the callbacks never fire and nothing is
	 * stored (better than an invented ID that can never be matched).
	 *
	 * @return {void}
	 */
	function requestIds() {
		gtagCommand(
			'get',
			config.measurementIds[ 0 ],
			'client_id',
			function ( value ) {
				if ( ! value ) {
					return;
				}

				pending.clientId = String( value );
				flush();
			}
		);

		config.measurementIds.forEach( function ( measurementId ) {
			gtagCommand(
				'get',
				measurementId,
				'session_id',
				function ( value ) {
					if ( ! value ) {
						return;
					}

					pending.sessions[ measurementId ] = String( value );
					flush();
				}
			);
		} );
	}

	/**
	 * Restores what earlier pageviews captured into the buffer.
	 *
	 * @return {void}
	 */
	function restorePending() {
		const raw = gtm4wp_read_cookie( config.idsCookie );

		if ( ! raw ) {
			return;
		}

		let stored;

		try {
			stored = JSON.parse( decodeURIComponent( raw ) );
		} catch ( e ) {
			return;
		}

		if ( ! stored || config.version !== stored.v ) {
			return;
		}

		// Everything stored, not just the click IDs: every rewrite (any consent
		// change) is built from the buffer, so anything left out would be
		// dropped. Fresh lookups overwrite these as they answer.
		config.clickIds.forEach( function ( name ) {
			if ( 'string' === typeof stored[ name ] ) {
				pending.clickIds[ name ] = stored[ name ];
			}
		} );

		if ( 'string' === typeof stored.client_id ) {
			pending.clientId = stored.client_id;
		}

		if ( stored.sessions && 'object' === typeof stored.sessions ) {
			Object.keys( stored.sessions ).forEach( function ( measurementId ) {
				if ( 'string' === typeof stored.sessions[ measurementId ] ) {
					pending.sessions[ measurementId ] =
						stored.sessions[ measurementId ];
				}
			} );
		}
	}

	/**
	 * Schedules the backfill POST as a microtask so lookups serviced in one
	 * task post together. Batching only: the ids can resolve in separate
	 * tasks, and a later arrival gets the next POST.
	 *
	 * @return {void}
	 */
	function scheduleBackfill() {
		if ( ! config.backfill || backfillScheduled ) {
			return;
		}

		backfillScheduled = true;

		Promise.resolve().then( function () {
			backfillScheduled = false;
			sendBackfill();
		} );
	}

	/**
	 * Posts the captured values to the backfill route, only on a confirmation
	 * page the server flagged as still missing its attribution, and only what
	 * consent allows storing. The body repeats every value it has (the route
	 * writes only absent fields), so a lost request is retried by the next.
	 *
	 * @return {void}
	 */
	function sendBackfill() {
		if ( ! config.backfill ) {
			return;
		}

		const values = {};

		if ( isGranted( SIGNAL_ANALYTICS ) ) {
			if ( pending.clientId ) {
				values.client_id = pending.clientId;
			}

			if ( Object.keys( pending.sessions ).length ) {
				values.sessions = pending.sessions;
			}
		}

		if ( isGranted( SIGNAL_ADS ) ) {
			Object.keys( pending.clickIds ).forEach( function ( name ) {
				values[ name ] = pending.clickIds[ name ];
			} );
		}

		// A POST only when something not yet offered arrived.
		const fresh = Object.keys( values ).filter( function ( key ) {
			return ! backfillSentKeys[ key ];
		} );

		if ( ! fresh.length ) {
			return;
		}

		fresh.forEach( function ( key ) {
			backfillSentKeys[ key ] = true;
		} );

		const body = {
			platform: config.backfill.platform,
			order: config.backfill.order,
			token: config.backfill.token,
			values,
			consent: {
				signals: consentSignals || {},
				captured_at: Math.floor( Date.now() / 1000 ),
			},
		};

		try {
			window.fetch( config.backfill.url, {
				method: 'POST',
				keepalive: true,
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.backfill.nonce,
				},
				body: JSON.stringify( body ),
			} );
		} catch ( e ) {
			// A failed backfill must never break the page.
		}
	}

	refreshConsent();
	observeDataLayer();
	restorePending();
	captureClickIds();
	requestIds();
	flush();

	// Two deferred looks that also take the push hook back from GTM: `load`
	// (the container and its consent tag have run) and `pagehide` (the last
	// moment; what the cookie holds as the visitor leaves is what the server
	// reads on the next page, so a late withdrawal still lands).
	window.addEventListener( 'load', function () {
		observeDataLayer();
		onConsentMaybeChanged();
	} );

	window.addEventListener( 'pagehide', function () {
		observeDataLayer();
		onConsentMaybeChanged();
	} );
} )();
