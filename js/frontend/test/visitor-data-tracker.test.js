/**
 * Unit tests for the cache-safe data layer client runtime
 * (js/frontend/gtm4wp-visitor-data.js).
 *
 * The tracker runs once, at load time, and pushes a single gtm4wp.visitorData
 * event carrying the Tier 1 fields (values the browser can compute itself) the
 * PHP config lists. Each test sets the browser sources (location.search,
 * document.referrer) and the config first, then loads the module fresh with
 * jest.isolateModules so its IIFE re-runs against that state (TC-9).
 *
 * The dataLayer push is a structured object sink (TC-11), so there is no HTML
 * output-encoding surface at the push site; the untrusted-input contract that
 * matters is raw-passthrough (the referrer/search value reaches the push
 * verbatim, not entity-encoded — the downstream GTM tag owns any DOM escaping).
 */

/**
 * Sets window.location.search for the current test via the History API, which
 * jsdom honors. Reset to '/' in beforeEach so nothing leaks between tests (TS-7).
 *
 * @param {string} search The query string to set (without the leading '?').
 * @return {void}
 */
function setSearch( search ) {
	window.history.replaceState( {}, '', '' === search ? '/' : '/?' + search );
}

/**
 * Overrides document.referrer for the current test (read-only in jsdom).
 *
 * @param {string} referrer The referrer URL.
 * @return {void}
 */
function setReferrer( referrer ) {
	Object.defineProperty( window.document, 'referrer', {
		value: referrer,
		configurable: true,
	} );
}

/**
 * Loads the tracker fresh so its load-time IIFE re-runs.
 *
 * @return {void}
 */
function loadTracker() {
	jest.isolateModules( () => {
		require( '../gtm4wp-visitor-data' );
	} );
}

const visitorEvents = () =>
	window.dataLayer.filter(
		( entry ) => entry.event === 'gtm4wp.visitorData'
	);

// The runtime attaches a MutationObserver to the shared jsdom document.body for
// the WooCommerce cart fragment; without cleanup it leaks across isolateModules
// reloads and fires (throwing on a torn-down document) in later tests (TS-7).
// Track every observer the tracker creates and disconnect it after each test.
let trackedObservers = [];
const RealMutationObserver = window.MutationObserver;
beforeEach( () => {
	trackedObservers = [];
	window.MutationObserver = function ( callback ) {
		const observer = new RealMutationObserver( callback );
		trackedObservers.push( observer );
		return observer;
	};
} );
afterEach( () => {
	trackedObservers.forEach( ( observer ) => observer.disconnect() );
	window.MutationObserver = RealMutationObserver;
} );

