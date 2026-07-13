/**
 * Unit tests for the GTM built-in "Video *" variable helpers
 * (js/frontend/lib/native-video-params.js). These build the flat gtm.video*
 * keys the media trackers spread into their data layer pushes so GTM's
 * built-in Video variables resolve. Pure functions — no DOM/player API needed.
 */

import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
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
