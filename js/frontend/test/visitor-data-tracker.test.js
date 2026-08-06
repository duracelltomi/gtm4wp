/**
 * Unit tests for the cache-safe data layer client runtime
 * (js/frontend/gtm4wp-visitor-data.js).
 *
 * The tracker runs once, at load time, and pushes one event PER DATA FAMILY, so a
 * GTM setup can tell from the event name alone which keys arrived:
 * gtm4wp.visitorData (Tier 1 + the endpoint fields, merged into one push),
 * gtm4wp.customerData (the WooCommerce customer* keys) and gtm4wp.cartData
 * (cartContent). The two WooCommerce families come off the cart fragment and are
 * re-pushed on a cart change, each only when ITS OWN half changed. Each test sets
 * the browser sources (location.search, document.referrer) and the config first,
 * then loads the module fresh with jest.isolateModules so its IIFE re-runs against
 * that state (TC-9).
 *
 * The dataLayer push is a structured object sink (TC-11), so there is no HTML
 * output-encoding surface at the push site; the untrusted-input contract that
 * matters is raw-passthrough (the referrer/search/customer value reaches the push
 * verbatim, not entity-encoded — the downstream GTM tag owns any DOM escaping).
 */

/**
 * The event-name map PHP bakes into the client config (VisitorDataModule::EVENT_*).
 * Fixtures use this rather than the pre-split single `event` key; the legacy shape
 * and the no-config shape have their own dedicated tests further down.
 */
const EVENTS = {
	visitor: 'gtm4wp.visitorData',
	customer: 'gtm4wp.customerData',
	cart: 'gtm4wp.cartData',
};

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

const eventsNamed = ( name ) =>
	window.dataLayer.filter( ( entry ) => entry.event === name );

const visitorEvents = () => eventsNamed( 'gtm4wp.visitorData' );
const customerEvents = () => eventsNamed( 'gtm4wp.customerData' );
const cartEvents = () => eventsNamed( 'gtm4wp.cartData' );

/**
 * Adds the WooCommerce cart-fragment placeholder carrying the given two-part block,
 * or updates the existing one (which is what a fragment refresh looks like to the
 * MutationObserver).
 *
 * @param {Object} block The { customer, cart } payload PHP encodes.
 * @return {void}
 */
function setCartFragment( block ) {
	let el = document.querySelector( '.gtm4wp-wc-visitor-data' );
	if ( ! el ) {
		el = document.createElement( 'div' );
		el.className = 'gtm4wp-wc-visitor-data';
		document.body.appendChild( el );
	}
	el.setAttribute( 'data-gtm4wp-visitor-cart', JSON.stringify( block ) );
}

/**
 * Replaces the placeholder element wholesale, the way WooCommerce's cart-fragments
 * script does (it swaps the node rather than editing the attribute), so the
 * MutationObserver's childList branch fires.
 *
 * @param {Object} block The { customer, cart } payload PHP encodes.
 * @return {void}
 */
function replaceCartFragment( block ) {
	const existing = document.querySelector( '.gtm4wp-wc-visitor-data' );
	if ( existing ) {
		existing.remove();
	}
	setCartFragment( block );
}

/**
 * Waits for the MutationObserver callback (which jsdom delivers as a microtask).
 *
 * @return {Promise<void>}
 */
function flushObservers() {
	return Promise.resolve().then( () => {} );
}

