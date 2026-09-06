/**
 * GTM4WP Google Data Manager attribution capture.
 *
 * Collects what a later server-side event needs in order to be matched to the
 * purchase it belongs to: the Google Analytics client and session IDs, the
 * Google Ads click IDs of the visit, and the consent state the visitor is in.
 * The values are held in memory and written into two first-party cookies,
 * which the order-creation hooks read once an order exists.
 *
 * Three design rules run through the whole file:
 *
 * 1. **Ask Google's own API, never parse Google's cookies.** The IDs come from
 *    `gtag('get', <measurement id>, 'client_id'|'session_id', cb)`, the
 *    documented way to obtain them. The `_ga` / `_gcl_*` cookie formats carry
 *    no stability promise, so they are never read.
 * 2. **Queue once, then wait.** The `get` commands are pushed once and the
 *    callbacks fire whenever the Google tag core loads and replays the queue -
 *    including when a GTM container brings the core in seconds later
 *    (verified). No polling, no retries, no timers waiting for `window.gtag`.
 * 3. **Persist only what consent allows, but decide as late as possible.**
 *    Everything captured goes into a pending buffer first; writing happens
 *    when the consent state permits it, and re-runs whenever that state
 *    changes, so a banner answered on the landing page still persists the
 *    click ID that page arrived with.
 */
import {
	gtm4wp_clear_cookie,
	gtm4wp_read_cookie,
	gtm4wp_write_cookie,
} from './lib/gtm4wp-cookies';

