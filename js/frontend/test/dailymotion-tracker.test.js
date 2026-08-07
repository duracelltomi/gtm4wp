/**
 * Unit tests for the Dailymotion interaction tracker
 * (js/frontend/gtm4wp-dailymotion.js).
 *
 * Dailymotion sunset the attach-to-an-existing-iframe SDK, and its replacement
 * (Player Embeds) can only observe a player it created itself. So the tracker
 * replaces each embed iframe with a container div, calls
 * dailymotion.createPlayer() into it, and subscribes with
 * player.on( dailymotion.events.X ) — where every event delivers the FULL player
 * state rather than a per-event payload.
 *
 * Three things about the fake below are load-bearing, not incidental:
 *
 *   1. `dailymotion.events` lists only names Dailymotion actually publishes, and
 *      `player.on()` THROWS on anything else. A permissive fake would let
 *      `dailymotion.events.TYPO` resolve to undefined, register a handler under
 *      the key "undefined", and leave this suite green (UC-3).
 *   2. createPlayer() returns a Promise and injects its own iframe INTO the
 *      container — which matches the tracker's own selector. Modelling that is
 *      what exercises the marked-ancestor skip; a fake that leaves the container
 *      empty cannot see the unbounded re-wire loop it caused.
 *   3. The library URL comes from window.gtm4wp_dailymotion_config, which PHP
 *      publishes, so the tests set it rather than assuming a literal.
 */

/**
 * The Player Embeds event vocabulary, in full — including the four events the
 * tracker deliberately does NOT subscribe to, so the pinning test below proves
 * the omission rather than assuming it.
 */
const DM_EVENTS = {
	PLAYER_CRITICALPATHREADY: 'playercriticalpathready',
	PLAYER_ERROR: 'playererror',
	PLAYER_PRESENTATIONMODECHANGE: 'playerpresentationmodechange',
	PLAYER_VIEWABILITYCHANGE: 'playerviewabilitychange',
	PLAYER_VOLUMECHANGE: 'playervolumechange',
	VIDEO_BUFFERING: 'videobuffering',
	VIDEO_DURATIONCHANGE: 'videodurationchange',
	VIDEO_END: 'videoend',
	VIDEO_PAUSE: 'videopause',
	VIDEO_PLAY: 'videoplay',
	VIDEO_QUALITYCHANGE: 'videoqualitychange',
	VIDEO_SEEKEND: 'videoseekend',
	VIDEO_SEEKSTART: 'videoseekstart',
	VIDEO_START: 'videostart',
	VIDEO_TIMECHANGE: 'videotimechange',
};
const DM_EVENT_VALUES = Object.values( DM_EVENTS );

/**
 * The exact set the tracker maps to a data layer push. Pinned by a test below.
 */
const SUBSCRIBED = [
	DM_EVENTS.PLAYER_CRITICALPATHREADY,
	DM_EVENTS.PLAYER_ERROR,
	DM_EVENTS.PLAYER_PRESENTATIONMODECHANGE,
	DM_EVENTS.PLAYER_VOLUMECHANGE,
	DM_EVENTS.VIDEO_BUFFERING,
	DM_EVENTS.VIDEO_END,
	DM_EVENTS.VIDEO_PAUSE,
	DM_EVENTS.VIDEO_PLAY,
	DM_EVENTS.VIDEO_QUALITYCHANGE,
	DM_EVENTS.VIDEO_SEEKEND,
	DM_EVENTS.VIDEO_TIMECHANGE,
];

class FakeDailymotionPlayer {
	constructor() {
		this.handlers = {};
	}
	on( event, cb ) {
		// The real SDK is handed a string. `dailymotion.events.TYPO` is
		// undefined, which must not quietly register a handler under a key
		// nothing will ever emit.
		if (
			'string' !== typeof event ||
			! DM_EVENT_VALUES.includes( event )
		) {
			throw new Error( 'Unknown Dailymotion event: ' + event );
		}
		this.handlers[ event ] = cb;
	}
	off( event ) {
		delete this.handlers[ event ];
	}
	getState() {
		return Promise.resolve( this.state || {} );
	}
	/**
	 * Throws when the tracker never subscribed, so a missing mapping fails.
	 *
	 * @param {string} event The Player Embeds event name.
	 * @param {Object} state The full player state the real SDK delivers.
	 */
	emit( event, state ) {
		this.handlers[ event ]( state );
	}
}

