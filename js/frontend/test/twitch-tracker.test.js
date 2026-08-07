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
		// The container-id counter lives on window (so ids survive bundle
		// re-execution); reset it so it does not leak into other tests.
		delete window.gtm4wp_twitch_frame_index;
		delete window.gtm4wp_media_scanners;
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
			// "Ready" has no native GTM status, but the built-in Video
			// variables still resolve: gtm.videoStatus is present and empty
			// rather than absent, so it cannot inherit an earlier push's value.
			'gtm.videoProvider': 'twitch',
			'gtm.videoUrl': 'https://www.twitch.tv/videos/123456789',
			'gtm.videoTitle': '123456789',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 0,
			'gtm.videoDuration': 3600,
			'gtm.videoPercent': 0,
			// jsdom gives the container a 0×0 box, so it measures as off screen.
			'gtm.videoVisible': false,
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

	it( 'reports the SDK container’s viewport position as gtm.videoVisible, per push', () => {
		loadTracker();
		// The tracker replaced the original iframe with a container of its own,
		// which the Embed SDK fills - that container is the visible player.
		const container = document.querySelector( '[data-gtm4wp-media-wired]' );
		// jsdom gives every element a 0×0 box, so the player is measured through
		// a stubbed rect against the default 1024×768 viewport.
		let box = { top: 10, left: 10, bottom: 210, right: 330 };
		container.getBoundingClientRect = () => ( {
			...box,
			width: 320,
			height: 200,
		} );
		player._duration = 3600;

		player.emit( Ctor.PLAY );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		// PAUSE also stops the percentage-polling interval PLAY started.
		player.emit( Ctor.PAUSE );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'tracks ONLINE/OFFLINE as player events', () => {
		loadTracker();

		player.emit( Ctor.ONLINE );
		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'online',
			// A player event is still a gtm4wp.media* push, so it carries the
			// built-in Video variables — with an empty status, which also clears
			// the one a preceding state change left in the merged data layer.
			'gtm.videoProvider': 'twitch',
			'gtm.videoTitle': '123456789',
			'gtm.videoStatus': '',
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

	it( 'gives each replaced player a unique container id across bundle re-execution', () => {
		// A tag-manager re-injection re-runs the bundle from fresh module scope. A
		// module-scoped counter would restart at 0 and collide with the still-present
		// first container (both `gtm4wp-twitch-0`), so `new Twitch.Player('gtm4wp-twitch-0')`
		// would bind to the stale container. The window-scoped counter keeps ids unique.
		delete window.gtm4wp_twitch_frame_index;

		loadTracker();
		const firstId = player.elementId;

		// Re-injection: a fresh iframe plus a fresh bundle execution.
		document.body.insertAdjacentHTML(
			'beforeend',
			'<iframe src="https://player.twitch.tv/?video=987654321&parent=example.com"></iframe>'
		);
		loadTracker();
		const secondId = player.elementId;

		expect( firstId ).toBe( 'gtm4wp-twitch-0' );
		expect( secondId ).toBe( 'gtm4wp-twitch-1' );
		expect( secondId ).not.toBe( firstId );
	} );

	describe( 'SDK loading', () => {
		// Pins the URL registered as an upstream coupling (U65).
		const SDK = 'https://embed.twitch.tv/embed/v1.js';

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

		it( 'requests nothing from Twitch on a page with no Twitch embed', () => {
			document.body.innerHTML = '';
			delete global.Twitch;

			jest.isolateModules( () => {
				require( '../gtm4wp-twitch' );
			} );

			expect( sdkTags() ).toHaveLength( 0 );
		} );

		it( 'fetches the Embed API when an embed is present and the SDK is not', () => {
			delete global.Twitch;

			jest.isolateModules( () => {
				require( '../gtm4wp-twitch' );
			} );

			expect( sdkTags() ).toHaveLength( 1 );
		} );
	} );
} );
