/**
 * Unit tests for the Spotify interaction tracker
 * (js/frontend/gtm4wp-spotify.js).
 *
 * The tracker registers a global onSpotifyIframeApiReady callback; the Spotify
 * iFrame API invokes it with an IFrameAPI, and the tracker creates a controller
 * for each open.spotify.com/embed iframe. The Spotify embed only reports a
 * periodic playback_update (isPaused/isBuffering/position/duration in ms), so
 * discrete play/pause/end/buffering states are derived and de-duplicated. These
 * tests stub the IFrameAPI + controller, drive playback_update and assert the
 * data layer pushes — including the flat gtm.video* keys.
 */

class FakeSpotifyController {
	constructor() {
		this.handlers = {};
	}
	addListener( event, cb ) {
		this.handlers[ event ] = cb;
	}
	emit( event, data ) {
		if ( this.handlers[ event ] ) {
			this.handlers[ event ]( data );
		}
	}
}

/**
 * A stand-in for the oEmbed Response. Deliberately no more permissive than the
 * real thing: the tracker reads `ok` before it reads the body, so a double that
 * always parsed would hide the non-2xx branch.
 *
 * @param {Object}  body The JSON body the response resolves to.
 * @param {boolean} [ok] Whether the response reports success.
 * @return {Object} The stub Response.
 */
function oembedResponse( body, ok = true ) {
	return {
		ok,
		json: () => Promise.resolve( body ),
	};
}

const URI = 'spotify:track:4cOdK2wGLETKBW3PvgPWqT';
const EMBED_SRC = 'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT';

// Spotify's oEmbed markup carries the real title on the iframe, prefixed with
// its own literal, and WordPress core preserves that attribute rather than
// composing one of its own. Pins the prefix registered as U104.
const EMBED_TITLE = 'Never Gonna Give You Up';
const TITLE_ATTR = 'Spotify Embed: ' + EMBED_TITLE;

// The exact request the oEmbed fallback must issue, written out rather than
// rebuilt with encodeURIComponent so it pins the upstream contract (U105)
// instead of restating the source's own arithmetic.
const OEMBED_URL =
	'https://open.spotify.com/oembed?url=' +
	'https%3A%2F%2Fopen.spotify.com%2Ftrack%2F4cOdK2wGLETKBW3PvgPWqT';
const OEMBED_TITLE = 'Fetched Track Title';