// The runtime attaches a MutationObserver to the shared jsdom document.body for
// the WooCommerce cart fragment; without cleanup it leaks across isolateModules
// reloads and fires (throwing on a torn-down document) in later tests (TS-7).
// Track every observer the tracker creates and disconnect it after each test.
let trackedObservers = [];
const RealMutationObserver = window.MutationObserver;
beforeEach( () => {
	// The bundle guards its boot with window.gtm4wp_visitordata_inited so a
	// re-injected copy cannot push, re-fetch and re-observe a second time (#83). That
	// flag lives on window, which jsdom keeps for the whole file, so it has to be
	// cleared before every module load - and at FILE level, not inside one describe,
	// because all five describes here reload the module.
	delete window.gtm4wp_visitordata_inited;
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
			events: EVENTS,
			fields: {
				siteSearchTerm: 'searchTerm',
				siteSearchFrom: 'searchReferrer',
			},
		};
		// jsdom keeps one document for the whole file, so the cart placeholder the #83
		// test installs would otherwise leak into every later describe (TS-7).
		document.body.innerHTML = '';
		setSearch( '' );
		setReferrer( '' );
	} );

	it( 'does not push or re-observe when the bundle is loaded twice (#83)', async () => {
		// This bundle attaches no document-level listeners - it pushes, fetches and
		// observes from its module body - so PA-9's "module-scope addEventListener"
		// litmus never selected it. A re-injected bundle pushed the visitor event a
		// second time and, worse, left a SECOND MutationObserver on document.body with
		// its own de-dupe state, so every later cart change pushed twice for good - and
		// since the split that is twice for BOTH WooCommerce events. The placeholder is
		// in the DOM here precisely so the Woo half of the failure is covered too.
		setSearch( 's=' + encodeURIComponent( 'blue shoes' ) );
		setCartFragment( {
			customer: { customerTotalOrders: 1 },
			cart: { cartContent: { items: [] } },
		} );

		loadTracker();
		expect( visitorEvents() ).toHaveLength( 1 );
		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 1 );
		const observersAfterFirstLoad = trackedObservers.length;

		// Second copy of the bundle: the guard flag is left exactly as it would find it.
		loadTracker();

		expect( visitorEvents() ).toHaveLength( 1 );
		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 1 );
		expect( trackedObservers ).toHaveLength( observersAfterFirstLoad );

		// And a later cart change is still delivered exactly once per family, not twice.
		replaceCartFragment( {
			customer: { customerTotalOrders: 1 },
			cart: { cartContent: { items: [ { item_name: 'Mug' } ] } },
		} );
		await flushObservers();

		expect( cartEvents() ).toHaveLength( 2 );
		expect( customerEvents() ).toHaveLength( 1 );
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
			events: EVENTS,
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
 * (wrapped in the hex-encoded string payload the endpoint returns) plus the fresh
 * per-request nonce the endpoint hands back for the confirm beacons (issue #398).
 *
 * @param {Object} data  The visitor data map the endpoint should return.
 * @param {string} nonce The fresh beacon nonce the endpoint returns.
 * @return {void}
 */
function mockEndpointOnce( data, nonce = 'fresh-nonce' ) {
	global.fetch.mockResolvedValueOnce( {
		ok: true,
		json: () =>
			Promise.resolve( { payload: JSON.stringify( data ), nonce } ),
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

	it( 'still flushes the Tier 1 push when fetch is unavailable', async () => {
		// The endpoint call used to be unguarded while its sibling beacon
		// feature-detected fetch, so on a browser without fetch the ReferenceError
		// escaped collectEndpointFields() and done() never ran — losing not just
		// the Tier 2/3 data but the already-collected Tier 1 values with it.
		delete global.fetch;

		setSearch( 's=shoes' );
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: { siteSearchTerm: 'searchTerm' },
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};

		expect( () => loadTracker() ).not.toThrow();
		await flush();

		const event = visitorEvents().find(
			( e ) => e.siteSearchTerm === 'shoes'
		);
		expect( event ).toBeTruthy();
		// The server-only field simply is not delivered — no placeholder.
		expect( event.visitorIP ).toBeUndefined();
	} );

	it( 'fetches Tier 2 once per session, then replays from cache with no request', async () => {
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
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
		// A page view is a new window, so the #83 boot guard is not set there; jsdom
		// keeps one window for the whole file, so clear it to model that faithfully.
		// (Leaving it set would model a re-injection on the SAME page instead, which
		// is the case the guard exists to block and is covered by its own test.)
		delete window.gtm4wp_visitordata_inited;
		window.dataLayer = [];
		loadTracker();
		await flush();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect(
			visitorEvents().find( ( e ) => e.visitorIP === '8.8.8.8' )
		).toBeTruthy();
	} );

	it( 'sends the nonce as X-WP-Nonce only when fetching identity-bound gated data', async () => {
		// Issue #398: the baked config nonce is sent ONLY when a Tier 3 gate cookie is
		// active — i.e. a logged-in visitor, whose page is never full-page cached, so
		// the baked nonce is fresh. WordPress needs it to authenticate their auth cookie.
		setCookie( 'gtm4wp_login', 'abc' );
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'secret-nonce',
			sessionKey: 'gtm4wp_visitor_session',
			gates: [ { cookie: 'gtm4wp_login', keys: [ 'visitorEmail' ] } ],
		};
		mockEndpointOnce( { visitorEmail: 'user@example.com' } );

		loadTracker();
		await flush();

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'secret-nonce' );
	} );

	it( 'sends NO nonce on an anonymous Tier 2 fetch (a stale baked nonce must not 403 the read)', async () => {
		// Issue #398 (#35): an anonymous visitor needs no nonce to read Tier 2. Sending
		// the baked nonce from a long-lived cached page — where it has gone stale —
		// would make WordPress 403 the request and silently drop the IP/country. So no
		// nonce is sent on a Tier-2-only (no active gate) fetch.
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'stale-baked-nonce',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};
		mockEndpointOnce( { visitorIP: '1.1.1.1' } );

		loadTracker();
		await flush();

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBeUndefined();
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
			events: EVENTS,
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
			events: EVENTS,
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
			events: EVENTS,
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
			events: EVENTS,
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

	it( 'pushes the customer and cart halves as their own separate events', () => {
		// The point of the split: a GTM setup must be able to tell from the event name
		// alone which keys arrived, so neither family may appear on the other's event
		// (nor on the visitor event, which used to carry both).
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: {},
		};

		setCartFragment( {
			customer: {
				customerTotalOrders: 3,
				customerBillingCompany: 'Acme',
			},
			cart: { cartContent: { items: [ { item_name: 'Mug' } ] } },
		} );

		loadTracker();

		expect( customerEvents() ).toHaveLength( 1 );
		expect( customerEvents()[ 0 ] ).toEqual( {
			event: 'gtm4wp.customerData',
			customerTotalOrders: 3,
			customerBillingCompany: 'Acme',
		} );

		expect( cartEvents() ).toHaveLength( 1 );
		expect( cartEvents()[ 0 ] ).toEqual( {
			event: 'gtm4wp.cartData',
			cartContent: { items: [ { item_name: 'Mug' } ] },
		} );

		// Neither family rides the visitor event any more.
		expect( visitorEvents() ).toHaveLength( 0 );
	} );

	it( 'fires only cartData when the cart feature alone is enabled', () => {
		window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

		setCartFragment( { cart: { cartContent: { items: [] } } } );

		loadTracker();

		expect( cartEvents() ).toHaveLength( 1 );
		expect( customerEvents() ).toHaveLength( 0 );
	} );

	it( 'fires only customerData when the customer feature alone is enabled', () => {
		window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

		setCartFragment( { customer: { customerTotalOrders: 3 } } );

		loadTracker();

		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 0 );
	} );

	it( 'delivers an EMPTY cart rather than omitting it', () => {
		// "The cart is now empty" is exactly the state a tag reads after the last
		// remove_from_cart, so absence of gtm4wp.cartData must never be how an empty
		// cart is expressed - an omitted event means the feature is off.
		window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

		setCartFragment( {
			cart: {
				cartContent: {
					totals: { subtotal: 0, total: 0, applied_coupons: [] },
					items: [],
				},
			},
		} );

		loadTracker();

		expect( cartEvents() ).toHaveLength( 1 );
		expect( cartEvents()[ 0 ].cartContent.items ).toEqual( [] );
		expect( cartEvents()[ 0 ].cartContent.totals.total ).toBe( 0 );
	} );

	it( 'refires only the half that changed on a cart update', async () => {
		// WooCommerce re-applies the WHOLE fragment as one blob, so a single
		// whole-payload comparison would refire customerData on every quantity change
		// with byte-identical values - and the event name promises the opposite.
		window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

		setCartFragment( {
			customer: { customerTotalOrders: 3 },
			cart: {
				cartContent: { items: [ { item_name: 'Mug', quantity: 1 } ] },
			},
		} );

		loadTracker();

		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 1 );

		// Quantity change: identical customer half, different cart half.
		replaceCartFragment( {
			customer: { customerTotalOrders: 3 },
			cart: {
				cartContent: { items: [ { item_name: 'Mug', quantity: 2 } ] },
			},
		} );
		await flushObservers();

		expect( cartEvents() ).toHaveLength( 2 );
		expect( cartEvents()[ 1 ].cartContent.items[ 0 ].quantity ).toBe( 2 );
		expect( customerEvents() ).toHaveLength( 1 );

		// Now the reverse: the customer half changes (a billing field on checkout).
		replaceCartFragment( {
			customer: { customerTotalOrders: 4 },
			cart: {
				cartContent: { items: [ { item_name: 'Mug', quantity: 2 } ] },
			},
		} );
		await flushObservers();

		expect( customerEvents() ).toHaveLength( 2 );
		expect( cartEvents() ).toHaveLength( 2 );
	} );

	it( 'does not refire when the same fragment is re-applied', async () => {
		window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

		const block = {
			customer: { customerTotalOrders: 3 },
			cart: { cartContent: { items: [] } },
		};

		setCartFragment( block );
		loadTracker();

		// WooCommerce replays its sessionStorage-cached fragment on every page load.
		replaceCartFragment( block );
		await flushObservers();

		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 1 );
	} );

	it( 'passes a hostile customer field through raw (structured sink, TC-11)', () => {
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: {},
		};

		const hostile = '</script>"&<x';
		setCartFragment( { customer: { customerBillingCompany: hostile } } );

		loadTracker();

		expect( customerEvents() ).toHaveLength( 1 );
		expect( customerEvents()[ 0 ].customerBillingCompany ).toBe( hostile );
	} );

	it( 'passes a hostile customer field through raw from the real PHP-encoded attribute', () => {
		// Every other fixture builds the attribute with setAttribute + JSON.stringify,
		// which skips the HTML-entity leg: PHP writes
		// esc_attr( wp_json_encode( …, JSON_HEX_* ) ), so the structural quotes arrive as
		// &quot; and the hostile characters as < / " / &. This is the one
		// place a wrong JSON_HEX_* flag set would actually surface, so parse the
		// attribute the way the browser really does - through innerHTML.
		window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

		document.body.innerHTML =
			'<div class="gtm4wp-wc-visitor-data" style="display:none" ' +
			'data-gtm4wp-visitor-cart="' +
			'{&quot;customer&quot;:{&quot;customerBillingCompany&quot;:' +
			'&quot;Evil\\u003C/script\\u003E\\u0022\\u0026Co&quot;}}' +
			'"></div>';

		loadTracker();

		expect( customerEvents() ).toHaveLength( 1 );
		// The break-out characters reach the push RAW (TS-2, first direction): the
		// hex escapes were transport encoding, not sanitization.
		expect( customerEvents()[ 0 ].customerBillingCompany ).toBe(
			'Evil</script>"&Co'
		);
	} );

	it( 'does nothing when there is no fragment placeholder', () => {
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: {},
		};

		expect( () => loadTracker() ).not.toThrow();
		expect( visitorEvents() ).toHaveLength( 0 );
		expect( customerEvents() ).toHaveLength( 0 );
		expect( cartEvents() ).toHaveLength( 0 );
	} );

	it.each( [
		[ 'an empty attribute', '' ],
		[ 'a non-JSON string', 'not json' ],
		[ 'the literal null', 'null' ],
		[ 'a number', '5' ],
		[ 'a JSON string', '"x"' ],
		[ 'an empty array (PHP encodes an empty payload as [])', '[]' ],
		[ 'an object with neither part', '{}' ],
		[ 'a part that is not an object', '{"customer":5,"cart":[]}' ],
		[
			'a legacy FLAT payload replayed from the WC fragment cache',
			'{"customerTotalOrders":3,"cartContent":{"items":[]}}',
		],
	] )(
		'delivers nothing and does not throw for %s',
		( _label, rawAttribute ) => {
			// JSON.parse SUCCEEDS on 'null', '5', '"x"' and '[]', so the try/catch is not
			// the guard here - the type check before any property read is, and without it
			// parsed.customer throws inside the MutationObserver callback. The flat case
			// is the post-update skew: WooCommerce replays its own sessionStorage fragment
			// cache, and rescuing it by sniffing key prefixes would put a copy of today's
			// naming in the client, so it is deliberately dropped until the next refresh.
			window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

			const el = document.createElement( 'div' );
			el.className = 'gtm4wp-wc-visitor-data';
			el.setAttribute( 'data-gtm4wp-visitor-cart', rawAttribute );
			document.body.appendChild( el );

			expect( () => loadTracker() ).not.toThrow();

			expect( visitorEvents() ).toHaveLength( 0 );
			expect( customerEvents() ).toHaveLength( 0 );
			expect( cartEvents() ).toHaveLength( 0 );
		}
	);

	it( 'fires the WooCommerce events even when there is no visitor event at all', () => {
		// No Tier 1 field and no endpoint: the visitor push no-ops on its empty map, but
		// the WooCommerce families must still be delivered.
		window.gtm4wp_visitordata_config = { events: EVENTS, fields: {} };

		setCartFragment( {
			customer: { customerTotalOrders: 3 },
			cart: { cartContent: { items: [] } },
		} );

		loadTracker();

		expect( visitorEvents() ).toHaveLength( 0 );
		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 1 );
	} );

	describe( 'event names from the config', () => {
		beforeEach( () => {
			setCartFragment( {
				customer: { customerTotalOrders: 3 },
				cart: { cartContent: { items: [] } },
			} );
		} );

		it( 'uses the names the config supplies', () => {
			window.gtm4wp_visitordata_config = {
				events: {
					visitor: 'custom.visitor',
					customer: 'custom.customer',
					cart: 'custom.cart',
				},
				fields: {},
			};

			loadTracker();

			expect( eventsNamed( 'custom.customer' ) ).toHaveLength( 1 );
			expect( eventsNamed( 'custom.cart' ) ).toHaveLength( 1 );
		} );

		it( 'falls back to the documented names with no config at all', () => {
			// WooCommerceModule enqueues this handle without an inline config, so the
			// runtime must still push under the documented names.
			delete window.gtm4wp_visitordata_config;

			loadTracker();

			expect( customerEvents() ).toHaveLength( 1 );
			expect( cartEvents() ).toHaveLength( 1 );
		} );

		it( 'accepts a pre-split config replayed from cached HTML', () => {
			// The script is version-busted but the inline config is baked into cacheable
			// HTML, so after an update the realistic state is NEW script + OLD config:
			// a single `event` key and no map. The visitor name comes from it; the two
			// WooCommerce names fall back to their literals.
			window.gtm4wp_visitordata_config = {
				event: 'legacy.visitorData',
				fields: { siteSearchTerm: 'searchTerm' },
			};
			window.history.replaceState( {}, '', '/?s=shoes' );

			loadTracker();

			expect( eventsNamed( 'legacy.visitorData' ) ).toHaveLength( 1 );
			expect( customerEvents() ).toHaveLength( 1 );
			expect( cartEvents() ).toHaveLength( 1 );
		} );

		it.each( [
			[ 'an empty string', '' ],
			[ 'a number', 123 ],
			[ 'null', null ],
		] )(
			'falls back to the documented name when a configured name is %s',
			( _label, badName ) => {
				// A partial or garbage map must never put { event: undefined } into the
				// data layer.
				window.gtm4wp_visitordata_config = {
					events: { cart: badName },
					fields: {},
				};

				loadTracker();

				expect( cartEvents() ).toHaveLength( 1 );
				expect(
					window.dataLayer.filter(
						( entry ) => entry.event === undefined
					)
				).toHaveLength( 0 );
			}
		);
	} );
} );

