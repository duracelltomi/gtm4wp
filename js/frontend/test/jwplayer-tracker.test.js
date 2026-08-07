/**
 * Unit tests for the JW Player interaction tracker
 * (js/frontend/gtm4wp-jwplayer.js).
 *
 * The tracker finds JW Player containers (`.jwplayer`/`.jw-player`), fetches
 * each instance via the global `jwplayer(id)` and pushes gtm4wp.media* events
 * to the data layer. Metadata comes from getPlaylistItem(); position/duration
 * from getPosition()/getDuration(). These tests stub the JW Player global,
 * drive the captured handlers and assert the data layer pushes — including the
 * flat gtm.video* keys.
 */

class FakeJWPlayer {
	constructor() {
		this.handlers = {};
		this._position = 0;
		this._duration = 120;
		this._item = {
			mediaid: 'xyz789',
			file: 'https://cdn.example.com/video.mp4',
			title: 'My JW Video',
		};
	}
	on( event, cb ) {
		this.handlers[ event ] = cb;
	}
	emit( event, data ) {
		if ( this.handlers[ event ] ) {
			this.handlers[ event ]( data );
		}
	}
	getPosition() {
		return this._position;
	}
	getDuration() {
		return this._duration;
	}
	getPlaylistItem() {
		return this._item;
	}
}

describe( 'gtm4wp-jwplayer', () => {
	let player;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		player = new FakeJWPlayer();
		global.jwplayer = function () {
			return player;
		};
		document.body.innerHTML =
			'<div id="jwplayer-1" class="jwplayer"></div>';
	} );

	afterEach( () => {
		delete global.jwplayer;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-jwplayer' );
		} );
		return player;
	}

	it( 'pushes mediaPlayerReady immediately with the current playlist item metadata', () => {
		loadTracker();

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'jwplayer',
			mediaData: {
				id: 'xyz789',
				author: '',
				title: 'My JW Video',
				url: 'https://cdn.example.com/video.mp4',
				duration: 120,
			},
			mediaCurrentTime: 0,
			// "Ready" has no native GTM status, but the built-in Video
			// variables still resolve: gtm.videoStatus is present and empty
			// rather than absent, so it cannot inherit an earlier push's value.
			'gtm.videoProvider': 'jwplayer',
			'gtm.videoUrl': 'https://cdn.example.com/video.mp4',
			'gtm.videoTitle': 'My JW Video',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 0,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 0,
			// jsdom gives the container a 0×0 box, so it measures as off screen.
			'gtm.videoVisible': false,
		} );
	} );

	it( 'tracks play as a start state reading position from getPosition()', () => {
		loadTracker();
		player._position = 30;

		player.emit( 'play' );

		expect( lastPush() ).toMatchObject( {
			mediaType: 'jwplayer',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'jwplayer',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'reports the player container’s viewport position as gtm.videoVisible, per push', () => {
		const container = document.querySelector( '.jwplayer' );
		// jsdom gives every element a 0×0 box, so the player is measured through
		// a stubbed rect against the default 1024×768 viewport.
		let box = { top: 10, left: 10, bottom: 210, right: 330 };
		container.getBoundingClientRect = () => ( {
			...box,
			width: 320,
			height: 200,
		} );
		loadTracker();

		player.emit( 'play' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		player.emit( 'pause' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'maps complete to an "ended" state and seeked to a "seeked" state', () => {
		loadTracker();

		player.emit( 'complete' );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );

		player.emit( 'seeked' );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'seeked',
			'gtm.videoStatus': 'seek',
		} );
	} );

	it( 'maps buffer to a "buffering" state', () => {
		loadTracker();

		player.emit( 'buffer' );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'buffering',
			'gtm.videoStatus': 'buffering',
		} );
	} );

	it( 'emits mediaPlaybackPercentage milestones on the time event', () => {
		loadTracker();
		player._position = 60;
		player._duration = 240;

		player.emit( 'time', { position: 60, duration: 240 } );

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
		} );
	} );

	it( 'tracks playbackRateChanged as a player event carrying the new rate', () => {
		loadTracker();

		player.emit( 'playbackRateChanged', { playbackRate: 1.5 } );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'playbackRateChanged',
			mediaPlayerEventParam: 1.5,
			// A player event is still a gtm4wp.media* push, so it carries the
			// built-in Video variables — with an empty status, which also clears
			// the one a preceding state change left in the merged data layer.
			'gtm.videoProvider': 'jwplayer',
			'gtm.videoTitle': 'My JW Video',
			'gtm.videoStatus': '',
		} );
	} );

	it( 'pushes the playlist item title into the data layer object verbatim (no HTML entity-encoding)', () => {
		player._item = {
			mediaid: 'evil1',
			file: 'https://cdn.example.com/x.mp4',
			title: '</script>&<b>',
		};

		loadTracker();

		expect( window.dataLayer[ 0 ].mediaData.title ).toBe( '</script>&<b>' );
		expect( window.dataLayer[ 0 ].mediaData.title ).not.toContain( '&lt;' );
		expect( window.dataLayer[ 0 ].mediaData.title ).not.toContain(
			'&amp;'
		);
	} );

	it( 'does not throw or push anything when the JW Player global is missing', () => {
		delete global.jwplayer;

		expect( () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-jwplayer' );
			} );
		} ).not.toThrow();

		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
