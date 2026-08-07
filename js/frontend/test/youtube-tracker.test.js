/**
 * Unit tests for the YouTube interaction tracker (js/frontend/gtm4wp-youtube.js).
 *
 * The tracker registers a global onYouTubeIframeAPIReady callback that wraps
 * every YouTube embed iframe in a YT.Player and pushes gtm4wp.media* events to
 * the data layer as the player fires state/quality/rate/error events, plus
 * percentage milestones polled on an interval. These tests stub the YT IFrame
 * API, capture the events config the tracker registers, drive those handlers
 * with a mock player and assert the data layer pushes — including the flat
 * gtm.video* keys.
 */

describe( 'gtm4wp-youtube', () => {
	let capturedEvents;
	let player;
	let YT;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		capturedEvents = null;
		player = null;

		// The tracker guards on a still-undefined onYouTubeIframeAPIReady and
		// inserts its API <script> before the first existing one, so provide a
		// clean global and a script tag to anchor the insert.
		delete global.onYouTubeIframeAPIReady;
		delete window.onYouTubeIframeAPIReady;
		document.head.innerHTML = '<script id="anchor"></script>';
		document.body.innerHTML =
			'<iframe id="ytframe" src="https://www.youtube.com/embed/abc123"></iframe>';

		const Ctor = function ( id, opts ) {
			player = this;
			this.id = id;
			capturedEvents = opts && opts.events;
			// Video metadata + playback position the handlers read off the player.
			this._data = {
				video_id: 'abc123',
				author: 'The Channel',
				title: 'My Video',
			};
			this._url = 'https://www.youtube.com/watch?v=abc123';
			this._duration = 120;
			this._currentTime = 30;
		};
		Ctor.prototype.getVideoData = function () {
			return this._data;
		};
		Ctor.prototype.getVideoUrl = function () {
			return this._url;
		};
		Ctor.prototype.getDuration = function () {
			return this._duration;
		};
		Ctor.prototype.getCurrentTime = function () {
			return this._currentTime;
		};
		// Part of the YouTube IFrame API: the DOM node of the embed this player
		// drives. The tracker measures it for gtm.videoVisible, so the stub must
		// expose it too — a stub without it would make the suite pass by
		// silently dropping the key.
		Ctor.prototype.getIframe = function () {
			return document.getElementById( this.id );
		};

		YT = {
			Player: Ctor,
			PlayerState: {
				UNSTARTED: -1,
				ENDED: 0,
				PLAYING: 1,
				PAUSED: 2,
				BUFFERING: 3,
				CUED: 5,
			},
		};
		global.YT = YT;
	} );

	afterEach( () => {
		delete global.YT;
		delete global.onYouTubeIframeAPIReady;
		delete window.onYouTubeIframeAPIReady;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-youtube' );
		} );
	}

	// Builds a YT event object carrying the mock player as its target.
	const ytEvent = ( extra = {} ) => ( { target: player, ...extra } );

	it( 'registers a global onYouTubeIframeAPIReady callback on load', () => {
		loadTracker();
		expect( typeof window.onYouTubeIframeAPIReady ).toBe( 'function' );
	} );

	it( 'wires each embed into a YT.Player without throwing (regression: undeclared `player`)', () => {
		loadTracker();

		// Before the fix this threw `ReferenceError: player is not defined`
		// (the tracker is a strict-mode ES module and `player` was an undeclared
		// implicit global assignment), aborting the frame loop.
		expect( () => window.onYouTubeIframeAPIReady() ).not.toThrow();

		expect( player ).not.toBeNull();
		expect( player.id ).toBe( 'ytframe' );
		// The API-ready signal is pushed before the frames are wired.
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaApiReady',
			mediaType: 'youtube',
		} );
	} );

	it( 'rewrites the embed src with a well-formed enablejsapi query (regression: stray "?&")', () => {
		loadTracker();
		window.onYouTubeIframeAPIReady();

		const src = document.getElementById( 'ytframe' ).getAttribute( 'src' );
		// The src had no query string, so the separator must be '?', not '?&'
		// (the previous code appended '?' then '&enablejsapi=1').
		expect( src ).toContain( 'embed/abc123?enablejsapi=1' );
		expect( src ).not.toContain( '?&' );
	} );

	it( 'pushes mediaPlayerReady with the video metadata on the onReady event', () => {
		loadTracker();
		window.onYouTubeIframeAPIReady();

		capturedEvents.onReady( ytEvent() );

		// toEqual, not toMatchObject: "ready" has no native GTM status, so the
		// exact shape is the assertion — gtm.videoStatus must be present and
		// empty rather than absent (the data layer is merged state, so an absent
		// key would inherit whatever the previous push left there).
		expect( lastPush() ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'youtube',
			mediaData: {
				id: 'abc123',
				author: 'The Channel',
				title: 'My Video',
				url: 'https://www.youtube.com/watch?v=abc123',
				duration: 120,
			},
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'youtube',
			'gtm.videoUrl': 'https://www.youtube.com/watch?v=abc123',
			'gtm.videoTitle': 'My Video',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
			// jsdom gives the iframe a 0×0 box, so it measures as off screen.
			'gtm.videoVisible': false,
		} );
	} );

	it( 'maps the PLAYING state to a start status with native video params', () => {
		loadTracker();
		window.onYouTubeIframeAPIReady();

		capturedEvents.onStateChange(
			ytEvent( { data: YT.PlayerState.PLAYING } )
		);

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'youtube',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			mediaData: { title: 'My Video' },
			'gtm.videoProvider': 'youtube',
			'gtm.videoStatus': 'start',
			'gtm.videoTitle': 'My Video',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'maps PAUSED and ENDED states to their native statuses', () => {
		jest.useFakeTimers();
		try {
			loadTracker();
			window.onYouTubeIframeAPIReady();

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PAUSED } )
			);
			expect( lastPush() ).toMatchObject( {
				mediaPlayerState: 'pause',
				'gtm.videoStatus': 'pause',
			} );

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.ENDED } )
			);
			expect( lastPush() ).toMatchObject( {
				mediaPlayerState: 'ended',
				'gtm.videoStatus': 'complete',
			} );
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'reports the embed iframe’s viewport position as gtm.videoVisible, per push', () => {
		jest.useFakeTimers();
		try {
			const frame = document.getElementById( 'ytframe' );
			// jsdom gives every element a 0×0 box, so the player is measured
			// through a stubbed rect against the default 1024×768 viewport.
			let box = { top: 10, left: 10, bottom: 210, right: 330 };
			frame.getBoundingClientRect = () => ( {
				...box,
				width: 320,
				height: 200,
			} );

			loadTracker();
			window.onYouTubeIframeAPIReady();

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PLAYING } )
			);
			expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

			// Scrolled below the fold by the time the next event fires. PAUSED
			// also clears the percentage interval PLAYING started.
			box = { top: 900, left: 10, bottom: 1100, right: 330 };
			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PAUSED } )
			);
			expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'reports a percentage milestone fired in a background tab as not visible', () => {
		jest.useFakeTimers();
		try {
			// The reported case: the video is on screen geometrically, playback
			// continues while the visitor is in another tab (GTM Preview), and
			// the milestone interval keeps firing at a player nobody can see.
			const frame = document.getElementById( 'ytframe' );
			frame.getBoundingClientRect = () => ( {
				top: 10,
				left: 10,
				bottom: 210,
				right: 330,
				width: 320,
				height: 200,
			} );
			Object.defineProperty( document, 'visibilityState', {
				value: 'hidden',
				configurable: true,
			} );

			loadTracker();
			window.onYouTubeIframeAPIReady();

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PLAYING } )
			);
			jest.advanceTimersByTime( 1000 );

			const marks = window.dataLayer.filter(
				( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
			);
			expect( marks ).not.toHaveLength( 0 );
			marks.forEach( ( mark ) => {
				expect( mark[ 'gtm.videoVisible' ] ).toBe( false );
			} );

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PAUSED } )
			);
		} finally {
			delete document.visibilityState;
			jest.useRealTimers();
		}
	} );

	it( 'polls percentage milestones on an interval while playing', () => {
		jest.useFakeTimers();
		try {
			loadTracker();
			window.onYouTubeIframeAPIReady();

			// currentTime 30 / duration 120 = 25% -> crosses the 0/10/20 marks.
			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PLAYING } )
			);
			jest.advanceTimersByTime( 1000 );

			const marks = window.dataLayer.filter(
				( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
			);
			expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
				0, 10, 20,
			] );
			expect( lastPush() ).toMatchObject( {
				event: 'gtm4wp.mediaPlaybackPercentage',
				mediaType: 'youtube',
				mediaPercentage: 20,
				'gtm.videoStatus': 'progress',
				'gtm.videoPercent': 20,
			} );

			// Stop the interval so it cannot leak into later tests.
			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PAUSED } )
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'polls no percentage milestone when the video duration is 0 (regression: Infinity milestones)', () => {
		jest.useFakeTimers();
		try {
			loadTracker();
			window.onYouTubeIframeAPIReady();

			// A live stream / not-yet-loaded video reports duration 0 while the
			// current time advances. Without the guard, currentTime / 0 is
			// Infinity, which is > every mark and would push them all.
			player._duration = 0;
			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PLAYING } )
			);
			jest.advanceTimersByTime( 1000 );

			const marks = window.dataLayer.filter(
				( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
			);
			expect( marks ).toHaveLength( 0 );

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PAUSED } )
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'reports playback rate and error changes as mediaPlayerEvent pushes', () => {
		loadTracker();
		window.onYouTubeIframeAPIReady();

		capturedEvents.onPlaybackRateChange( ytEvent( { data: 1.5 } ) );
		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'youtube',
			mediaPlayerEvent: 'ratechange',
			mediaPlayerEventParam: 1.5,
		} );

		capturedEvents.onError( ytEvent( { data: 2 } ) );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerEvent: 'error',
			mediaPlayerEventParam: 2,
		} );
	} );

	it( 'carries the built-in gtm.video* variables on every media push that has a player', () => {
		loadTracker();
		window.onYouTubeIframeAPIReady();

		// The four gtm.video* keys GTM's built-in Video variables read that are
		// NOT derived from the player state, so they must be identical on every
		// push regardless of which event produced it.
		const identity = {
			'gtm.videoProvider': 'youtube',
			'gtm.videoUrl': 'https://www.youtube.com/watch?v=abc123',
			'gtm.videoTitle': 'My Video',
			'gtm.videoDuration': 120,
		};

		capturedEvents.onReady( ytEvent() );
		expect( lastPush() ).toMatchObject( {
			...identity,
			'gtm.videoStatus': '',
		} );

		capturedEvents.onPlaybackQualityChange( ytEvent( { data: 'hd720' } ) );
		expect( lastPush() ).toMatchObject( {
			...identity,
			'gtm.videoStatus': '',
			mediaPlayerEvent: 'quality-change',
		} );

		capturedEvents.onApiChange( ytEvent() );
		expect( lastPush() ).toMatchObject( {
			...identity,
			'gtm.videoStatus': '',
			mediaPlayerEvent: 'api-change',
		} );

		// mediaApiReady is the one exception: it fires when the IFrame API
		// script loads, before any player exists, so it has nothing to report.
		const apiReady = window.dataLayer.find(
			( entry ) => entry.event === 'gtm4wp.mediaApiReady'
		);
		expect( apiReady ).toBeDefined();
		expect( Object.keys( apiReady ) ).toEqual( [ 'event', 'mediaType' ] );
	} );

	it( 'clears a stale gtm.videoStatus when a player event follows a state change', () => {
		jest.useFakeTimers();
		try {
			loadTracker();
			window.onYouTubeIframeAPIReady();

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PLAYING } )
			);
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'start' );

			// The data layer merges pushes, so omitting gtm.videoStatus here
			// would leave Video Status resolving to 'start' during an event that
			// is not a playback start at all.
			capturedEvents.onError( ytEvent( { data: 2 } ) );
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( '' );

			capturedEvents.onStateChange(
				ytEvent( { data: YT.PlayerState.PAUSED } )
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'pushes only the API-ready signal when the page has no YouTube embeds', () => {
		document.body.innerHTML = '<p>No videos here.</p>';

		loadTracker();
		window.onYouTubeIframeAPIReady();

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ].event ).toBe( 'gtm4wp.mediaApiReady' );
		expect( player ).toBeNull();
	} );

	it( 'throws on load when another script already owns the YouTube API', () => {
		window.onYouTubeIframeAPIReady = function () {};

		expect( () => loadTracker() ).toThrow(
			/already utilizing YouTube API/
		);
	} );

	describe( 'SDK loading', () => {
		// Pins the URL registered as an upstream coupling (U65). Protocol-
		// relative, which is why the lib compares the literal src attribute
		// rather than the resolved .src property when looking for a tag already
		// requesting it.
		const SDK = '//www.youtube.com/iframe_api';

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

		it( 'requests nothing from YouTube on a page with no YouTube embed', () => {
			document.body.innerHTML = '';
			delete global.YT;

			jest.isolateModules( () => {
				require( '../gtm4wp-youtube' );
			} );

			expect( sdkTags() ).toHaveLength( 0 );
		} );

		it( 'fetches the IFrame API when an embed is present and the SDK is not', () => {
			delete global.YT;

			jest.isolateModules( () => {
				require( '../gtm4wp-youtube' );
			} );

			expect( sdkTags() ).toHaveLength( 1 );
		} );
	} );
} );