describe( 'gtm4wp-visitor-data — the merged visitor push', () => {
	beforeEach( () => {
		resetBrowserState();
		global.fetch = jest.fn();
		document.body.innerHTML = '';
	} );

	const wooBlock = {
		customer: { customerTotalOrders: 3 },
		cart: { cartContent: { items: [] } },
	};

	it( 'merges Tier 1 and the endpoint into ONE visitor push on the first view', async () => {
		setCartFragment( wooBlock );
		window.history.replaceState( {}, '', '/?s=shoes' );

		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: { siteSearchTerm: 'searchTerm' },
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};
		mockEndpointOnce( { visitorIP: '8.8.8.8' } );

		loadTracker();
		await flush();

		// The visitor families still merge: the endpoint data appears exactly once, in
		// the same push as the Tier 1 value, never split across two events.
		expect( visitorEvents() ).toHaveLength( 1 );
		expect( visitorEvents()[ 0 ] ).toEqual( {
			event: 'gtm4wp.visitorData',
			siteSearchTerm: 'shoes',
			visitorIP: '8.8.8.8',
		} );

		// The WooCommerce data is NOT in it - it has its own events.
		expect( visitorEvents()[ 0 ].cartContent ).toBeUndefined();
		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 1 );
	} );

	it( 'replays as ONE synchronous visitor push on a cached view, with no fetch', () => {
		window.sessionStorage.setItem(
			'gtm4wp_visitor_session',
			JSON.stringify( { gates: {}, session: { visitorIP: '8.8.8.8' } } )
		);
		setCartFragment( wooBlock );

		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: {},
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};

		// No await: the replay and the cart read are both synchronous, so all three
		// events land before loadTracker() returns.
		loadTracker();

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( visitorEvents() ).toHaveLength( 1 );
		expect( visitorEvents()[ 0 ].visitorIP ).toBe( '8.8.8.8' );
		expect( customerEvents() ).toHaveLength( 1 );
		expect( cartEvents() ).toHaveLength( 1 );
	} );

	it( 'pushes the visitor event BEFORE the WooCommerce ones', async () => {
		// GTM's model keeps the keys of earlier pushes but not later ones, so a tag
		// triggered on customerData/cartData can read visitorId only while the visitor
		// event lands first. That ordering is a contract, not an artefact of statement
		// order, and it has to hold on the async (first-view) path too.
		setCartFragment( wooBlock );
		window.history.replaceState( {}, '', '/?s=shoes' );

		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: { siteSearchTerm: 'searchTerm' },
			endpoint: 'https://site.example/wp-json/gtm4wp/v2/visitor-data',
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
		};
		mockEndpointOnce( { visitorIP: '8.8.8.8' } );

		loadTracker();
		await flush();

		const names = window.dataLayer.map( ( entry ) => entry.event );
		expect( names ).toEqual( [
			'gtm4wp.visitorData',
			'gtm4wp.customerData',
			'gtm4wp.cartData',
		] );
	} );
} );

