/**
 * Unit tests for the native HTML5 media interaction tracker
 * (js/frontend/gtm4wp-html5media.js).
 *
 * The tracker binds to every <video>/<audio> element and pushes gtm4wp.media*
 * events to the data layer as the element fires DOM media events. jsdom
 * provides the elements but does not run resource selection or playback, so the
 * read-only media properties (currentSrc, duration, currentTime, readyState,
 * error) are stubbed per element with Object.defineProperty and the handlers are
 * driven with dispatchEvent. Assertions cover the bespoke gtm4wp.media* shape
 * and the flat gtm.video* keys that populate GTM's built-in Video variables.
 */

/**
 * Overrides the (mostly read-only) media element properties the tracker reads,
 * so a test can present a fully-loaded, partially-played media element to jsdom.
 *
 * @param {HTMLMediaElement} el    The media element to stub.
 * @param {Object}           props Property name => value pairs to define.
 */
function stubMedia( el, props ) {
	Object.keys( props ).forEach( ( key ) => {
		Object.defineProperty( el, key, {
			value: props[ key ],
			writable: true,
			configurable: true,
		} );
	} );
}

/**
 * Creates a stubbed <video>/<audio> element, appends it to the document (the
 * tracker queries the DOM on load) and returns it. Sensible defaults stand in
 * for an unloaded element; pass overrides to simulate loaded/played state.
 *
 * @param {string} tag   'video' or 'audio'.
 * @param {Object} props Property overrides (readyState, duration, currentTime…).
 * @return {HTMLMediaElement} The appended, stubbed element.
 */
function createMedia( tag, props = {} ) {
	const el = document.createElement( tag );
	stubMedia( el, {
		currentSrc: 'https://example.com/media/clip.mp4',
		duration: NaN,
		currentTime: 0,
		readyState: 0,
		error: null,
		playbackRate: 1,
		volume: 1,
		...props,
	} );
	document.body.appendChild( el );
	return el;
}

