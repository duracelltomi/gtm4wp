/**
 * Unit tests for the Twitch interaction tracker (js/frontend/gtm4wp-twitch.js).
 *
 * A plain Twitch player iframe cannot be wrapped after the fact, so the tracker
 * replaces each `iframe[src*="player.twitch.tv"]` with a Twitch.Player pointing
 * at the same channel/video and subscribes to its events, pushing gtm4wp.media*
 * events to the data layer. VOD progress is polled on an interval (Twitch has no
 * time event). These tests stub the Twitch Embed API, drive the captured
 * handlers and assert the data layer pushes — including the flat gtm.video*
 * keys.
 */

describe( 'gtm4wp-twitch', () => {
	let player;
	let Ctor;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		player = null;

		Ctor = function ( elementId, options ) {
			player = this;
			this.elementId = elementId;
			this.options = options;
			this.handlers = {};
			this._currentTime = 0;
			this._duration = 0;
		};
		Ctor.prototype.addEventListener = function ( event, cb ) {
			this.handlers[ event ] = cb;
		};
		Ctor.prototype.emit = function ( event, data ) {
			if ( this.handlers[ event ] ) {
				this.handlers[ event ]( data );
			}
		};
		Ctor.prototype.getCurrentTime = function () {
			return this._currentTime;
		};
		Ctor.prototype.getDuration = function () {
			return this._duration;
		};
		Ctor.READY = 'ready';
		Ctor.PLAY = 'play';
		Ctor.PAUSE = 'pause';
		Ctor.ENDED = 'ended';
		Ctor.SEEK = 'seek';
		Ctor.ONLINE = 'online';
		Ctor.OFFLINE = 'offline';
		global.Twitch = { Player: Ctor };

		document.body.innerHTML =
			'<iframe src="https://player.twitch.tv/?video=123456789&parent=example.com" width="640" height="360"></iframe>';
	} );

	afterEach( () => {
		delete global.Twitch;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-twitch' );
		} );
		return player;
	}

	it( 'replaces the Twitch iframe with a Twitch.Player built from the src params', () => {
		loadTracker();

		expect( player ).not.toBeNull();
		expect( player.options.video ).toBe( '123456789' );
		expect( player.options.width ).toBe( '640' );
		// The original iframe was replaced by the tracked player's container.
		expect( document.querySelector( 'iframe' ) ).toBeNull();
	} );

	it( 'pushes mediaPlayerReady on the READY event with the parsed video id', () => {
		loadTracker();
		player._duration = 3600;

		player.emit( Ctor.READY );

		expect( lastPush() ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'twitch',
			mediaData: {
				id: '123456789',
				author: '',
				title: '123456789',
				url: 'https://www.twitch.tv/videos/123456789',
				duration: 3600,
			},
			mediaCurrentTime: 0,
		} );
	} );

	it( 'tracks PLAY as a start state and PAUSE/ENDED/SEEK with their native statuses', () => {
		loadTracker();
		player._duration = 3600;
		player._currentTime = 900;

		player.emit( Ctor.PLAY );
		expect( lastPush() ).toMatchObject( {
			mediaType: 'twitch',
			mediaPlayerState: 'play',
			mediaCurrentTime: 900,
			'gtm.videoProvider': 'twitch',
			'gtm.videoStatus': 'start',
			'gtm.videoDuration': 3600,
			'gtm.videoPercent': 25,
		} );

		player.emit( Ctor.PAUSE );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'pause',
			'gtm.videoStatus': 'pause',
		} );

		player.emit( Ctor.ENDED );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );

		player.emit( Ctor.SEEK );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'seeked',
			'gtm.videoStatus': 'seek',
		} );
	} );

	it( 'tracks ONLINE/OFFLINE as player events', () => {
		loadTracker();

		player.emit( Ctor.ONLINE );
		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'online',
		} );

		player.emit( Ctor.OFFLINE );
		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'offline',
		} );
	} );

	it( 'polls VOD progress on an interval and emits percentage milestones', () => {
		jest.useFakeTimers();
		try {
			loadTracker();
			player._duration = 3600;
			player._currentTime = 900; // 25%

			player.emit( Ctor.PLAY );
			jest.advanceTimersByTime( 1000 );

			const marks = window.dataLayer.filter(
				( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
			);
			expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
				0, 10, 20,
			] );

			player.emit( Ctor.PAUSE ); // clears the interval
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'does not throw or push anything when the Twitch Embed API is missing', () => {
		delete global.Twitch;

		expect( () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-twitch' );
			} );
		} ).not.toThrow();

		// The iframe is left untouched and nothing is pushed.
		expect( document.querySelector( 'iframe' ) ).not.toBeNull();
		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
