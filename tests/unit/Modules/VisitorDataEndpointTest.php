<?php
/**
 * Unit tests for the cache-safe data layer session endpoint (issue #398, Phase 2).
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\VisitorData\VisitorDataEndpoint;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Tests\unit\TestCase;

/**
 * The endpoint delivers the Tier 2/3 visitor fields for the CURRENT request only.
 * These tests pin its two security contracts: it never returns a field a resolver
 * gated out (a logged-out request receives no user data), and a hostile
 * request-header value round-trips through the response JSON hex-encoded — no raw
 * break-out character survives. It also sends no-cache headers.
 */
final class VisitorDataEndpointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		// The endpoint stamps the WordPress no-cache headers onto the response.
		Functions\when( 'wp_get_nocache_headers' )->justReturn(
			array(
				'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
				'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store',
			)
		);

		// A fresh per-request nonce for the client's one-shot confirm beacons.
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce' );
	}

	/**
	 * Feeds the given fields through the GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS
	 * filter (Brain Monkey does not run add_filter callbacks by default).
	 *
	 * @param array<int, mixed> $fields The fields the filter should return.
	 * @return void
	 */
	private function stub_declared_fields( array $fields ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $fields ) {
				return GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS === $tag ? $fields : $value;
			}
		);
	}

	/**
	 * Decodes the hex-encoded JSON string payload the endpoint returns back into
	 * the visitor data map.
	 *
	 * @param \WP_REST_Response $response The endpoint response.
	 * @return array<string, mixed>
	 */
	private function decode_payload( \WP_REST_Response $response ): array {
		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'payload', $body );
		$this->assertIsString( $body['payload'] );

		return json_decode( $body['payload'], true );
	}

	public function test_resolve_collects_tier2_and_tier3_values_and_skips_tier1(): void {
		$endpoint = new VisitorDataEndpoint();

		$data = $endpoint->resolve(
			array(
				// Tier 1 is delivered client-side, never resolved on the endpoint.
				new VisitorField( 'siteSearchTerm', VisitorField::TIER_CLIENT, 'searchTerm' ),
				new VisitorField( 'visitorIP', VisitorField::TIER_SESSION, '', static fn () => '8.8.8.8' ),
				new VisitorField( 'visitorEmail', VisitorField::TIER_ACTION, '', static fn () => 'user@example.com', 'gtm4wp_login' ),
			)
		);

		$this->assertSame(
			array(
				'visitorIP'    => '8.8.8.8',
				'visitorEmail' => 'user@example.com',
			),
			$data
		);
		$this->assertArrayNotHasKey( 'siteSearchTerm', $data, 'Tier 1 fields are not resolved on the endpoint.' );
	}

	public function test_resolve_omits_a_field_whose_resolver_returns_null(): void {
		$endpoint = new VisitorDataEndpoint();

		$data = $endpoint->resolve(
			array(
				new VisitorField( 'visitorIP', VisitorField::TIER_SESSION, '', static fn () => '8.8.8.8' ),
				// A field with no resolver, and one whose resolver gated itself out.
				new VisitorField( 'geoCloudflareCountryCode', VisitorField::TIER_SESSION ),
				new VisitorField( 'visitorEmail', VisitorField::TIER_ACTION, '', static fn () => null, 'gtm4wp_login' ),
			)
		);

		$this->assertSame( array( 'visitorIP' => '8.8.8.8' ), $data );
	}

	/**
	 * The capability/identity gate: a logged-out request must never receive user
	 * data. The user resolver returns null when not logged in (the mechanism the
	 * PageVariables resolvers use), so the endpoint omits the field entirely.
	 */
	public function test_logged_out_request_receives_no_user_data(): void {
		$logged_in = false;

		$this->stub_declared_fields(
			array(
				new VisitorField( 'visitorIP', VisitorField::TIER_SESSION, '', static fn () => '8.8.8.8' ),
				new VisitorField(
					'visitorEmail',
					VisitorField::TIER_ACTION,
					'',
					static fn () => $logged_in ? 'user@example.com' : null,
					'gtm4wp_login'
				),
			)
		);

		$data = $this->decode_payload( ( new VisitorDataEndpoint() )->get_visitor_data() );

		// The Tier 2 (request-scoped, not identity) field is present.
		$this->assertSame( '8.8.8.8', $data['visitorIP'] );
		// But no user data leaks to an anonymous caller.
		$this->assertArrayNotHasKey( 'visitorEmail', $data );
	}

	public function test_logged_in_request_receives_user_data(): void {
		$logged_in = true;

		$this->stub_declared_fields(
			array(
				new VisitorField(
					'visitorEmail',
					VisitorField::TIER_ACTION,
					'',
					static fn () => $logged_in ? 'user@example.com' : null,
					'gtm4wp_login'
				),
			)
		);

		$data = $this->decode_payload( ( new VisitorDataEndpoint() )->get_visitor_data() );

		$this->assertSame( 'user@example.com', $data['visitorEmail'] );
	}

	/**
	 * A hostile request-header value (a spoofed X-Forwarded-For / Cloudflare
	 * country carrying every script break-out character) must round-trip through
	 * the endpoint payload hex-encoded — the safe form present, the raw break-out
	 * absent — so it can never surface a raw </script>/"/&/' anywhere downstream.
	 */
	public function test_hostile_header_value_round_trips_hex_encoded(): void {
		$hostile = "</script>\x22\x26\x27<x";

		$this->stub_declared_fields(
			array(
				new VisitorField( 'geoCloudflareCountryCode', VisitorField::TIER_SESSION, '', static fn () => $hostile ),
			)
		);

		$response = ( new VisitorDataEndpoint() )->get_visitor_data();
		$payload  = $response->get_data()['payload'];

		// The safe form, computed with the SAME hex flags the endpoint uses (TC-2).
		$expected = json_encode( $hostile, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( trim( $expected, '"' ), $payload );

		// No raw break-out character survives in the payload string.
		$this->assertStringNotContainsString( '</script>', $payload );
		$this->assertStringNotContainsString( '&', $payload );
		$this->assertStringNotContainsString( '"</', $payload );

		// The client parses it back to the exact raw value (structured sink).
		$data = json_decode( $payload, true );
		$this->assertSame( $hostile, $data['geoCloudflareCountryCode'] );
	}

	public function test_response_carries_no_cache_headers(): void {
		$this->stub_declared_fields( array() );

		$response = ( new VisitorDataEndpoint() )->get_visitor_data();
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	/**
	 * The response carries a FRESH nonce (generated per request) for the client's
	 * one-shot confirm beacons, so a beacon fired from a long-lived cached page — whose
	 * baked config nonce has gone stale — still authenticates (issue #398).
	 */
	public function test_response_carries_a_fresh_beacon_nonce(): void {
		$this->stub_declared_fields( array() );

		$body = ( new VisitorDataEndpoint() )->get_visitor_data()->get_data();

		$this->assertArrayHasKey( 'nonce', $body );
		$this->assertSame( 'fresh-nonce', $body['nonce'] );
	}

	/**
	 * #97: the namespace's cross-origin policy must NOT be registered from here.
	 *
	 * It used to be, and that tied a control covering the whole gtm4wp/v2 namespace
	 * to this module's own feature flag - the module never runs when the cache-safe
	 * data layer is off, while the namespace still exists because of the admin
	 * settings routes. The policy now lives in GTM4WP\RestCors, registered
	 * unconditionally from Plugin::boot(); see RestCorsTest.
	 */
	public function test_registering_the_route_does_not_own_the_cors_policy(): void {
		Functions\when( 'register_rest_route' )->justReturn( true );

		( new VisitorDataEndpoint() )->register_routes();

		$this->assertFalse(
			has_filter( 'rest_pre_serve_request' ),
			'The CORS policy must be registered at plugin level, not by this module.'
		);
	}
}
