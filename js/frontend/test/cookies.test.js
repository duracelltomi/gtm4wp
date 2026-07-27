/**
 * Unit tests for the shared first-party cookie helpers
 * (js/frontend/lib/gtm4wp-cookies.js).
 *
 * The write format is contract, not implementation detail: the PHP-emitted
 * order-tracked guard (PageDataLayer::purchase_dedupe_guard()) writes the same
 * cookie with the same byte format and cannot import this lib, so the format
 * test below pins the two copies together.
 */

import {
	gtm4wp_read_cookie,
	gtm4wp_write_cookie,
	gtm4wp_clear_cookie,
} from '../lib/gtm4wp-cookies';

describe( 'gtm4wp-cookies', () => {
	afterEach( () => {
		// jsdom keeps document.cookie across tests in a file; expire everything
		// this suite may have written (TS-7).
		[ 'alpha', 'beta', 'gtm4wp_test' ].forEach( gtm4wp_clear_cookie );
	} );

	describe( 'gtm4wp_read_cookie', () => {
		it( 'returns "" when the cookie is absent', () => {
			expect( gtm4wp_read_cookie( 'alpha' ) ).toBe( '' );
		} );

		it( 'reads back a written value, unmangled, among other cookies', () => {
			document.cookie = 'beta=other';
			gtm4wp_write_cookie( 'alpha', 'enc%22oded', 1 );

			expect( gtm4wp_read_cookie( 'alpha' ) ).toBe( 'enc%22oded' );
			expect( gtm4wp_read_cookie( 'beta' ) ).toBe( 'other' );
		} );

		it( 'does not match a cookie whose name only ends with the requested name', () => {
			document.cookie = 'xalpha=wrong';

			expect( gtm4wp_read_cookie( 'alpha' ) ).toBe( '' );

			gtm4wp_clear_cookie( 'xalpha' );
		} );
	} );

	describe( 'gtm4wp_write_cookie', () => {
		/**
		 * Captures the exact string assigned to document.cookie.
		 *
		 * @param {Function} act The code that performs the write.
		 * @return {string} The assigned cookie string.
		 */
		function captureCookieWrite( act ) {
			let written = '';
			Object.defineProperty( document, 'cookie', {
				configurable: true,
				set: ( value ) => {
					written = value;
				},
				get: () => '',
			} );
			try {
				act();
			} finally {
				delete document.cookie;
			}
			return written;
		}

		it( 'writes name=value;expires=<UTC>;path=/ — the PHP guard byte format', () => {
			const written = captureCookieWrite( () =>
				gtm4wp_write_cookie( 'gtm4wp_test', '1042', 365 )
			);

			expect( written ).toMatch(
				/^gtm4wp_test=1042;expires=[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} [\d:]{8} GMT;path=\/$/
			);
		} );

		it( 'appends ;SameSite=Lax only when asked (the list-attribution cookie)', () => {
			const written = captureCookieWrite( () =>
				gtm4wp_write_cookie( 'gtm4wp_test', 'v', 3, true )
			);

			expect( written ).toMatch( /;path=\/;SameSite=Lax$/ );
		} );

		it( 'sets the expiry the requested number of days ahead', () => {
			const written = captureCookieWrite( () =>
				gtm4wp_write_cookie( 'gtm4wp_test', 'v', 10 )
			);

			const expires = new Date(
				written.match( /;expires=([^;]+);/ )[ 1 ]
			).getTime();
			const expected = Date.now() + 10 * 24 * 60 * 60 * 1000;

			// toUTCString() truncates milliseconds; allow a minute of slack.
			expect( Math.abs( expires - expected ) ).toBeLessThan( 60 * 1000 );
		} );
	} );

	describe( 'gtm4wp_clear_cookie', () => {
		it( 'removes a previously written cookie', () => {
			gtm4wp_write_cookie( 'alpha', 'gone-soon', 1 );
			expect( gtm4wp_read_cookie( 'alpha' ) ).toBe( 'gone-soon' );

			gtm4wp_clear_cookie( 'alpha' );

			expect( gtm4wp_read_cookie( 'alpha' ) ).toBe( '' );
		} );
	} );
} );
