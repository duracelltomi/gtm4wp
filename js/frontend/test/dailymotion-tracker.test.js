/**
 * Unit tests for the Dailymotion interaction tracker
 * (js/frontend/gtm4wp-dailymotion.js).
 *
 * The tracker wraps every `iframe[src*="dailymotion.com"]`/`dai.ly` with a
 * DM.player and pushes gtm4wp.media* events to the data layer as the player
 * fires events. The Dailymotion player mirrors the HTML5 media API (events
 * carry no payload; current time/duration are read from the player object), so
 * the stub exposes those properties. These tests drive the captured handlers
 * and assert the data layer pushes — including the flat gtm.video* keys.
 */

class FakeDMPlayer {
	constructor() {
		this.handlers = {};
		this.currentTime = 0;
		this.duration = 120;
		this.volume = 1;
		this.quality = 'auto';
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

describe( 'gtm4wp-dailymotion', () => {
	let player;

	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		player = new FakeDMPlayer();
		global.DM = {
			player() {
				return player;
			},
		};
		document.body.innerHTML =
			'<iframe src="https://www.dailymotion.com/embed/video/x2jvvep?autoplay=0"></iframe>';
	} );

	afterEach( () => {
		delete global.DM;
	} );

	const lastPush = () => window.dataLayer[ window.dataLayer.length - 1 ];

	function loadTracker() {
		jest.isolateModules( () => {
			require( '../gtm4wp-dailymotion' );
		} );
		return player;
	}

	it( 'pushes mediaPlayerReady with the id parsed from the embed path', () => {
		loadTracker();

		player.emit( 'apiready' );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toEqual( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'dailymotion',
			mediaData: {
				id: 'x2jvvep',
				author: '',
				title: 'x2jvvep',
				url: 'https://www.dailymotion.com/embed/video/x2jvvep',
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
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'dailymotion',
			mediaPlayerState: 'play',
			mediaCurrentTime: 30,
			'gtm.videoProvider': 'dailymotion',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'maps video_end to an "ended" state with the native "complete" status', () => {
		loadTracker();
		player.currentTime = 120;

		player.emit( 'video_end' );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaPlayerState: 'ended',
			'gtm.videoStatus': 'complete',
		} );
	} );

	it( 'maps seeked to a "seeked" state with the native "seek" status', () => {
		loadTracker();

		player.emit( 'seeked' );

		expect( lastPush() ).toMatchObject( {
			mediaPlayerState: 'seeked',
			'gtm.videoStatus': 'seek',
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

	it( 'tracks qualitychange as a player event carrying the current quality', () => {
		loadTracker();
		player.quality = '720';

		player.emit( 'qualitychange' );

		expect( lastPush() ).toMatchObject( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaPlayerEvent: 'qualitychange',
			mediaPlayerEventParam: '720',
		} );
	} );

	it( 'pushes the parsed id into the data layer object verbatim (no HTML entity-encoding)', () => {
		// A crafted embed URL whose video id carries break-out characters must
		// reach the data layer object raw: the tracker pushes to a JS object, it
		// never concatenates the value into an HTML/<script> body.
		document.body.innerHTML =
			'<iframe src="https://geo.dailymotion.com/player.html?video=%3Cscript%3E"></iframe>';

		loadTracker();
		player.emit( 'apiready' );

		expect( window.dataLayer[ 0 ].mediaData.id ).toBe( '<script>' );
		expect( window.dataLayer[ 0 ].mediaData.id ).not.toContain( '&lt;' );
	} );

	it( 'does not throw or push anything when the Dailymotion SDK failed to load', () => {
		delete global.DM;

		expect( () => {
			jest.isolateModules( () => {
				require( '../gtm4wp-dailymotion' );
			} );
		} ).not.toThrow();

		expect( window.dataLayer ).toHaveLength( 0 );
	} );
} );
