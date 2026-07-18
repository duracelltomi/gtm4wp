/**
 * Unit tests for the GTM built-in "Video *" variable helpers
 * (js/frontend/lib/native-video-params.js). These build the flat gtm.video*
 * keys the media trackers spread into their data layer pushes so GTM's
 * built-in Video variables resolve. Pure functions — no DOM/player API needed.
 */

import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from '../lib/native-video-params';

describe( 'gtm4wpNativeVideoStatus', () => {
	it( 'maps each GTM4WP state to its GTM built-in Video status', () => {
		expect( gtm4wpNativeVideoStatus( 'play' ) ).toBe( 'start' );
		expect( gtm4wpNativeVideoStatus( 'pause' ) ).toBe( 'pause' );
		expect( gtm4wpNativeVideoStatus( 'buffering' ) ).toBe( 'buffering' );
		expect( gtm4wpNativeVideoStatus( 'ended' ) ).toBe( 'complete' );
		expect( gtm4wpNativeVideoStatus( 'seeked' ) ).toBe( 'seek' );
	} );

	it( 'returns an empty string for states with no native equivalent', () => {
		expect( gtm4wpNativeVideoStatus( 'cued' ) ).toBe( '' );
		expect( gtm4wpNativeVideoStatus( 'unstarted' ) ).toBe( '' );
		// The Vimeo tracker emits a 'bufferend' state (buffering resumed);
		// GTM has no native status for it, so it must resolve to ''.
		expect( gtm4wpNativeVideoStatus( 'bufferend' ) ).toBe( '' );
		expect( gtm4wpNativeVideoStatus( undefined ) ).toBe( '' );
	} );
} );

describe( 'gtm4wpNativeVideoParams', () => {
	it( 'builds the gtm.video* keys and computes percent from time/duration', () => {
		const params = gtm4wpNativeVideoParams( {
			provider: 'youtube',
			status: 'start',
			url: 'https://youtu.be/abc',
			title: 'My video',
			currentTime: 30,
			duration: 120,
		} );

		expect( params ).toEqual( {
			'gtm.videoProvider': 'youtube',
			'gtm.videoUrl': 'https://youtu.be/abc',
			'gtm.videoTitle': 'My video',
			'gtm.videoStatus': 'start',
			'gtm.videoCurrentTime': 30,
			'gtm.videoDuration': 120,
			'gtm.videoPercent': 25,
		} );
	} );

	it( 'does not emit gtm.videoVisible (visibility is not tracked)', () => {
		const params = gtm4wpNativeVideoParams( {
			provider: 'youtube',
			status: 'start',
			url: 'u',
			title: 't',
			currentTime: 0,
			duration: 10,
		} );

		expect( params ).not.toHaveProperty( 'gtm.videoVisible' );
	} );

	it( 'floors fractional current time and duration to whole seconds', () => {
		const params = gtm4wpNativeVideoParams( {
			provider: 'vimeo',
			status: 'seek',
			url: 'u',
			title: 't',
			currentTime: 12.9,
			duration: 100.4,
		} );

		expect( params[ 'gtm.videoCurrentTime' ] ).toBe( 12 );
		expect( params[ 'gtm.videoDuration' ] ).toBe( 100 );
		expect( params[ 'gtm.videoPercent' ] ).toBe( 12 );
	} );

	it( 'respects an explicit percent (progress milestones) over the computed one', () => {
		const params = gtm4wpNativeVideoParams( {
			provider: 'youtube',
			status: 'progress',
			url: 'u',
			title: 't',
			currentTime: 33,
			duration: 100,
			percent: 30,
		} );

		expect( params[ 'gtm.videoPercent' ] ).toBe( 30 );
	} );

	it( 'yields percent 0 when duration is missing or zero (no divide-by-zero)', () => {
		const params = gtm4wpNativeVideoParams( {
			provider: 'html5',
			status: 'start',
			url: 'u',
			title: 't',
			currentTime: 5,
			duration: 0,
		} );

		expect( params[ 'gtm.videoPercent' ] ).toBe( 0 );
		expect( params[ 'gtm.videoDuration' ] ).toBe( 0 );
	} );

	it( 'coerces non-numeric time/duration to 0', () => {
		const params = gtm4wpNativeVideoParams( {
			provider: 'html5',
			status: 'start',
			url: 'u',
			title: 't',
			currentTime: NaN,
			duration: undefined,
		} );

		expect( params[ 'gtm.videoCurrentTime' ] ).toBe( 0 );
		expect( params[ 'gtm.videoDuration' ] ).toBe( 0 );
		expect( params[ 'gtm.videoPercent' ] ).toBe( 0 );
	} );
} );

