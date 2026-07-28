/**
 * GTM4WP consent-aware data layer event queue runtime.
 *
 * This file is NOT a webpack entry and is NOT imported by any bundle: PHP
 * inlines its source verbatim into the GTM4WP head block
 * (GTM4WP\Modules\ConsentMode\ConsentQueue::add_head_js(), which reads it
 * with file_get_contents()), so it runs before the GTM container loader and
 * before every deferred GTM4WP tracker bundle. Jest requires the same file
 * directly (js/frontend/test/consent-queue-runtime.test.js), keeping this the
 * one canonical definition of the queue semantics.
 *
 * HARD CONSTRAINT: the head block is sanitized by
 * ScriptTag::print_script_block() - wp_kses() with an ampersand-only restore
 * step - so this source must not contain any angle bracket character. No
 * arrow functions, no less-than or greater-than comparisons, none inside
 * comments either: wp_kses() would entity-encode the bracket, nothing would
 * decode it back, and the whole head block would become a syntax error.
 * A regression test asserts this property of the file.
 *
 * Canonical push entry point: window.gtm4wp_datalayer_push(). Guarded
 * callers that fall back to a direct data layer push when this runtime is
 * not on the page: js/frontend/lib/gtm4wp-datalayer.js (the JS bundles) and
 * GTM4WP\Frontend\DataLayer::push_snippet() (PHP-emitted inline snippets).
 * Keep the three in sync.
 */
( function () {
	'use strict';

	// Double-init guard: the runtime must only ever be installed once.
	if ( window.gtm4wp_datalayer_push ) {
		return;
	}

	const config = window.gtm4wp_consent_queue_config || {};
	let ready = false;

	window.gtm4wp_consent_queue = [];

	function datalayer() {
		const dlName = window.gtm4wp_datalayer_name || 'dataLayer';
		window[ dlName ] = window[ dlName ] || [];
		return window[ dlName ];
	}

	/**
	 * Pushes a data layer event object, or queues it while the visitor's
	 * consent choice is not yet available.
	 *
	 * @param {Object} dlObj The data layer event object.
	 */
	window.gtm4wp_datalayer_push = function ( dlObj ) {
		if ( ready ) {
			datalayer().push( dlObj );
			return;
		}

		if ( dlObj && 'function' === typeof dlObj.eventCallback ) {
			// GTM does not process the push while it sits in the queue, so
			// the eventTimeout safety would never run either: invoke the
			// callback promptly (async, so the calling code finishes first)
			// and strip it - replaying it later would run it a second time.
			const eventCallback = dlObj.eventCallback;
			delete dlObj.eventCallback;
			delete dlObj.eventTimeout;
			setTimeout( eventCallback, 0 );
		}

		window.gtm4wp_consent_queue.push( dlObj );
	};

	/**
	 * Unlocks the queue: replays every held event in its original order and
	 * lets every later push pass straight through.
	 *
	 * Idempotent - the first consent signal wins. Any repeated or
	 * overlapping signal (a consent tool re-firing its event on a later
	 * consent change, a vendor firing two of its documented events, or the
	 * generic gtm4wp_consent_ready event arriving on top of the vendor
	 * event) is a no-op, so the queue is replayed exactly once.
	 */
	window.gtm4wp_consent_unlock = function () {
		if ( ready ) {
			return;
		}

		ready = true;

		while ( window.gtm4wp_consent_queue.length ) {
			datalayer().push( window.gtm4wp_consent_queue.shift() );
		}
	};

	// Generic signal, always installed: any consent tool or custom
	// integration can dispatch this browser event on document.
	document.addEventListener( 'gtm4wp_consent_ready', function () {
		window.gtm4wp_consent_unlock();
	} );

	function cookieContains( fragment ) {
		return -1 !== document.cookie.indexOf( fragment );
	}

	/*
	 * Vendor adapters. Exactly one is installed, selected by the config.cmp
	 * value (allow-listed on the PHP side). Each adapter maps the vendor's
	 * documented browser event onto the idempotent unlock above and - where
	 * the vendor exposes one - runs a synchronous "was the choice already
	 * available before this script ran" check, closing the race when the
	 * consent tool executed earlier in the page with a stored consent.
	 * Reading the vendor's own consent cookie here only answers "does a
	 * stored choice exist" - the granted/denied detail is never inspected.
	 */
	const adapters = {
		cookiebot() {
			window.addEventListener( 'CookiebotOnConsentReady', function () {
				window.gtm4wp_consent_unlock();
			} );

			if ( window.Cookiebot && window.Cookiebot.hasResponse ) {
				window.gtm4wp_consent_unlock();
			}
		},

		cookieyes() {
			document.addEventListener( 'cookieyes_consent_update', function () {
				window.gtm4wp_consent_unlock();
			} );

			// Fires on every page load with the current state; it only
			// counts as "choice available" when the visitor has actually
			// completed a banner action, now or on an earlier visit.
			document.addEventListener( 'cookieyes_banner_load', function ( e ) {
				if ( e && e.detail && e.detail.isUserActionCompleted ) {
					window.gtm4wp_consent_unlock();
				}
			} );
		},

		complianz() {
			document.addEventListener( 'cmplz_fire_categories', function () {
				window.gtm4wp_consent_unlock();
			} );

			document.addEventListener( 'cmplz_status_change', function () {
				window.gtm4wp_consent_unlock();
			} );

			if ( cookieContains( 'cmplz_banner-status=dismissed' ) ) {
				window.gtm4wp_consent_unlock();
			}
		},

		borlabs() {
			window.addEventListener(
				'borlabs-cookie-consent-saved',
				function () {
					window.gtm4wp_consent_unlock();
				}
			);

			// after-init fires on every load once the tool is up; it only
			// counts when a consent cookie from an earlier visit exists.
			window.addEventListener( 'borlabs-cookie-after-init', function () {
				if ( cookieContains( 'borlabs-cookie=' ) ) {
					window.gtm4wp_consent_unlock();
				}
			} );

			if ( window.BorlabsCookie && cookieContains( 'borlabs-cookie=' ) ) {
				window.gtm4wp_consent_unlock();
			}
		},

		'wp-consent-api'() {
			document.addEventListener(
				'wp_listen_for_consent_change',
				function () {
					window.gtm4wp_consent_unlock();
				}
			);

			// A stored choice does not necessarily re-fire the change event
			// on later page loads, but the standard persists it in
			// wp_consent_* cookies.
			if ( cookieContains( 'wp_consent_' ) ) {
				window.gtm4wp_consent_unlock();
			}
		},

		custom() {
			// Nothing vendor specific: the always-installed
			// gtm4wp_consent_ready listener and window.gtm4wp_consent_unlock()
			// are the documented contract for custom integrations.
		},
	};

	if ( 'function' === typeof adapters[ config.cmp ] ) {
		adapters[ config.cmp ]();
	}

	// Debugging aid only. The queue is intentionally held forever when no
	// consent signal arrives (no timeout-flush by design), but a
	// misconfigured tool selection would be hard to spot without a hint.
	setTimeout( function () {
		if ( ! ready && window.gtm4wp_consent_queue.length ) {
			console.warn(
				'GTM4WP consent-aware event queue: no consent signal received after 15 seconds, ' +
					window.gtm4wp_consent_queue.length +
					' data layer event(s) still on hold. Verify the consent tool selection in the GTM4WP settings.'
			);
		}
	}, 15000 );
} )();
