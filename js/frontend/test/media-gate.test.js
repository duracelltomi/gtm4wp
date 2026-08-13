/**
 * Unit tests for the consent gate FILE itself (js/frontend/gtm4wp-media-gate.js).
 *
 * T41: every consent-gate case in native-video-params.test.js hand-sets
 * `window.gtm4wp_media_sdk_allowed` — the test supplying the collaborator's only
 * behavior (TS-13/TS-17). Emptying the gate file left the whole suite green
 * while every SDK fetch on gate-shipping pages silently failed closed. These
 * cases execute the real file, so a rename of the flag, a regression to a
 * lexical binding (RI-14 reversed — the reader is an explicit `window.` read,
 * which jsdom would still satisfy for a module-scoped `const` but a browser
 * would not… and neither creates the window own property asserted here), or an
 * emptied file all go red.
 */

import { gtm4wpObserveMedia } from '../lib/native-video-params';

const SDK = 'https://sdk.example.test/player.js';

/** Every script tag requesting the fake SDK, matched as the trackers write it. */
const sdkTags = () =>
	Array.from( document.getElementsByTagName( 'script' ) ).filter(
		( tag ) => tag.getAttribute( 'src' ) === SDK
	);

/** Loads the real gate file fresh (it is a side-effect-only bundle). */
const runGate = () => {
	jest.isolateModules( () => {
		require( '../gtm4wp-media-gate' );
	} );
};

describe( 'gtm4wp-media-gate.js', () => {
	beforeEach( () => {
		delete window.gtm4wp_media_sdk_allowed;
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		if ( window.gtm4wp_media_observer ) {
			window.gtm4wp_media_observer.disconnect();
		}
		delete window.gtm4wp_media_observer;
		delete window.gtm4wp_media_scanners;
		delete window.gtm4wp_media_observe_dynamic;
		delete window.gtm4wp_media_sdk_blocked;
		delete window.gtm4wp_media_gate_expected;
		delete window.gtm4wp_media_sdk_allowed;
		sdkTags().forEach( ( tag ) => tag.remove() );
		document.body.innerHTML = '';
	} );

	it( 'raises the flag as a window OWN property when it runs', () => {
		// Pin the precondition first: if the property already existed here,
		// the assertion below would stop discriminating (TC-16's rule 4).
		expect(
			Object.prototype.hasOwnProperty.call(
				window,
				'gtm4wp_media_sdk_allowed'
			)
		).toBe( false );

		runGate();

		// A window own property, not a module/lexical binding: the readers are
		// other bundles reading `window.gtm4wp_media_sdk_allowed`, and only a
		// real window property survives whatever order they load in.
		expect(
			Object.prototype.hasOwnProperty.call(
				window,
				'gtm4wp_media_sdk_allowed'
			)
		).toBe( true );
		expect( window.gtm4wp_media_sdk_allowed ).toBe( true );
	} );

	it( 'opens the gate for a real SDK fetch when it ran (integration with the lib reader)', () => {
		// The same case the lib suite drives with a hand-set flag — here the
		// flag comes from executing the real file, so the file's contract with
		// the reader (name, value, binding kind) is what is under test.
		document.body.innerHTML = '<video></video>';
		window.gtm4wp_media_gate_expected = true;

		runGate();

		gtm4wpObserveMedia(
			'video',
			() => {},
			() => false,
			SDK
		);

		expect( sdkTags() ).toHaveLength( 1 );
	} );

	it( 'leaves the gate shut when it never runs (the blocked-gate state it exists to signal)', () => {
		// The other half of the same contract, with the real file in the
		// picture: expected but not executed — the state a consent manager
		// produces by refusing the tag — must withhold the fetch.
		document.body.innerHTML = '<video></video>';
		window.gtm4wp_media_gate_expected = true;

		gtm4wpObserveMedia(
			'video',
			() => {},
			() => false,
			SDK
		);

		expect( sdkTags() ).toHaveLength( 0 );
	} );
} );
