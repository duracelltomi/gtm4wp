<?php
/**
 * Cross-origin policy for this plugin's own REST namespace.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP;

defined( 'ABSPATH' ) || exit;

/**
 * Stops WordPress handing this plugin's REST responses to third-party origins.
 * Core's rest_send_cors_headers() REFLECTS the request Origin with
 * Access-Control-Allow-Credentials: true, so any page could read a visitor's
 * session-derived data from the public routes with their cookies attached, and
 * harvest any token they issue (#78); SameSite=Lax is a browser default, not a
 * control this plugin owns. Removing the headers makes the browser refuse the
 * cross-origin read; same-origin requests are unaffected. Scoped to this
 * namespace only. Lives at plugin level because the policy protects a
 * NAMESPACE that outlives any one feature; registering it from a module tied
 * it to that module's feature flag (#97).
 */
final class RestCors {

	/**
	 * REST namespace of every route this plugin registers. Single definition:
	 * Admin\RestController and Modules\VisitorData\VisitorDataEndpoint both
	 * point their own REST_NAMESPACE constant at this one.
	 */
	public const REST_NAMESPACE = 'gtm4wp/v2';

	/**
	 * Registers the policy. Hooked to rest_api_init from Plugin::boot(), for
	 * every request, with no feature-flag condition.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Priority 11: after core's rest_send_cors_headers() at 10.
		add_filter( 'rest_pre_serve_request', array( self::class, 'restrict_cors' ), 11, 3 );
	}

	/**
	 * Strips the reflected CORS headers from a foreign-origin request to this
	 * plugin's namespace.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param mixed            $result  The response data (unused).
	 * @param \WP_REST_Request $request The request being served.
	 * @return bool The unchanged $served value.
	 */
	public static function restrict_cors( $served, $result, $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) ) {
			return $served;
		}

		$origin = get_http_origin();

		if ( ! self::should_restrict_cors( (string) $request->get_route(), is_string( $origin ) ? $origin : '' ) ) {
			return $served;
		}

		// Removed, not replaced: with no Access-Control-Allow-Origin at all the
		// browser blocks the read.
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );
		header_remove( 'Access-Control-Allow-Methods' );

		return $served;
	}

	/**
	 * Whether the reflected CORS headers must be stripped for this route and
	 * origin. Split out so the decision is testable without sending headers.
	 *
	 * @param string $route  The REST route being served (e.g. /gtm4wp/v2/visitor-data).
	 * @param string $origin The request Origin header, empty when absent.
	 * @return bool
	 */
	public static function should_restrict_cors( string $route, string $origin ): bool {
		// No Origin: not a cross-origin request, so core sent no CORS headers either.
		if ( '' === $origin ) {
			return false;
		}

		// Two shapes: the namespace's auto-registered index route (/gtm4wp/v2)
		// and everything under it. The trailing slash keeps a future gtm4wp/v22
		// out. Lowercased because WordPress matches routes case-INSENSITIVELY
		// (WP_REST_Server::match_request_to_handler() uses the i modifier), so a
		// case-sensitive test would serve the request while leaving it outside
		// the policy.
		$path = strtolower( ltrim( $route, '/' ) );

		if ( self::REST_NAMESPACE !== $path
			&& 0 !== strpos( $path, self::REST_NAMESPACE . '/' )
		) {
			return false;
		}

		$site = wp_parse_url( home_url() );
		if ( ! is_array( $site ) || empty( $site['host'] ) ) {
			// Cannot establish this site's origin, so cannot vouch for any. Strip.
			return true;
		}

		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return true;
		}

		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) $site['host'] ) ) {
			return true;
		}

		return ( $parts['port'] ?? null ) !== ( $site['port'] ?? null );
	}
}
