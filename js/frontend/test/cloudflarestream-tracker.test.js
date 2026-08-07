/**
 * Unit tests for the Cloudflare Stream interaction tracker
 * (js/frontend/gtm4wp-cloudflarestream.js).
 *
 * The tracker wraps every `iframe[src*="cloudflarestream.com"]`/`videodelivery.net`
 * with a Stream() player and pushes gtm4wp.media* events to the data layer. The
 * Stream player mirrors the HTML5 media API (events carry no payload; current
 * time/duration are read from the player object), so the stub exposes those
 * properties. These tests drive the captured handlers and assert the data layer
 * pushes — including the flat gtm.video* keys.
 */

class FakeStreamPlayer {
	constructor() {
		this.handlers = {};
		this.currentTime = 0;
		this.duration = 120;
		this.volume = 1;
		this.playbackRate = 1;
	}
	addEventListener( event, cb ) {
		this.handlers[ event ] = cb;
	}
	emit( event ) {
		if ( this.handlers[ event ] ) {
			this.handlers[ event ]();
		}
	}
}

describe( 'gtm4wp-cloudflarestream', () => {
	let player;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		player = new FakeStreamPlayer();
		global.Stream = function () {
			return player;
		};
		document.body.innerHTML =
			'<iframe src="https://customer-abc123.cloudflarestream.com/6b9e68b07dfee8cc2d116e4c51d6a957/iframe"></iframe>';
	} );

	afterEach( () => {
		delete global.Stream;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-cloudflarestream' );
		} );
		return player;
	}

	it( 'pushes mediaPlayerReady with the UID parsed from the /{uid}/iframe URL on loadedmetadata', () => {
		loadTracker();

		player.emit( 'loadedmetadata' );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'cloudflarestream',
			mediaData: {
				id: '6b9e68b07dfee8cc2d116e4c51d6a957',
				author: '',
				title: '6b9e68b07dfee8cc2d116e4c51d6a957',
				url: 'https://customer-abc123.cloudflarestream.com/6b9e68b07dfee8cc2d116e4c51d6a957/iframe',
				duration: 120,
			},
			mediaCurrentTime: 0,
		} );
	} );

	it( 'tracks play as a start state reading current time from the player', () => {
		loadTracker();
		player.currentTime = 30;

		player.emit( 'play' );

		expect( lastPush() ).toMatchObject( {
			mediaType: 'cloudflarestream',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'cloudflarestream',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'reports the embed’s viewport position as gtm.videoVisible, per push', () => {
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

		player.emit( 'play' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( true );

		// Scrolled below the fold by the time the next event fires.
		box = { top: 900, left: 10, bottom: 1100, right: 330 };
		player.emit( 'pause' );
		expect( lastPush()[ 'gtm.videoVisible' ] ).toBe( false );
	} );

	it( 'maps ended to an "ended" state with the native "complete" status', () => {
		loadTracker();
		player.currentTime = 120;

		player.emit( 'ended' );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );
	} );

	it( 'maps waiting to a "buffering" state', () => {
		loadTracker();

		player.emit( 'waiting' );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'buffering',
			'gtm.videoStatus': 'buffering',
		} );
	} );

	it( 'emits mediaPlaybackPercentage milestones on timeupdate', () => {
		loadTracker();
		player.currentTime = 60;
		player.duration = 240;

		player.emit( 'timeupdate' );

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

	it( 'tracks ratechange as a player event carrying the playback rate', () => {
		loadTracker();
		player.playbackRate = 1.5;

		player.emit( 'ratechange' );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'ratechange',
			mediaPlayerEventParam: 1.5,
		} );
	} );

	it( 'does not throw or push anything when the Stream SDK failed to load', () => {
		delete global.Stream;

		expect( () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-cloudflarestream' );
			} );
		} ).not.toThrow();

		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
