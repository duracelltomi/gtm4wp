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

const URI = 'spotify:track:4cOdK2wGLETKBW3PvgPWqT';

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
			'<iframe src="https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT"></iframe>';
	} );

	afterEach( () => {
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
				title: URI,
				url: 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
				duration: 0,
			},
			mediaCurrentTime: 0,
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
} );
