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
 * Whether a request demonstrably came from a page on this site.
 *
 * This is the load-bearing control of every route the plugin exposes to
 * logged-out visitors. Those routes cannot use a capability gate - a guest
 * checkout is the normal case - and they cannot lean on the REST nonce either:
 * for a logged-out caller WordPress derives `wp_rest` from uid 0 with an empty
 * session token, so the value is the same for every guest on the site for the
 * whole nonce tick, and this plugin even hands one out from a public endpoint.
 * The nonce is a malformed-request filter. The Origin is the gate: a browser
 * sets it on every POST and page script cannot forge it.
 *
 * Binding a token to the visitor's session instead was considered and does not
 * work on its own: WordPress reflects the request Origin with
 * `Access-Control-Allow-Credentials: true` by default, so a third-party page
 * could read any token this site hands out with the visitor's cookies attached
 * and replay it. RestCors withdraws that reflection for this plugin's
 * namespace, but the Origin check deliberately does not depend on it - two
 * independent controls, so moving or missing one cannot quietly take the other
 * with it.
 *
 * Extracted so the routes that need it share one definition rather than one
 * copy each: a rule like this drifts between copies long before anything
 * upstream moves (UC-6).
 */
final class RequestOrigin {

	/**
	 * Whether this request demonstrably originated from a page on this site.
	 *
	 * Origin is the primary signal: browsers send it on every POST, including
	 * same-origin ones, and script cannot set it. Referer is the fallback for
	 * the rare client that omits Origin; it is weaker (a referrer policy can
	 * strip it) but it is only ever consulted when Origin is absent. When
	 * neither is present the request is refused - a state change on behalf of a
	 * visitor should come from a page, and "no evidence" is not "same origin".
	 *
	 * The Referer is read from $_SERVER, NOT through wp_get_raw_referer():
	 * that helper prefers $_REQUEST['_wp_http_referer'], a request parameter
	 * supplied by the very request being judged. It is the right helper for
	 * restoring a form's return URL and the wrong one for an access decision -
	 * the value has to come from the transport, not the payload.
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

		// esc_url_raw(), not sanitize_text_field(): the value is a URL that is
		// about to be parsed, and sanitize_text_field() strips every %XX
		// sequence out of whatever it is given. That cannot change this
		// decision today (only host and port are compared, and removing
		// characters can never turn a foreign host into ours), but a gate
		// should not be built on a sanitizer that silently rewrites the thing
		// being judged.
		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		if ( '' !== $referer ) {
			return self::url_matches_site( $referer, $site );
		}

		return false;
	}

	/**
	 * Whether a URL's host and port are this site's.
	 *
	 * Scheme is deliberately not compared: TLS-terminating proxies and mixed
	 * http/https home_url configurations make it an unreliable signal, while
	 * host and port are what separate this site from an attacker's. A subdomain
	 * is a different host and is therefore refused.
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
	 * A URL's port, with its own scheme's default port reported as "absent".
	 *
	 * A browser never puts the default port in Origin, so a site whose home_url
	 * carries one explicitly (`https://example.com:443`, which some
	 * reverse-proxy setups produce) would otherwise compare 443 against null
	 * and refuse every guest request on that site - silently, and fail-closed,
	 * which is the shape that never generates a bug report.
	 *
	 * Each side is normalized against ITS OWN scheme rather than against the
	 * other's. That is what keeps this from reintroducing the scheme comparison
	 * url_matches_site() deliberately leaves out: an http home_url behind a
	 * TLS-terminating proxy and an https Origin both reduce to "no explicit
	 * port" and still match, which is the case the scheme exclusion exists for.
	 * A genuinely different port (:8080, :8443) survives normalization and is
	 * still refused.
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
	 * Whether the request carries a valid REST nonce.
	 *
	 * A filter, never the gate - see the class doc block. Accepts the header
	 * form and the `_wpnonce` parameter, because navigator.sendBeacon cannot
	 * set headers.
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
