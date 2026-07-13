/**
 * Unit tests for the Wistia interaction tracker (js/frontend/gtm4wp-wistia.js).
 *
 * The tracker registers an onReady handler for id '_all' on the global `_wq`
 * ready queue; Wistia's runtime later invokes it with the player API object for
 * each video. These tests load the tracker, then simulate the runtime by
 * running the registered onReady with a stubbed Wistia video, drive the bound
 * handlers and assert the data layer pushes — including the flat gtm.video*
 * keys that populate GTM's built-in Video variables.
 */

class FakeWistiaVideo {
	constructor() {
		this.handlers = {};
		this._name = 'My Wistia Video';
		this._duration = 120;
		this._hashedId = 'abc123def4';
		this._time = 0;
	}
	bind( event, cb ) {
		this.handlers[ event ] = cb;
	}
	emit( event, ...args ) {
		if ( this.handlers[ event ] ) {
			this.handlers[ event ]( ...args );
		}
	}
	name() {
		return this._name;
	}
	duration() {
		return this._duration;
	}
	hashedId() {
		return this._hashedId;
	}
	time() {
		return this._time;
	}
}

describe( 'gtm4wp-wistia', () => {
	let video;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		window._wq = [];
		video = new FakeWistiaVideo();
	} );

	afterEach( () => {
		delete window._wq;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	/**
	 * Loads the tracker (which pushes an onReady config onto _wq) and simulates
	 * Wistia's runtime by invoking that config with the stubbed video.
	 *
	 * @return {FakeWistiaVideo} The video passed to onReady.
	 */
	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-wistia' );
		} );
		const config = window._wq[ window._wq.length - 1 ];
		config.onReady( video );
		return video;
	}

	it( 'registers an onReady handler for id "_all" on the _wq queue', () => {
		jest.isolateModules( () => {
			require( '../gtm4wp-wistia' );
		} );

		expect( window._wq ).toHaveLength( 1 );
		expect( window._wq[ 0 ].id ).toBe( '_all' );
		expect( typeof window._wq[ 0 ].onReady ).toBe( 'function' );
		// Nothing is pushed until the Wistia runtime invokes onReady.
		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'pushes mediaPlayerReady with the hashed id, name and duration', () => {
		loadTracker();

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'wistia',
			mediaData: {
				id: 'abc123def4',
				author: '',
				title: 'My Wistia Video',
				url: 'https://fast.wistia.net/embed/iframe/abc123def4',
				duration: 120,
			},
			mediaCurrentTime: 0,
		} );
	} );

	it( 'tracks play as a start state reading the current time from the player', () => {
		loadTracker();
		video._time = 30;

		video.emit( 'play' );

		expect( lastPush() ).toMatchObject( {
			mediaType: 'wistia',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'wistia',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'maps end to an "ended" state and seek to a "seeked" state', () => {
		loadTracker();

		video.emit( 'end' );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );

		video.emit( 'seek' );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'seeked',
			'gtm.videoStatus': 'seek',
		} );
	} );

	it( 'emits mediaPlaybackPercentage milestones from percentwatchedchanged', () => {
		loadTracker();
		video._time = 30; // 25% of 120s

		video.emit( 'percentwatchedchanged', 0.25, 0.2 );

		const marks = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
		);
		expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
			0, 10, 20,
		] );
		expect( lastPush() ).toMatchObject( {
			mediaPercentage: 20,
			'gtm.videoStatus': 'progress',
			'gtm.videoPercent': 20,
			'gtm.videoCurrentTime': 30,
		} );
	} );

	it( 'tracks playbackratechange as a player event carrying the new rate', () => {
		loadTracker();

		video.emit( 'playbackratechange', 1.5 );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'playbackratechange',
			mediaPlayerEventParam: 1.5,
		} );
	} );

	it( 'pushes the player-provided name into the data layer object verbatim (no HTML entity-encoding)', () => {
		// The video name is player-provided free text. A hostile name must reach
		// the data layer object raw: the tracker pushes to a JS object, it never
		// concatenates the value into an HTML/<script> body.
		video._name = '</script>"&<b>';

		loadTracker();

		expect( window.dataLayer[ 0 ].mediaData.title ).toBe(
			'</script>"&<b>'
		);
		expect( window.dataLayer[ 0 ].mediaData.title ).not.toContain( '&lt;' );
		expect( window.dataLayer[ 0 ].mediaData.title ).not.toContain(
			'&amp;'
		);
	} );
} );
