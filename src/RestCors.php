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
 *
 * WordPress registers rest_send_cors_headers() on rest_pre_serve_request by
 * default, and it REFLECTS the request Origin while sending
 * Access-Control-Allow-Credentials: true. On a public route that is a standing
 * invitation: any page on the internet can fetch these routes with the visitor's
 * own cookies attached and read the response. For this namespace that would mean
 * a visitor's WooCommerce-session-derived data (a pending order, cart contents)
 * readable by whatever site they happen to be on, and any token these routes
 * issue being harvestable and replayable (#78).
 *
 * Browser cookie defaults (SameSite=Lax) probably block most of that today, but a
 * browser default is not a control this plugin owns. Removing the headers makes
 * the browser refuse the cross-origin read outright. Same-origin requests are
 * unaffected: they either send no Origin or send this site's own.
 *
 * Scoped strictly to this plugin's namespace - other plugins' routes keep core's
 * behavior.
 *
 * This lives at plugin level, not inside a module, because the policy protects a
 * NAMESPACE and the namespace outlives any one feature. gtm4wp/v2 carries the
 * admin settings routes (registered unconditionally in Plugin::boot()), the
 * public visitor-data GET and the WooCommerce confirm beacons. It used to be
 * registered from VisitorDataEndpoint::register_routes(), which the VisitorData
 * module only reaches when the cache-safe data layer option is on - so a control
 * covering the whole namespace was owned by one module's feature flag, and the
 * next route registered here from anywhere else would silently have inherited
 * core's reflected grant (#97).
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
		// Priority 11: core registers rest_send_cors_headers() on this filter at
		// the default 10, so this runs after it and undoes what it did for this
		// namespace.
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

		// header() replaces a previously sent header of the same name, but removing
		// is what we want: with no Access-Control-Allow-Origin at all the browser
		// blocks the read rather than being told which origin is permitted.
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );
		header_remove( 'Access-Control-Allow-Methods' );

		return $served;
	}

	/**
	 * Whether the reflected CORS headers must be stripped for this route and origin.
	 *
	 * Split out from restrict_cors() so the decision is testable without sending
	 * headers: the header calls have no return value and no observable effect in a
	 * unit test, but this predicate is the whole of the logic.
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

		// Two shapes have to match, and only one of them is a route this plugin
		// registers. WordPress auto-registers an index route for every namespace
		// the first time it sees one (WP_REST_Server::register_route() adds
		// '/' . $namespace -> get_namespace_index), so /gtm4wp/v2 answers requests
		// as surely as /gtm4wp/v2/settings does. A prefix test alone therefore
		// left the namespace's own index outside the policy that is named after
		// the namespace - it returns only route metadata, but "every route in
		// this namespace" has to mean every route.
		//
		// The trailing slash in the prefix stays: without it, a future
		// gtm4wp/v22 namespace would be swept in by a plain strpos().
		$path = ltrim( $route, '/' );

		if ( self::REST_NAMESPACE !== $path
			&& 0 !== strpos( $path, self::REST_NAMESPACE . '/' )
		) {
			return false;
		}

		$site = wp_parse_url( home_url() );
		if ( ! is_array( $site ) || empty( $site['host'] ) ) {
			// Cannot establish what this site's origin is, so cannot vouch for any
			// third-party one either. Strip.
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