describe( 'gtm4wpMediaMilestones', () => {
	it( 'fires each newly-crossed mark once and records it', () => {
		const marks = {};
		const fired = [];

		// 25% crosses the 0, 10 and 20 marks in one tick.
		gtm4wpMediaMilestones( marks, 'vid1', 25, 10, ( i ) =>
			fired.push( i )
		);

		expect( fired ).toEqual( [ 0, 10, 20 ] );
		expect( marks.vid1 ).toEqual( [ 0, 10, 20 ] );
	} );

	it( 'does not re-fire marks already recorded on a later tick', () => {
		const marks = {};
		const fired = [];

		gtm4wpMediaMilestones( marks, 'vid1', 25, 10, ( i ) =>
			fired.push( i )
		);
		fired.length = 0;
		// A later tick at 45% must fire only the newly crossed 30 and 40 marks.
		gtm4wpMediaMilestones( marks, 'vid1', 45, 10, ( i ) =>
			fired.push( i )
		);

		expect( fired ).toEqual( [ 30, 40 ] );
		expect( marks.vid1 ).toEqual( [ 0, 10, 20, 30, 40 ] );
	} );

	it( 'keeps marks independent per key', () => {
		const marks = {};

		gtm4wpMediaMilestones( marks, 'a', 25, 10, () => {} );
		const firedForB = [];
		gtm4wpMediaMilestones( marks, 'b', 15, 10, ( i ) =>
			firedForB.push( i )
		);

		// 'b' is a fresh key: its 0 and 10 marks fire even though 'a' passed them.
		expect( firedForB ).toEqual( [ 0, 10 ] );
		expect( marks.a ).toEqual( [ 0, 10, 20 ] );
		expect( marks.b ).toEqual( [ 0, 10 ] );
	} );

	it( 'fires nothing below the first mark', () => {
		const marks = {};
		const fired = [];

		// Strictly-greater-than: at exactly 0% no mark has been passed yet.
		gtm4wpMediaMilestones( marks, 'v', 0, 10, ( i ) => fired.push( i ) );

		expect( fired ).toEqual( [] );
		expect( marks.v ).toEqual( [] );
	} );

	it( 'fires every mark for a non-finite percentage (why callers must guard duration)', () => {
		const marks = {};
		const fired = [];

		// time / 0 = Infinity, which is > every mark. This documents the
		// contract: trackers must `if ( ! duration ) return;` before calling.
		gtm4wpMediaMilestones( marks, 'v', Infinity, 10, ( i ) =>
			fired.push( i )
		);

		expect( fired ).toEqual( [ 0, 10, 20, 30, 40, 50, 60, 70, 80, 90 ] );
	} );
} );