describe( 'gtm4wp-dailymotion', () => {
	let player;
	let createCalls;
	let rejectWith;

	const SDK = 'https://geo.dailymotion.com/libs/player.js';

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	// Cap on the emulated SDK so an unbounded re-wire regression fails an
	// assertion instead of hanging the run (the loop is a microtask loop, which
	// would starve the macrotask flush() below).
	const CREATE_CAP = 10;

	beforeEach( () => {
		window.dataLayer = [];
		player = null;
		createCalls = [];
		rejectWith = null;

		window.gtm4wp_dailymotion_config = { sdk: SDK };

		global.dailymotion = {
			events: DM_EVENTS,
			createPlayer( containerId, options ) {
				createCalls.push( { containerId, options } );

				if ( createCalls.length > CREATE_CAP ) {
					return Promise.reject( new Error( 'create cap' ) );
				}
				if ( rejectWith ) {
					return Promise.reject( rejectWith );
				}

				// The real SDK injects its own iframe INTO the container, and
				// that iframe matches iframe[src*="dailymotion.com"] too.
				const container = document.getElementById( containerId );
				if ( container ) {
					const injected = document.createElement( 'iframe' );
					injected.setAttribute(
						'src',
						'https://geo.dailymotion.com/player.html?video=' +
							options.video
					);
					container.appendChild( injected );
				}

				player = new FakeDailymotionPlayer();
				return Promise.resolve( player );
			},
		};

		document.body.innerHTML =
			'<iframe src="https://geo.dailymotion.com/player.html?video=x2jvvep&" width="640" height="360"></iframe>';
	} );

	afterEach( () => {
		delete global.dailymotion;
		delete window.gtm4wp_dailymotion_config;
		delete window.gtm4wp_dailymotion_frame_index;
		// The shared media observer lives on the (jsdom) window, which persists
		// across tests; disconnect and reset it so nothing leaks into the next.
		if ( window.gtm4wp_media_observer ) {
			window.gtm4wp_media_observer.disconnect();
		}
		delete window.gtm4wp_media_observer;
		delete window.gtm4wp_media_scanners;
		delete window.gtm4wp_media_observe_dynamic;
		sdkTags().forEach( ( tag ) => tag.remove() );
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];
	const pushesOf = ( name ) =>
		window.dataLayer.filter( ( entry ) => entry.event === name );
	// MutationObserver records and createPlayer's promise both resolve as
	// microtasks, so awaiting a macrotask guarantees both have already run.
	const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	const sdkTags = () =>
		Array.from( document.getElementsByTagName( 'script' ) ).filter(
			( tag ) =>
				( tag.getAttribute( 'src' ) || '' ).includes(
					'geo.dailymotion.com/libs/'
				)
		);

	const container = () =>
		document.querySelector( 'div[data-gtm4wp-media-wired]' );

	async function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-dailymotion' );
		} );
		await flush();
		return player;
	}

	/**
	 * The state shape the real SDK delivers with every event.
	 *
	 * @param {Object} [overrides] Fields to change for this event.
	 * @return {Object} The player state.
	 */
	const state = ( overrides = {} ) => ( {
		videoId: 'x2jvvep',
		videoTitle: 'A Dailymotion video',
		videoOwnerScreenname: 'Player team',
		videoDuration: 120,
		videoTime: 30,
		videoQuality: '720',
		playerVolume: 0.5,
		playerPresentationMode: 'fullscreen',
		playerError: null,
		...overrides,
	} );

	describe( 'building the player', () => {
		it( 'replaces the oEmbed iframe with a marked container and creates a player in it', async () => {
			await loadTracker();

			expect( createCalls ).toHaveLength( 1 );
			expect( createCalls[ 0 ].containerId ).toBe(
				'gtm4wp-dailymotion-0'
			);
			expect( createCalls[ 0 ].options.video ).toBe( 'x2jvvep' );

			const div = container();
			expect( div ).not.toBeNull();
			expect( div.id ).toBe( 'gtm4wp-dailymotion-0' );
			// The original embed is gone: only the SDK's own iframe is left, and
			// it lives inside the marked container.
			expect( document.querySelectorAll( 'iframe' ) ).toHaveLength( 1 );
			expect( div.querySelector( 'iframe' ) ).not.toBeNull();
		} );

		it( 'carries over the player named by a /player/<id>.html embed', async () => {
			document.body.innerHTML =
				'<iframe src="https://geo.dailymotion.com/player/xabcd.html?video=x2jvvep"></iframe>';

			await loadTracker();

			expect( createCalls[ 0 ].options ).toEqual( {
				video: 'x2jvvep',
				player: 'xabcd',
			} );
		} );

		it( 'omits the player option entirely when the embed names none', async () => {
			await loadTracker();

			// Absence, not `undefined`: an explicit undefined would be a value
			// the SDK has to interpret.
			expect( 'player' in createCalls[ 0 ].options ).toBe( false );
		} );

		it( 'parses the id from the legacy /embed/video/<id> path', async () => {
			document.body.innerHTML =
				'<iframe src="https://www.dailymotion.com/embed/video/x2jvvep?autoplay=0"></iframe>';

			await loadTracker();

			expect( createCalls[ 0 ].options.video ).toBe( 'x2jvvep' );
		} );

		it( 'parses the id from a dai.ly short link', async () => {
			document.body.innerHTML =
				'<iframe src="https://dai.ly/x2jvvep"></iframe>';

			await loadTracker();

			expect( createCalls[ 0 ].options.video ).toBe( 'x2jvvep' );
		} );

		it( 'leaves an embed with no video id alone instead of guessing one', async () => {
			// A playlist embed carries no `video` parameter. The old "last path
			// segment" fallback would have created a player for a video called
			// "player.html", replacing a working embed with a broken one.
			document.body.innerHTML =
				'<iframe src="https://geo.dailymotion.com/player.html?playlist=x85ce2"></iframe>';

			await loadTracker();

			expect( createCalls ).toHaveLength( 0 );
			expect( container() ).toBeNull();
			expect(
				document.querySelector( 'iframe' ).getAttribute( 'src' )
			).toBe( 'https://geo.dailymotion.com/player.html?playlist=x85ce2' );
			expect( window.dataLayer ).toHaveLength( 0 );
		} );

		it( 'sizes the container from the iframe dimension attributes', async () => {
			// createPlayer takes no width/height - the player fills its
			// container - so a container with no size collapses the video away.
			await loadTracker();

			expect( container().style.width ).toBe( '640px' );
			expect( container().style.height ).toBe( '360px' );
		} );

		it( 'falls back to the measured box when the embed carries no dimensions', async () => {
			document.body.innerHTML =
				'<iframe src="https://geo.dailymotion.com/player.html?video=x2jvvep"></iframe>';
			document.querySelector( 'iframe' ).getBoundingClientRect = () => ( {
				top: 0,
				left: 0,
				bottom: 200,
				right: 320,
				width: 320,
				height: 200,
			} );

			await loadTracker();

			expect( container().style.width ).toBe( '320px' );
			expect( container().style.height ).toBe( '200px' );
		} );

		it( 'gives each embed its own container id across a bundle re-execution', async () => {
			await loadTracker();
			// A tag-manager re-injection restarts module scope; the counter is on
			// window so the second container cannot collide with the first.
			document.body.innerHTML =
				'<iframe src="https://geo.dailymotion.com/player.html?video=xsecond"></iframe>';
			await loadTracker();

			expect( createCalls.map( ( call ) => call.containerId ) ).toEqual( [
				'gtm4wp-dailymotion-0',
				'gtm4wp-dailymotion-1',
			] );
		} );
	} );

	describe( 'event subscriptions', () => {
		it( 'subscribes to exactly the Player Embeds events it maps, by their real names', async () => {
			await loadTracker();

			// A mirrored vocabulary needs something that BREAKS when it drifts:
			// this fails on a rename, an addition, a removal and a typo alike.
			// Because VIDEO_START and VIDEO_SEEKSTART are in the fake's event map
			// it also pins the two deliberate omissions - VIDEO_START would
			// double the first play (VIDEO_PLAY already reports it, resumes
			// included) and VIDEO_SEEKSTART would double every scrub with the
			// pre-seek position.
			expect( Object.keys( player.handlers ).sort() ).toEqual(
				[ ...SUBSCRIBED ].sort()
			);
		} );
	} );

	describe( 'data layer pushes', () => {
		it( 'pushes mediaPlayerReady on the critical-path-ready event', async () => {
			await loadTracker();

			player.emit( DM_EVENTS.PLAYER_CRITICALPATHREADY, state() );

			expect( window.dataLayer ).toHaveLength( 1 );
			expect( window.dataLayer[ 0 ] ).toEqual( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'dailymotion',
				mediaData: {
					id: 'x2jvvep',
					author: 'Player team',
					title: 'A Dailymotion video',
					url: 'https://www.dailymotion.com/video/x2jvvep',
					duration: 120,
				},
				mediaCurrentTime: 30,
				'gtm.videoProvider': 'dailymotion',
				'gtm.videoUrl': 'https://www.dailymotion.com/video/x2jvvep',
				'gtm.videoTitle': 'A Dailymotion video',
				'gtm.videoStatus': '',
				'gtm.videoCurrentTime': 30,
				'gtm.videoDuration': 120,
				'gtm.videoPercent': 25,
				'gtm.videoVisible': false,
			} );
		} );

		it( 'falls back to the video id for the title until the metadata arrives', async () => {
			await loadTracker();

			// The player has fetched no metadata yet, so the state carries none.
			player.emit( DM_EVENTS.PLAYER_CRITICALPATHREADY, {
				videoDuration: 0,
				videoTime: 0,
			} );

			expect( lastPush().mediaData ).toEqual( {
				id: 'x2jvvep',
				author: '',
				title: 'x2jvvep',
				url: 'https://www.dailymotion.com/video/x2jvvep',
				duration: 0,
			} );
			expect( lastPush()[ 'gtm.videoTitle' ] ).toBe( 'x2jvvep' );
		} );

		it( 'maps every playback event to its native video status', async () => {
			await loadTracker();

			player.emit( DM_EVENTS.VIDEO_PLAY, state() );
			expect( lastPush().mediaPlayerState ).toBe( 'play' );
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'start' );

			player.emit( DM_EVENTS.VIDEO_PAUSE, state() );
			expect( lastPush().mediaPlayerState ).toBe( 'pause' );
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'pause' );

			player.emit( DM_EVENTS.VIDEO_END, state( { videoTime: 120 } ) );
			expect( lastPush().mediaPlayerState ).toBe( 'ended' );
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'complete' );

			player.emit( DM_EVENTS.VIDEO_SEEKEND, state( { videoTime: 60 } ) );
			expect( lastPush().mediaPlayerState ).toBe( 'seeked' );
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'seek' );
			// The seek reports the destination, which is only true because the
			// state cache is refreshed before the handler reads it.
			expect( lastPush()[ 'gtm.videoCurrentTime' ] ).toBe( 60 );

			player.emit( DM_EVENTS.VIDEO_BUFFERING, state() );
			expect( lastPush().mediaPlayerState ).toBe( 'buffering' );
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'buffering' );
		} );

		it( 'emits playback milestones from the time-change event', async () => {
			await loadTracker();

			player.emit(
				DM_EVENTS.VIDEO_TIMECHANGE,
				state( { videoTime: 60, videoDuration: 240 } )
			);

			expect(
				pushesOf( 'gtm4wp.mediaPlaybackPercentage' ).map(
					( entry ) => entry.mediaPercentage
				)
			).toEqual( [ 0, 10, 20 ] );
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'progress' );
		} );

		it( 'emits no milestone while the duration is still unknown', async () => {
			await loadTracker();

			// A live stream, or a video whose metadata has not loaded: dividing
			// by zero would fire every milestone at once.
			player.emit(
				DM_EVENTS.VIDEO_TIMECHANGE,
				state( { videoTime: 5, videoDuration: 0 } )
			);

			expect( pushesOf( 'gtm4wp.mediaPlaybackPercentage' ) ).toHaveLength(
				0
			);
		} );

		it( 'carries the right state field on each player event', async () => {
			await loadTracker();

			player.emit( DM_EVENTS.PLAYER_VOLUMECHANGE, state() );
			expect( lastPush().mediaPlayerEvent ).toBe( 'volumechange' );
			expect( lastPush().mediaPlayerEventParam ).toBe( 0.5 );

			player.emit( DM_EVENTS.VIDEO_QUALITYCHANGE, state() );
			expect( lastPush().mediaPlayerEvent ).toBe( 'qualitychange' );
			expect( lastPush().mediaPlayerEventParam ).toBe( '720' );

			// Player Embeds folds fullscreen and PiP into one presentation mode;
			// the event NAME stays the one sites already trigger on.
			player.emit( DM_EVENTS.PLAYER_PRESENTATIONMODECHANGE, state() );
			expect( lastPush().mediaPlayerEvent ).toBe( 'fullscreenchange' );
			expect( lastPush().mediaPlayerEventParam ).toBe( 'fullscreen' );

			player.emit(
				DM_EVENTS.PLAYER_ERROR,
				state( { playerError: { code: 'DM001' } } )
			);
			expect( lastPush().mediaPlayerEvent ).toBe( 'error' );
			expect( lastPush().mediaPlayerEventParam ).toEqual( {
				code: 'DM001',
			} );
			// None of these are playback states GTM models.
			expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( '' );
		} );

		it( 'reports the container’s viewport position as gtm.videoVisible, per push', async () => {
			await loadTracker();

			// jsdom gives every element a 0x0 box, so the player is measured
			// through a stubbed rect against the default 1024x768 viewport.
			let box = { top: 10, left: 10, bottom: 210, right: 330 };
			container().getBoundingClientRect = () => ( {
				...box,
				width: 320,
				height: 200,
			} );

			player.emit( DM_EVENTS.VIDEO_PLAY, state() );
			expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

			// Scrolled out of view between the two events.
			box = { top: 900, left: 10, bottom: 1100, right: 330 };
			player.emit( DM_EVENTS.VIDEO_PAUSE, state() );
			expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
		} );

		it( 'pushes a hostile video id verbatim, without entity encoding', async () => {
			document.body.innerHTML =
				'<iframe src="https://geo.dailymotion.com/player.html?video=%3Cscript%3E"></iframe>';

			await loadTracker();
			player.emit( DM_EVENTS.PLAYER_CRITICALPATHREADY, {
				videoDuration: 0,
				videoTime: 0,
			} );

			// The data layer holds raw values; escaping is the consumer's job, and
			// a pre-escaped one here would be double-encoded downstream.
			expect( lastPush().mediaData.id ).toBe( '<script>' );
			expect( lastPush().mediaData.title ).toBe( '<script>' );
			expect( JSON.stringify( lastPush() ) ).not.toContain( '&lt;' );
		} );
	} );

	describe( 'failure handling', () => {
		it( 'restores the original embed when the player cannot be created', async () => {
			rejectWith = new Error( 'player unavailable' );

			await loadTracker();

			// Without the restore, a failure to TRACK the video would become a
			// failure to SHOW it: the visitor gets an empty box.
			expect( container() ).toBeNull();
			const restored = document.querySelector( 'iframe' );
			expect( restored.getAttribute( 'src' ) ).toBe(
				'https://geo.dailymotion.com/player.html?video=x2jvvep&'
			);
			// Re-marked, so the observer cannot wire/replace/fail/restore forever.
			expect( restored.getAttribute( 'data-gtm4wp-media-wired' ) ).toBe(
				'1'
			);

			expect( window.dataLayer ).toHaveLength( 1 );
			expect( lastPush().event ).toBe( 'gtm4wp.mediaPlayerEvent' );
			expect( lastPush().mediaPlayerEvent ).toBe( 'error' );
			expect( lastPush().mediaPlayerEventParam ).toBe( rejectWith );
			expect( lastPush().mediaData.id ).toBe( 'x2jvvep' );
		} );

		it( 'keeps a created player when only the event wiring fails', async () => {
			// A rejection handler cannot see which half of the .then threw, so
			// the two failures have to be told apart deliberately. Tearing the
			// container out here would destroy a video the visitor is already
			// watching, and restart it, to report a problem that is ours.
			const bindError = new Error( 'on() is not a function' );
			const create = global.dailymotion.createPlayer;
			global.dailymotion.createPlayer = function ( id, options ) {
				return create( id, options ).then( ( created ) => {
					created.on = () => {
						throw bindError;
					};
					return created;
				} );
			};

			await loadTracker();

			expect( container() ).not.toBeNull();
			expect( container().querySelector( 'iframe' ) ).not.toBeNull();
			// The original embed is NOT put back: that would reload the video.
			expect( document.querySelectorAll( 'iframe' ) ).toHaveLength( 1 );

			expect( lastPush().mediaPlayerEvent ).toBe( 'error' );
			expect( lastPush().mediaPlayerEventParam ).toBe( bindError );
		} );

		it( 'wires nothing when the player library failed to load', async () => {
			delete global.dailymotion;

			await loadTracker();

			// The embed is left exactly as WordPress emitted it, so the video
			// still plays; it simply goes untracked.
			expect(
				document.querySelector( 'iframe' ).getAttribute( 'src' )
			).toBe( 'https://geo.dailymotion.com/player.html?video=x2jvvep&' );
			expect( window.dataLayer ).toHaveLength( 0 );
		} );

		it( 'wires nothing when the global exists but the API does not', async () => {
			// Dailymotion's documented bootstrap has the page define this stub
			// BEFORE the library loads, so a bare `typeof dailymotion` check
			// would tear the embed out and then fail to build anything.
			global.dailymotion = { onScriptLoaded() {} };

			await loadTracker();

			expect( container() ).toBeNull();
			expect(
				document.querySelector( 'iframe' ).getAttribute( 'src' )
			).toBe( 'https://geo.dailymotion.com/player.html?video=x2jvvep&' );
			expect( window.dataLayer ).toHaveLength( 0 );
		} );
	} );

	describe( 'runtime insertion', () => {
		it( 'creates exactly one player for an embed inserted after load', async () => {
			document.body.innerHTML = '';
			window.gtm4wp_media_observe_dynamic = true;

			await loadTracker();

			const inserted = document.createElement( 'iframe' );
			inserted.setAttribute(
				'src',
				'https://geo.dailymotion.com/player.html?video=x2jvvep'
			);
			document.body.appendChild( inserted );
			await flush();
			await flush();

			// The iframe the SDK injects into the container matches the tracker's
			// own selector; the pre-marked container is what stops it being
			// replaced again, forever.
			expect( createCalls ).toHaveLength( 1 );
		} );
	} );

	describe( 'SDK loading', () => {
		it( 'requests nothing from Dailymotion on a page with no Dailymotion embed', async () => {
			document.body.innerHTML = '';
			delete global.dailymotion;

			await loadTracker();

			expect( sdkTags() ).toHaveLength( 0 );
		} );

		it( 'fetches the library URL PHP published when an embed is present', async () => {
			delete global.dailymotion;

			await loadTracker();

			expect( sdkTags() ).toHaveLength( 1 );
			expect( sdkTags()[ 0 ].getAttribute( 'src' ) ).toBe( SDK );
		} );

		it( 'fetches the per-player library when the site configured a player id', async () => {
			// PHP builds this URL (it url-encodes the stored id); the tracker
			// never assembles one, which is why the whole URL is the contract.
			window.gtm4wp_dailymotion_config = {
				sdk: 'https://geo.dailymotion.com/libs/player/xabcd.js',
			};
			delete global.dailymotion;

			await loadTracker();

			expect( sdkTags() ).toHaveLength( 1 );
			expect( sdkTags()[ 0 ].getAttribute( 'src' ) ).toBe(
				'https://geo.dailymotion.com/libs/player/xabcd.js'
			);
		} );

		it( 'requests nothing when PHP published no library URL', async () => {
			// The URL has exactly one definition, in PHP. With none published
			// there is nothing to fetch and the embed is left alone, rather than
			// the tracker inventing a URL that would then differ from PHP's.
			delete window.gtm4wp_dailymotion_config;
			delete global.dailymotion;

			await loadTracker();

			expect( sdkTags() ).toHaveLength( 0 );
			expect(
				document.querySelector( 'iframe' ).getAttribute( 'src' )
			).toBe( 'https://geo.dailymotion.com/player.html?video=x2jvvep&' );
		} );
	} );
} );
