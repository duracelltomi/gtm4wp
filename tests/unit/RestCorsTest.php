<?php
/**
 * Unit tests for the REST namespace cross-origin policy.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Functions;
use GTM4WP\Admin\RestController;
use GTM4WP\Modules\VisitorData\VisitorDataEndpoint;
use GTM4WP\RestCors;

/**
 * WordPress registers rest_send_cors_headers() on rest_pre_serve_request by default,
 * and it REFLECTS the request Origin while sending
 * Access-Control-Allow-Credentials: true. On a public route that would let any page on
 * the internet read the response with the visitor's own cookies attached - their
 * WooCommerce-session-derived data, and any token the route hands out (#78/#90).
 *
 * The decision to strip those headers is split out from the header calls so it can be
 * asserted; the header() calls themselves have no observable effect in a unit test,
 * which is exactly why the logic does not live inside them.
 */
final class RestCorsTest extends TestCase {

	/**
	 * Stubs the two URL helpers the predicate reads.
	 *
	 * @return void
	 */
	private function stub_url_helpers(): void {
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		// wp_parse_url() is parse_url() plus a PHP 5.4 compat shim, so the plain
		// function is a faithful stand-in on the supported PHP versions.
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( $url, $component = -1 ) => parse_url( $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		);
	}

	/**
	 * The predicate that decides whether core's reflected grant has to be revoked.
	 *
	 * @param string $route    The REST route being served.
	 * @param string $origin   The request Origin, empty when absent.
	 * @param bool   $expected Whether the reflected CORS headers must be stripped.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_cors_cases' )]
	public function test_should_restrict_cors( string $route, string $origin, bool $expected ): void {
		$this->stub_url_helpers();

		$this->assertSame( $expected, RestCors::should_restrict_cors( $route, $origin ) );
	}

	/**
	 * Supplies both directions: origins whose CORS grant must be revoked, and the
	 * requests that must be left exactly as core served them.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function provide_cors_cases(): array {
		return array(
			'foreign origin on the public GET'      => array( '/gtm4wp/v2/visitor-data', 'https://evil.example', true ),
			'foreign origin on our beacon'          => array( '/gtm4wp/v2/confirm-purchase-tracked', 'https://evil.example', true ),
			// #97: the settings routes share the namespace and are registered on every
			// install, so the policy has to cover them too.
			'foreign origin on the settings route'  => array( '/gtm4wp/v2/settings', 'https://evil.example', true ),
			// #102: WordPress registers an index route for the namespace itself
			// (WP_REST_Server::register_route() -> get_namespace_index), so
			// /gtm4wp/v2 is a real route and not merely a prefix. A prefix test
			// requiring the trailing slash used to let exactly this one through.
			'foreign origin on the namespace index' => array( '/gtm4wp/v2', 'https://evil.example', true ),
			'namespace index with trailing slash'   => array( '/gtm4wp/v2/', 'https://evil.example', true ),
			'our own origin on the namespace index' => array( '/gtm4wp/v2', 'https://shop.example', false ),
			// WordPress resolves REST routes case-insensitively
			// (WP_REST_Server::match_request_to_handler() matches with an /i regex),
			// so a route spelled in any casing reaches the same callback and has to
			// reach the same policy - a case-sensitive comparison would serve the
			// response with core's reflected grant still on it.
			'mixed-case namespace'                  => array( '/GTM4WP/v2/visitor-data', 'https://evil.example', true ),
			'upper-case route'                      => array( '/GTM4WP/V2/VISITOR-DATA', 'https://evil.example', true ),
			'mixed-case settings route'             => array( '/Gtm4Wp/V2/Settings', 'https://evil.example', true ),
			'mixed-case namespace index'            => array( '/GTM4WP/V2', 'https://evil.example', true ),
			// Case-insensitivity must not become "strip everything": our own origin
			// stays untouched however the URL is spelled, and a look-alike namespace
			// stays outside the policy in every casing.
			'our own origin, mixed-case route'      => array( '/GTM4WP/V2/visitor-data', 'https://shop.example', false ),
			'mixed-case look-alike namespace'       => array( '/GTM4WP/V22/something', 'https://evil.example', false ),
			'look-alike host'                       => array( '/gtm4wp/v2/visitor-data', 'https://shop.example.evil.example', true ),
			'different port is a different origin'  => array( '/gtm4wp/v2/visitor-data', 'https://shop.example:8443', true ),
			'unparseable origin'                    => array( '/gtm4wp/v2/visitor-data', 'not a url', true ),
			// Origin: null is what a file:// or sandboxed-iframe document sends. It is
			// not this site, so it is stripped like any other foreign origin.
			'null origin'                           => array( '/gtm4wp/v2/visitor-data', 'null', true ),
			'our own origin'                        => array( '/gtm4wp/v2/visitor-data', 'https://shop.example', false ),
			// No Origin means the request was not cross-origin, so core sent no CORS
			// headers to remove in the first place.
			'no origin header'                      => array( '/gtm4wp/v2/visitor-data', '', false ),
			// Strictly scoped: another plugin's routes keep WordPress' own behavior.
			'another namespace'                     => array( '/wc/store/v1/cart', 'https://evil.example', false ),
			'core namespace'                        => array( '/wp/v2/posts', 'https://evil.example', false ),
			// A namespace that merely starts with ours must not be caught by a prefix
			// match - hence the trailing slash in the comparison.
			'look-alike namespace'                  => array( '/gtm4wp/v22/something', 'https://evil.example', false ),
		);
	}

	public function test_restrict_cors_returns_the_served_value_unchanged(): void {
		// The filter is a side-effect hook on rest_pre_serve_request: whatever it does
		// with headers, it must hand back the $served value it was given or the REST
		// server stops serving the response.
		$this->stub_url_helpers();
		Functions\when( 'get_http_origin' )->justReturn( 'https://evil.example' );

		$request = new \WP_REST_Request();
		$request->set_route( '/gtm4wp/v2/visitor-data' );

		$this->assertTrue( RestCors::restrict_cors( true, null, $request ) );
		$this->assertFalse( RestCors::restrict_cors( false, null, $request ) );
	}

	public function test_restrict_cors_ignores_a_non_request_argument(): void {
		// rest_pre_serve_request hands a WP_REST_Request, but another filter callback
		// on the same hook could pass anything; a type error here would take out the
		// whole REST response.
		$this->assertTrue( RestCors::restrict_cors( true, null, null ) );
	}

	/**
	 * #97: the policy covers a NAMESPACE, so it must be registered independently of
	 * any module's feature flag - it used to hang off the VisitorData module's route
	 * registration, which never runs unless the cache-safe data layer is enabled.
	 */
	public function test_register_hooks_the_filter_after_the_core_one(): void {
		RestCors::register();

		$this->assertSame(
			11,
			has_filter( 'rest_pre_serve_request', array( RestCors::class, 'restrict_cors' ) ),
			'Must run after core rest_send_cors_headers() (priority 10) so there is something to undo.'
		);
	}

	/**
	 * One definition of the namespace string: three classes register routes into it,
	 * and the policy matches on it by prefix. Two spellings would mean routes silently
	 * outside the policy (RI-14).
	 */
	public function test_every_route_owner_shares_one_namespace_definition(): void {
		$this->assertSame( RestCors::REST_NAMESPACE, RestController::REST_NAMESPACE );
		$this->assertSame( RestCors::REST_NAMESPACE, VisitorDataEndpoint::REST_NAMESPACE );
	}
}