describe( 'gtm4wp-visitor-data', () => {
	beforeEach( () => {
		window.dataLayer = [];
		window.gtm4wp_datalayer_name = 'dataLayer';
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {
				siteSearchTerm: 'searchTerm',
				siteSearchFrom: 'searchReferrer',
			},
		};
		setSearch( '' );
		setReferrer( '' );
	} );

	it( 'pushes the moved search term and referrer under their existing names', () => {
		setSearch( 's=' + encodeURIComponent( 'blue shoes' ) );
		setReferrer( 'https://ref.example/land/?utm=abc&x=1' );

		loadTracker();

		expect( visitorEvents() ).toHaveLength( 1 );
		expect( visitorEvents()[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.visitorData',
			siteSearchTerm: 'blue shoes',
			// Normalized like the 1.x siteSearchFrom: path + '?' + urlencoded query.
			siteSearchFrom: 'https://ref.example/land/?utm%3Dabc%26x%3D1',
		} );
	} );

	it( 'passes a hostile search term/referrer through raw (structured sink, TC-11)', () => {
		const hostileTerm = '</script><img src=x onerror=alert(1)>';
		setSearch( 's=' + encodeURIComponent( hostileTerm ) );
		// A referrer with no query string is returned verbatim.
		setReferrer( 'https://evil.example/"><script>' );

		loadTracker();

		const push = visitorEvents()[ 0 ];
		// The raw value reaches the object untouched — no HTML-entity encoding at
		// the push site (the GTM tag that writes it to the DOM owns escaping).
		expect( push.siteSearchTerm ).toBe( hostileTerm );
		expect( push.siteSearchFrom ).toBe( 'https://evil.example/"><script>' );
	} );

	it( 'omits a field whose producer yields nothing (empty search / no referrer)', () => {
		// No ?s= in the URL and no referrer: both producers return '' so neither
		// key is pushed — but there is nothing to push at all, so no event fires.
		setSearch( '' );
		setReferrer( '' );

		loadTracker();

		expect( visitorEvents() ).toHaveLength( 0 );
	} );

	it( 'pushes only the search term when the referrer is absent', () => {
		setSearch( 's=hats' );
		setReferrer( '' );

		loadTracker();

		const push = visitorEvents()[ 0 ];
		expect( push.siteSearchTerm ).toBe( 'hats' );
		expect( push ).not.toHaveProperty( 'siteSearchFrom' );
	} );

	it( 'computes only the fields the config lists', () => {
		// Config asks for the referrer only; the search term must not be pushed
		// even though ?s= is present in the URL.
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: { siteSearchFrom: 'searchReferrer' },
		};
		setSearch( 's=ignored' );
		setReferrer( 'https://ref.example/page' );

		loadTracker();

		const push = visitorEvents()[ 0 ];
		expect( push.siteSearchFrom ).toBe( 'https://ref.example/page' );
		expect( push ).not.toHaveProperty( 'siteSearchTerm' );
	} );

	it( 'ignores an unknown producer token without throwing', () => {
		window.gtm4wp_visitordata_config = {
			fields: { somethingNew: 'notAProducerYet' },
		};

		expect( () => loadTracker() ).not.toThrow();
		expect( visitorEvents() ).toHaveLength( 0 );
	} );

	it( 'does not throw and pushes nothing when no config is present', () => {
		delete window.gtm4wp_visitordata_config;

		expect( () => loadTracker() ).not.toThrow();
		expect( visitorEvents() ).toHaveLength( 0 );
	} );
} );

/**
 * Resets document.cookie, both storage areas, the data layer and fetch between
 * tests so the endpoint gating (TS-7) never leaks state across cases.
 *
 * @return {void}
 */
function resetBrowserState() {
	document.cookie.split( ';' ).forEach( function ( pair ) {
		const name = pair.split( '=' )[ 0 ].trim();
		if ( name ) {
			document.cookie =
				name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
		}
	} );
	window.sessionStorage.clear();
	window.localStorage.clear();
	window.dataLayer = [];
	window.gtm4wp_datalayer_name = 'dataLayer';
}

/**
 * Sets a cookie for the current test.
 *
 * @param {string} name  Cookie name.
 * @param {string} value Cookie value.
 * @return {void}
 */
function setCookie( name, value ) {
	document.cookie = name + '=' + value + ';path=/';
}

/**
 * Mocks the session-endpoint fetch to resolve once with the given data map
 * (wrapped in the hex-encoded string payload the endpoint returns).
 *
 * @param {Object} data The visitor data map the endpoint should return.
 * @return {void}
 */
function mockEndpointOnce( data ) {
	global.fetch.mockResolvedValueOnce( {
		ok: true,
		json: () => Promise.resolve( { payload: JSON.stringify( data ) } ),
	} );
}

/**
 * Flushes the microtask/macrotask queue so the fetch().then() chain settles.
 *
 * @return {Promise<void>}
 */
