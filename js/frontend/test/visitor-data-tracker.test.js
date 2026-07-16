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
