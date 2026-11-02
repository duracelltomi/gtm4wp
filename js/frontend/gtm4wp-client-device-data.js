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

	// Double-init guard (#83, PA-9): the bundle pushes at import time, so a
	// re-injected copy would push gtm4wp.deviceData twice.
	if ( window.gtm4wp_clientdevice_inited ) {
		return;
	}
	window.gtm4wp_clientdevice_inited = true;

	// The PHP side prints the config with the bundle; when a page optimiser has
	// stripped it, fail closed like gtm4wp-visitor-data.js: pushing all three
	// signals would ignore the admin's per-signal choice (#290).
	const config = window.gtm4wp_clientdevice_config;
	if ( ! config ) {
		return;
	}
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

	// Client Hints brands under the names 1.x and the UA fallback report (#653).
	const brandNames = {
		'Google Chrome': 'Chrome',
		'Microsoft Edge': 'Edge',
	};

	function browserName( brand ) {
		return brandNames[ brand ] || brand;
	}

	// Windows' platformVersion is its UniversalApiContract version: 13+ is
	// Windows 11, 1-10 is Windows 10, 0 is older (Microsoft's mapping, U69).
	function windowsVersion( platformVersion ) {
		const major = parseInt( platformVersion, 10 );
		if ( major >= 13 ) {
			return '11.0';
		}
		return major >= 1 ? '10.0' : '';
	}

	function detectWithClientHints( uaData ) {
		const data = {
			// Android without mobile is a tablet (#653).
			deviceType: uaData.mobile
				? 'mobile'
				: ( 'Android' === uaData.platform && 'tablet' ) || 'desktop',
			osName: uaData.platform || '',
		};

		const brand = significantBrand( uaData.brands );
		if ( brand ) {
			data.browserName = browserName( brand.brand );
			data.browserVersion = brand.version;
		}

		// Only the high-entropy hints an enabled option reports (#653).
		const wanted = [];
		if ( config.os ) {
			wanted.push( 'platformVersion' );
		}
		if ( config.device ) {
			wanted.push( 'model' );
		}
		if ( config.browser ) {
			wanted.push( 'fullVersionList' );
		}
		if ( ! wanted.length ) {
			pushDeviceData( data );
			return;
		}

		uaData
			.getHighEntropyValues( wanted )
			.then( function ( highEntropy ) {
				if ( highEntropy.platformVersion ) {
					data.osVersion =
						'Windows' === uaData.platform
							? windowsVersion( highEntropy.platformVersion )
							: highEntropy.platformVersion;
				}

				if ( highEntropy.model ) {
					data.deviceModel = highEntropy.model;
				}

				const fullBrand = significantBrand(
					highEntropy.fullVersionList
				);
				if ( fullBrand ) {
					data.browserName = browserName( fullBrand.brand );
					data.browserVersion = fullBrand.version;
				}

				pushDeviceData( data );
			} )
			.catch( function () {
				pushDeviceData( data );
			} );
	}

	function detectWithUserAgent( userAgent ) {
		// Tablets first (#653): an iPad, Android without "Mobile", and iPadOS
		// in desktop mode (a Mac UA with a touch screen).
		const isTablet =
			/iPad/.test( userAgent ) ||
			( /Android/i.test( userAgent ) && ! /Mobi/i.test( userAgent ) ) ||
			( /Macintosh/.test( userAgent ) && navigator.maxTouchPoints > 1 );
		let deviceType = /Mobi|iPhone|iPod/i.test( userAgent )
			? 'mobile'
			: 'desktop';
		if ( isTablet ) {
			deviceType = 'tablet';
		}
		const data = { deviceType };

		// iOS browsers name themselves with their own tokens and no Version/.
		const browserMatchers = [
			[ /CriOS\/([0-9.]+)/, 'Chrome' ],
			[ /FxiOS\/([0-9.]+)/, 'Firefox' ],
			[ /EdgiOS\/([0-9.]+)/, 'Edge' ],
			[ /Firefox\/([0-9.]+)/, 'Firefox' ],
			[ /Edg[a-z]*\/([0-9.]+)/, 'Edge' ],
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