function flush() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'gtm4wp-visitor-data — session endpoint (Tier 2/3)', () => {
	beforeEach( () => {
		resetBrowserState();
		global.fetch = jest.fn();
	} );

	it( 'fetches Tier 2 once per session, then replays from cache with no request', async () => {
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};

		mockEndpointOnce( { visitorIP: '8.8.8.8' } );

		loadTracker();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect(
			visitorEvents().find( ( e ) => e.visitorIP === '8.8.8.8' )
		).toBeTruthy();

		// A later page view in the same session replays from sessionStorage — no fetch.
		window.dataLayer = [];
		loadTracker();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect(
			visitorEvents().find( ( e ) => e.visitorIP === '8.8.8.8' )
		).toBeTruthy();
	} );

	it( 'sends the nonce as X-WP-Nonce for the logged-in cookie auth', async () => {
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'secret-nonce',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};
		mockEndpointOnce( { visitorIP: '1.1.1.1' } );

		loadTracker();
		await flush();

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'secret-nonce' );
	} );

	it( 'does NOT fetch when the gate cookie is unchanged (cookie gate suppresses it)', async () => {
		// Seed the cache as if we already fetched at cookie value "abc".
		window.sessionStorage.setItem(
			'gtm4wp_visitor_session',
			JSON.stringify( {
				gates: {
					gtm4wp_login: {
						v: 'abc',
						data: { visitorEmail: 'user@example.com' },
					},
				},
			} )
		);
		setCookie( 'gtm4wp_login', 'abc' );

		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			gates: [ { cookie: 'gtm4wp_login', keys: [ 'visitorEmail' ] } ],
		};

		loadTracker();
		await flush();

		expect( global.fetch ).not.toHaveBeenCalled();
		// The unchanged value is still replayed from cache (present on every page).
		expect(
			visitorEvents().find(
				( e ) => e.visitorEmail === 'user@example.com'
			)
		).toBeTruthy();
	} );

	it( 'fetches when the gate cookie changed since the last fetch', async () => {
		window.sessionStorage.setItem(
			'gtm4wp_visitor_session',
			JSON.stringify( {
				gates: {
					gtm4wp_login: {
						v: 'OLD',
						data: { visitorEmail: 'stale@example.com' },
					},
				},
			} )
		);
		setCookie( 'gtm4wp_login', 'NEW' );

		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			gates: [ { cookie: 'gtm4wp_login', keys: [ 'visitorEmail' ] } ],
		};
		mockEndpointOnce( { visitorEmail: 'fresh@example.com' } );

		loadTracker();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect(
			visitorEvents().find(
				( e ) => e.visitorEmail === 'fresh@example.com'
			)
		).toBeTruthy();
	} );

	it( 'never fetches user data for an anonymous visitor (no gate cookie)', async () => {
		// Gates only, no session fields, and the login cookie is absent.
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			gates: [ { cookie: 'gtm4wp_login', keys: [ 'visitorEmail' ] } ],
		};

		loadTracker();
		await flush();

		expect( global.fetch ).not.toHaveBeenCalled();
		expect(
			visitorEvents().find( ( e ) => 'visitorEmail' in e )
		).toBeFalsy();
	} );

	it( 'drops cached identity data and does not replay it after logout', async () => {
		window.sessionStorage.setItem(
			'gtm4wp_visitor_session',
			JSON.stringify( {
				gates: {
					gtm4wp_login: {
						v: 'abc',
						data: { visitorEmail: 'user@example.com' },
					},
				},
			} )
		);
		// The gate cookie is gone (logged out).
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			gates: [ { cookie: 'gtm4wp_login', keys: [ 'visitorEmail' ] } ],
		};

		loadTracker();
		await flush();

		expect( global.fetch ).not.toHaveBeenCalled();
		expect(
			visitorEvents().find(
				( e ) => e.visitorEmail === 'user@example.com'
			)
		).toBeFalsy();

		const store = JSON.parse(
			window.sessionStorage.getItem( 'gtm4wp_visitor_session' )
		);
		expect( store.gates.gtm4wp_login ).toBeUndefined();
	} );
} );

