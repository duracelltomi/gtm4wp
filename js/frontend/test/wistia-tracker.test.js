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
		// The container element. Wistia's runtime exposes it as elem(), but its
		// Player API documents no DOM accessor, so a runtime without one is a
		// case the tracker has to survive — `null` models that.
		this._elem = null;
	}
	elem() {
		return this._elem;
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
		// The tracker guards against double-init via this window flag; clear it
		// so each isolated re-require re-registers the _wq onReady handler.
		delete window.gtm4wp_wistia_inited;
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
			// "Ready" has no native GTM status, but the built-in Video
			// variables still resolve: gtm.videoStatus is present and empty
			// rather than absent, so it cannot inherit an earlier push's value.
			'gtm.videoProvider': 'wistia',
			'gtm.videoUrl': 'https://fast.wistia.net/embed/iframe/abc123def4',
			'gtm.videoTitle': 'My Wistia Video',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 0,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 0,
			// gtm.videoVisible is absent, not false: this stub exposes no
			// elem() and the page carries no .wistia_async_ container, so there
			// is nothing to measure and the key is omitted rather than guessed.
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

	/**
	 * Gives an element a layout box: jsdom reports 0×0 for everything, so a
	 * player is only measurable against the default 1024×768 viewport with a
	 * stubbed rect.
	 *
	 * @param {HTMLElement} element The element to measure.
	 * @param {Function}    readBox Returns the current box, so a test can move
	 *                              the player between two pushes.
	 */
	const measurable = ( element, readBox ) => {
		element.getBoundingClientRect = () => ( {
			...readBox(),
			width: 320,
			height: 200,
		} );
	};

	const ON_SCREEN = { top: 10, left: 10, bottom: 210, right: 330 };
	const OFF_SCREEN = { top: 900, left: 10, bottom: 1100, right: 330 };

	it( 'reports the container’s viewport position as gtm.videoVisible, per push', () => {
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		let box = ON_SCREEN;
		measurable( container, () => box );
		video._elem = container;

		loadTracker();

		video.emit( 'play' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		box = OFF_SCREEN;
		video.emit( 'pause' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'falls back to the wistia_async_<hashedId> embed container when the runtime exposes no element', () => {
		document.body.innerHTML =
			'<div class="wistia_embed wistia_async_abc123def4"></div>';
		const container = document.querySelector( '.wistia_async_abc123def4' );
		measurable( container, () => ON_SCREEN );
		// video._elem stays null: this is the documented-markup path.

		loadTracker();
		video.emit( 'play' );

		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );
	} );

	it( 'omits gtm.videoVisible when the player cannot be found in the page', () => {
		// No elem(), no matching embed markup: nothing to measure, so the key is
		// left out rather than reported as a (possibly wrong) false.
		document.body.innerHTML = '';

		loadTracker();
		video.emit( 'play' );

		expect( lastPush() ).not.toHaveProperty( 'gtm.videoVisible' );
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
			// A player event is still a gtm4wp.media* push, so it carries the
			// built-in Video variables — with an empty status, which also clears
			// the one a preceding state change left in the merged data layer.
			'gtm.videoProvider': 'wistia',
			'gtm.videoTitle': 'My Wistia Video',
			'gtm.videoStatus': '',
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

	it( 'registers the _wq onReady only once when the bundle loads twice (regression: double-init)', () => {
		jest.isolateModules( () => {
			require( '../gtm4wp-wistia' );
		} );
		const lenAfterFirst = window._wq.length;

		// A second execution of the same bundle (e.g. re-injected by a tag
		// manager) must not push a second onReady handler, which would double
		// every Wistia event in the data layer.
		jest.isolateModules( () => {
			require( '../gtm4wp-wistia' );
		} );

		expect( window._wq.length ).toBe( lenAfterFirst );
	} );
} );
