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