describe( 'gtm4wpOnReady', () => {
	afterEach( () => {
		// Restore jsdom's default readyState getter between tests.
		delete document.readyState;
	} );

	it( 'runs the callback synchronously when the DOM is already parsed', () => {
		Object.defineProperty( document, 'readyState', {
			value: 'complete',
			configurable: true,
		} );
		const cb = jest.fn();

		gtm4wpOnReady( cb );

		expect( cb ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'defers to DOMContentLoaded while the document is still loading', () => {
		Object.defineProperty( document, 'readyState', {
			value: 'loading',
			configurable: true,
		} );
		const cb = jest.fn();

		gtm4wpOnReady( cb );
		// Not called yet: it waits for the DOM to finish parsing.
		expect( cb ).not.toHaveBeenCalled();

		// The helper listens on window, so dispatch there directly.
		window.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
		expect( cb ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'gtm4wpObserveMedia', () => {
	// MutationObserver records are delivered as microtasks (before macrotasks),
	// so awaiting a macrotask guarantees the observer callback has already run.
	const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		// The shared observer lives on the (jsdom) document, which persists across
		// tests; disconnect and reset it so nothing leaks into the next test.
		if ( window.gtm4wp_media_observer ) {
			window.gtm4wp_media_observer.disconnect();
		}
		delete window.gtm4wp_media_observer;
		delete window.gtm4wp_media_scanners;
		delete window.gtm4wp_media_observe_dynamic;
		document.body.innerHTML = '';
	} );

	it( 'wires elements already in the DOM regardless of the opt-in', () => {
		document.body.innerHTML = '<video></video><video></video>';
		const wired = [];

		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ) );

		expect( wired ).toHaveLength( 2 );
		// Each wired element is marked so a rescan never binds it twice.
		expect(
			document.querySelectorAll( 'video[data-gtm4wp-media-wired]' )
		).toHaveLength( 2 );
	} );

	it( 'does not wire the same element twice on a repeat call (marker guard)', () => {
		document.body.innerHTML = '<video></video>';
		const wired = [];

		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ) );
		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ) );

		expect( wired ).toHaveLength( 1 );
	} );

	it( 'leaves an element unwired and unmarked when isReady is false, then wires it once ready', () => {
		document.body.innerHTML = '<video></video>';
		let ready = false;
		const wired = [];
		const isReady = () => ready;

		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ), isReady );

		// SDK not ready: nothing wired, nothing marked (so a retry stays possible).
		expect( wired ).toHaveLength( 0 );
		expect(
			document
				.querySelector( 'video' )
				.hasAttribute( 'data-gtm4wp-media-wired' )
		).toBe( false );

		ready = true;
		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ), isReady );

		expect( wired ).toHaveLength( 1 );
	} );

	it( 'does NOT watch for later insertions when the opt-in is off', async () => {
		const wired = [];

		// window.gtm4wp_media_observe_dynamic is unset (opt-in off).
		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ) );
		expect( window.gtm4wp_media_observer ).toBeUndefined();

		document.body.appendChild( document.createElement( 'video' ) );
		await flush();

		expect( wired ).toHaveLength( 0 );
	} );

	it( 'wires a player inserted after init when the opt-in is on', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		const wired = [];

		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ) );
		expect( wired ).toHaveLength( 0 );

		// Simulate a popup/AJAX insertion of a wrapper that contains the player.
		const wrapper = document.createElement( 'div' );
		wrapper.innerHTML = '<video></video>';
		document.body.appendChild( wrapper );
		await flush();

		expect( wired ).toHaveLength( 1 );
		expect(
			wrapper
				.querySelector( 'video' )
				.hasAttribute( 'data-gtm4wp-media-wired' )
		).toBe( true );
	} );

	it( 'wires an element inserted directly (the added node itself matches)', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		const wired = [];

		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ) );

		document.body.appendChild( document.createElement( 'video' ) );
		await flush();

		expect( wired ).toHaveLength( 1 );
	} );

	it( 'does not re-wire an already-wired player that is moved in the DOM', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		document.body.innerHTML =
			'<div id="a"></div><div id="b"></div><video></video>';
		const wired = [];

		gtm4wpObserveMedia( 'video', ( el ) => wired.push( el ) );
		expect( wired ).toHaveLength( 1 ); // wired at init

		// Moving the (already-marked) player re-reports it as an added node; the
		// marker must stop it being wired a second time.
		document
			.querySelector( '#b' )
			.appendChild( document.querySelector( 'video' ) );
		await flush();

		expect( wired ).toHaveLength( 1 );
	} );

	it( 'skips a matching element injected inside a container already marked wired (SDK self-insertion guard)', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		const wired = [];

		gtm4wpObserveMedia( 'iframe[src*="player.twitch.tv"]', ( el ) =>
			wired.push( el )
		);

		// Emulate the Twitch tracker: a container it created and marked, into which
		// the SDK later injects an iframe that also matches the selector.
		const container = document.createElement( 'div' );
		container.setAttribute( 'data-gtm4wp-media-wired', '1' );
		document.body.appendChild( container );
		await flush();

		const sdkFrame = document.createElement( 'iframe' );
		sdkFrame.setAttribute( 'src', 'https://player.twitch.tv/?channel=x' );
		container.appendChild( sdkFrame );
		await flush();

		expect( wired ).toHaveLength( 0 );
	} );

	// A provider SDK that REPLACES the element it is handed (Spotify's
	// createController does parentElement.replaceChild + a synchronous src) takes
	// the wired-marker with the replaced node. Without the re-mark in wireOnce the
	// observer sees the SDK's own iframe as a fresh match and wires it — replacing
	// it again, forever. The SPOTIFY_CAP below turns that regression into a
	// failing assertion rather than a hung test run (the loop is a microtask loop,
	// which would starve the macrotask flush()).
	const SPOTIFY_SELECTOR = 'iframe[src*="open.spotify.com/embed"]';
	const SPOTIFY_CAP = 10;

	/**
	 * Emulates Spotify's IFrameAPI.createController(): it replaces the target
	 * element with its own iframe and sets the embed src synchronously, so the
	 * replacement matches the selector before the observer's microtask runs.
	 *
	 * @param {Array} wired Collects each element handed to the wiring.
	 * @return {Function} The wireElement callback.
	 */
	const spotifyReplacingWire = ( wired ) => ( element ) => {
		wired.push( element );
		if ( wired.length > SPOTIFY_CAP ) {
			return;
		}

		const replacement = document.createElement( 'iframe' );
		replacement.setAttribute(
			'src',
			'https://open.spotify.com/embed/track/x'
		);
		element.parentElement.replaceChild( replacement, element );
	};

	it( 'does not re-wire the SDK’s replacement when a sibling provider registered the observer first', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		document.body.innerHTML =
			'<iframe src="https://open.spotify.com/embed/track/x"></iframe>';
		const wired = [];

		// The common case: every sibling tracker wires on gtm4wpOnReady, while
		// Spotify wires from its async onSpotifyIframeApiReady callback — so the
		// shared observer is already live when Spotify's replaceChild lands, and
		// an embed merely PRESENT AT LOAD is enough to trigger the loop.
		gtm4wpObserveMedia( 'video', () => {} );
		gtm4wpObserveMedia( SPOTIFY_SELECTOR, spotifyReplacingWire( wired ) );

		await flush();
		await flush();

		expect( wired ).toHaveLength( 1 );
		// The SDK's replacement carries the marker, so nothing re-wires it.
		expect(
			document
				.querySelector( SPOTIFY_SELECTOR )
				.hasAttribute( 'data-gtm4wp-media-wired' )
		).toBe( true );
	} );

	it( 'does not re-wire the SDK’s replacement for a later-inserted embed', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		const wired = [];

		gtm4wpObserveMedia( SPOTIFY_SELECTOR, spotifyReplacingWire( wired ) );

		// The feature's own advertised scenario: a popup/AJAX insertion.
		const frame = document.createElement( 'iframe' );
		frame.setAttribute( 'src', 'https://open.spotify.com/embed/track/x' );
		document.body.appendChild( frame );

		await flush();
		await flush();

		expect( wired ).toHaveLength( 1 );
	} );

	it( 'still wires an unrelated element that shifts into the slot of a removed one', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		document.body.innerHTML = '<video id="first"></video>';
		const wired = [];

		// A wire that REMOVES its element without replacing it must not cause the
		// node that shifts into the vacated slot to be marked — that would hide a
		// real embed from tracking.
		gtm4wpObserveMedia( 'video', ( element ) => {
			wired.push( element );
			const later = document.createElement( 'video' );
			later.id = 'second';
			element.parentElement.replaceChild( later, element );
		} );

		await flush();

		// #second took #first's slot and is a genuine, unwired embed: it must be
		// marked (it matches the selector) rather than silently skipped forever.
		expect(
			document
				.querySelector( '#second' )
				.hasAttribute( 'data-gtm4wp-media-wired' )
		).toBe( true );
		expect( wired ).toHaveLength( 1 );
	} );

	it( 'shares one observer across providers and wires each provider’s late insertions', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		const videos = [];
		const audios = [];

		gtm4wpObserveMedia( 'video', ( el ) => videos.push( el ) );
		gtm4wpObserveMedia( 'audio', ( el ) => audios.push( el ) );

		// One shared MutationObserver serves both providers, not one per provider.
		expect( window.gtm4wp_media_scanners ).toHaveLength( 2 );

		document.body.appendChild( document.createElement( 'video' ) );
		document.body.appendChild( document.createElement( 'audio' ) );
		await flush();

		expect( videos ).toHaveLength( 1 );
		expect( audios ).toHaveLength( 1 );
	} );

	it( 'replaces its own scanner on re-init instead of stacking a duplicate', async () => {
		window.gtm4wp_media_observe_dynamic = true;
		const first = [];
		const second = [];

		gtm4wpObserveMedia( 'video', ( el ) => first.push( el ) );
		gtm4wpObserveMedia( 'video', ( el ) => second.push( el ) );

		expect( window.gtm4wp_media_scanners ).toHaveLength( 1 );

		document.body.appendChild( document.createElement( 'video' ) );
		await flush();

		// Only the latest registration wires the late insertion (no double push).
		expect( first ).toHaveLength( 0 );
		expect( second ).toHaveLength( 1 );
	} );
} );