describe( 'gtm4wp-visitor-data — WooCommerce cart fragment', () => {
	beforeEach( () => {
		resetBrowserState();
		global.fetch = jest.fn();
		document.body.innerHTML = '';
	} );

	it( 'pushes the customer/cart block carried on the cart-fragments placeholder', () => {
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
		};

		const block = {
			customerTotalOrders: 3,
			customerBillingCompany: 'Acme',
			cartContent: { items: [ { item_name: 'Mug' } ] },
		};
		const el = document.createElement( 'div' );
		el.className = 'gtm4wp-wc-visitor-data';
		el.setAttribute( 'data-gtm4wp-visitor-cart', JSON.stringify( block ) );
		document.body.appendChild( el );

		loadTracker();

		const push = visitorEvents().find(
			( e ) => e.customerTotalOrders === 3
		);
		expect( push ).toBeTruthy();
		expect( push.customerBillingCompany ).toBe( 'Acme' );
		expect( push.cartContent.items[ 0 ].item_name ).toBe( 'Mug' );
	} );

	it( 'passes a hostile customer field through raw (structured sink, TC-11)', () => {
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
		};

		const hostile = '</script>"&<x';
		const el = document.createElement( 'div' );
		el.className = 'gtm4wp-wc-visitor-data';
		el.setAttribute(
			'data-gtm4wp-visitor-cart',
			JSON.stringify( { customerBillingCompany: hostile } )
		);
		document.body.appendChild( el );

		loadTracker();

		const push = visitorEvents().find(
			( e ) => e.customerBillingCompany === hostile
		);
		expect( push ).toBeTruthy();
	} );

	it( 'does nothing when there is no fragment placeholder', () => {
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
		};

		expect( () => loadTracker() ).not.toThrow();
		expect( visitorEvents() ).toHaveLength( 0 );
	} );
} );

describe( 'gtm4wp-visitor-data — single merged push', () => {
	beforeEach( () => {
		resetBrowserState();
		global.fetch = jest.fn();
		document.body.innerHTML = '';
	} );

	/**
	 * Adds the WooCommerce cart-fragment placeholder with the given block.
	 *
	 * @param {Object} block The cart block JSON.
	 * @return {void}
	 */
	function addCartElement( block ) {
		const el = document.createElement( 'div' );
		el.className = 'gtm4wp-wc-visitor-data';
		el.setAttribute( 'data-gtm4wp-visitor-cart', JSON.stringify( block ) );
		document.body.appendChild( el );
	}

	it( 'merges Tier 1, the endpoint and the cart into ONE push on the first view', async () => {
		addCartElement( { cartContent: { items: [] } } );
		window.history.replaceState( {}, '', '/?s=shoes' );

		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: { siteSearchTerm: 'searchTerm' },
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};
		mockEndpointOnce( { visitorIP: '8.8.8.8' } );

		loadTracker();
		await flush();

		// The endpoint data appears exactly once — not split from the rest — and
		// that single push carries all three sources together.
		const withVisitor = visitorEvents().filter( ( e ) => 'visitorIP' in e );
		expect( withVisitor ).toHaveLength( 1 );
		expect( withVisitor[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.visitorData',
			siteSearchTerm: 'shoes',
			visitorIP: '8.8.8.8',
			cartContent: { items: [] },
		} );
	} );

	it( 'replays as ONE synchronous push on a cached view, with no fetch', () => {
		window.sessionStorage.setItem(
			'gtm4wp_visitor_session',
			JSON.stringify( { gates: {}, session: { visitorIP: '8.8.8.8' } } )
		);
		addCartElement( { cartContent: { items: [] } } );

		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};

		// No await: the replay + cart read are synchronous.
		loadTracker();

		expect( global.fetch ).not.toHaveBeenCalled();
		const withVisitor = visitorEvents().filter( ( e ) => 'visitorIP' in e );
		expect( withVisitor ).toHaveLength( 1 );
		expect( withVisitor[ 0 ] ).toMatchObject( {
			visitorIP: '8.8.8.8',
			cartContent: { items: [] },
		} );
	} );
} );

