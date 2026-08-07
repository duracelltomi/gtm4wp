/**
 * Unit tests for the VideoPress interaction tracker
 * (js/frontend/gtm4wp-videopress.js).
 *
 * The tracker listens for postMessage events from VideoPress player iframes and
 * pushes gtm4wp.media* events to the data layer. Messages are validated by
 * origin, their `videopress_*` event is mapped to a media state, and times
 * (reported in ms) are converted to seconds. These tests dispatch MessageEvents
 * and assert the data layer pushes — including the flat gtm.video* keys.
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

	it( 'pushes mediaPlayerReady on loadedmetadata, converting ms to seconds', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_loadedmetadata',
			id: 'AbCdEfGh',
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

	it( 'reports an unmapped player message as a mediaPlayerEvent carrying the native params', () => {
		loadTracker();

		// A start state first, so the assertion below also proves the player
		// event CLEARS the status it left in the merged data layer.
		dispatch( {
			event: 'videopress_playing',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
			durationMs: 120000,
		} );
		expect( lastPush()[ 'gtm.videoStatus' ] ).toBe( 'start' );

		dispatch( {
			event: 'videopress_volumechange',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
			durationMs: 120000,
		} );

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
	} );

	it( 'tracks playing as a start state with seconds-based native params', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_playing',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
			durationMs: 120000,
		} );

		expect( lastPush() ).toMatchObject( {
			mediaType: 'videopress',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'videopress',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'maps ended to an "ended" state and seeked to a "seeked" state', () => {
		loadTracker();

		dispatch( { event: 'videopress_ended', id: 'AbCdEfGh' } );
		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );

		dispatch( { event: 'videopress_seeked', id: 'AbCdEfGh' } );
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
		fromFrame( frame, {
			event: 'videopress_playing',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
			durationMs: 120000,
		} );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		fromFrame( frame, {
			event: 'videopress_pause',
			id: 'AbCdEfGh',
			currentTimeMs: 31000,
			durationMs: 120000,
		} );
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
		dispatch( {
			event: 'videopress_playing',
			id: 'AbCdEfGh',
			currentTimeMs: 30000,
			durationMs: 120000,
		} );

		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );
	} );

	it( 'emits mediaPlaybackPercentage milestones on timeupdate', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_timeupdate',
			id: 'AbCdEfGh',
			currentTimeMs: 60000,
			durationMs: 240000,
		} );

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

	it( 'collapses the duplicate play/playing signals into a single state change', () => {
		loadTracker();

		dispatch( { event: 'videopress_play', id: 'AbCdEfGh' } );
		dispatch( { event: 'videopress_playing', id: 'AbCdEfGh' } );

		const stateChanges = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlayerStateChange'
		);
		expect( stateChanges ).toHaveLength( 1 );
		expect( stateChanges[ 0 ].mediaPlayerState ).toBe( 'play' );
	} );

	it( 'ignores messages from a non-VideoPress origin', () => {
		loadTracker();

		dispatch(
			{
				event: 'videopress_playing',
				id: 'AbCdEfGh',
				currentTimeMs: 1000,
				durationMs: 120000,
			},
			'https://evil.example.com'
		);

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'accepts a videopress.com subdomain origin', () => {
		loadTracker();

		dispatch(
			{
				event: 'videopress_loadedmetadata',
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
				event: 'videopress_loadedmetadata',
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
			{ event: 'videopress_playing', id: 'AbCdEfGh', durationMs: 1000 },
			'https://evilvideopress.com'
		);

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'parses a JSON string payload from a valid origin', () => {
		loadTracker();

		// VideoPress may deliver event.data as a JSON string rather than an object.
		dispatch(
			JSON.stringify( {
				event: 'videopress_loadedmetadata',
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
			data: {
				event: 'videopress_playing',
				id: 'AbCdEfGh',
				durationMs: 1000,
			},
		} );

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'pushes the guid into the data layer object verbatim (no HTML entity-encoding)', () => {
		loadTracker();

		dispatch( {
			event: 'videopress_loadedmetadata',
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
		dispatch( {
			event: 'videopress_playing',
			id: 'AbCdEfGh',
			currentTimeMs: 1000,
			durationMs: 120000,
		} );
		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
