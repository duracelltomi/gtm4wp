/**
 * Unit tests for the Data Manager attribution capture bundle
 * (js/frontend/gtm4wp-attribution.js).
 *
 * The bundle runs at import time, so each test arranges the world - config,
 * data layer contents, URL, cookies - and then loads the module fresh with
 * jest.isolateModules so the IIFE re-runs against that state (the TC-9/TC-10
 * fresh-module pattern used by the sibling trackers).
 *
 * The behaviours that matter here are the consent-gated persistence rules
 * (which is a privacy control, not a nicety), the queue-once discipline of the
 * gtag lookups, and the two ways the script can obtain a gtag function. The
 * cookie payload is also one half of a contract whose other half is PHP, so
 * the format version and the exact member names are asserted rather than
 * assumed.
 */

const IDS_COOKIE = 'gtm4wp_gdm_ids';
const CONSENT_COOKIE = 'gtm4wp_gdm_consent';

const baseConfig = {
	idsCookie: IDS_COOKIE,
	consentCookie: CONSENT_COOKIE,
	version: 1,
	lifetimeDays: 90,
	maxBytes: 2048,
	clickIds: [ 'gclid', 'gbraid', 'wbraid' ],
	measurementIds: [ 'G-AAAA1111' ],
};

/**
 * Loads the bundle fresh so its boot IIFE re-runs.
 *
 * @return {void}
 */
function loadTracker() {
	jest.isolateModules( () => {
		require( '../gtm4wp-attribution' );
	} );
}

/**
 * Sets the page URL (and therefore location.search) for the current test.
 *
 * @param {string} search The query string, including the leading '?'.
 * @return {void}
 */
function setSearch( search ) {
	delete window.location;
	window.location = new URL( 'https://example.com/landing' + search );
}

/**
 * Reads one cookie the bundle wrote and decodes its payload.
 *
 * @param {string} name Cookie name.
 * @return {Object|null} The decoded payload, or null when the cookie is absent.
 */
function readPayload( name ) {
	const parts = ( '; ' + document.cookie ).split( '; ' + name + '=' );

	if ( 2 !== parts.length ) {
		return null;
	}

	const raw = parts.pop().split( ';' ).shift();

	return JSON.parse( decodeURIComponent( raw ) );
}

/**
 * Every 'get' command that reached the data layer or the gtag stand-in.
 *
 * @return {Array} The recorded get commands as plain arrays.
 */
function getCommands() {
	return window.dataLayer
		.filter( ( entry ) => entry && 'get' === entry[ 0 ] )
		.map( ( entry ) => Array.prototype.slice.call( entry ) );
}

/**
 * Answers the queued get commands the way the Google tag core does when it
 * loads and replays the queue: each callback is invoked with the value for its
 * field. This is the stand-in for the real core, so it is deliberately no more
 * permissive than the real thing - it only answers commands that carry a
 * callback, and it answers a measurement id it was told about (UC-3).
 *
 * @param {Object} values Field values keyed by field name.
 * @return {void}
 */
function serviceQueuedGets( values ) {
	getCommands().forEach( ( command ) => {
		const [ , measurementId, field, callback ] = command;

		if ( 'function' !== typeof callback ) {
			return;
		}

		const value =
			'session_id' === field
				? values.sessions && values.sessions[ measurementId ]
				: values[ field ];

		if ( undefined !== value ) {
			callback( value );
		}
	} );
}

