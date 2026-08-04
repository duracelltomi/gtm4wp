/**
 * Unit tests for the client-side device/browser/OS detection tracker
 * (js/frontend/gtm4wp-client-device-data.js).
 *
 * The tracker runs its detection once, at load time, reading navigator, and
 * pushes a single gtm4wp.deviceData event. Each test therefore sets navigator
 * first, then loads the module fresh with jest.isolateModules so the IIFE
 * re-runs against that navigator state (TC-9 / TC-10 fresh-module pattern).
 *
 * The dataLayer push is a structured object sink (TC-11), so there is no HTML
 * output-encoding surface here; the behaviors that matter are the two detection
 * paths (User-Agent Client Hints vs. the userAgent fallback), the per-signal
 * config gating (a privacy control), and the not-throw / no-push guards.
 */

/**
 * Overrides navigator.userAgent and navigator.userAgentData for the current
 * test. Both are redefined every call so no state leaks between tests (TS-7).
 *
 * @param {Object}      options               Navigator overrides.
 * @param {string}      options.userAgent     navigator.userAgent value.
 * @param {Object|void} options.userAgentData navigator.userAgentData value.
 * @return {void}
 */
function setNavigator( { userAgent, userAgentData } ) {
	Object.defineProperty( window.navigator, 'userAgent', {
		value: userAgent,
		configurable: true,
	} );
	Object.defineProperty( window.navigator, 'userAgentData', {
		value: userAgentData,
		configurable: true,
	} );
}

/**
 * Loads the tracker fresh so its load-time detection IIFE re-runs.
 *
 * @return {void}
 */
function loadTracker() {
	jest.isolateModules( () => {
		require( '../gtm4wp-client-device-data' );
	} );
}

/**
 * Flushes the microtask + macrotask queue so the Client Hints promise chain
 * (getHighEntropyValues().then/catch) has resolved before assertions run.
 *
 * @return {Promise<void>}
 */
const flushAsync = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const deviceEvents = () =>
	window.dataLayer.filter( ( entry ) => entry.event === 'gtm4wp.deviceData' );

describe( 'gtm4wp-client-device-data', () => {
	beforeEach( () => {
		// The bundle guards its boot with window.gtm4wp_clientdevice_inited so a
		// re-injected copy cannot push gtm4wp.deviceData twice (#83). That flag lives
		// on window, which jsdom keeps for the whole file, so every test has to clear
		// it before loading the module again - otherwise every test after the first
		// silently gets an early return and no push at all.
		delete window.gtm4wp_clientdevice_inited;
		window.dataLayer = [];
		window.gtm4wp_datalayer_name = 'dataLayer';
		window.gtm4wp_clientdevice_config = {
			browser: true,
			os: true,
			device: true,
		};
		// Default: no navigator info; individual tests opt into a path.
		setNavigator( { userAgent: '', userAgentData: undefined } );
	} );

	it( 'detects a desktop Chrome/Windows browser from the userAgent fallback', () => {
		setNavigator( {
			userAgent:
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			userAgentData: undefined,
		} );

		loadTracker();

		expect( deviceEvents() ).toHaveLength( 1 );
		expect( deviceEvents()[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.deviceData',
			deviceType: 'desktop',
			browserName: 'Chrome',
			browserVersion: '120.0.0.0',
			osName: 'Windows',
			osVersion: '10.0',
		} );
	} );

	it( 'detects a mobile iOS Safari device from the userAgent fallback', () => {
		setNavigator( {
			userAgent:
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
			userAgentData: undefined,
		} );

		loadTracker();

		expect( deviceEvents()[ 0 ] ).toMatchObject( {
			deviceType: 'mobile',
			browserName: 'Safari',
			browserVersion: '17.0',
			osName: 'iOS',
			// The underscore in "OS 17_0" is normalized to a dot.
			osVersion: '17.0',
		} );
	} );

	it( 'omits os and device signals when the config disables them', () => {
		window.gtm4wp_clientdevice_config = {
			browser: true,
			os: false,
			device: false,
		};
		setNavigator( {
			userAgent:
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			userAgentData: undefined,
		} );

		loadTracker();

		const push = deviceEvents()[ 0 ];
		expect( push.browserName ).toBe( 'Chrome' );
		// os* and device* keys are gated off.
		expect( push ).not.toHaveProperty( 'osName' );
		expect( push ).not.toHaveProperty( 'osVersion' );
		expect( push ).not.toHaveProperty( 'deviceType' );
	} );

	it( 'uses Client Hints and picks the significant brand over Chromium/Not-A-Brand', async () => {
		setNavigator( {
			userAgent: 'ignored when userAgentData is present',
			userAgentData: {
				mobile: false,
				platform: 'Windows',
				brands: [
					{ brand: 'Not_A Brand', version: '99' },
					{ brand: 'Chromium', version: '120' },
					{ brand: 'Google Chrome', version: '120' },
				],
				getHighEntropyValues: () =>
					Promise.resolve( {
						platformVersion: '15.0.0',
						model: '',
						fullVersionList: [
							{ brand: 'Not_A Brand', version: '99.0.0.0' },
							{
								brand: 'Google Chrome',
								version: '120.0.6099.109',
							},
						],
					} ),
			},
		} );

		loadTracker();
		await flushAsync();

		expect( deviceEvents() ).toHaveLength( 1 );
		expect( deviceEvents()[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.deviceData',
			deviceType: 'desktop',
			osName: 'Windows',
			osVersion: '15.0.0',
			// The high-entropy full version wins over the low-entropy "120".
			browserName: 'Google Chrome',
			browserVersion: '120.0.6099.109',
		} );
	} );

	it( 'still pushes the low-entropy data when getHighEntropyValues rejects', async () => {
		setNavigator( {
			userAgent: 'ignored',
			userAgentData: {
				mobile: true,
				platform: 'Android',
				brands: [ { brand: 'Google Chrome', version: '120' } ],
				getHighEntropyValues: () =>
					Promise.reject( new Error( 'permission denied' ) ),
			},
		} );

		loadTracker();
		await flushAsync();

		expect( deviceEvents() ).toHaveLength( 1 );
		expect( deviceEvents()[ 0 ] ).toMatchObject( {
			deviceType: 'mobile',
			osName: 'Android',
			browserName: 'Google Chrome',
			browserVersion: '120',
		} );
	} );

	it( 'pushes nothing when navigator exposes neither userAgentData nor userAgent', () => {
		setNavigator( { userAgent: '', userAgentData: undefined } );

		expect( () => loadTracker() ).not.toThrow();
		expect( deviceEvents() ).toHaveLength( 0 );
	} );

	it( 'does not push a second time when the bundle is loaded twice (#83)', () => {
		// This bundle attaches no listeners - it detects and pushes straight from its
		// module body - so PA-9's "module-scope addEventListener" litmus never
		// selected it, and a re-injected bundle (AJAX navigation, a page builder
		// duplicating the handle) pushed gtm4wp.deviceData twice.
		setNavigator( {
			userAgent:
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			userAgentData: undefined,
		} );

		loadTracker();
		expect( deviceEvents() ).toHaveLength( 1 );

		// Second copy of the bundle: the guard flag is left exactly as it would find it.
		loadTracker();
		expect( deviceEvents() ).toHaveLength( 1 );
	} );
} );
