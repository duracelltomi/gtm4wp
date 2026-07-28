/**
 * Unit tests for the consent-aware data layer event queue runtime
 * (js/frontend/lib/gtm4wp-consent-queue-runtime.js).
 *
 * The runtime is inlined into the head block by PHP (ConsentQueue::add_head_js())
 * and required here directly, so these tests exercise the one canonical
 * definition of the queue/replay/adapter semantics.
 */

const RUNTIME = '../lib/gtm4wp-consent-queue-runtime';

// Cookies the vendor adapters sniff; expired in afterEach so no test can
// leak a stored-consent state into the next one (TS-7).
const TEST_COOKIES = [
	'cmplz_banner-status',
	'borlabs-cookie',
	'wp_consent_marketing',
];

describe( 'gtm4wp-consent-queue-runtime', () => {
	let docAdd;
	let winAdd;

	// (Re-)runs the runtime IIFE with the given config, as the PHP-inlined
	// head block would.
	function installRuntime( config ) {
		window.gtm4wp_consent_queue_config = config;
		jest.isolateModules( () => {
			require( RUNTIME );
		} );
	}

	beforeEach( () => {
		jest.useFakeTimers();

		// Record every listener the runtime attaches so afterEach can detach
		// it — a stale adapter listener from a previous install would
		// otherwise call the current test's unlock (TS-7).
		docAdd = jest.spyOn( document, 'addEventListener' );
		winAdd = jest.spyOn( window, 'addEventListener' );

		window.gtm4wp_datalayer_name = 'dataLayer';
		window.dataLayer = [];
		delete window.gtm4wp_datalayer_push;
		delete window.gtm4wp_consent_unlock;
		delete window.gtm4wp_consent_queue;
		delete window.gtm4wp_consent_queue_config;
		delete window.Cookiebot;
		delete window.BorlabsCookie;
		delete window.customDL;
	} );

	afterEach( () => {
		docAdd.mock.calls.forEach( ( [ type, listener ] ) =>
			document.removeEventListener( type, listener )
		);
		winAdd.mock.calls.forEach( ( [ type, listener ] ) =>
			window.removeEventListener( type, listener )
		);
		docAdd.mockRestore();
		winAdd.mockRestore();

		TEST_COOKIES.forEach( ( name ) => {
			document.cookie =
				name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
		} );

		jest.useRealTimers();
	} );

	describe( 'queueing and replay', () => {
		it( 'queues pushes while locked and replays them in FIFO order on unlock', () => {
			installRuntime( { cmp: 'custom' } );

			window.gtm4wp_datalayer_push( { event: 'first' } );
			window.gtm4wp_datalayer_push( { event: 'second' } );

			expect( window.dataLayer ).toHaveLength( 0 );
			expect( window.gtm4wp_consent_queue ).toHaveLength( 2 );

			window.gtm4wp_consent_unlock();

			expect( window.dataLayer ).toEqual( [
				{ event: 'first' },
				{ event: 'second' },
			] );
			expect( window.gtm4wp_consent_queue ).toHaveLength( 0 );
		} );

		it( 'passes pushes straight through once unlocked', () => {
			installRuntime( { cmp: 'custom' } );

			window.gtm4wp_consent_unlock();
			window.gtm4wp_datalayer_push( { event: 'later' } );

			expect( window.dataLayer ).toEqual( [ { event: 'later' } ] );
			expect( window.gtm4wp_consent_queue ).toHaveLength( 0 );
		} );

		it( 'replays exactly once on repeated unlock calls', () => {
			installRuntime( { cmp: 'custom' } );

			window.gtm4wp_datalayer_push( { event: 'once' } );
			window.gtm4wp_consent_unlock();
			window.gtm4wp_consent_unlock();

			expect( window.dataLayer ).toEqual( [ { event: 'once' } ] );
		} );

		it( 'does not re-install over an existing runtime (double-init guard)', () => {
			installRuntime( { cmp: 'custom' } );

			const firstPush = window.gtm4wp_datalayer_push;
			window.gtm4wp_datalayer_push( { event: 'kept' } );

			installRuntime( { cmp: 'custom' } );

			expect( window.gtm4wp_datalayer_push ).toBe( firstPush );
			expect( window.gtm4wp_consent_queue ).toHaveLength( 1 );
		} );

		it( 'honors a custom data layer variable name at replay time', () => {
			window.gtm4wp_datalayer_name = 'customDL';
			installRuntime( { cmp: 'custom' } );

			window.gtm4wp_datalayer_push( { event: 'renamed' } );
			window.gtm4wp_consent_unlock();

			expect( window.customDL ).toEqual( [ { event: 'renamed' } ] );
			expect( window.dataLayer ).toHaveLength( 0 );

			window.gtm4wp_datalayer_name = 'dataLayer';
		} );
	} );

	describe( 'eventCallback handling', () => {
		it( 'invokes a queued eventCallback promptly, strips it and never re-runs it on replay', () => {
			installRuntime( { cmp: 'custom' } );

			const callback = jest.fn();
			window.gtm4wp_datalayer_push( {
				event: 'add_to_cart',
				eventCallback: callback,
				eventTimeout: 2000,
			} );

			// Async on purpose: the pushing code must finish first.
			expect( callback ).not.toHaveBeenCalled();
			jest.advanceTimersByTime( 0 );
			expect( callback ).toHaveBeenCalledTimes( 1 );

			const queued = window.gtm4wp_consent_queue[ 0 ];
			expect( queued.eventCallback ).toBeUndefined();
			expect( queued.eventTimeout ).toBeUndefined();

			window.gtm4wp_consent_unlock();
			jest.runOnlyPendingTimers();

			expect( callback ).toHaveBeenCalledTimes( 1 );
			expect( window.dataLayer ).toEqual( [ { event: 'add_to_cart' } ] );
		} );

		it( 'leaves the eventCallback on pass-through pushes after unlock', () => {
			installRuntime( { cmp: 'custom' } );
			window.gtm4wp_consent_unlock();

			const callback = jest.fn();
			window.gtm4wp_datalayer_push( {
				event: 'select_item',
				eventCallback: callback,
			} );

			expect( window.dataLayer[ 0 ].eventCallback ).toBe( callback );
			expect( callback ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'generic consent-ready signal', () => {
		it( 'unlocks on the gtm4wp_consent_ready document event regardless of the selected tool', () => {
			installRuntime( { cmp: 'custom' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			document.dispatchEvent( new Event( 'gtm4wp_consent_ready' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'installs no vendor adapter for an unknown cmp value but still honors the generic signal', () => {
			installRuntime( { cmp: 'nonsense' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			window.dispatchEvent( new Event( 'CookiebotOnConsentReady' ) );
			expect( window.dataLayer ).toHaveLength( 0 );

			document.dispatchEvent( new Event( 'gtm4wp_consent_ready' ) );
			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );
	} );

	describe( 'Cookiebot adapter', () => {
		it( 'unlocks on CookiebotOnConsentReady', () => {
			installRuntime( { cmp: 'cookiebot' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			window.dispatchEvent( new Event( 'CookiebotOnConsentReady' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'is ready immediately when Cookiebot already has a response (stored consent race)', () => {
			window.Cookiebot = { hasResponse: true };
			installRuntime( { cmp: 'cookiebot' } );

			window.gtm4wp_datalayer_push( { event: 'direct' } );

			expect( window.dataLayer ).toEqual( [ { event: 'direct' } ] );
		} );

		it( 'stays locked when Cookiebot is present without a response yet', () => {
			window.Cookiebot = { hasResponse: false };
			installRuntime( { cmp: 'cookiebot' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );

			expect( window.dataLayer ).toHaveLength( 0 );
		} );
	} );

	describe( 'CookieYes adapter', () => {
		it( 'unlocks on cookieyes_consent_update', () => {
			installRuntime( { cmp: 'cookieyes' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			document.dispatchEvent( new Event( 'cookieyes_consent_update' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'ignores cookieyes_banner_load while no user action has completed', () => {
			installRuntime( { cmp: 'cookieyes' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			document.dispatchEvent(
				new CustomEvent( 'cookieyes_banner_load', {
					detail: { isUserActionCompleted: false },
				} )
			);

			expect( window.dataLayer ).toHaveLength( 0 );
		} );

		it( 'unlocks on cookieyes_banner_load with a completed user action (stored consent)', () => {
			installRuntime( { cmp: 'cookieyes' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			document.dispatchEvent(
				new CustomEvent( 'cookieyes_banner_load', {
					detail: { isUserActionCompleted: true },
				} )
			);

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );
	} );

	describe( 'Complianz adapter', () => {
		it( 'unlocks on cmplz_fire_categories', () => {
			installRuntime( { cmp: 'complianz' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			document.dispatchEvent( new Event( 'cmplz_fire_categories' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'unlocks on cmplz_status_change', () => {
			installRuntime( { cmp: 'complianz' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			document.dispatchEvent( new Event( 'cmplz_status_change' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'is ready immediately when the banner was dismissed on an earlier visit', () => {
			document.cookie = 'cmplz_banner-status=dismissed; path=/';
			installRuntime( { cmp: 'complianz' } );

			window.gtm4wp_datalayer_push( { event: 'direct' } );

			expect( window.dataLayer ).toEqual( [ { event: 'direct' } ] );
		} );
	} );

	describe( 'Borlabs Cookie adapter', () => {
		it( 'unlocks on borlabs-cookie-consent-saved', () => {
			installRuntime( { cmp: 'borlabs' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			window.dispatchEvent( new Event( 'borlabs-cookie-consent-saved' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'ignores borlabs-cookie-after-init without a stored consent cookie', () => {
			installRuntime( { cmp: 'borlabs' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			window.dispatchEvent( new Event( 'borlabs-cookie-after-init' ) );

			expect( window.dataLayer ).toHaveLength( 0 );
		} );

		it( 'unlocks on borlabs-cookie-after-init when a stored consent cookie exists', () => {
			document.cookie =
				'borlabs-cookie=%7B%22consents%22%3A%7B%7D%7D; path=/';
			installRuntime( { cmp: 'borlabs' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			window.dispatchEvent( new Event( 'borlabs-cookie-after-init' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'is ready immediately when Borlabs already ran with a stored consent (race)', () => {
			document.cookie = 'borlabs-cookie=%7B%7D; path=/';
			window.BorlabsCookie = {};
			installRuntime( { cmp: 'borlabs' } );

			window.gtm4wp_datalayer_push( { event: 'direct' } );

			expect( window.dataLayer ).toEqual( [ { event: 'direct' } ] );
		} );
	} );

	describe( 'WP Consent API adapter', () => {
		it( 'unlocks on wp_listen_for_consent_change', () => {
			installRuntime( { cmp: 'wp-consent-api' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );
			document.dispatchEvent(
				new CustomEvent( 'wp_listen_for_consent_change', {
					detail: { marketing: 'allow' },
				} )
			);

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );

		it( 'is ready immediately when a wp_consent_* cookie stores an earlier choice', () => {
			document.cookie = 'wp_consent_marketing=allow; path=/';
			installRuntime( { cmp: 'wp-consent-api' } );

			window.gtm4wp_datalayer_push( { event: 'direct' } );

			expect( window.dataLayer ).toEqual( [ { event: 'direct' } ] );
		} );
	} );

	describe( 'overlapping consent signals', () => {
		it( 'replays exactly once when multiple signals arrive (native event + WP Consent API + generic)', () => {
			installRuntime( { cmp: 'wp-consent-api' } );

			window.gtm4wp_datalayer_push( { event: 'held' } );

			document.dispatchEvent(
				new CustomEvent( 'wp_listen_for_consent_change', {
					detail: { marketing: 'allow' },
				} )
			);
			// The vendor's own native event and the generic signal on top —
			// e.g. a CMP that supports both paths.
			document.dispatchEvent( new Event( 'cookieyes_consent_update' ) );
			document.dispatchEvent( new Event( 'gtm4wp_consent_ready' ) );

			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );

			// A repeated vendor event after unlock stays a no-op.
			document.dispatchEvent(
				new CustomEvent( 'wp_listen_for_consent_change', {
					detail: { statistics: 'allow' },
				} )
			);
			expect( window.dataLayer ).toEqual( [ { event: 'held' } ] );
		} );
	} );

	describe( 'hold-forever behavior', () => {
		it( 'never auto-unlocks and warns once after 15 seconds with events on hold', () => {
			const warn = jest
				.spyOn( console, 'warn' )
				.mockImplementation( () => {} );

			installRuntime( { cmp: 'cookiebot' } );
			window.gtm4wp_datalayer_push( { event: 'held' } );

			jest.advanceTimersByTime( 15000 );

			expect( window.dataLayer ).toHaveLength( 0 );
			expect( window.gtm4wp_consent_queue ).toHaveLength( 1 );
			expect( warn ).toHaveBeenCalledTimes( 1 );

			jest.advanceTimersByTime( 60000 );
			expect( warn ).toHaveBeenCalledTimes( 1 );

			warn.mockRestore();
		} );

		it( 'does not warn when the queue was unlocked in time', () => {
			const warn = jest
				.spyOn( console, 'warn' )
				.mockImplementation( () => {} );

			installRuntime( { cmp: 'custom' } );
			window.gtm4wp_datalayer_push( { event: 'held' } );
			window.gtm4wp_consent_unlock();

			jest.advanceTimersByTime( 15000 );
			expect( warn ).not.toHaveBeenCalled();

			warn.mockRestore();
		} );
	} );
} );