( function () {
	'use strict';

	// Guard against double registration (#83): this bundle both runs at import
	// time and installs a dataLayer hook, so a second copy would queue the get
	// commands twice and wrap the wrapper.
	if ( window.gtm4wp_gdm_attribution_inited ) {
		return;
	}
	window.gtm4wp_gdm_attribution_inited = true;

	const config = window.gtm4wp_gdm_attribution_config;

	// No config means the module did not print one, which means capture is off.
	// Nothing to do, and deliberately no literal defaults: a measurement ID
	// cannot be guessed, and guessing the cookie contract would let this script
	// disagree with the PHP that reads it (RI-14).
	if (
		! config ||
		! config.measurementIds ||
		! config.measurementIds.length
	) {
		return;
	}

	// Printed as `var` by the module, so it really is a window property; a
	// top-level `const` in the head block would bind lexically and never show
	// up here. The 'dataLayer' fallback covers the bundle loading on a page
	// where the head block did not run.
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
	 * seen at all. The distinction matters: "no consent regime on this site" is
	 * the site owner's posture and writing is allowed, whereas an observed
	 * denial is an answer that must be respected.
	 *
	 * @type {Object|null}
	 */
	let consentSignals = null;

	/**
	 * Which value keys the confirmation-page backfill has already posted.
	 *
	 * Not a "sent once" flag, which is what this started as and what made it
	 * wrong: the client id and the session ids arrive in separate callbacks,
	 * and a single POST fired after the first of them left the second with
	 * nowhere to go. Keying it lets a later arrival be offered too, while a
	 * value already sent is never sent twice - so the number of requests is
	 * bounded by the number of distinct values, not by how often flush runs.
	 *
	 * Repeat POSTs are safe by construction anyway: the route only ever fills
	 * a field that is still empty.
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
	 * Pushes a gtag command onto the data layer.
	 *
	 * Uses an existing gtag() when the page has one (a gtag.js install, Site
	 * Kit, the consent-mode block this plugin prints itself), so commands stay
	 * on whatever queue that function feeds. Otherwise it pushes the arguments
	 * object directly, which is exactly what the standard gtag shim does - and
	 * onto the *configured* data layer, because replay only ever happens on the
	 * queue the tag core actually reads.
	 *
	 * Takes the gtag command and its arguments, forwarded verbatim.
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
	 * Reads the consent state out of the Google tag's own consent engine.
	 *
	 * This is the authority whenever it exists, because it is the state the
	 * tags themselves are gated on, and it is the ONLY place a consent choice
	 * made inside the container is visible. A consent tool implemented as a GTM
	 * template calls the sandboxed `updateConsentState()` API, which writes
	 * straight into this engine and pushes nothing onto the data layer - which
	 * is why a template that wants to *trigger* something has to push its own
	 * separate event. On a site that drives both the default and the update
	 * that way, the data layer carries no consent trace whatsoever, so a scan
	 * of it cannot tell "this site runs no consent mode" from "a consent mode I
	 * cannot see". Reading here can: the presence of these entries IS the
	 * regime.
	 *
	 * Undocumented internal, entered deliberately (registered upstream). It can
	 * only ever ADD to what we know: anything unexpected here falls back to the
	 * data-layer scan, which is exactly the behaviour without it.
	 *
	 * Per-signal shape, measured 2026-09-04:
	 * `{implicit?: bool, default: bool, update?: bool, quiet: bool}`. The
	 * discriminator is the TYPE of `update`, not its presence: the property is
	 * defined-but-`undefined` when no update was made, so a boolean means an
	 * update happened and carries the new state. Reading it as
	 * `update || default` would keep reporting granted after a withdrawal
	 * (`default: true` then `update: false`), which is the unsafe direction.
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
	 * Reads the consent state out of the data layer.
	 *
	 * The fallback for before the Google tag has loaded, when its engine does
	 * not exist yet. The queue is the authority rather than any event: gtag
	 * consent commands stay in the array after they are processed, so a single
	 * scan sees the `default` set from the head block and every later `update`,
	 * in order, whether they were pushed before or after this script loaded.
	 * Events only tell us *when* to look again.
	 *
	 * A consent command is the arguments-like ['consent', 'default'|'update',
	 * {signal: 'granted'|'denied'}] shape.
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
				// The whole map is kept, not a chosen subset: which signal
				// gates a given send is the consumer's decision, and a signal
				// dropped here could not be recovered later.
				if ( 'string' === typeof signals[ name ] ) {
					found[ name ] = signals[ name ];
				}
			} );
		}

		return found;
	}

	/**
	 * The consent state, from the best source currently available.
	 *
	 * The tag's engine wins whenever it is readable: it holds everything the
	 * data layer holds (page-level gtag commands are processed into it) plus
	 * everything the data layer cannot show (in-container template calls). The
	 * scan carries us until the tag has loaded.
	 *
	 * @return {Object|null} The signal map, or null when nothing has been observed at all.
	 */
	function currentConsent() {
		return readTagConsent() || scanConsent();
	}

	/**
	 * Whether a signal is granted in the current state.
	 *
	 * With no consent regime observed at all, this answers true: the site runs
	 * no consent mode, and that absence is the site owner's posture rather than
	 * a denial. An observed state, on the other hand, has to say "granted"
	 * exactly.
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
	 * Writes one cookie, JSON encoded and URL encoded, refusing an oversized
	 * payload rather than storing something the server-side parser will drop.
	 *
	 * The encoding is not optional: a JSON body contains commas and semicolons,
	 * which a cookie value may not carry raw. Note the asymmetry with the
	 * server side - document.cookie hands this script back exactly what was
	 * stored, so the read below decodes explicitly, while PHP decodes $_COOKIE
	 * itself and the parser there must NOT decode again.
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
	 * Persists whatever the current consent state allows.
	 *
	 * Runs on every capture and on every consent change, which is what makes a
	 * grant arriving later in the same pageview still persist the click ID the
	 * landing URL carried. Values already in a cookie are re-written rather
	 * than merged: the pending buffer is the fuller picture, since it holds
	 * everything this pageview resolved.
	 *
	 * @return {void}
	 */
	function flush() {
		// Every persistence decision reads the freshest state rather than
		// whatever was observed at boot. The tag's engine usually appears
		// AFTER this script runs, so the `get` callbacks that bring the IDs in
		// tend to arrive at the same moment the real consent state does - and
		// at that moment GTM has also just replaced push, so this is the
		// natural place to take the hook back.
		observeDataLayer();
		refreshConsent();

		// The consent map is written in every case, including a denial - it is
		// the record of the choice, which is the same basis a consent tool
		// stores its own answer on, and the server-side consent gate cannot
		// work without knowing what the visitor answered.
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
			// Nothing may be stored right now, and that is not the same as
			// "nothing to do": consent can be withdrawn after a grant, and a
			// consent tool that loads late can push its denied default moments
			// after this script has already written under the no-regime rule.
			// In both cases values are sitting in the cookie that the visitor's
			// current answer does not allow, so the cookie goes.
			gtm4wp_clear_cookie( config.idsCookie );

			return;
		}

		writeCookie( config.idsCookie, ids );

		// The same values the cookie just took, offered to the order that was
		// created before they resolved.
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
	 * Installs, or re-installs, the data layer observer.
	 *
	 * Wraps push() so that anything arriving later - a consent command, a
	 * consent-tool bridge event, any activity at all - makes us look at the
	 * consent state again. That matters more than it sounds, because the tag's
	 * consent engine fires no event of its own: a push is the only moment we
	 * are handed to notice a change.
	 *
	 * **It has to be re-installable.** GTM replaces `push` with its own
	 * function when the container initialises, which happens AFTER this
	 * deferred bundle runs - so a wrapper installed once at boot is discarded
	 * a moment later and every trigger silently disappears with it. Calling
	 * this again at each point we get to run re-wraps whatever `push` is now,
	 * and the identity check keeps that from stacking wrappers or looping when
	 * our own hook is the one already in place.
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
				// A failure here must never break the push for everybody else
				// on the data layer.
			}

			return result;
		};

		queue.push = wrapper;
		installedPush = wrapper;
	}

	/**
	 * Captures the Google Ads click IDs from the landing URL.
	 *
	 * Read from the URL on every page rather than from Google's `_gcl_*`
	 * cookies, which keeps this working on a cached page and avoids coupling to
	 * a cookie format that carries no stability promise. The values are kept
	 * verbatim: what they mean is Google's business, and re-encoding them would
	 * only risk changing them.
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
	 * Queues the ID lookups.
	 *
	 * One client_id lookup (it is shared across the property's streams) and one
	 * session_id lookup per configured measurement ID (that one is per stream).
	 * Queued exactly once: if no Google Analytics tag ever brings the tag core
	 * in, the callbacks simply never fire and nothing is stored - an honest gap
	 * is better than an invented ID, which would produce an event that can
	 * never be matched.
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
	 * Restores what earlier pageviews captured.
	 *
	 * Only used to decide whether this pageview still needs to resolve
	 * anything; the cookie stays the authority for the server side either way.
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

		// Everything stored is taken back into the buffer, not just the click
		// IDs. The buffer is what every write is built from, so anything left
		// out of it here would be dropped the next time the cookie is
		// rewritten - and rewrites happen on any consent change, not only when
		// this page resolved something of its own. The freshly queued lookups
		// overwrite these as soon as they answer.
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
	 * Schedules the backfill POST for the end of the current task.
	 *
	 * A microtask, so that lookups serviced back to back in one task are posted
	 * together rather than one request each. It is only a batching convenience,
	 * never the correctness guarantee: measured on a real site, the client id
	 * and the session id can resolve milliseconds apart in SEPARATE tasks, and
	 * anything that arrives after this has run is picked up by the next POST.
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
	 * Posts the captured values to the backfill route.
	 *
	 * Only runs on a confirmation page the server flagged as still missing its
	 * attribution - the client cannot read order meta, so that flag is the only
	 * way it could know. Carries only what consent allows storing, so a POST
	 * never holds more than the cookie would have.
	 *
	 * The body repeats every value it has, not just the newly arrived ones,
	 * which costs nothing against a write-only-if-absent route and means a
	 * first request lost to the network is retried by the next one.
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

		// Only what has not been offered yet. Everything resolved before the
		// first POST goes in that one; a lookup that answers afterwards gets a
		// second, and a flush that brings nothing new sends nothing at all.
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
			// A failed backfill costs the attribution of one order, which is
			// the same outcome as not trying; it must never break the page.
		}
	}

	refreshConsent();
	observeDataLayer();
	restorePending();
	captureClickIds();
	requestIds();
	flush();

	// Two deferred looks, and both also take the push hook back from GTM, which
	// replaces it while the container initialises.
	//
	// `load` is when the container and its consent tag have run, so it catches
	// a choice already made by then. `pagehide` is the last moment we get, and
	// it is the one that matters most for this feature: what the cookie holds
	// as the visitor leaves is what the server reads when they order on the
	// next page, so a withdrawal late in a pageview still lands before it
	// could be acted on.
	window.addEventListener( 'load', function () {
		observeDataLayer();
		onConsentMaybeChanged();
	} );

	window.addEventListener( 'pagehide', function () {
		observeDataLayer();
		onConsentMaybeChanged();
	} );
} )();
