<?php
/**
 * First-party session endpoint for the cache-safe data layer (issue #398).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\VisitorData;

use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * First-party REST route returning the Tier 2/3 visitor-scoped fields for the
 * CURRENT request only; the client fetches it once per session (Tier 2) or
 * when a gating cookie changed (Tier 3). Security model:
 *
 * - No user/session id parameter (no IDOR): everything derives from the request
 *   context, and each resolver is its own identity gate (a user field returns
 *   null for an anonymous request), so the permission callback is public.
 * - READ-ONLY: the one-shot resolvers read their session markers but never
 *   consume them; every state change happens on the authenticated POST beacons
 *   (PageDataLayer's confirm_* routes). A mutating GET could be fired by a
 *   cross-site navigation carrying SameSite=Lax cookies.
 * - A logged-in caller sends the wp_rest nonce so the user fields resolve (a
 *   logged-in page is never cached, so it is fresh); an anonymous caller sends
 *   none, so a stale nonce in a cached page never 403s the read.
 * - The response carries a FRESH wp_rest nonce for the beacons. For a guest it
 *   is a site-wide constant per tick: it filters junk and authenticates nobody;
 *   the beacons' CSRF gate is the Origin check (#78).
 * - CORS is restricted namespace-wide by GTM4WP\RestCors at plugin level, not
 *   here, because this class is only registered when the mode is on (#97).
 * - The payload is a hex-flagged JSON string, so a hostile request header can
 *   never surface a raw break-out character; no-cache headers are sent.
 */
final class VisitorDataEndpoint {

	/**
	 * REST namespace of the endpoint. Defined once in RestCors, which also owns
	 * the namespace's cross-origin policy.
	 */
	public const REST_NAMESPACE = RestCors::REST_NAMESPACE;

	/**
	 * REST route of the endpoint (relative to the namespace).
	 */
	public const REST_ROUTE = '/visitor-data';

	/**
	 * Registers the session endpoint route. Hooked to rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_visitor_data' ),
				// Public: request-scoped, self-owned, read-only; each resolver is
				// its own identity gate (see the class docblock).
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET handler. Resolves the Tier 2/3 visitor-scoped fields for the current
	 * request and returns them as a hex-encoded JSON string payload with no-cache
	 * headers.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_visitor_data(): \WP_REST_Response {
		$data = $this->resolve( $this->declared_fields() );

		$response = new \WP_REST_Response(
			array(
				// Hex-flagged (RI-2), parsed back by the client; cast to object so an
				// empty map serializes as {} not [].
				'payload' => (string) wp_json_encode(
					(object) $data,
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
				),
				// FRESH nonce for the confirm beacons: the config nonce baked into a
				// cached page goes stale after a tick and would 403 the beacon.
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Per-visitor response: never cached, by the browser or an intermediary.
		foreach ( wp_get_nocache_headers() as $header_name => $header_value ) {
			$response->header( $header_name, $header_value );
		}
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * Collects the visitor-scoped fields every module declared through the
	 * GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS filter.
	 *
	 * @return array<int, mixed>
	 */
	private function declared_fields(): array {
		/** This filter is documented in VisitorDataModule::collect_client_fields(). */
		$fields = apply_filters( GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS, array() );

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Runs the Tier 2/3 resolvers for the current request into a flat key =>
	 * value map. A null result is omitted: that is how a field gates itself out.
	 *
	 * @param array<int, mixed> $fields The declared visitor-scoped fields.
	 * @return array<string, mixed>
	 */
	public function resolve( array $fields ): array {
		$data = array();

		foreach ( $fields as $field ) {
			if ( ! $field instanceof VisitorField ) {
				continue;
			}

			// Tier 1 is delivered client-side with no endpoint.
			if ( VisitorField::TIER_SESSION !== $field->tier && VisitorField::TIER_ACTION !== $field->tier ) {
				continue;
			}

			if ( null === $field->resolver || ! is_callable( $field->resolver ) ) {
				continue;
			}

			$value = call_user_func( $field->resolver );

			if ( null === $value ) {
				continue;
			}

			$data[ $field->key ] = $value;
		}

		return $data;
	}
}
