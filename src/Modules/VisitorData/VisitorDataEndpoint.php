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

defined( 'ABSPATH' ) || exit;

/**
 * The Phase 2 delivery channel for the server-only visitor fields (Tier 2/3):
 * a first-party REST route that returns the visitor-scoped fields for the
 * CURRENT request only. The client runtime fetches it once per session (Tier 2:
 * IP, Cloudflare country) or when a gating cookie changed (Tier 3: logged-in
 * user data, one-shot events), so a cached page never carries the data and an
 * anonymous visitor never fetches user data.
 *
 * Security model:
 *
 * - Everything is derived from the current request context — wp_get_current_user(),
 *   WC()->session, $_SERVER. The route accepts NO user/session id parameter, so
 *   there is no IDOR: a caller can only ever receive its own request's data.
 * - Each field's resolver is its own identity gate. A logged-in-user field's
 *   resolver returns null for an anonymous request (is_user_logged_in() is false
 *   because no valid auth cookie + REST nonce authenticated it), so a logged-out
 *   request receives NO user data. The permission callback is therefore public:
 *   the response is request-scoped and self-owned.
 * - READ-ONLY. This GET changes no state, so it has no CSRF surface and needs no
 *   nonce to authorize the caller. It is genuinely side-effect-free: the one-shot
 *   resolvers READ their session markers but never consume them — the delivered
 *   event's state change (marker consumption, and the purchase's _ga_tracked write)
 *   happens on a separate AUTHENTICATED POST beacon (PageDataLayer's
 *   confirm_* routes). A GET that mutated session state could be fired by any
 *   cross-site top-level navigation carrying the visitor's SameSite=Lax cookies,
 *   which would silently destroy a real buyer's pending event.
 * - For a logged-in caller the client DOES send the wp_rest nonce (per WP REST
 *   conventions) so WordPress authenticates the auth cookie and the user fields
 *   resolve; a logged-in page is never full-page cached, so that baked nonce is
 *   fresh. An anonymous caller sends NO nonce (nothing to authenticate), so a
 *   stale nonce baked into a long-lived cached page can never 403 the read.
 * - The response carries a FRESH wp_rest nonce for the client's one-shot confirm
 *   beacons, so a beacon fired from a cached page still authenticates.
 * - The response is serialized with the full JSON_HEX_* flag set and returned as
 *   a string payload (mirroring the Store API cart-item pattern in StoreApiData),
 *   so a hostile request header (a </script>"&'-laced X-Forwarded-For or CF
 *   country) can never surface a raw break-out character in the payload.
 * - no-cache headers are sent so the per-visitor response is itself never cached.
 */
final class VisitorDataEndpoint {

	/**
	 * REST namespace of the endpoint.
	 */
	public const REST_NAMESPACE = 'gtm4wp/v2';

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
				// Public: the route returns only request-scoped, self-owned data and
				// changes no state. Each field's resolver enforces its own identity
				// gate (a user field yields nothing to an anonymous request), so there
				// is nothing to authorize here beyond WordPress' own cookie+nonce
				// resolution of the current user.
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
				// The actual visitor data is serialized here with the full hex flag
				// set (RI-2) and parsed back by the client, so the request-header
				// values it carries never reach the client as a raw break-out char.
				// Cast to object so an empty map still serializes as {} (not []).
				'payload' => (string) wp_json_encode(
					(object) $data,
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
				),
				// A FRESH wp_rest nonce for the client's one-shot confirm beacons.
				// The config nonce is baked into (potentially long-lived cached) page
				// HTML and goes stale after a nonce tick (~24h), which would 403 the
				// beacon and silently restore the cross-device double-count. This one
				// is generated per request, so it is always valid when the client uses
				// it moments later. The GET itself needs no nonce for an anonymous
				// caller (a nonce is not sent on that path — see the client runtime),
				// so a stale baked nonce never blocks the read.
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// The response is specific to this visitor/request, so it must never be
		// cached — not by the browser and not by an intermediary.
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
	 * Runs the Tier 2/3 field resolvers for the current request and returns the
	 * flat data-layer key => value map. A resolver that returns null is omitted —
	 * that is how a field gates itself out (e.g. a user field on an anonymous
	 * request, so a logged-out caller receives no user data).
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

			// Tier 1 is delivered client-side with no endpoint; only the server-only
			// tiers are resolved here.
			if ( VisitorField::TIER_SESSION !== $field->tier && VisitorField::TIER_ACTION !== $field->tier ) {
				continue;
			}

			if ( null === $field->resolver || ! is_callable( $field->resolver ) ) {
				continue;
			}

			$value = call_user_func( $field->resolver );

			// null = the field gated itself out for this request (logged-out user,
			// header absent): omit it entirely.
			if ( null === $value ) {
				continue;
			}

			$data[ $field->key ] = $value;
		}

		return $data;
	}
}
