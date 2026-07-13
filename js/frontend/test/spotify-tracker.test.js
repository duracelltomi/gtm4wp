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
		controller = new FakeSpotifyController();
		document.body.innerHTML =
			'<iframe src="https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT"></iframe>';
	} );

	afterEach( () => {
		delete window.onSpotifyIframeApiReady;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];
	const stateChanges = () =>
		window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlayerStateChange'
		);

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
		window.onSpotifyIframeApiReady( {
			createController( element, options, cb ) {
				cb( controller );
			},
		} );
		return controller;
	}

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
} );
