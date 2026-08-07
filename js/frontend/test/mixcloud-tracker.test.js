/**
 * Unit tests for the Mixcloud interaction tracker
 * (js/frontend/gtm4wp-mixcloud.js).
 *
 * The tracker builds a Mixcloud.PlayerWidget for every
 * `iframe[src*="mixcloud.com"]`, identifies the show from the `feed` query
 * parameter of the embed URL and pushes gtm4wp.media* events to the data layer
 * as the widget fires events. These tests stub the Mixcloud Widget API, drive
 * the captured handlers and assert the resulting data layer pushes — including
 * the flat gtm.video* keys that populate GTM's built-in Video variables.
 */

const flushPromises = () =>
	new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/**
 * Minimal stub of the Mixcloud Widget API. `ready` is a resolved promise; each
 * event exposes an `on()` that captures its handler so a test can emit it.
 * Positions and durations are in seconds, as the real API reports them.
 */
class FakeMixcloudWidget {
	constructor() {
		this.handlers = {};
		this.ready = Promise.resolve();
		const makeEvent = ( name ) => ( {
			on: ( cb ) => {
				this.handlers[ name ] = cb;
			},
		} );
		this.events = {
			play: makeEvent( 'play' ),
			pause: makeEvent( 'pause' ),
			ended: makeEvent( 'ended' ),
			buffering: makeEvent( 'buffering' ),
			progress: makeEvent( 'progress' ),
			error: makeEvent( 'error' ),
		};
	}
	emit( name, ...args ) {
		if ( this.handlers[ name ] ) {
			this.handlers[ name ]( ...args );
		}
	}
}

describe( 'gtm4wp-mixcloud', () => {
	let widget;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		widget = new FakeMixcloudWidget();
		global.Mixcloud = {
			PlayerWidget() {
				return widget;
			},
		};
		document.body.innerHTML =
			'<iframe src="https://www.mixcloud.com/widget/iframe/?feed=%2Fspartacus%2Fparty-time%2F"></iframe>';
	} );

	afterEach( () => {
		delete global.Mixcloud;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	/**
	 * Loads the tracker fresh (it initializes on load because jsdom reports
	 * document.readyState as 'complete') and waits for the widget.ready promise
	 * to resolve so the per-event handlers are bound.
	 *
	 * @return {Promise<FakeMixcloudWidget>} The widget the tracker constructed.
	 */
	async function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-mixcloud' );
		} );
		await flushPromises();
		return widget;
	}

	it( 'pushes mediaPlayerReady with the feed-derived id, url and title once ready', async () => {
		await loadTracker();

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'mixcloud',
			mediaData: {
				id: '/spartacus/party-time/',
				author: '',
				title: '/spartacus/party-time/',
				url: 'https://www.mixcloud.com/spartacus/party-time/',
				duration: 0,
			},
			mediaCurrentTime: 0,
			// "Ready" has no native GTM status, but the built-in Video
			// variables still resolve: gtm.videoStatus is present and empty
			// rather than absent, so it cannot inherit an earlier push's value.
			// No progress tick has arrived yet, so the duration is still 0.
			'gtm.videoProvider': 'mixcloud',
			'gtm.videoUrl': 'https://www.mixcloud.com/spartacus/party-time/',
			'gtm.videoTitle': '/spartacus/party-time/',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 0,
			'gtm.videoDuration': 0,
			'gtm.videoPercent': 0,
			// jsdom gives the iframe a 0×0 box, so it measures as off screen.
			'gtm.videoVisible': false,
		} );
	} );

	it( 'tracks PLAY as a start state using the position/duration cached from progress', async () => {
		await loadTracker();

		// A progress tick seeds the cached position and duration the play state
		// change reuses (the play event itself carries no payload).
		widget.emit( 'progress', 30, 120 );
		widget.emit( 'play' );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'mixcloud',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'mixcloud',
			'gtm.videoStatus': 'start',
			'gtm.videoUrl': 'https://www.mixcloud.com/spartacus/party-time/',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'reports the embed’s viewport position as gtm.videoVisible, per push', async () => {
		const frame = document.querySelector( 'iframe' );
		// jsdom gives every element a 0×0 box, so the player is measured through
		// a stubbed rect against the default 1024×768 viewport.
		let box = { top: 10, left: 10, bottom: 210, right: 330 };
		frame.getBoundingClientRect = () => ( {
			...box,
			width: 320,
			height: 200,
		} );
		await loadTracker();

		widget.emit( 'progress', 30, 120 );
		widget.emit( 'play' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		widget.emit( 'pause' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'maps ended to an "ended" state with the native "complete" status', async () => {
		await loadTracker();

		widget.emit( 'progress', 120, 120 );
		widget.emit( 'ended' );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );
	} );

	it( 'maps buffering to a "buffering" state with the native buffering status', async () => {
		await loadTracker();

		widget.emit( 'buffering' );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'buffering',
			'gtm.videoStatus': 'buffering',
		} );
	} );

	it( 'emits mediaPlaybackPercentage milestones with matching gtm.videoPercent', async () => {
		await loadTracker();

		// 60 / 240 = 25% -> crosses the 0, 10 and 20 marks in one progress tick.
		widget.emit( 'progress', 60, 240 );

		const marks = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.mediaPlaybackPercentage'
		);
		expect( marks.map( ( entry ) => entry.mediaPercentage ) ).toEqual( [
			0, 10, 20,
		] );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlaybackPercentage',
			mediaType: 'mixcloud',
			mediaPercentage: 20,
			mediaCurrentTime: 60,
			'gtm.videoStatus': 'progress',
			'gtm.videoPercent': 20,
			'gtm.videoCurrentTime': 60,
			'gtm.videoDuration': 240,
		} );
	} );

	it( 'tracks an error as a player event', async () => {
		await loadTracker();

		widget.emit( 'error' );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'mixcloud',
			mediaPlayerEvent: 'error',
			// A player event is still a gtm4wp.media* push, so it carries the
			// built-in Video variables — with an empty status, which also clears
			// the one a preceding state change left in the merged data layer.
			'gtm.videoProvider': 'mixcloud',
			'gtm.videoTitle': '/spartacus/party-time/',
			'gtm.videoStatus': '',
		} );
	} );

	it( 'pushes the feed value into the data layer object verbatim (no HTML entity-encoding)', async () => {
		// A feed carrying break-out characters must reach the data layer object
		// raw: the tracker pushes to a JS object, never concatenates into an
		// HTML/<script> body, so it must not entity-encode the value.
		document.body.innerHTML =
			'<iframe src="https://www.mixcloud.com/widget/iframe/?feed=%2Fartist%2F%3Cscript%3E%2F"></iframe>';

		await loadTracker();

		expect( window.dataLayer[ 0 ].mediaData.id ).toBe(
			'/artist/<script>/'
		);
		expect( window.dataLayer[ 0 ].mediaData.title ).toBe(
			'/artist/<script>/'
		);
		expect( window.dataLayer[ 0 ].mediaData.id ).not.toContain( '&lt;' );
	} );

	it( 'does not throw or push anything when the Mixcloud Widget API failed to load', async () => {
		// No global.Mixcloud: the guard must bail out gracefully instead of
		// throwing on `Mixcloud.PlayerWidget()`.
		delete global.Mixcloud;

		expect( () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-mixcloud' );
			} );
		} ).not.toThrow();

		await flushPromises();
		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
