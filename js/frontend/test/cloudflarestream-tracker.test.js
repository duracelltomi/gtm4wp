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
			// "Ready" has no native GTM status, but the built-in Video
			// variables still resolve: gtm.videoStatus is present and empty
			// rather than absent, so it cannot inherit an earlier push's value.
			'gtm.videoProvider': 'cloudflarestream',
			'gtm.videoUrl':
				'https://customer-abc123.cloudflarestream.com/6b9e68b07dfee8cc2d116e4c51d6a957/iframe',
			'gtm.videoTitle': '6b9e68b07dfee8cc2d116e4c51d6a957',
			'gtm.videoStatus': '',
			'gtm.videoCurrentTime': 0,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 0,
			// jsdom gives the iframe a 0×0 box, so it measures as off screen.
			'gtm.videoVisible': false,
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
			// A player event is still a gtm4wp.media* push, so it carries the
			// built-in Video variables — with an empty status, which also clears
			// the one a preceding state change left in the merged data layer.
			'gtm.videoProvider': 'cloudflarestream',
			'gtm.videoTitle': '6b9e68b07dfee8cc2d116e4c51d6a957',
			'gtm.videoStatus': '',
		} );
	} );

	describe( 'embed URL parsing', () => {
		const UID = '6b9e68b07dfee8cc2d116e4c51d6a957';

		/**
		 * Replaces the fixture with a single Stream iframe.
		 *
		 * @param {string} attributes The iframe's attributes.
		 */
		const withEmbed = ( attributes ) => {
			document.body.innerHTML = '<iframe ' + attributes + '></iframe>';
		};

		it( 'recovers the UID from the #?secret= fragment WordPress appends to the embed', () => {
			// This is what a WordPress page actually renders: core's
			// wp_filter_oembed_result() appends "#?secret=<10 chars>" to the
			// iframe src of every oEmbed result whose provider is not in its
			// trusted list, and Cloudflare Stream is not one (U106). Cutting the
			// src at the '?' alone left the '#' on the last path segment, so the
			// /iframe test missed and every event reported the video as "iframe#".
			withEmbed(
				'src="https://customer-f33zs165nr7gyfy4.cloudflarestream.com/' +
					UID +
					'/iframe#?secret=ty1V7TXUb1" data-secret="ty1V7TXUb1"'
			);
			loadTracker();

			player.emit( 'loadedmetadata' );

			const push = window.dataLayer[ 0 ];
			expect( push.mediaData ).toEqual( {
				id: UID,
				author: '',
				title: UID,
				url:
					'https://customer-f33zs165nr7gyfy4.cloudflarestream.com/' +
					UID +
					'/iframe',
				duration: 120,
			} );
			expect( push[ 'gtm.videoTitle' ] ).toBe( UID );
			expect( push[ 'gtm.videoUrl' ] ).toBe(
				'https://customer-f33zs165nr7gyfy4.cloudflarestream.com/' +
					UID +
					'/iframe'
			);
			// The other direction: nothing anywhere in the push still carries the
			// fragment, the secret, or the "iframe#" the parse used to produce.
			const serialized = JSON.stringify( push );
			expect( serialized ).not.toContain( 'iframe#' );
			expect( serialized ).not.toContain( '#' );
			expect( serialized ).not.toContain( 'secret' );
		} );

		it( 'parses the UID from the legacy videodelivery.net embed the selector also matches', () => {
			withEmbed( 'src="https://iframe.videodelivery.net/' + UID + '"' );
			loadTracker();

			player.emit( 'loadedmetadata' );

			expect( window.dataLayer[ 0 ].mediaData ).toMatchObject( {
				id: UID,
				title: UID,
				url: 'https://iframe.videodelivery.net/' + UID,
			} );
		} );

		it( 'reports the embed’s title attribute as the title, keeping the UID as the id', () => {
			// The Player SDK exposes no title, so the accessible title attribute
			// is the only real one available client-side. WordPress fills it in
			// from the oEmbed response (wp_filter_oembed_iframe_title_attribute())
			// and its oEmbed sanitizer keeps it.
			withEmbed(
				'title="Cloudflare Stream demo" src="https://customer-abc123.cloudflarestream.com/' +
					UID +
					'/iframe#?secret=ty1V7TXUb1"'
			);
			loadTracker();

			player.emit( 'play' );

			expect( lastPush().mediaData ).toMatchObject( {
				id: UID,
				title: 'Cloudflare Stream demo',
			} );
			expect( lastPush()[ 'gtm.videoTitle' ] ).toBe(
				'Cloudflare Stream demo'
			);
		} );

		it( 'falls back to the UID when the title attribute is empty or blank', () => {
			withEmbed(
				'title="   " src="https://customer-abc123.cloudflarestream.com/' +
					UID +
					'/iframe"'
			);
			loadTracker();

			player.emit( 'play' );

			expect( lastPush().mediaData.title ).toBe( UID );
			expect( lastPush()[ 'gtm.videoTitle' ] ).toBe( UID );
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

	describe( 'SDK loading', () => {
		// Pins the URL registered as an upstream coupling (U65) — the one
		// floating `sdk.latest.js` among the media SDKs, so it cannot be diffed
		// and only its reachability and shape can be checked.
		const SDK = 'https://embed.cloudflarestream.com/embed/sdk.latest.js';

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

		it( 'requests nothing from Cloudflare on a page with no Stream embed', () => {
			document.body.innerHTML = '';
			delete global.Stream;

			jest.isolateModules( () => {
				require( '../gtm4wp-cloudflarestream' );
			} );

			expect( sdkTags() ).toHaveLength( 0 );
		} );

		it( 'fetches the Player SDK when an embed is present and the SDK is not', () => {
			delete global.Stream;

			jest.isolateModules( () => {
				require( '../gtm4wp-cloudflarestream' );
			} );

			expect( sdkTags() ).toHaveLength( 1 );
		} );
	} );
} );
