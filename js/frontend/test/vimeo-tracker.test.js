/**
 * Unit tests for the Vimeo interaction tracker (js/frontend/gtm4wp-vimeo.js).
 *
 * The tracker constructs a Vimeo.Player for every `iframe[src*="vimeo.com"]`,
 * awaits the SDK metadata getters and pushes gtm4wp.media* events to the data
 * layer as the player fires events. These tests stub the Vimeo Player SDK,
 * drive the captured event handlers and assert the resulting data layer pushes
 * — including the flat gtm.video* keys that populate GTM's built-in Video
 * variables.
 */

const flushPromises = () =>
	new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/**
 * Minimal stub of the Vimeo Player SDK. Captures the handlers registered via
 * on() so a test can emit player events, and resolves the metadata/current
 * time getters the tracker awaits.
 */
class FakeVimeoPlayer {
	constructor() {
		this.handlers = {};
		this.title = 'My Vimeo Video';
		this.duration = 120;
		this.currentTime = 30;
	}
	on( event, cb ) {
		this.handlers[ event ] = cb;
	}
	emit( event, data ) {
		if ( this.handlers[ event ] ) {
			this.handlers[ event ]( data );
		}
	}
	getVideoTitle() {
		return Promise.resolve( this.title );
	}
	getDuration() {
		return Promise.resolve( this.duration );
	}
	getCurrentTime() {
		return Promise.resolve( this.currentTime );
	}
}

describe( 'gtm4wp-vimeo', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		delete global.Vimeo;
		document.body.innerHTML =
			'<iframe src="https://player.vimeo.com/video/987654321?h=abc"></iframe>';
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	/**
	 * Installs the SDK stub, loads the tracker fresh (it initializes on load
	 * because jsdom reports document.readyState as 'complete') and waits for
	 * the getVideoTitle -> getDuration ready chain to resolve.
	 *
	 * @return {Promise<FakeVimeoPlayer>} The player the tracker constructed.
	 */
	async function loadTracker() {
		let player;
		// Referenced via a variable (not an inline function-expression
		// property) so it stays a real, `new`-able constructor — a method
		// shorthand would not be constructable.
		const PlayerConstructor = function () {
			player = new FakeVimeoPlayer();
			return player;
		};
		global.Vimeo = { Player: PlayerConstructor };
		jest.isolateModules( () => {
			require( '../gtm4wp-vimeo' );
		} );
		await flushPromises();
		return player;
	}

	it( 'pushes mediaPlayerReady with title, duration and empty author once metadata resolves', async () => {
		await loadTracker();

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'vimeo',
			mediaData: {
				id: '987654321',
				author: '',
				title: 'My Vimeo Video',
				url: 'https://player.vimeo.com/video/987654321',
				duration: 120,
			},
			mediaCurrentTime: 0,
		} );
	} );

	it( 'tracks the "playing" event (not "play") as a start state with native params', async () => {
		const player = await loadTracker();

		// Only the "playing" event should be wired as the start signal; "play"
		// (fired on mere playback request) must not produce a state change.
		player.emit( 'play', { duration: 120, percent: 0, seconds: 0 } );
		expect( window.dataLayer ).toHaveLength( 1 );

		player.emit( 'playing', { duration: 120, percent: 0.04, seconds: 5 } );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'vimeo',
			mediaPlayerState: 'play',
			mediaCurrentTime: 5,
			'gtm.videoProvider': 'vimeo',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 5,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 4,
		} );
	} );

	it( 'maps bufferstart to a "buffering" state and the native buffering status', async () => {
		const player = await loadTracker();

		player.emit( 'bufferstart' );
		await flushPromises();

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'buffering',
			mediaCurrentTime: 30,
			'gtm.videoStatus': 'buffering',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
		} );
	} );

	it( 'emits bufferend as a state with an empty native status (no GTM equivalent)', async () => {
		const player = await loadTracker();

		player.emit( 'bufferend' );
		await flushPromises();

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'bufferend',
			'gtm.videoStatus': '',
		} );
	} );

	it( 'tracks playbackratechange with the new rate and the current time', async () => {
		const player = await loadTracker();

		player.emit( 'playbackratechange', { playbackRate: 1.5 } );
		await flushPromises();

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'vimeo',
			mediaPlayerEvent: 'playbackratechange',
			mediaPlayerEventParam: 1.5,
			mediaCurrentTime: 30,
		} );
	} );

	it( 'tracks qualitychange with the selected quality', async () => {
		const player = await loadTracker();

		player.emit( 'qualitychange', { quality: '720p' } );
		await flushPromises();

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'qualitychange',
			mediaPlayerEventParam: '720p',
		} );
	} );

	it( 'tracks fullscreenchange with the fullscreen flag', async () => {
		const player = await loadTracker();

		player.emit( 'fullscreenchange', { fullscreen: true } );
		await flushPromises();

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'fullscreenchange',
			mediaPlayerEventParam: true,
		} );
	} );

	it( 'tracks entering picture-in-picture mode', async () => {
		const player = await loadTracker();

		player.emit( 'enterpictureinpicture' );
		await flushPromises();

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'enterpictureinpicture',
		} );
	} );

	it( 'does not throw or push anything when the Vimeo SDK failed to load', () => {
		// No global.Vimeo (removed in beforeEach): the guard must bail out
		// gracefully instead of throwing on `new Vimeo.Player()`.
		expect( () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-vimeo' );
			} );
		} ).not.toThrow();

		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