describe( 'gtm4wp-attribution', () => {
	beforeEach( () => {
		// The bundle guards its boot with a window flag, so it has to be
		// cleared or only the first test in the file would ever run it.
		delete window.gtm4wp_gdm_attribution_inited;
		delete window.gtm4wp_gdm_attribution_config;
		delete window.gtm4wp_datalayer_name;
		delete window.gtag;
		// Process-wide, and the engine read prefers it over the data layer, so
		// a leftover from one case would silently drive the next (TS-7).
		delete window.google_tag_data;

		window.dataLayer = [];

		// Expire every cookie a previous test wrote (TS-7).
		document.cookie
			.split( ';' )
			.map( ( one ) => one.split( '=' )[ 0 ].trim() )
			.filter( Boolean )
			.forEach( ( name ) => {
				document.cookie =
					name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
			} );

		setSearch( '' );

		window.gtm4wp_gdm_attribution_config = { ...baseConfig };
	} );

	describe( 'boot conditions', () => {
		it( 'does nothing without a printed config', () => {
			delete window.gtm4wp_gdm_attribution_config;

			loadTracker();

			expect( window.dataLayer ).toHaveLength( 0 );
			expect( document.cookie ).toBe( '' );
		} );

		it( 'does nothing when no measurement id was configured', () => {
			window.gtm4wp_gdm_attribution_config.measurementIds = [];

			loadTracker();

			expect( window.dataLayer ).toHaveLength( 0 );
			expect( document.cookie ).toBe( '' );
		} );

		it( 'does not queue its lookups twice when the bundle loads again', () => {
			loadTracker();
			const first = getCommands().length;

			loadTracker();

			expect( getCommands() ).toHaveLength( first );
		} );
	} );

	describe( 'the gtag command path', () => {
		it( 'uses an existing gtag function when the page has one', () => {
			const calls = [];
			window.gtag = function () {
				calls.push( Array.prototype.slice.call( arguments ) );
			};

			loadTracker();

			expect( calls.length ).toBeGreaterThan( 0 );
			expect( calls[ 0 ][ 0 ] ).toBe( 'get' );
			// Nothing was pushed behind that function's back.
			expect( getCommands() ).toHaveLength( 0 );
		} );

		it( 'pushes onto the configured data layer when there is no gtag', () => {
			window.gtm4wp_datalayer_name = 'myDataLayer';
			window.myDataLayer = [];

			loadTracker();

			const commands = window.myDataLayer.filter(
				( entry ) => entry && 'get' === entry[ 0 ]
			);

			expect( commands.length ).toBeGreaterThan( 0 );
			// The default name must stay untouched: replay only happens on the
			// queue the tag core actually reads, which is the configured one.
			expect( window.dataLayer ).toHaveLength( 0 );
		} );

		it( 'asks for the client id once and a session id per measurement id', () => {
			window.gtm4wp_gdm_attribution_config.measurementIds = [
				'G-AAAA1111',
				'G-BBBB2222',
			];

			loadTracker();

			const fields = getCommands().map( ( command ) => [
				command[ 1 ],
				command[ 2 ],
			] );

			expect( fields ).toEqual( [
				[ 'G-AAAA1111', 'client_id' ],
				[ 'G-AAAA1111', 'session_id' ],
				[ 'G-BBBB2222', 'session_id' ],
			] );
		} );
	} );

	describe( 'persistence with no consent regime on the site', () => {
		it( 'stores the ids once the queued lookups are serviced', () => {
			loadTracker();

			expect( readPayload( IDS_COOKIE ) ).toBeNull();

			serviceQueuedGets( {
				client_id: '111.222',
				sessions: { 'G-AAAA1111': '1788522496' },
			} );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
				sessions: { 'G-AAAA1111': '1788522496' },
			} );
		} );

		it( 'stores the click ids of the landing url', () => {
			setSearch( '?gclid=abc123&utm_source=newsletter' );

			loadTracker();

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				gclid: 'abc123',
			} );
		} );

		it( 'writes the consent cookie even when nothing else is captured', () => {
			loadTracker();

			const consent = readPayload( CONSENT_COOKIE );

			expect( consent.v ).toBe( 1 );
			expect( consent.signals ).toEqual( {} );
			expect( typeof consent.captured_at ).toBe( 'number' );
		} );
	} );

	describe( 'consent-gated persistence', () => {
		it( 'persists nothing but the consent record while storage is denied', () => {
			setSearch( '?gclid=abc123' );
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied', ad_storage: 'denied' },
			] );

			loadTracker();
			serviceQueuedGets( {
				client_id: '111.222',
				sessions: { 'G-AAAA1111': '1788522496' },
			} );

			expect( readPayload( IDS_COOKIE ) ).toBeNull();
			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'denied',
				ad_storage: 'denied',
			} );
		} );

		it( 'flushes the url click ids when consent is granted later in the same pageview', () => {
			setSearch( '?gclid=abc123' );
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied', ad_storage: 'denied' },
			] );

			loadTracker();

			expect( readPayload( IDS_COOKIE ) ).toBeNull();

			// The banner is answered on the landing page itself - the dominant
			// case, and the whole reason capture holds a pending buffer instead
			// of deciding once at load.
			window.dataLayer.push( [
				'consent',
				'update',
				{ analytics_storage: 'granted', ad_storage: 'granted' },
			] );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				gclid: 'abc123',
			} );
		} );

		it( 'keeps the ids out while only ad storage is granted, and the other way round', () => {
			setSearch( '?gclid=abc123' );
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied', ad_storage: 'granted' },
			] );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				gclid: 'abc123',
			} );
		} );

		it( 'stores the ids but not the click ids when only analytics storage is granted', () => {
			setSearch( '?gclid=abc123' );
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'granted', ad_storage: 'denied' },
			] );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
			} );
		} );

		it( 'removes what it stored when consent is withdrawn', () => {
			setSearch( '?gclid=abc123' );
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'granted', ad_storage: 'granted' },
			] );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).not.toBeNull();

			window.dataLayer.push( [
				'consent',
				'update',
				{ analytics_storage: 'denied', ad_storage: 'denied' },
			] );

			// Declining to write from here on would not be enough: the values
			// are already on the visitor's machine, and the answer that put
			// them there has been taken back.
			expect( readPayload( IDS_COOKIE ) ).toBeNull();
		} );

		it( 'removes what it stored when a late consent tool answers denied', () => {
			// The realistic race: the bundle runs before the consent tool has
			// pushed anything, so the no-regime rule allows the write, and the
			// denial arrives moments later.
			setSearch( '?gclid=abc123' );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).not.toBeNull();

			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied', ad_storage: 'denied' },
			] );

			expect( readPayload( IDS_COOKIE ) ).toBeNull();
		} );

		it( 'drops only the category that was withdrawn', () => {
			setSearch( '?gclid=abc123' );
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'granted', ad_storage: 'granted' },
			] );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			window.dataLayer.push( [
				'consent',
				'update',
				{ ad_storage: 'denied' },
			] );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
			} );
		} );

		it( 'keeps what an earlier page stored while this one is still resolving', () => {
			// The counterpart to the two cases above: an empty buffer is not a
			// denial. A page that has not resolved anything yet - or never
			// will, because it carries no Analytics tag - must leave a stored
			// value alone rather than treat "nothing to write" as "clear it".
			document.cookie =
				IDS_COOKIE +
				'=' +
				encodeURIComponent(
					JSON.stringify( {
						v: 1,
						client_id: '111.222',
						gclid: 'from-the-landing-page',
					} )
				) +
				';path=/';

			loadTracker();

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
				gclid: 'from-the-landing-page',
			} );
		} );

		it( 'reads a consent default that was queued before the bundle loaded', () => {
			// The head block pushes its consent default long before this bundle
			// runs, so scanning the queue - not listening for an event - is what
			// makes the initial state visible at all.
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied' },
			] );

			loadTracker();

			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'denied',
			} );
		} );

		it( 'keeps every observed signal, not just the two it gates on', () => {
			window.dataLayer.push( [
				'consent',
				'default',
				{
					analytics_storage: 'granted',
					ad_storage: 'granted',
					ad_user_data: 'granted',
					ad_personalization: 'denied',
					functionality_storage: 'granted',
				},
			] );

			loadTracker();

			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'granted',
				ad_storage: 'granted',
				ad_user_data: 'granted',
				ad_personalization: 'denied',
				functionality_storage: 'granted',
			} );
		} );

		it( 'merges a later update over the default', () => {
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied', ad_storage: 'denied' },
			] );

			loadTracker();

			window.dataLayer.push( [
				'consent',
				'update',
				{ analytics_storage: 'granted' },
			] );

			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'granted',
				ad_storage: 'denied',
			} );
		} );
	} );

	describe( "the Google tag's own consent engine", () => {
		/**
		 * Builds a google_tag_data.ics.entries stand-in.
		 *
		 * Faithful to the measured shape, including the part that matters: a
		 * signal that was never updated carries an `update` property whose
		 * value is `undefined`, not a missing property and not `false`. A
		 * double that omitted the key would let a wrong implementation pass
		 * (UC-3).
		 *
		 * @param {Object} signals name => {def, update} where update may be omitted.
		 * @return {void}
		 */
		function setTagConsent( signals ) {
			const entries = {};

			Object.keys( signals ).forEach( ( name ) => {
				entries[ name ] = {
					default: signals[ name ].def,
					update: signals[ name ].update,
					quiet: false,
				};
			} );

			window.google_tag_data = { ics: { entries } };
		}

		afterEach( () => {
			delete window.google_tag_data;
		} );

		/**
		 * The case that sent us here: a consent tool built as a GTM template
		 * calls the sandboxed updateConsentState(), which writes into the
		 * engine and pushes NOTHING onto the data layer. Scanning the queue
		 * sees only the denied default and concludes the visitor refused.
		 */
		it( 'sees an update the data layer never carried', () => {
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied', ad_storage: 'denied' },
			] );

			setTagConsent( {
				analytics_storage: { def: false, update: true },
				ad_storage: { def: false, update: true },
			} );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
			} );
			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'granted',
				ad_storage: 'granted',
			} );
		} );

		/**
		 * The discriminator, isolated. `update: undefined` means no update was
		 * made, so the default stands - reading it as `update || default`
		 * would be indistinguishable here but wrong in the next test.
		 */
		it( 'falls back to the default when no update was made', () => {
			setTagConsent( {
				analytics_storage: { def: true, update: undefined },
				ad_storage: { def: false, update: undefined },
			} );

			loadTracker();

			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'granted',
				ad_storage: 'denied',
			} );
		} );

		/**
		 * The unsafe direction, and the reason the type of `update` is the
		 * discriminator rather than its truthiness: a withdrawal is
		 * `default: true` followed by `update: false`, which any
		 * `update || default` reading would report as still granted.
		 */
		it( 'honours a withdrawal that leaves the default granted', () => {
			setTagConsent( {
				analytics_storage: { def: true, update: false },
				ad_storage: { def: true, update: false },
			} );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).toBeNull();
			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'denied',
				ad_storage: 'denied',
			} );
		} );

		/**
		 * The compliance case a data-layer-only reading gets backwards: with
		 * both the default AND the update set in-container, the queue is
		 * empty, which the no-regime rule would read as "this site runs no
		 * consent mode, so writing is the owner's posture" - and it would
		 * write for a visitor who denied. The engine's presence IS the regime.
		 */
		it( 'does not mistake an invisible regime for no regime at all', () => {
			setSearch( '?gclid=abc123' );

			setTagConsent( {
				analytics_storage: { def: false, update: false },
				ad_storage: { def: false, update: false },
			} );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).toBeNull();
		} );

		it( 'still writes when there is genuinely no consent regime anywhere', () => {
			setSearch( '?gclid=abc123' );

			loadTracker();

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				gclid: 'abc123',
			} );
		} );

		it( 'prefers the engine over the data layer when the two disagree', () => {
			// The queue holds the stale page-level default; the engine holds
			// the answer the visitor actually gave.
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied' },
			] );

			setTagConsent( {
				analytics_storage: { def: false, update: true },
			} );

			loadTracker();

			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'granted',
			} );
		} );

		/**
		 * The engine appears when the Google tag loads, which is normally
		 * after this script has run and written under whatever the data layer
		 * said. Nothing pushes to the data layer when an in-container update
		 * lands, so a deferred re-check is the only thing that would notice.
		 */
		it( 'picks up an engine that only appears after load', () => {
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'denied', ad_storage: 'denied' },
			] );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).toBeNull();

			setTagConsent( {
				analytics_storage: { def: false, update: true },
			} );
			window.dispatchEvent( new Event( 'load' ) );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
			} );
		} );

		it( 'ignores an engine that is missing, empty or malformed', () => {
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'granted' },
			] );

			window.google_tag_data = { ics: { entries: {} } };

			loadTracker();

			// Falls straight back to the data layer, which is exactly the
			// behaviour without the engine read at all.
			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'granted',
			} );
		} );

		it( 'survives entries of an unexpected shape', () => {
			window.google_tag_data = {
				ics: {
					entries: {
						analytics_storage: 'not-an-object',
						ad_storage: null,
						ad_user_data: { default: 'denied' },
					},
				},
			};

			expect( () => loadTracker() ).not.toThrow();
			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {} );
		} );
	} );

	describe( 'the data layer observer', () => {
		it( 'leaves push working for everybody else on the data layer', () => {
			loadTracker();

			const returned = window.dataLayer.push( {
				event: 'something_else',
			} );

			expect( returned ).toBe( window.dataLayer.length );
			expect( window.dataLayer ).toContainEqual( {
				event: 'something_else',
			} );
		} );

		it( 'still sees a consent update when something replaced push afterwards', () => {
			loadTracker();

			// A consent tool that wraps push after us must not blind the scan:
			// the entries stay in the array, which is why scanning is the
			// authority and the wrapper is only a trigger.
			const ourPush = window.dataLayer.push;
			window.dataLayer.push = function () {
				return ourPush.apply( window.dataLayer, arguments );
			};

			window.dataLayer.push( [
				'consent',
				'update',
				{ analytics_storage: 'granted' },
			] );

			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {
				analytics_storage: 'granted',
			} );
		} );

		it( 'ignores data layer entries that are not consent commands', () => {
			loadTracker();

			expect( () => {
				window.dataLayer.push( { event: 'gtm.js' } );
				window.dataLayer.push( null );
				window.dataLayer.push( [ 'consent' ] );
				window.dataLayer.push( [ 'consent', 'update' ] );
				window.dataLayer.push( [
					'consent',
					'update',
					'not-an-object',
				] );
				window.dataLayer.push( [ 'config', 'G-AAAA1111' ] );
			} ).not.toThrow();

			expect( readPayload( CONSENT_COOKIE ).signals ).toEqual( {} );
		} );
	} );

	describe( 'the confirmation-page backfill', () => {
		const backfill = {
			url: 'https://example.com/wp-json/gtm4wp/v2/google/attribution-backfill',
			nonce: 'a-rest-nonce',
			platform: 'wc',
			order: '42',
			token: 'wc_order_aBcDeF123456',
		};

		/**
		 * Lets the queued microtask that batches the POST run.
		 *
		 * @return {Promise<void>}
		 */
		const settle = () => Promise.resolve().then( () => {} );

		beforeEach( () => {
			window.fetch = jest.fn();
		} );

		afterEach( () => {
			delete window.fetch;
		} );

		it( 'posts nothing when the server did not flag this page', async () => {
			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );
			await settle();

			expect( window.fetch ).not.toHaveBeenCalled();
		} );

		it( 'posts the resolved values when the page is flagged', async () => {
			window.gtm4wp_gdm_attribution_config.backfill = backfill;

			loadTracker();
			await settle();

			// Nothing has resolved yet, so there is nothing worth posting.
			expect( window.fetch ).not.toHaveBeenCalled();

			serviceQueuedGets( {
				client_id: '111.222',
				sessions: { 'G-AAAA1111': '1788522496' },
			} );
			await settle();

			// One POST carrying everything that batch resolved, not one per
			// value: the core services the whole queue back to back.
			expect( window.fetch ).toHaveBeenCalledTimes( 1 );

			const [ url, options ] = window.fetch.mock.calls[ 0 ];

			expect( url ).toBe( backfill.url );
			expect( options.method ).toBe( 'POST' );
			expect( options.headers[ 'X-WP-Nonce' ] ).toBe( backfill.nonce );

			expect( JSON.parse( options.body ) ).toEqual( {
				platform: 'wc',
				order: '42',
				token: backfill.token,
				values: {
					client_id: '111.222',
					sessions: { 'G-AAAA1111': '1788522496' },
				},
				consent: {
					signals: {},
					captured_at: expect.any( Number ),
				},
			} );
		} );

		it( 'posts at most once per pageview', async () => {
			window.gtm4wp_gdm_attribution_config.backfill = backfill;

			loadTracker();
			serviceQueuedGets( {
				client_id: '111.222',
				sessions: { 'G-AAAA1111': '1788522496' },
			} );
			await settle();

			// A later consent change re-runs persistence; the route only ever
			// fills empty fields, so a second POST could add nothing.
			window.dataLayer.push( [
				'consent',
				'update',
				{ analytics_storage: 'granted', ad_storage: 'granted' },
			] );
			await settle();

			expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'posts nothing that consent does not allow storing', async () => {
			setSearch( '?gclid=abc123' );
			window.gtm4wp_gdm_attribution_config.backfill = backfill;
			window.dataLayer.push( [
				'consent',
				'default',
				{ analytics_storage: 'granted', ad_storage: 'denied' },
			] );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );
			await settle();

			const body = JSON.parse( window.fetch.mock.calls[ 0 ][ 1 ].body );

			expect( body.values.client_id ).toBe( '111.222' );
			expect( body.values.gclid ).toBeUndefined();
			expect( body.consent.signals ).toEqual( {
				analytics_storage: 'granted',
				ad_storage: 'denied',
			} );
		} );

		it( 'does not break the page when the request fails', async () => {
			window.gtm4wp_gdm_attribution_config.backfill = backfill;
			window.fetch = jest.fn( () => {
				throw new Error( 'offline' );
			} );

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			await expect( settle() ).resolves.toBeUndefined();

			// The cookie was still written: a failed backfill costs the
			// attribution of one order, nothing else.
			expect( readPayload( IDS_COOKIE ).client_id ).toBe( '111.222' );
		} );
	} );

	describe( 'hostile and malformed input', () => {
		it( 'keeps a click id verbatim and leaves the cookie parseable', () => {
			// The value is Google's to define, so it is stored as it came; what
			// matters is that a crafted value cannot break out of the cookie -
			// the payload still decodes to exactly what went in, and the raw
			// cookie carries no bare quote or semicolon.
			setSearch( '?gclid=' + encodeURIComponent( '";</script><b>x' ) );

			loadTracker();

			expect( readPayload( IDS_COOKIE ).gclid ).toBe( '";</script><b>x' );
			expect( document.cookie ).not.toContain( '</script>' );
			expect( document.cookie ).not.toContain( '"' );
		} );

		it( 'refuses to write a payload larger than the configured cap', () => {
			setSearch( '?gclid=' + 'a'.repeat( 3000 ) );

			loadTracker();

			expect( readPayload( IDS_COOKIE ) ).toBeNull();
		} );

		it( 'never adopts the content of a cookie written in another format version', () => {
			document.cookie =
				IDS_COOKIE +
				'=' +
				encodeURIComponent(
					JSON.stringify( { v: 99, gclid: 'from-the-future' } )
				) +
				';path=/';

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			// The stale payload is left where it is - it expires on its own and
			// the server-side parser refuses it by version too - but nothing
			// out of it is carried into what this pageview writes.
			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
			} );
		} );

		it( 'survives an unparseable stored cookie', () => {
			document.cookie = IDS_COOKIE + '=not-json;path=/';

			expect( () => loadTracker() ).not.toThrow();
		} );

		it( 'carries a click id captured on an earlier page forward', () => {
			document.cookie =
				IDS_COOKIE +
				'=' +
				encodeURIComponent(
					JSON.stringify( { v: 1, gclid: 'from-the-landing-page' } )
				) +
				';path=/';

			loadTracker();
			serviceQueuedGets( { client_id: '111.222' } );

			expect( readPayload( IDS_COOKIE ) ).toEqual( {
				v: 1,
				client_id: '111.222',
				gclid: 'from-the-landing-page',
			} );
		} );

		it( 'stores nothing when the lookups are never serviced', () => {
			// No Google Analytics tag in the container: the callbacks never
			// fire, and an absent id is better than an invented one.
			loadTracker();

			expect( readPayload( IDS_COOKIE ) ).toBeNull();
		} );

		it( 'ignores an empty value handed back by a lookup', () => {
			loadTracker();
			serviceQueuedGets( { client_id: '' } );

			expect( readPayload( IDS_COOKIE ) ).toBeNull();
		} );
	} );
} );
