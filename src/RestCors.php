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
 * Stops WordPress handing this plugin's REST responses to other origins.
 * Core's rest_send_cors_headers() REFLECTS the request Origin with
 * Access-Control-Allow-Credentials: true, so another origin could read a
 * visitor's session data from the public routes and harvest any token they
 * issue (#78); removing the headers makes the browser refuse that read. Core's
 * JSONP wrapper is a channel CORS does not govern, so it is refused (#337).
 * Namespace-scoped, at plugin level because it protects a NAMESPACE (#97).
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
		add_filter( 'rest_pre_dispatch', array( self::class, 'refuse_jsonp' ), 10, 3 );
	}

	/**
	 * Refuses a JSONP request to this namespace before its callback runs, so the
	 * wrapped response carries an error and no data. No client of ours uses JSONP.
	 * rest_jsonp_enabled cannot do this: it runs before the route is known.
	 *
	 * @param mixed            $result  The pre-dispatch result; null when nothing short-circuited.
	 * @param mixed            $server  The REST server (unused).
	 * @param \WP_REST_Request $request The request being dispatched.
	 * @return mixed The unchanged $result, or a WP_Error for a JSONP request to this namespace.
	 */
	public static function refuse_jsonp( $result, $server, $request ) {
		if ( null !== $result || ! ( $request instanceof \WP_REST_Request ) ) {
			return $result;
		}

		$query = $request->get_query_params();

		if ( ! is_array( $query ) || ! array_key_exists( '_jsonp', $query ) || ! self::in_namespace( (string) $request->get_route() ) ) {
			return $result;
		}

		return new \WP_Error(
			'rest_jsonp_refused',
			__( 'JSONP is not supported by this API.', 'duracelltomi-google-tag-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Whether a route is the namespace index (/gtm4wp/v2) or under it; the slash
	 * keeps gtm4wp/v22 out. Lowercased: WordPress matches routes case-insensitively.
	 *
	 * @param string $route The REST route, e.g. /gtm4wp/v2/visitor-data.
	 * @return bool
	 */
	private static function in_namespace( string $route ): bool {
		$path = strtolower( ltrim( $route, '/' ) );

		return self::REST_NAMESPACE === $path || 0 === strpos( $path, self::REST_NAMESPACE . '/' );
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

		if ( ! self::in_namespace( $route ) ) {
			return false;
		}

		$site = wp_parse_url( home_url() );
		if ( ! is_array( $site ) || empty( $site['host'] ) ) {
			// Cannot establish this site's origin, so cannot vouch for any. Strip.
			return true;
		}

		// The beacons' matcher: host + default-normalised port, scheme not compared (#130, #347).
		return ! RequestOrigin::url_matches_site( $origin, $site );
	}
}