/**
 * Phase 3: the two WooCommerce one-shot events (an add_to_cart after the cart
 * "Undo" and the reliable-purchase fallback) delivered over the same endpoint but
 * cookie-gated, fired ONCE each with a per-event de-dupe guard, and never merged
 * into the gtm4wp.visitorData push nor cached/replayed. The purchase reuses the
 * SAME gtm4wp_orderid_tracked guard the order-received page's inline block writes,
 * so a fallback fire and a real order-received purchase for one order never both
 * count.
 */
describe( 'gtm4wp-visitor-data — one-shot events (Phase 3)', () => {
	const ENDPOINT = 'https://site.example/wp-json/gtm4wp/v2/visitor-data';
	const CONFIRM_URL =
		'https://site.example/wp-json/gtm4wp/v2/confirm-purchase-tracked';
	const EVENT_COOKIE = 'gtm4wp_woo_event';

	beforeEach( () => {
		resetBrowserState();
		global.fetch = jest.fn();
		document.body.innerHTML = '';
	} );

	/**
	 * A config that watches the shared one-shot event cookie for the given keys.
	 *
	 * @param {string[]} keys One-shot field keys (readdedToCart / pendingPurchase).
	 * @return {Object} The client config.
	 */
	function actionConfig( keys ) {
		return {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: ENDPOINT,
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			actions: [ { cookie: EVENT_COOKIE, keys } ],
		};
	}

	/**
	 * As actionConfig(), but the action entry carries a per-key confirm-beacon URL
	 * map (issue #398): the client fires that authenticated POST after delivering the
	 * one-shot so the server can flag the order tracked, keeping the GET read-only.
	 *
	 * @param {string[]} keys       One-shot field keys.
	 * @param {Object}   confirmMap key => confirm-beacon URL.
	 * @return {Object} The client config.
	 */
	function actionConfigConfirm( keys, confirmMap ) {
		const config = actionConfig( keys );
		config.actions[ 0 ].confirm = confirmMap;
		return config;
	}

	const confirmBeacon = () =>
		global.fetch.mock.calls.find( ( call ) => call[ 0 ] === CONFIRM_URL );

	const eventsNamed = ( name ) =>
		window.dataLayer.filter( ( entry ) => entry.event === name );

	const purchasePayload = ( orderNumber, flag ) => ( {
		pendingPurchase: {
			push: {
				event: 'purchase',
				ecommerce: {
					currency: 'EUR',
					transaction_id: orderNumber,
					value: 100,
					items: [ { item_name: 'Mug' } ],
				},
			},
			orderNumber,
			flag,
		},
	} );

	const readdedPayload = ( token ) => ( {
		readdedToCart: {
			push: {
				event: 'add_to_cart',
				ecommerce: {
					currency: 'EUR',
					value: 20,
					items: [ { item_name: 'Mug' } ],
				},
			},
			token,
		},
	} );

	it( 'does NOT fetch and pushes nothing when the event cookie is absent', async () => {
		window.gtm4wp_visitordata_config = actionConfig( [
			'readdedToCart',
			'pendingPurchase',
		] );
		// No event cookie set: an anonymous cached-page visitor never fetches.

		loadTracker();
		await flush();

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( eventsNamed( 'add_to_cart' ) ).toHaveLength( 0 );
		expect( eventsNamed( 'purchase' ) ).toHaveLength( 0 );
	} );

	it( 'fires the re-added-to-cart add_to_cart once and clears the event cookie', async () => {
		window.gtm4wp_visitordata_config = actionConfig( [ 'readdedToCart' ] );
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( readdedPayload( 'hash-1' ) );

		loadTracker();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( eventsNamed( 'add_to_cart' ) ).toHaveLength( 1 );
		// The one-shot must NOT bleed into the merged visitorData push.
		expect(
			visitorEvents().find( ( e ) => 'readdedToCart' in e )
		).toBeFalsy();
		// The token is recorded and the event cookie consumed.
		expect(
			JSON.parse(
				window.localStorage.getItem( 'gtm4wp_readded_to_cart' )
			)
		).toContain( 'hash-1' );
		expect( document.cookie ).not.toContain( EVENT_COOKIE + '=1' );
	} );

	it( 'does not re-push a re-add whose token is already in localStorage (reload)', async () => {
		// The token was already fired in this browser (e.g. a page reload).
		window.localStorage.setItem(
			'gtm4wp_readded_to_cart',
			JSON.stringify( [ 'hash-1' ] )
		);
		window.gtm4wp_visitordata_config = actionConfig( [ 'readdedToCart' ] );
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( readdedPayload( 'hash-1' ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'add_to_cart' ) ).toHaveLength( 0 );
	} );

	it( 'fires the reliable-purchase fallback once and writes the shared guard', async () => {
		window.gtm4wp_visitordata_config = actionConfig( [
			'pendingPurchase',
		] );
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( purchasePayload( '1001', true ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'purchase' ) ).toHaveLength( 1 );
		expect( eventsNamed( 'purchase' )[ 0 ].ecommerce.transaction_id ).toBe(
			'1001'
		);
		// Scenario (a): the fallback records gtm4wp_orderid_tracked keyed on the
		// order number, so a LATER order-received purchase for the same order is
		// suppressed by that page's inline guard (shared key).
		expect( window.localStorage.getItem( 'gtm4wp_orderid_tracked' ) ).toBe(
			'1001'
		);
		expect( document.cookie ).not.toContain( EVENT_COOKIE + '=1' );
	} );

	it( 'suppresses the fallback when the order is already tracked (scenario b)', async () => {
		// Scenario (b): the order-received purchase fired first and wrote the shared
		// gtm4wp_orderid_tracked guard; the fallback for the SAME order must not fire.
		window.localStorage.setItem( 'gtm4wp_orderid_tracked', '1001' );
		window.gtm4wp_visitordata_config = actionConfig( [
			'pendingPurchase',
		] );
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( purchasePayload( '1001', true ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'purchase' ) ).toHaveLength( 0 );
		// The stale event cookie is still consumed so no further fetch happens.
		expect( document.cookie ).not.toContain( EVENT_COOKIE + '=1' );
	} );

	it( 'with flag=false, pushes the purchase but writes NO order-tracked state', async () => {
		// "Do not flag orders as being tracked": no order-tracked state is read or
		// written anywhere, matching the server path (#369).
		window.gtm4wp_visitordata_config = actionConfig( [
			'pendingPurchase',
		] );
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( purchasePayload( '1001', false ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'purchase' ) ).toHaveLength( 1 );
		expect(
			window.localStorage.getItem( 'gtm4wp_orderid_tracked' )
		).toBeNull();
	} );

	it( 'keeps one-shot keys out of the merged push and out of the cache', async () => {
		// A fetch carrying BOTH a Tier 2 session field and a one-shot: only the
		// session field is merged/cached; the one-shot fires as its own event.
		window.gtm4wp_visitordata_config = {
			event: 'gtm4wp.visitorData',
			fields: {},
			endpoint: ENDPOINT,
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
			actions: [ { cookie: EVENT_COOKIE, keys: [ 'pendingPurchase' ] } ],
		};
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce(
			Object.assign(
				{ visitorIP: '8.8.8.8' },
				purchasePayload( '1001', true )
			)
		);

		loadTracker();
		await flush();

		const merged = visitorEvents().find( ( e ) => 'visitorIP' in e );
		expect( merged ).toBeTruthy();
		expect( merged ).not.toHaveProperty( 'pendingPurchase' );
		expect( eventsNamed( 'purchase' ) ).toHaveLength( 1 );

		// A later page in the same session: the session field replays from cache
		// (no fetch), and the purchase is NOT re-fired (the event cookie is gone and
		// the one-shot was never cached).
		window.dataLayer = [];
		loadTracker();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect(
			visitorEvents().find( ( e ) => e.visitorIP === '8.8.8.8' )
		).toBeTruthy();
		expect( eventsNamed( 'purchase' ) ).toHaveLength( 0 );
	} );

	it( 'clears a stale event cookie even when nothing is pending server-side', async () => {
		window.gtm4wp_visitordata_config = actionConfig( [
			'readdedToCart',
			'pendingPurchase',
		] );
		setCookie( EVENT_COOKIE, '1' );
		// The server has nothing queued (marker already consumed): empty payload.
		mockEndpointOnce( {} );

		loadTracker();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( eventsNamed( 'purchase' ) ).toHaveLength( 0 );
		expect( eventsNamed( 'add_to_cart' ) ).toHaveLength( 0 );
		expect( document.cookie ).not.toContain( EVENT_COOKIE + '=1' );
	} );

	// Cross-device dedupe (issue #398): after a fallback delivery the client fires ONE
	// authenticated POST beacon (with the shared wp_rest nonce) to flag the order
	// tracked server-side, so a later order-received render on another device is
	// suppressed — but only when the browser guard is in use and a purchase actually
	// fired, and never when the GET is read-only-only (flag false / already tracked).
	it( 'fires the confirm-purchase POST beacon after a fallback delivery, with the nonce', async () => {
		window.gtm4wp_visitordata_config = actionConfigConfirm(
			[ 'pendingPurchase' ],
			{ pendingPurchase: CONFIRM_URL }
		);
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( purchasePayload( '1001', true ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'purchase' ) ).toHaveLength( 1 );

		// A second fetch: POST to the confirm route, keepalive, credentials + the nonce.
		const beacon = confirmBeacon();
		expect( beacon ).toBeTruthy();
		const options = beacon[ 1 ];
		expect( options.method ).toBe( 'POST' );
		expect( options.keepalive ).toBe( true );
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'n1' );
		// The beacon carries NO order id — the server resolves it from the session.
		expect( options.body ).toBeUndefined();
	} );

	it( 'sends NO confirm beacon when flag is false (do-not-flag option)', async () => {
		window.gtm4wp_visitordata_config = actionConfigConfirm(
			[ 'pendingPurchase' ],
			{ pendingPurchase: CONFIRM_URL }
		);
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( purchasePayload( '1001', false ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'purchase' ) ).toHaveLength( 1 );
		expect( confirmBeacon() ).toBeFalsy();
	} );

	it( 'sends NO confirm beacon when the purchase is suppressed as already tracked', async () => {
		// The order-received purchase already wrote the shared guard, so the fallback
		// is suppressed — and with no push there is nothing to confirm.
		window.localStorage.setItem( 'gtm4wp_orderid_tracked', '1001' );
		window.gtm4wp_visitordata_config = actionConfigConfirm(
			[ 'pendingPurchase' ],
			{ pendingPurchase: CONFIRM_URL }
		);
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( purchasePayload( '1001', true ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'purchase' ) ).toHaveLength( 0 );
		expect( confirmBeacon() ).toBeFalsy();
	} );

	it( 'still delivers the purchase when the confirm beacon fails (graceful)', async () => {
		window.gtm4wp_visitordata_config = actionConfigConfirm(
			[ 'pendingPurchase' ],
			{ pendingPurchase: CONFIRM_URL }
		);
		setCookie( EVENT_COOKIE, '1' );
		// The endpoint resolves the fallback; the beacon POST then rejects.
		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: () =>
					Promise.resolve( {
						payload: JSON.stringify(
							purchasePayload( '1001', true )
						),
					} ),
			} )
			.mockRejectedValueOnce( new Error( 'network down' ) );

		loadTracker();
		await flush();

		// The fallback purchase and the same-browser guard are unaffected by the
		// beacon's failure (degrades to today's behavior — no regression).
		expect( eventsNamed( 'purchase' ) ).toHaveLength( 1 );
		expect( window.localStorage.getItem( 'gtm4wp_orderid_tracked' ) ).toBe(
			'1001'
		);
		expect( confirmBeacon() ).toBeTruthy();
	} );
} );