describe( 'gtm4wp-html5media', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		document.body.innerHTML = '';
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	/**
	 * Loads the tracker fresh. It initializes on load because jsdom reports
	 * document.readyState as 'complete', so any media elements and their stubs
	 * must already be in the DOM before this is called.
	 */
	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-html5media' );
		} );
	}

	it( 'pushes mediaPlayerReady with the real duration once metadata is available', () => {
		createMedia( 'video', { readyState: 1, duration: 120 } );

		loadTracker();

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'html5media',
			mediaData: {
				id: 'clip.mp4',
				author: '',
				title: 'clip.mp4',
				url: 'https://example.com/media/clip.mp4',
				duration: 120,
			},
			mediaCurrentTime: 0,
		} );
	} );

	it( 'defers mediaPlayerReady until loadedmetadata when the element is not loaded yet', () => {
		const el = createMedia( 'video', { readyState: 0, duration: NaN } );

		loadTracker();

		// Nothing pushed while metadata (and duration) is still unknown.
		expect( window.dataLayer ).toHaveLength( 0 );

		// Metadata arrives: duration and currentSrc are now known.
		stubMedia( el, { readyState: 1, duration: 90 } );
		el.dispatchEvent( new Event( 'loadedmetadata' ) );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaData: { duration: 90 },
		} );
	} );

	it( 'tracks the "playing" event (not "play") as a start state with native params', () => {
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 120,
			currentTime: 5,
		} );

		loadTracker();

		// The bare "play" event (mere playback request) must not fire a state
		// change; only "playing" (real playback) does.
		el.dispatchEvent( new Event( 'play' ) );
		expect( window.dataLayer ).toHaveLength( 1 );

		el.dispatchEvent( new Event( 'playing' ) );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'html5media',
			mediaPlayerState: 'play',
			mediaCurrentTime: 5,
			'gtm.videoProvider': 'html5',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 5,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 4,
		} );
	} );

	it( 'reports the media element’s viewport position as gtm.videoVisible, per push', () => {
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 120,
			currentTime: 5,
		} );
		// jsdom gives every element a 0×0 box, so the player is measured through
		// a stubbed rect against the default 1024×768 viewport.
		let box = { top: 10, left: 10, bottom: 210, right: 330 };
		el.getBoundingClientRect = () => ( {
			...box,
			width: 320,
			height: 200,
		} );
		loadTracker();

		el.dispatchEvent( new Event( 'playing' ) );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		el.dispatchEvent( new Event( 'pause' ) );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'maps the "waiting" event to a buffering state and the native buffering status', () => {
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 120,
			currentTime: 5,
		} );

		loadTracker();
		el.dispatchEvent( new Event( 'waiting' ) );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'buffering',
			mediaCurrentTime: 5,
			'gtm.videoStatus': 'buffering',
		} );
	} );

	it( 'maps ended to "complete" and seeked to "seek"', () => {
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 120,
			currentTime: 120,
		} );

		loadTracker();

		el.dispatchEvent( new Event( 'ended' ) );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );

		el.dispatchEvent( new Event( 'seeked' ) );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'seeked',
			'gtm.videoStatus': 'seek',
		} );
	} );

	it( 'emits mediaPlaybackPercentage milestones with matching gtm.videoPercent', () => {
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 120,
			currentTime: 30,
		} );

		loadTracker();

		// 30 / 120 = 25% -> crosses the 0, 10 and 20 marks in one timeupdate.
		el.dispatchEvent( new Event( 'timeupdate' ) );

		const marks = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
		);
		expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
			0, 10, 20,
		] );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlaybackPercentage',
			mediaType: 'html5media',
			mediaPercentage: 20,
			mediaCurrentTime: 30,
			'gtm.videoStatus': 'progress',
			'gtm.videoPercent': 20,
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
		} );
	} );

	it( 'fires no milestone when the duration is 0 (avoids an Infinity percentage)', () => {
		// A zero duration with a positive currentTime makes currentTime / duration
		// Infinity, and Math.floor( Infinity * 100 ) would cross EVERY milestone at once
		// (e.g. a stream before metadata loads). The `! duration` guard blocks it, the
		// same way the other trackers do (finding #20 family convention, #42).
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 0,
			currentTime: 5,
		} );

		loadTracker();
		el.dispatchEvent( new Event( 'timeupdate' ) );

		const marks = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
		);
		expect( marks ).toHaveLength( 0 );
	} );

	it( 'pushes an error as a mediaPlayerEvent with the html5media type and the error code', () => {
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 60,
			currentTime: 10,
			error: { code: 4 },
		} );

		loadTracker();
		el.dispatchEvent( new Event( 'error' ) );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			// Regression guard: this used to be pushed as 'html5video', out of
			// sync with every other push in the tracker.
			mediaType: 'html5media',
			mediaPlayerEvent: 'error',
			mediaPlayerEventParam: 4,
			mediaCurrentTime: 10,
		} );
	} );

	it( 'reports ratechange and volumechange as html5media player events', () => {
		const el = createMedia( 'video', {
			readyState: 1,
			duration: 60,
			currentTime: 10,
			playbackRate: 1.5,
			volume: 0.25,
		} );

		loadTracker();

		el.dispatchEvent( new Event( 'ratechange' ) );
		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'html5media',
			mediaPlayerEvent: 'ratechange',
			mediaPlayerEventParam: 1.5,
		} );

		el.dispatchEvent( new Event( 'volumechange' ) );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerEvent: 'volumechange',
			mediaPlayerEventParam: 0.25,
		} );
	} );

	it( 'tracks Picture-in-Picture and fullscreen changes on a <video>', () => {
		const video = createMedia( 'video', { readyState: 1, duration: 60 } );

		loadTracker();

		video.dispatchEvent( new Event( 'enterpictureinpicture' ) );
		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'enterpictureinpicture',
			mediaPlayerEventParam: true,
		} );

		video.dispatchEvent( new Event( 'leavepictureinpicture' ) );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerEvent: 'leavepictureinpicture',
			mediaPlayerEventParam: true,
		} );

		Object.defineProperty( document, 'fullscreenElement', {
			value: video,
			configurable: true,
		} );
		video.dispatchEvent( new Event( 'fullscreenchange' ) );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerEvent: 'fullscreenchange',
			mediaPlayerEventParam: true,
		} );
	} );

	it( 'does not bind Picture-in-Picture or fullscreen on an <audio>', () => {
		const audio = createMedia( 'audio', { readyState: 1, duration: 60 } );

		loadTracker();
		const pushCount = window.dataLayer.length;

		audio.dispatchEvent( new Event( 'enterpictureinpicture' ) );
		audio.dispatchEvent( new Event( 'leavepictureinpicture' ) );
		audio.dispatchEvent( new Event( 'fullscreenchange' ) );

		expect( window.dataLayer ).toHaveLength( pushCount );
	} );

	it( 'does not throw or push anything when the page has no media elements', () => {
		expect( () => loadTracker() ).not.toThrow();
		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
