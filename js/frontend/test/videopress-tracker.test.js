/**
 * Unit tests for the VideoPress interaction tracker
 * (js/frontend/gtm4wp-videopress.js).
 *
 * The tracker listens for postMessage events from VideoPress player iframes and
 * pushes gtm4wp.media* events to the data layer. Messages are validated by
 * origin and their `videopress_*` event is mapped to a media state. These tests
 * dispatch MessageEvents and assert the data layer pushes — including the flat
 * gtm.video* keys.
 *
 * The payloads below carry ONLY the properties the real player sends with each
 * message, per Automattic's postMessage documentation (upstream claim U109):
 * `videopress_timeupdate` carries the position and no duration,
 * `videopress_durationchange` the duration and no position, and every state
 * message carries neither. A fixture that puts `currentTimeMs` and `durationMs`
 * on every message is the reason a tracker reading them off the message in hand
 * passed its suite while reporting 0 for both on a real page — keep these
 * fixtures no more generous than the player.
 */

describe( 'gtm4wp-videopress', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		document.body.innerHTML =
			'<iframe src="https://videopress.com/embed/AbCdEfGh"></iframe>';
	} );

	afterEach( () => {
		// The tracker attaches a persistent window message listener; remove it so
		// it cannot fire for the next test.
		if ( window.gtm4wp_videopress_handler ) {
			window.removeEventListener(
				'message',
				window.gtm4wp_videopress_handler
			);
			delete window.gtm4wp_videopress_handler;
		}
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	const pushesOf = ( eventName ) =>
		window.dataLayer.filter( ( entry ) => entry.event === eventName );

	const dispatch = ( data, origin = 'https://videopress.com' ) => {
		window.dispatchEvent( new MessageEvent( 'message', { data, origin } ) );
	};

	// Dispatches a message the way a real player does: from the embed's own
	// window, which is how the tracker tells one embed on the page from another.
	const fromFrame = ( frame, data, origin = 'https://videopress.com' ) => {
		window.dispatchEvent(
			new MessageEvent( 'message', {
				data,
				origin,
				source: frame.contentWindow,
			} )
		);
	};

	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-videopress' );
		} );
	}

	it( 'pushes mediaPlayerReady on durationchange, converting ms to seconds', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			duration: 120,
			durationMs: 120000,
		} );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'videopress',
			mediaData: {
				id: 'AbCdEfGh',
				author: '',
				title: 'AbCdEfGh',
				url: 'https://videopress.com/v/AbCdEfGh',
				duration: 120,
			},
			mediaCurrentTime: 0,
			// "Ready" has no native GTM status, but the built-in Video
			// variables still resolve: gtm.videoStatus is present and empty
			// rather than absent, so it cannot inherit an earlier push's value.
			'gtm.videoProvider': 'videopress',
			'gtm.videoUrl': 'https://videopress.com/v/AbCdEfGh',
			'gtm.videoTitle': 'AbCdEfGh',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 0,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 0,
			// jsdom gives the iframe a 0×0 box, so it measures as off screen.
			'gtm.videoVisible': false,
		} );
	} );

	it( 'pushes each message exactly once when a re-injected bundle re-attaches (T43)', () => {
		// Every sibling bundle with a re-injection guard has this case; this
		// file did not, so neutralizing the removeEventListener in
		// gtm4wp_attachVideoPressListener left all its tests green. The attach
		// only runs when an UNWIRED embed is found, so the discriminating shape
		// is: first load wires the embed, the page is re-rendered with a fresh
		// embed (its wired marker gone with the old node), and the re-injected
		// bundle attaches again. Without detaching the previous listener
		// (remembered on window.gtm4wp_videopress_handler) both remain bound
		// and EVERY message double-pushes.
		loadTracker();

		// The page re-renders (AJAX navigation / page builder): the old iframe
		// - and its wired marker - is gone, a fresh one is in its place.
		document.body.innerHTML =
			'<iframe src="https://videopress.com/embed/AbCdEfGh"></iframe>';

		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			duration: 120,
			durationMs: 120000,
		} );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( pushesOf( 'gtm4wp.mediaPlayerReady' ) ).toHaveLength( 1 );
	} );

	it( 'pushes mediaPlayerReady only once per video, however often the duration is reported', () => {
		loadTracker();

		// A quality switch makes the player report the duration again.
		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 120000,
		} );
		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 120000,
		} );

		expect( pushesOf( 'gtm4wp.mediaPlayerReady' ) ).toHaveLength( 1 );

		// A second video on the page still gets its own ready event.
		dispatch( {
			event: 'videopress_durationchange',
			id: 'ZzZzZzZz',
			durationMs: 60000,
		} );

		expect( pushesOf( 'gtm4wp.mediaPlayerReady' ) ).toHaveLength( 2 );
	} );

	it( 'carries the duration into a state message, which reports none of its own', () => {
		loadTracker();

		// The real message sequence: the duration arrives on its own message,
		// the position on another, and `videopress_playing` carries neither.
		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 120000,
		} );
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
		} );
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'videopress',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			mediaData: expect.objectContaining( { duration: 120 } ),
			'gtm.videoProvider': 'videopress',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'emits mediaPlaybackPercentage on timeupdate, whose message carries no duration', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 240000,
		} );
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 60000,
		} );

		const marks = pushesOf( 'gtm4wp.mediaPlaybackPercentage' );
		expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
			0, 10, 20,
		] );
		expect( lastPush() ).toMatchObject( {
			mediaCurrentTime: 60,
			mediaPercentage: 20,
			'gtm.videoStatus': 'progress',
			'gtm.videoDuration': 240,
			'gtm.videoPercent': 20,
		} );
	} );

	it( 'stays silent on timeupdate until a duration has been reported', () => {
		loadTracker();

		// No durationchange yet: dividing by an unknown duration is what the
		// zero-duration guard exists to prevent, so nothing is pushed at all.
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 60000,
		} );

		expect( pushesOf( 'gtm4wp.mediaPlaybackPercentage' ) ).toHaveLength(
			0
		);

		// Once it arrives, the position already recorded is used immediately.
		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 240000,
		} );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaCurrentTime: 60,
			'gtm.videoCurrentTime': 60,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'keeps each video’s playback separate', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 120000,
		} );
		dispatch( {
			event: 'videopress_durationchange',
			id: 'ZzZzZzZz',
			durationMs: 60000,
		} );
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
		} );

		dispatch( { event: 'videopress_pause', id: 'ZzZzZzZz' } );

		// The other video's position must not leak into this one.
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'pause',
			mediaCurrentTime: 0,
			'gtm.videoDuration': 60,
			'gtm.videoCurrentTime': 0,
		} );
	} );

	it( 'treats a reported position of 0 as a real position, not as "not reported"', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 120000,
		} );
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
		} );
		// Rewound to the start: 0 must overwrite the remembered 30, which a
		// truthiness test on the incoming value would silently discard.
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 0,
		} );
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'play',
			mediaCurrentTime: 0,
			'gtm.videoCurrentTime': 0,
			'gtm.videoPercent': 0,
		} );
	} );

	it( 'falls back to the seconds form when a message carries no millisecond one', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			duration: 120,
		} );
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTime: 30,
		} );
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );

		expect( lastPush() ).toMatchObject( {
			mediaCurrentTime: 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'reports an unmapped player message as a mediaPlayerEvent carrying the native params', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: 'AbCdEfGh',
			durationMs: 120000,
		} );
		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
		} );

		// A start state first, so the assertion below also proves the player
		// event CLEARS the status it left in the merged data layer.
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );
		expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'start' );

		dispatch( { event: 'videopress_volumechange', id: 'AbCdEfGh' } );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'videopress',
			mediaPlayerEvent: 'volumechange',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'videopress',
			'gtm.videoUrl': 'https://videopress.com/v/AbCdEfGh',
			'gtm.videoTitle': 'AbCdEfGh',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
		// Only the messages that carry a value report one.
		expect( lastPush() ).not.toHaveProperty( 'mediaPlayerEventParam' );
	} );

	it( 'tracks the fullscreen message, which the player sends without the videopress_ prefix', () => {
		loadTracker();

		dispatch( {
			event: 'toggle_fullscreen',
			id: 'AbCdEfGh',
			isFullScreen: true,
		} );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'toggle_fullscreen',
			mediaPlayerEventParam: true,
			'gtm.videoProvider': 'videopress',
			'gtm.videoStatus': '',
		} );
	} );

	it( 'reports the selected quality level on a source switch', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_toggle-source',
			id: 'AbCdEfGh',
			qualityLevelInternalName: 'hd_1080',
		} );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerEvent: 'toggle-source',
			mediaPlayerEventParam: 'hd_1080',
		} );
	} );

	it( 'ignores a message whose event name is neither prefixed nor a known unprefixed one', () => {
		loadTracker();

		dispatch( { event: 'some_other_embed_event', id: 'AbCdEfGh' } );

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'maps ended to an "ended" state and seeking to a "seeked" state', () => {
		loadTracker();

		dispatch( { event: 'videopress_ended', id: 'AbCdEfGh' } );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );

		dispatch( { event: 'videopress_seeking', id: 'AbCdEfGh' } );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'seeked',
			'gtm.videoStatus': 'seek',
		} );
	} );

	it( 'reports the sending embed’s viewport position as gtm.videoVisible, per push', () => {
		const frame = document.querySelector( 'iframe' );
		// jsdom gives every element a 0×0 box, so the player is measured through
		// a stubbed rect against the default 1024×768 viewport.
		let box = { top: 10, left: 10, bottom: 210, right: 330 };
		frame.getBoundingClientRect = () => ( {
			...box,
			width: 320,
			height: 200,
		} );
		loadTracker();

		// The player posts from its own window, which is how the tracker knows
		// which embed on the page the message belongs to.
		fromFrame( frame, { event: 'videopress_playing', id: 'AbCdEfGh' } );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		fromFrame( frame, { event: 'videopress_pause', id: 'AbCdEfGh' } );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'falls back to the guid in the embed URL when the message has no source window', () => {
		const frame = document.querySelector( 'iframe' );
		frame.getBoundingClientRect = () => ( {
			top: 10,
			left: 10,
			bottom: 210,
			right: 330,
			width: 320,
			height: 200,
		} );
		loadTracker();

		// A message relayed without a source window (a nested player frame) still
		// identifies its embed through the guid in the src.
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );

		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );
	} );

	it( 'collapses the duplicate play/playing signals into a single state change', () => {
		loadTracker();

		dispatch( { event: 'videopress_play', id: 'AbCdEfGh' } );
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );

		const stateChanges = pushesOf( 'gtm4wp.mediaPlayerStateChange' );
		expect( stateChanges ).toHaveLength( 1 );
		expect( stateChanges[ 0 ].mediaPlayerState ).toBe( 'play' );
	} );

	it( 'does not let a guid of __proto__ reach Object.prototype', () => {
		loadTracker();

		// The playback record is keyed on the guid the message reports and is
		// written back through that lookup, so a plain object would resolve
		// `__proto__` to Object.prototype and write the position onto it.
		dispatch( {
			event: 'videopress_durationchange',
			id: '__proto__',
			durationMs: 120000,
		} );
		dispatch( {
			event: 'videopress_timeupdate',
			id: '__proto__',
			currentTimeMs: 30000,
		} );

		expect( {} ).not.toHaveProperty( 'currentTime' );
		expect( {} ).not.toHaveProperty( 'duration' );
		// The video itself is still tracked normally.
		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlaybackPercentage',
			mediaCurrentTime: 30,
			'gtm.videoDuration': 120,
		} );
	} );

	it( 'ignores messages from a non-VideoPress origin', () => {
		loadTracker();

		dispatch(
			{ event: 'videopress_playing', id: 'AbCdEfGh' },
			'https://evil.example.com'
		);

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'accepts a videopress.com subdomain origin', () => {
		loadTracker();

		dispatch(
			{
				event: 'videopress_durationchange',
				id: 'AbCdEfGh',
				durationMs: 1000,
			},
			'https://videos.videopress.com'
		);

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ].event ).toBe( 'gtm4wp.mediaPlayerReady' );
	} );

	it( 'accepts the video.wordpress.com origin', () => {
		loadTracker();

		dispatch(
			{
				event: 'videopress_durationchange',
				id: 'AbCdEfGh',
				durationMs: 1000,
			},
			'https://video.wordpress.com'
		);

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ].event ).toBe( 'gtm4wp.mediaPlayerReady' );
	} );

	it( 'rejects a look-alike origin that only ends with the bare domain', () => {
		loadTracker();

		// `evilvideopress.com` must NOT satisfy the `.videopress.com` suffix check.
		dispatch(
			{ event: 'videopress_playing', id: 'AbCdEfGh' },
			'https://evilvideopress.com'
		);

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'rejects the unprefixed fullscreen message from a foreign origin', () => {
		loadTracker();

		// Accepting an unprefixed event name must not become a way past the
		// origin check for a frame that is not a VideoPress player.
		dispatch(
			{ event: 'toggle_fullscreen', id: 'AbCdEfGh', isFullScreen: true },
			'https://evil.example.com'
		);

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'parses a JSON string payload from a valid origin', () => {
		loadTracker();

		// VideoPress may deliver event.data as a JSON string rather than an object.
		dispatch(
			JSON.stringify( {
				event: 'videopress_durationchange',
				id: 'AbCdEfGh',
				durationMs: 2000,
			} )
		);

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ].mediaData.duration ).toBe( 2 );
	} );

	it( 'ignores a string payload that is not valid JSON', () => {
		loadTracker();

		dispatch( 'not-json-at-all' );

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'ignores a message with a non-string origin', () => {
		loadTracker();

		// A MessageEvent coerces origin to a string, so drive the bound handler
		// directly to reach the `typeof origin !== 'string'` guard.
		window.gtm4wp_videopress_handler( {
			origin: null,
			data: { event: 'videopress_playing', id: 'AbCdEfGh' },
		} );

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'pushes the guid into the data layer object verbatim (no HTML entity-encoding)', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_durationchange',
			id: '</script>&x',
			durationMs: 1000,
		} );

		expect( window.dataLayer[ 0 ].mediaData.id ).toBe( '</script>&x' );
		expect( window.dataLayer[ 0 ].mediaData.id ).not.toContain( '&lt;' );
		expect( window.dataLayer[ 0 ].mediaData.id ).not.toContain( '&amp;' );
	} );

	it( 'does not attach a listener when no VideoPress iframe is present', () => {
		document.body.innerHTML = '<p>no videopress here</p>';

		loadTracker();

		expect( window.gtm4wp_videopress_handler ).toBeUndefined();
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );
		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
