/**
 * Unit tests for the SoundCloud interaction tracker
 * (js/frontend/gtm4wp-soundcloud.js).
 *
 * The tracker builds an SC.Widget for every `iframe[src*="soundcloud.com"]`,
 * refreshes the current sound via getCurrentSound and pushes gtm4wp.media*
 * events to the data layer as the widget fires events. These tests stub the
 * SoundCloud Widget API, drive the captured event handlers and assert the
 * resulting data layer pushes — including the flat gtm.video* keys (with the
 * SoundCloud-specific millisecond -> second conversion) that populate GTM's
 * built-in Video variables.
 */

const flushPromises = () =>
	new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const SC_EVENTS = {
	READY: 'ready',
	PLAY: 'play',
	PLAY_PROGRESS: 'playProgress',
	PAUSE: 'pause',
	FINISH: 'finish',
	SEEK: 'seek',
	CLICK_DOWNLOAD: 'clickDownload',
	CLICK_BUY: 'clickBuy',
	OPEN_SHARE_PANEL: 'openSharePanel',
	ERROR: 'error',
};

/**
 * Minimal stub of the SoundCloud Widget API. Captures the handlers registered
 * via bind() so a test can emit widget events, and resolves the
 * getCurrentSound/getPosition callbacks the tracker relies on. Durations and
 * positions are in milliseconds, as the real API reports them.
 */
class FakeSCWidget {
	constructor() {
		this.handlers = {};
		this.currentSound = {
			id: 12345,
			title: 'My SoundCloud Track',
			duration: 240000,
			permalink_url: 'https://soundcloud.com/artist/my-track',
			user: { username: 'The Artist' },
		};
		this.position = 0;
	}
	bind( event, cb ) {
		this.handlers[ event ] = cb;
	}
	emit( event, data ) {
		if ( this.handlers[ event ] ) {
			this.handlers[ event ]( data );
		}
	}
	getCurrentSound( cb ) {
		cb( this.currentSound );
	}
	getPosition( cb ) {
		cb( this.position );
	}
}

describe( 'gtm4wp-soundcloud', () => {
	let widget;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		widget = new FakeSCWidget();

		// SC.Widget is invoked as a function (not with `new`) and also exposes
		// the event-name constants on SC.Widget.Events.
		const WidgetFactory = function () {
			return widget;
		};
		WidgetFactory.Events = SC_EVENTS;
		global.SC = { Widget: WidgetFactory };

		document.body.innerHTML =
			'<iframe src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/12345"></iframe>';
	} );

	afterEach( () => {
		delete global.SC;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	/**
	 * Loads the tracker fresh (it initializes on load because jsdom reports
	 * document.readyState as 'complete') and emits READY so the widget's
	 * per-event handlers are bound and the current sound is cached.
	 *
	 * @return {FakeSCWidget} The widget the tracker constructed.
	 */
	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-soundcloud' );
		} );
		widget.emit( SC_EVENTS.READY );
		return widget;
	}

	it( 'pushes mediaPlayerReady with id, author, title, url and duration once the widget is ready', () => {
		loadTracker();

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'soundcloud',
			mediaData: {
				id: 12345,
				author: 'The Artist',
				title: 'My SoundCloud Track',
				url: 'https://soundcloud.com/artist/my-track',
				duration: 240000,
			},
			mediaCurrentTime: 0,
		} );
	} );

	it( 'tracks PLAY as a start state and converts SoundCloud ms to seconds for the native params', () => {
		loadTracker();

		widget.emit( SC_EVENTS.PLAY, { currentPosition: 30000 } );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'soundcloud',
			mediaPlayerState: 'play',
			// Bespoke shape keeps SoundCloud's native milliseconds...
			mediaCurrentTime: 30000,
			// ...while the gtm.video* keys are in whole seconds.
			'gtm.videoProvider': 'soundcloud',
			'gtm.videoStatus': 'start',
			'gtm.videoUrl': 'https://soundcloud.com/artist/my-track',
			'gtm.videoTitle': 'My SoundCloud Track',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 240,
			'gtm.videoPercent': 12,
		} );
	} );

	it( 'maps FINISH to an "ended" state with the native "complete" status', () => {
		loadTracker();

		widget.emit( SC_EVENTS.FINISH, { currentPosition: 240000 } );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'ended',
			mediaCurrentTime: 240000,
			'gtm.videoStatus': 'complete',
		} );
	} );

	it( 'emits mediaPlaybackPercentage milestones with matching gtm.videoPercent', () => {
		loadTracker();

		// 60000 / 240000 = 25% -> crosses the 0, 10 and 20 marks in one tick.
		widget.emit( SC_EVENTS.PLAY_PROGRESS, { currentPosition: 60000 } );

		const marks = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
		);
		expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
			0, 10, 20,
		] );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlaybackPercentage',
			mediaType: 'soundcloud',
			mediaPercentage: 20,
			mediaCurrentTime: 60000,
			'gtm.videoStatus': 'progress',
			'gtm.videoPercent': 20,
			'gtm.videoCurrentTime': 60,
			'gtm.videoDuration': 240,
		} );
	} );

	it( 'pushes no percentage milestone when the sound duration is 0 (regression: Infinity milestones)', () => {
		loadTracker();

		// A live/broken sound can report duration 0 while the position advances.
		// Without the guard, position / 0 = Infinity, which is > every mark and
		// would push them all.
		widget.currentSound.duration = 0;
		window.dataLayer = [];
		widget.emit( SC_EVENTS.PLAY_PROGRESS, { currentPosition: 60000 } );

		const marks = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
		);
		expect( marks ).toHaveLength( 0 );
	} );

	it( 'tracks CLICK_DOWNLOAD as a player event using the position read from the widget', () => {
		loadTracker();
		widget.position = 45000;

		widget.emit( SC_EVENTS.CLICK_DOWNLOAD );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'soundcloud',
			mediaPlayerEvent: 'click-download',
			mediaCurrentTime: 45000,
		} );
	} );

	it( 'refreshes the current sound on PLAY so a playlist reports the track actually playing', () => {
		loadTracker();

		// Playback advances to the next track in the playlist.
		widget.currentSound = {
			id: 67890,
			title: 'Second Track',
			duration: 120000,
			permalink_url: 'https://soundcloud.com/artist/second-track',
			user: { username: 'Another Artist' },
		};

		widget.emit( SC_EVENTS.PLAY, { currentPosition: 0 } );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'play',
			mediaData: {
				id: 67890,
				author: 'Another Artist',
				title: 'Second Track',
				url: 'https://soundcloud.com/artist/second-track',
				duration: 120000,
			},
			'gtm.videoTitle': 'Second Track',
			'gtm.videoDuration': 120,
		} );
	} );

	it( 'does not throw when the current sound has no user (author resolves to undefined)', () => {
		widget.currentSound = {
			id: 999,
			title: 'Userless Track',
			duration: 60000,
			permalink_url: 'https://soundcloud.com/unknown/track',
		};

		expect( () => loadTracker() ).not.toThrow();

		expect( window.dataLayer[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaData: {
				id: 999,
				title: 'Userless Track',
			},
		} );
		expect( window.dataLayer[ 0 ].mediaData.author ).toBeUndefined();
	} );

	it( 'does not throw or push anything when the SoundCloud Widget API failed to load', async () => {
		// No global.SC: the guard must bail out gracefully instead of throwing
		// on `SC.Widget()`.
		delete global.SC;

		expect( () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-soundcloud' );
			} );
		} ).not.toThrow();

		await flushPromises();
		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