describe( 'gtm4wp-spotify', () => {
	let controller;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		// The tracker guards against double-init via this window flag; clear it
		// so each isolated re-require re-registers onSpotifyIframeApiReady.
		delete window.gtm4wp_spotify_inited;
		controller = new FakeSpotifyController();
		document.body.innerHTML =
			'<iframe title="' +
			TITLE_ATTR +
			'" src="' +
			EMBED_SRC +
			'"></iframe>';
		// jsdom provides no fetch. Stubbed here rather than relied on from
		// another test file, and asserted against in both directions: the tests
		// below check not only what it returned but that it was not called at
		// all on the path that must stay offline.
		global.fetch = jest.fn( () =>
			Promise.resolve( oembedResponse( { title: OEMBED_TITLE } ) )
		);
	} );

	afterEach( () => {
		delete global.fetch;
		delete window.onSpotifyIframeApiReady;
		// The shared media observer lives on the (jsdom) window, which persists
		// across tests; disconnect and reset it so nothing leaks into the next.
		if ( window.gtm4wp_media_observer ) {
			window.gtm4wp_media_observer.disconnect();
		}
		delete window.gtm4wp_media_observer;
		delete window.gtm4wp_media_scanners;
		delete window.gtm4wp_media_observe_dynamic;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];
	const stateChanges = () =>
		window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlayerStateChange'
		);
	// MutationObserver records are delivered as microtasks, so awaiting a
	// macrotask guarantees the observer callback has already run.
	const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	// Cap on the emulated SDK so an unbounded re-wire regression fails the
	// assertion instead of hanging the run (the loop is a microtask loop, which
	// would starve the macrotask flush() above).
	const CREATE_CAP = 10;

	/**
	 * A stub IFrameAPI that models what the real Spotify SDK actually does: it
	 * does NOT reuse the element it is handed — createController() runs
	 * `parentElement.replaceChild( iframe, target )` and assigns the embed src
	 * synchronously, then reports the controller.
	 *
	 * Modelling the replacement is the point: a fake that leaves the element in
	 * place cannot see the unbounded re-wire loop it caused (the observer saw the
	 * SDK's own unmarked iframe as a fresh match and wired it, forever).
	 *
	 * @param {Array} [calls] Collects each element createController was given.
	 * @return {Object} The stub IFrameAPI.
	 */
	function fakeIFrameAPI( calls ) {
		return {
			createController( element, options, cb ) {
				if ( calls ) {
					calls.push( element );
					if ( calls.length > CREATE_CAP ) {
						cb( controller );
						return;
					}
				}

				const replacement = document.createElement( 'iframe' );
				replacement.setAttribute(
					'src',
					element.getAttribute( 'src' )
				);
				if ( element.parentElement ) {
					element.parentElement.replaceChild( replacement, element );
				}

				cb( controller );
			},
		};
	}

	/**
	 * Loads the tracker (which defines onSpotifyIframeApiReady) and simulates the
	 * Spotify API invoking it, so a controller is created and bound.
	 *
	 * @return {FakeSpotifyController} The bound controller.
	 */
	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-spotify' );
		} );
		window.onSpotifyIframeApiReady( fakeIFrameAPI() );
		return controller;
	}

	it( 'chains a pre-existing onSpotifyIframeApiReady instead of clobbering it (T49)', () => {
		// Another integration - or the site loading the iFrame API for its own
		// reasons - may have registered the callback first. The tracker's
		// subscribe() promises to preserve and chain it; nothing asserted that,
		// so a clobber regression was silent (the third party just stopped
		// working, with no error on our side).
		const previous = jest.fn();
		window.onSpotifyIframeApiReady = previous;

		const bound = loadTracker();

		// The site's callback fired exactly once, with the same IFrameAPI the
		// tracker received…
		expect( previous ).toHaveBeenCalledTimes( 1 );
		expect( previous.mock.calls[ 0 ][ 0 ] ).toBeDefined();
		// …and the tracker still did its own half: chained, not replaced in
		// either direction. A wired controller means playback events flow.
		expect( bound.handlers.playback_update ).toBeDefined();
	} );

	it( 'creates exactly one controller for a later-inserted embed the SDK replaces', async () => {
		// Runtime tracking on + an embed inserted after load (popup/AJAX) means the
		// shared observer is live when the SDK's replaceChild lands. The wired
		// marker leaves with the replaced node, so without the re-mark the
		// replacement is wired again — replacing it again, unbounded.
		window.gtm4wp_media_observe_dynamic = true;
		document.body.innerHTML = '';
		const calls = [];

		jest.isolateModules( () => {
			require( '../gtm4wp-spotify' );
		} );
		window.onSpotifyIframeApiReady( fakeIFrameAPI( calls ) );

		const frame = document.createElement( 'iframe' );
		frame.setAttribute(
			'src',
			'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT'
		);
		document.body.appendChild( frame );

		await flush();
		await flush();

		expect( calls ).toHaveLength( 1 );
	} );

	const update = ( overrides ) =>
		controller.emit( 'playback_update', {
			data: {
				isPaused: false,
				isBuffering: false,
				position: 0,
				duration: 120000,
				playingURI: URI,
				...overrides,
			},
		} );

	it( 'pushes mediaPlayerReady on the ready event', () => {
		loadTracker();

		controller.emit( 'ready' );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'spotify',
			mediaData: {
				id: '4cOdK2wGLETKBW3PvgPWqT',
				author: '',
				title: EMBED_TITLE,
				url: 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
				duration: 0,
			},
			mediaCurrentTime: 0,
			// "Ready" has no native GTM status, but the built-in Video
			// variables still resolve: gtm.videoStatus is present and empty
			// rather than absent, so it cannot inherit an earlier push's value.
			// The controller reports no duration before the first
			// playback_update, so it is 0 here as it is in mediaData.
			'gtm.videoProvider': 'spotify',
			'gtm.videoUrl':
				'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
			'gtm.videoTitle': EMBED_TITLE,
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 0,
			'gtm.videoDuration': 0,
			'gtm.videoPercent': 0,
			// jsdom gives the iframe a 0×0 box, so it measures as off screen.
			'gtm.videoVisible': false,
		} );
	} );

	it( 'derives a start state from a playing playback_update (ms -> seconds)', () => {
		loadTracker();

		update( { isPaused: false, position: 30000, duration: 120000 } );

		expect( lastPush() ).toMatchObject( {
			mediaType: 'spotify',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'spotify',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'measures gtm.videoVisible on the iframe the SDK put in the original’s place', () => {
		loadTracker();
		// createController replaced the embed: the element the tracker was handed
		// is detached and has no box at all, so a tracker measuring THAT would
		// report nothing. Only the live replacement can answer the question.
		const live = document.querySelector( 'iframe' );
		// jsdom gives every element a 0×0 box, so the player is measured through
		// a stubbed rect against the default 1024×768 viewport.
		let box = { top: 10, left: 10, bottom: 210, right: 330 };
		live.getBoundingClientRect = () => ( {
			...box,
			width: 320,
			height: 200,
		} );

		update( { isPaused: false, position: 30000, duration: 120000 } );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		update( { isPaused: true, position: 30000, duration: 120000 } );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'derives a pause state when isPaused flips to true', () => {
		loadTracker();

		update( { isPaused: false, position: 30000 } );
		update( { isPaused: true, position: 30000 } );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'pause',
			'gtm.videoStatus': 'pause',
		} );
	} );

	it( 'derives an ended state when the position reaches the duration', () => {
		loadTracker();

		update( { isPaused: false, position: 120000, duration: 120000 } );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );
	} );

	it( 'derives a buffering state from the isBuffering flag', () => {
		loadTracker();

		update( { isBuffering: true, position: 5000 } );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'buffering',
			'gtm.videoStatus': 'buffering',
		} );
	} );

	it( 'emits mediaPlaybackPercentage milestones from the update position', () => {
		loadTracker();

		update( { isPaused: false, position: 60000, duration: 240000 } );

		const marks = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
		);
		expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
			0, 10, 20,
		] );
	} );

	it( 'collapses repeated identical playback_update states into one', () => {
		loadTracker();

		// position 0 avoids any percentage push, isolating the state dedupe.
		update( { isPaused: false, position: 0 } );
		update( { isPaused: false, position: 0 } );

		expect( stateChanges() ).toHaveLength( 1 );
		expect( stateChanges()[ 0 ].mediaPlayerState ).toBe( 'play' );
	} );

	it( 'pushes the playingURI-derived id into the data layer object verbatim (no HTML entity-encoding)', () => {
		loadTracker();

		update( {
			isPaused: false,
			position: 0,
			playingURI: 'spotify:track:</script>&x',
		} );

		expect( lastPush().mediaData.id ).toBe( '</script>&x' );
		expect( lastPush().mediaData.id ).not.toContain( '&lt;' );
		expect( lastPush().mediaData.id ).not.toContain( '&amp;' );
	} );

	it( 'does not push anything when the Spotify API never invokes the callback', () => {
		jest.isolateModules( () => {
			require( '../gtm4wp-spotify' );
		} );

		// onSpotifyIframeApiReady is defined but never called by the (absent) SDK.
		expect( typeof window.onSpotifyIframeApiReady ).toBe( 'function' );
		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'does not re-register onSpotifyIframeApiReady when the bundle loads twice (regression: double-init)', () => {
		jest.isolateModules( () => {
			require( '../gtm4wp-spotify' );
		} );
		const firstCallback = window.onSpotifyIframeApiReady;

		// A second execution (e.g. re-injected by a tag manager) must bail so it
		// does not chain onto itself and create a controller per iframe twice.
		jest.isolateModules( () => {
			require( '../gtm4wp-spotify' );
		} );

		expect( window.onSpotifyIframeApiReady ).toBe( firstCallback );
	} );

	describe( 'title resolution', () => {
		// The Spotify embed API carries no title at all - its playback_update
		// reports only isPaused/isBuffering/position/duration/playingURI - so the
		// title comes from the embed markup, and from oEmbed when the markup has
		// none. Before this the URI itself was reported as the title.

		const embedWithoutTitle = () => {
			document.body.innerHTML =
				'<iframe src="' + EMBED_SRC + '"></iframe>';
		};

		it( 'strips Spotify’s prefix off the embed title and asks for nothing', async () => {
			loadTracker();
			controller.emit( 'ready' );
			await flush();

			expect( lastPush().mediaData.title ).toBe( EMBED_TITLE );
			expect( lastPush()[ 'gtm.videoTitle' ] ).toBe( EMBED_TITLE );
			// The markup already answered the question. A lookup here would be a
			// third-party request on every page carrying an embed, for nothing.
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'uses a title attribute carrying no Spotify prefix verbatim', async () => {
			document.body.innerHTML =
				'<iframe title="Hand written title" src="' +
				EMBED_SRC +
				'"></iframe>';

			loadTracker();
			controller.emit( 'ready' );
			await flush();

			expect( lastPush().mediaData.title ).toBe( 'Hand written title' );
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'reads the title before the SDK replaces the embed node', async () => {
			// createController() does parentElement.replaceChild(), and the
			// replacement carries only the src - so a tracker reading the title
			// after wiring reads it off a node that no longer has one.
			loadTracker();
			expect(
				document.querySelector( 'iframe' ).getAttribute( 'title' )
			).toBeNull();

			controller.emit( 'ready' );
			await flush();

			expect( lastPush().mediaData.title ).toBe( EMBED_TITLE );
		} );

		it( 'looks the title up from oEmbed when the embed carries none', async () => {
			embedWithoutTitle();

			loadTracker();
			await flush();

			expect( global.fetch ).toHaveBeenCalledTimes( 1 );
			expect( global.fetch ).toHaveBeenCalledWith( OEMBED_URL, {
				credentials: 'omit',
			} );

			update( { isPaused: false, position: 30000 } );
			expect( lastPush().mediaData.title ).toBe( OEMBED_TITLE );
			expect( lastPush()[ 'gtm.videoTitle' ] ).toBe( OEMBED_TITLE );
		} );

		it( 'reports the URI on an event that fires before the lookup lands', async () => {
			embedWithoutTitle();

			loadTracker();
			// No flush: the request is in flight, exactly as it would be if the
			// SDK reported ready first. The event is pushed anyway rather than
			// held back, so a hanging request can never cost an event.
			controller.emit( 'ready' );

			expect( lastPush().mediaData.title ).toBe( URI );
			expect( lastPush()[ 'gtm.videoTitle' ] ).toBe( URI );

			// ...and once it lands, later events carry the real title.
			await flush();
			update( { isPaused: false, position: 30000 } );
			expect( lastPush().mediaData.title ).toBe( OEMBED_TITLE );
		} );

		it( 'does not repeat the lookup as playback_update repeats', async () => {
			embedWithoutTitle();

			loadTracker();
			await flush();

			// playback_update arrives every few hundred ms for the whole of
			// playback; one lookup per URI is the entire budget.
			for ( let i = 0; i < 8; i++ ) {
				update( { isPaused: false, position: i * 1000 } );
			}
			await flush();

			expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		} );

		it.each( [
			[
				'the request is blocked',
				() => Promise.reject( new Error( 'blocked' ) ),
			],
			[
				'the endpoint answers non-2xx',
				() => Promise.resolve( oembedResponse( {}, false ) ),
			],
			[
				'the body does not parse',
				() =>
					Promise.resolve( {
						ok: true,
						json: () => Promise.reject( new Error( 'bad json' ) ),
					} ),
			],
			[
				'the title is not a string',
				() => Promise.resolve( oembedResponse( { title: 42 } ) ),
			],
			[
				'the title is empty',
				() => Promise.resolve( oembedResponse( { title: '   ' } ) ),
			],
		] )(
			'falls back to the URI and never retries when %s',
			async ( _label, response ) => {
				embedWithoutTitle();
				global.fetch = jest.fn( response );

				loadTracker();
				await flush();

				for ( let i = 0; i < 8; i++ ) {
					update( { isPaused: false, position: i * 1000 } );
				}
				await flush();

				expect( lastPush().mediaData.title ).toBe( URI );
				// The failure is remembered. Without that, every single
				// playback_update would re-request open.spotify.com.
				expect( global.fetch ).toHaveBeenCalledTimes( 1 );
			}
		);

		it( 'resolves a playlist track’s own title, not the playlist’s', async () => {
			document.body.innerHTML =
				'<iframe title="Spotify Embed: Today’s Top Hits" ' +
				'src="https://open.spotify.com/embed/playlist/37i9dQZF1DXcBWIGoYBM5M"></iframe>';

			loadTracker();
			// The embed's own title came from the markup, so nothing was asked
			// for until a track inside the playlist started.
			expect( global.fetch ).not.toHaveBeenCalled();

			update( { isPaused: false, position: 0 } );
			await flush();

			expect( global.fetch ).toHaveBeenCalledTimes( 1 );
			expect( global.fetch ).toHaveBeenCalledWith( OEMBED_URL, {
				credentials: 'omit',
			} );

			update( { isPaused: true, position: 0 } );
			expect( lastPush().mediaData.title ).toBe( OEMBED_TITLE );
			expect( lastPush().mediaData.title ).not.toBe( 'Today’s Top Hits' );
		} );

		it( 'percent-encodes the URI into the lookup URL', async () => {
			embedWithoutTitle();

			loadTracker();
			await flush();
			global.fetch.mockClear();

			update( {
				isPaused: false,
				position: 0,
				playingURI: 'spotify:track:</script>&x',
			} );
			await flush();

			expect( global.fetch ).toHaveBeenCalledWith(
				'https://open.spotify.com/oembed?url=' +
					'https%3A%2F%2Fopen.spotify.com%2Ftrack%2F%3C%2Fscript%3E%26x',
				{ credentials: 'omit' }
			);
		} );

		it( 'asks for nothing when the URI carries no type and id', async () => {
			document.body.innerHTML =
				'<iframe src="https://open.spotify.com/embed/"></iframe>';

			loadTracker();
			await flush();

			// Nothing addressable to look up, so no request is built from it.
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'carries the resolved title on every kind of push', async () => {
			loadTracker();

			controller.emit( 'ready' );
			update( { isPaused: false, position: 60000, duration: 240000 } );
			update( { isPaused: true, position: 60000, duration: 240000 } );
			await flush();

			expect( window.dataLayer.length ).toBeGreaterThan( 3 );
			window.dataLayer.forEach( ( entry ) => {
				expect( entry.mediaData.title ).toBe( EMBED_TITLE );
				expect( entry[ 'gtm.videoTitle' ] ).toBe( EMBED_TITLE );
			} );
		} );
	} );

	describe( 'SDK loading', () => {
		// Pins the URL registered as an upstream coupling (U65).
		const SDK = 'https://open.spotify.com/embed/iframe-api/v1';

		const sdkTags = () =>
			Array.from( document.getElementsByTagName( 'script' ) ).filter(
				( tag ) => tag.getAttribute( 'src' ) === SDK
			);

		// Earlier tests in this file exercise the SDK-missing path, which now
		// injects a tag of its own; start from a clean head so this describe
		// measures only the requests it caused.
		beforeEach( () => {
			sdkTags().forEach( ( tag ) => tag.remove() );
		} );

		afterEach( () => {
			sdkTags().forEach( ( tag ) => tag.remove() );
		} );

		it( 'requests nothing from Spotify on a page with no Spotify embed', () => {
			document.body.innerHTML = '';

			jest.isolateModules( () => {
				require( '../gtm4wp-spotify' );
			} );

			expect( sdkTags() ).toHaveLength( 0 );
		} );

		it( 'still claims onSpotifyIframeApiReady on a page with no embed', () => {
			document.body.innerHTML = '';
			delete window.onSpotifyIframeApiReady;

			jest.isolateModules( () => {
				require( '../gtm4wp-spotify' );
			} );

			// Nothing is fetched, but the callback is registered anyway, so a
			// site that loads the iFrame API itself is still tracked.
			expect( sdkTags() ).toHaveLength( 0 );
			expect( typeof window.onSpotifyIframeApiReady ).toBe( 'function' );
		} );

		it( 'fetches the iFrame API when the page has an embed', () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-spotify' );
			} );

			expect( sdkTags() ).toHaveLength( 1 );
		} );
	} );
} );