/**
 * Phase 3: the two WooCommerce one-shot events (an add_to_cart after the cart
 * "Undo" and the reliable-purchase fallback) delivered over the same endpoint but
 * cookie-gated, fired ONCE each with a per-event de-dupe guard, and never merged
 * into any of the three data-family pushes nor cached/replayed. The purchase reuses the
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
			events: EVENTS,
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
		// A fetch carrying BOTH a Tier 2 session field and a one-shot, on a page that
		// also has the WooCommerce cart fragment: the session field is merged/cached on
		// the visitor event, the WooCommerce families go to their own events, and the
		// one-shot appears on NONE of the three - it fires as the event the server named.
		window.gtm4wp_visitordata_config = {
			events: EVENTS,
			fields: {},
			endpoint: ENDPOINT,
			nonce: 'n1',
			sessionKey: 'gtm4wp_visitor_session',
			session: [ 'visitorIP' ],
			actions: [ { cookie: EVENT_COOKIE, keys: [ 'pendingPurchase' ] } ],
		};
		setCookie( EVENT_COOKIE, '1' );
		setCartFragment( {
			customer: { customerTotalOrders: 3 },
			cart: { cartContent: { items: [] } },
		} );
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

		// Cross-family isolation: each key only ever on its own event.
		expect( merged ).not.toHaveProperty( 'customerTotalOrders' );
		expect( merged ).not.toHaveProperty( 'cartContent' );
		expect( customerEvents()[ 0 ] ).not.toHaveProperty( 'visitorIP' );
		expect( customerEvents()[ 0 ] ).not.toHaveProperty( 'pendingPurchase' );
		expect( cartEvents()[ 0 ] ).not.toHaveProperty( 'customerTotalOrders' );
		expect( cartEvents()[ 0 ] ).not.toHaveProperty( 'pendingPurchase' );

		// A later page in the same session: the session field replays from cache
		// (no fetch), and the purchase is NOT re-fired (the event cookie is gone and
		// the one-shot was never cached). A page view is a new window, so clear the
		// #83 boot guard - see the note on the Tier 2 replay test above.
		delete window.gtm4wp_visitordata_inited;
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
		// Issue #398 (#35): the beacon authenticates with the FRESH nonce the endpoint
		// just returned, not the (possibly stale) baked config nonce 'n1'.
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'fresh-nonce' );
		// The beacon carries NO order id — the server resolves it from the session.
		expect( options.body ).toBeUndefined();
	} );

	it( 'fires the confirm-readd POST beacon after a re-add delivery', async () => {
		// Issue #398: the re-add's session marker is consumed by an authenticated POST
		// beacon too, so its delivering GET stays read-only.
		const READD_URL =
			'https://site.example/wp-json/gtm4wp/v2/confirm-readd-tracked';
		window.gtm4wp_visitordata_config = actionConfigConfirm(
			[ 'readdedToCart' ],
			{ readdedToCart: READD_URL }
		);
		setCookie( EVENT_COOKIE, '1' );
		mockEndpointOnce( readdedPayload( 'hash-1' ) );

		loadTracker();
		await flush();

		expect( eventsNamed( 'add_to_cart' ) ).toHaveLength( 1 );

		const beacon = global.fetch.mock.calls.find(
			( call ) => call[ 0 ] === READD_URL
		);
		expect( beacon ).toBeTruthy();
		expect( beacon[ 1 ].method ).toBe( 'POST' );
		expect( beacon[ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'fresh-nonce' );
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
