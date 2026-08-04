/**
 * GTM4WP client side browser / OS / device detection.
 *
 * Replaces the server side WhichBrowser library of GTM4WP 1.x. Uses
 * User-Agent Client Hints where available (Chromium based browsers) and a
 * minimal user agent fallback elsewhere. Pushes a gtm4wp.deviceData event
 * into the data layer with the same variable names as 1.x used.
 */
( function () {
	'use strict';

	// Guard against double registration (#83). This bundle attaches no listeners - it
	// detects and pushes straight from its module body - so PA-9's "module-scope
	// addEventListener" litmus never selected it, but a re-injected bundle pushes
	// gtm4wp.deviceData twice just the same. The trigger is "does anything run at
	// import time", not "does it listen".
	if ( window.gtm4wp_clientdevice_inited ) {
		return;
	}
	window.gtm4wp_clientdevice_inited = true;

	const config = window.gtm4wp_clientdevice_config || {
		browser: true,
		os: true,
		device: true,
	};
	const datalayerName = window.gtm4wp_datalayer_name || 'dataLayer';

	function pushDeviceData( data ) {
		const filtered = { event: 'gtm4wp.deviceData' };

		Object.keys( data ).forEach( function ( key ) {
			const enabled =
				( 0 === key.indexOf( 'browser' ) && config.browser ) ||
				( 0 === key.indexOf( 'os' ) && config.os ) ||
				( 0 === key.indexOf( 'device' ) && config.device );

			if ( enabled && '' !== data[ key ] && undefined !== data[ key ] ) {
				filtered[ key ] = data[ key ];
			}
		} );

		window[ datalayerName ] = window[ datalayerName ] || [];
		window[ datalayerName ].push( filtered );
	}

	function significantBrand( brands ) {
		let result = null;

		( brands || [] ).forEach( function ( oneBrand ) {
			if ( ! /not.?a.?brand|chromium/i.test( oneBrand.brand ) ) {
				result = oneBrand;
			}
		} );

		return result;
	}

	function detectWithClientHints( uaData ) {
		const data = {
			deviceType: uaData.mobile ? 'mobile' : 'desktop',
			osName: uaData.platform || '',
		};

		const brand = significantBrand( uaData.brands );
		if ( brand ) {
			data.browserName = brand.brand;
			data.browserVersion = brand.version;
		}

		const wanted = [ 'platformVersion', 'model', 'fullVersionList' ];

		uaData
			.getHighEntropyValues( wanted )
			.then( function ( highEntropy ) {
				if ( highEntropy.platformVersion ) {
					data.osVersion = highEntropy.platformVersion;
				}

				if ( highEntropy.model ) {
					data.deviceModel = highEntropy.model;
				}

				const fullBrand = significantBrand(
					highEntropy.fullVersionList
				);
				if ( fullBrand ) {
					data.browserName = fullBrand.brand;
					data.browserVersion = fullBrand.version;
				}

				pushDeviceData( data );
			} )
			.catch( function () {
				pushDeviceData( data );
			} );
	}

	function detectWithUserAgent( userAgent ) {
		const data = {
			deviceType: /Mobi|Android|iPhone|iPad|iPod/i.test( userAgent )
				? 'mobile'
				: 'desktop',
		};

		const browserMatchers = [
			[ /Firefox\/([0-9.]+)/, 'Firefox' ],
			[ /Edg[a-z]*\/([0-9.]+)/, 'Microsoft Edge' ],
			[ /OPR\/([0-9.]+)/, 'Opera' ],
			[ /Chrome\/([0-9.]+)/, 'Chrome' ],
			[ /Version\/([0-9.]+).*Safari/, 'Safari' ],
		];

		browserMatchers.some( function ( matcher ) {
			const match = userAgent.match( matcher[ 0 ] );
			if ( match ) {
				data.browserName = matcher[ 1 ];
				data.browserVersion = match[ 1 ];
				return true;
			}
			return false;
		} );

		const osMatchers = [
			[ /Windows NT ([0-9.]+)/, 'Windows' ],
			[ /Android ([0-9.]+)/, 'Android' ],
			[ /(iPhone|iPad|iPod).+OS ([0-9_]+)/, 'iOS', 2 ],
			[ /Mac OS X ([0-9_.]+)/, 'macOS' ],
			[ /Linux/, 'Linux' ],
		];

		osMatchers.some( function ( matcher ) {
			const match = userAgent.match( matcher[ 0 ] );
			if ( match ) {
				data.osName = matcher[ 1 ];
				const versionIndex = matcher[ 2 ] || 1;
				if ( match[ versionIndex ] ) {
					data.osVersion = match[ versionIndex ].replace( /_/g, '.' );
				}
				return true;
			}
			return false;
		} );

		pushDeviceData( data );
	}

	if ( navigator.userAgentData ) {
		detectWithClientHints( navigator.userAgentData );
	} else if ( navigator.userAgent ) {
		detectWithUserAgent( navigator.userAgent );
	}
} )();
