/**
 * GTM4WP shared first-party cookie helpers.
 *
 * The one canonical copy of the cookie read/write idiom used by the frontend
 * bundles (the list-attribution cookie in gtm4wp-ecommerce-generic.js, the
 * gate/one-shot/order-tracked cookies in gtm4wp-visitor-data.js). A THIRD copy
 * of the same byte format lives in PHP-emitted JavaScript —
 * PageDataLayer::purchase_dedupe_guard() — which cannot import this module:
 * its protocol (raw value, ';expires=<UTC>;path=/') must stay byte-compatible
 * with gtm4wp_write_cookie(), so change the two together.
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
 * Writes a first-party cookie on path=/ with a lifetime in days.
 *
 * The value is stored as given — callers URL-encode when their value needs
 * it — and the attribute order (expires, path, optional SameSite) matches the
 * PHP-emitted order-tracked guard byte for byte.
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
