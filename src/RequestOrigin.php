<?php
/**
 * Same-origin evidence for guest-facing REST mutations.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a request demonstrably came from a page on this site: the
 * load-bearing control of every guest-facing route (FP-5). Those routes cannot
 * use a capability gate, and the REST nonce is only a malformed-request filter
 * (for a logged-out caller `wp_rest` is a site-wide constant per tick). The
 * Origin is the gate: a browser sets it on every POST and script cannot forge
 * it. Do NOT replace it with a session-bound token: WordPress reflects the
 * Origin with Allow-Credentials by default, so a third-party page could read
 * and replay any token. RestCors withdraws that reflection, but the two
 * controls stay independent on purpose. One definition for every route (UC-6).
 */
final class RequestOrigin {

	/**
	 * Whether this request demonstrably originated from a page on this site.
	 * Origin is the primary signal; Referer is the weaker fallback consulted
	 * only when Origin is absent; neither present means refused ("no evidence"
	 * is not "same origin"). The Referer is read from $_SERVER, NOT via
	 * wp_get_raw_referer(), which prefers a request parameter supplied by the
	 * very request being judged.
	 *
	 * @return bool
	 */
	public static function is_same_origin_request(): bool {
		$site = wp_parse_url( home_url() );

		if ( ! is_array( $site ) || empty( $site['host'] ) ) {
			return false;
		}

		$origin = get_http_origin();
		if ( is_string( $origin ) && '' !== $origin ) {
			return self::url_matches_site( $origin, $site );
		}

		// esc_url_raw(), not sanitize_text_field(): a gate should not be built on
		// a sanitizer that rewrites (strips %XX from) the thing being judged.
		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		if ( '' !== $referer ) {
			return self::url_matches_site( $referer, $site );
		}

		return false;
	}

	/**
	 * Whether a URL's host and port are this site's. Scheme is deliberately not
	 * compared (TLS-terminating proxies make it unreliable); a subdomain is a
	 * different host and is refused.
	 *
	 * @param string               $url  The Origin or Referer value.
	 * @param array<string, mixed> $site Parsed home_url() parts.
	 * @return bool
	 */
	private static function url_matches_site( string $url, array $site ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) $site['host'] ) ) {
			return false;
		}

		return self::normalized_port( $parts ) === self::normalized_port( $site );
	}

	/**
	 * A URL's port, with its own scheme's default reported as "absent": a browser
	 * never puts the default port in Origin, so a home_url carrying `:443`
	 * explicitly would otherwise refuse every guest request, silently. Each side
	 * is normalized against ITS OWN scheme, so an http home_url behind a TLS
	 * proxy still matches an https Origin; a genuinely different port is refused.
	 *
	 * @param array<string, mixed> $parts Parsed URL parts from wp_parse_url().
	 * @return int|null The significant port, or null when it is the scheme default.
	 */
	private static function normalized_port( array $parts ): ?int {
		$defaults = array(
			'http'  => 80,
			'https' => 443,
		);

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		if ( null === $port ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		return ( isset( $defaults[ $scheme ] ) && $defaults[ $scheme ] === $port ) ? null : $port;
	}

	/**
	 * Whether the request carries a valid REST nonce - a filter, never the gate
	 * (see the class docblock). Accepts the header and the `_wpnonce` parameter,
	 * because navigator.sendBeacon cannot set headers.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public static function has_rest_nonce( \WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
