/**
 * GTM4WP shared first-party cookie helpers, the one copy for the frontend
 * bundles. A second copy of the byte format (raw value, ';expires=<UTC>;path=/')
 * lives in PHP-emitted JS, PageDataLayer::purchase_dedupe_guard(), which
 * cannot import this module: change the two together.
 */

/**
 * Reads a cookie value by name from document.cookie.
 *
 * @param {string} name The cookie name.
 * @return {string} The raw cookie value, or '' when absent.
 */
export function gtm4wp_read_cookie( name ) {
	const parts = ( '; ' + document.cookie ).split( '; ' + name + '=' );
	if ( 2 === parts.length ) {
		return parts.pop().split( ';' ).shift();
	}
	return '';
}

/**
 * Writes a first-party cookie on path=/ with a lifetime in days. The value is
 * stored as given (callers URL-encode); the attribute order matches the
 * PHP-emitted guard byte for byte.
 *
 * @param {string}  name        The cookie name.
 * @param {string}  value       The raw value to store.
 * @param {number}  days        Lifetime in days from now.
 * @param {boolean} sameSiteLax Append ';SameSite=Lax' (the list-attribution cookie does).
 * @return {void}
 */
export function gtm4wp_write_cookie( name, value, days, sameSiteLax = false ) {
	const expires = new Date();
	expires.setTime( expires.getTime() + days * 24 * 60 * 60 * 1000 );
	document.cookie =
		name +
		'=' +
		value +
		';expires=' +
		expires.toUTCString() +
		';path=/' +
		( sameSiteLax ? ';SameSite=Lax' : '' );
}

/**
 * Clears a cookie previously written on path=/.
 *
 * @param {string} name The cookie name.
 * @return {void}
 */
export function gtm4wp_clear_cookie( name ) {
	document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
}
