<?php
/**
 * Post-order backfill route of the attribution capture.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\RequestOrigin;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * The safety net for the buyer whose only pageview was the checkout.
 *
 * The Google tag answers the ID lookups asynchronously, so a visitor who lands
 * straight on the checkout and submits quickly can create an order before the
 * callbacks ever fire. The confirmation page is the second chance: the script
 * resolves once more there and posts what it has, and this route writes it
 * into the order.
 *
 * It is a mutation exposed to logged-out visitors, so the guardrails are the
 * ones that class needs:
 *
 * - **Authorization is the buyer's own proof of purchase**, the same secret
 *   each platform already trusts to show the receipt: the WooCommerce order key
 *   or the Easy Digital Downloads payment key. The order id alone is never
 *   enough, because it is sequential and guessable.
 * - **Write only if absent.** A field already captured is never overwritten,
 *   so even a caller holding a valid key cannot replace real attribution with
 *   values of their choosing. Backfill can only fill a hole.
 * - **It discloses nothing.** The response carries no order data at all, so
 *   the route cannot be turned into an oracle for whether an order exists or
 *   what it contains.
 * - Values pass the same parser the cookies do, so there is one grammar for
 *   attribution however it arrives.
 */
final class BackfillEndpoint {

	/**
	 * Route path under the plugin's REST namespace.
	 */
	public const REST_ROUTE = '/google/attribution-backfill';

	/**
	 * Platform identifier of a WooCommerce order.
	 */
	public const PLATFORM_WC = 'wc';

	/**
	 * Platform identifier of an Easy Digital Downloads order.
	 */
	public const PLATFORM_EDD = 'edd';

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			RestCors::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'backfill' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'platform' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( self::PLATFORM_WC, self::PLATFORM_EDD ),
					),
					'order'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'token'    => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * A guest checkout is the normal case, so this cannot be a capability
	 * gate. The controls are the ones RequestOrigin documents: the REST nonce
	 * as a malformed-request filter, and the request Origin as the actual gate.
	 * Whether this particular caller may touch this particular order is a
	 * separate question, answered in the callback by the platform's own
	 * purchase proof - a permission callback that returns true for everyone
	 * must be followed by an identity check in code, and this one is.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public function check_permission( \WP_REST_Request $request ): bool {
		if ( ! RequestOrigin::has_rest_nonce( $request ) ) {
			return false;
		}

		return RequestOrigin::is_same_origin_request();
	}

	/**
	 * Writes the posted attribution into the order, where it is still missing.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function backfill( \WP_REST_Request $request ) {
		$platform = (string) $request->get_param( 'platform' );
		$order    = (string) $request->get_param( 'order' );
		$token    = (string) $request->get_param( 'token' );

		$values = AttributionCapture::parse_payload( (array) $request->get_param( 'values' ) );
		$this->add_consent( $request, $values );

		if ( array() === $values ) {
			// Nothing usable was posted. Answered like a success on purpose:
			// the client has nothing to do differently, and a distinct error
			// would tell an unauthorized caller their guess parsed.
			return new \WP_REST_Response( null, 204 );
		}

		$writer = self::PLATFORM_WC === $platform
			? $this->woocommerce_writer( $order, $token )
			: $this->edd_writer( $order, $token );

		if ( null === $writer ) {
			// One answer for "no such order", "wrong key" and "that platform
			// is not active here", so the route says nothing about which
			// orders exist.
			return new \WP_Error(
				'gtm4wp_gdm_backfill_denied',
				__( 'This order could not be verified.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 403 )
			);
		}

		$writer( $values );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Adds the posted consent state to the values, when it parses.
	 *
	 * @param \WP_REST_Request     $request The REST request.
	 * @param array<string, mixed> $values  Values to extend.
	 * @return void
	 */
	private function add_consent( \WP_REST_Request $request, array &$values ): void {
		$consent = AttributionCapture::parse_consent_payload( (array) $request->get_param( 'consent' ) );

		if ( null !== $consent ) {
			$values[ AttributionCapture::META_CONSENT_STATE ] = $consent;
		}
	}

	/**
	 * A writer for a WooCommerce order, once the caller has proven they hold
	 * the order key WooCommerce itself uses to authorize the receipt.
	 *
	 * @param string $order_id The order id.
	 * @param string $token    The order key.
	 * @return callable|null Null when the order cannot be verified.
	 */
	private function woocommerce_writer( string $order_id, string $token ): ?callable {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( absint( $order_id ) );

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_order_key' ) ) {
			return null;
		}

		// hash_equals: the comparison is against a secret, so it must not leak
		// through timing.
		if ( '' === $token || ! hash_equals( (string) $order->get_order_key(), $token ) ) {
			return null;
		}

		return static function ( array $values ) use ( $order ): void {
			$written = false;

			foreach ( $values as $key => $value ) {
				if ( self::already_present( $order->get_meta( $key, true ) ) ) {
					continue;
				}

				$order->update_meta_data( $key, $value );
				$written = true;
			}

			if ( $written ) {
				$order->save();
			}
		};
	}

	/**
	 * A writer for an Easy Digital Downloads order.
	 *
	 * The payment key IS the proof here: it is the secret EDD's own receipt
	 * links carry, and the order is looked up *by* it rather than by an id the
	 * request supplies, so an unguessable value is the only way in.
	 *
	 * @param string $order_id The order id, used only to confirm the key belongs to it.
	 * @param string $token    The payment key.
	 * @return callable|null Null when the order cannot be verified.
	 */
	private function edd_writer( string $order_id, string $token ): ?callable {
		if ( ! function_exists( 'edd_get_order_by' ) || ! function_exists( 'edd_update_order_meta' ) ) {
			return null;
		}

		if ( '' === $token ) {
			return null;
		}

		$order = edd_get_order_by( 'payment_key', $token );

		if ( ! is_object( $order ) ) {
			return null;
		}

		$resolved_id = (int) ( $order->id ?? 0 );

		$requested_id = absint( $order_id );

		if ( 0 >= $resolved_id || $requested_id !== $resolved_id ) {
			return null;
		}

		return static function ( array $values ) use ( $resolved_id ): void {
			foreach ( $values as $key => $value ) {
				if ( self::already_present( edd_get_order_meta( $resolved_id, $key, true ) ) ) {
					continue;
				}

				edd_update_order_meta( $resolved_id, $key, $value );
			}
		};
	}

	/**
	 * Whether a stored meta value counts as already captured.
	 *
	 * The write-only-if-absent rule has to hold for every value shape the
	 * capture writes, and two of them are arrays (the session map and the
	 * consent state). Casting those to a string to test for emptiness both
	 * warns and answers the wrong question, so the shapes are handled
	 * explicitly.
	 *
	 * @param mixed $existing The stored value.
	 * @return bool
	 */
	private static function already_present( $existing ): bool {
		if ( is_array( $existing ) ) {
			return array() !== $existing;
		}

		if ( null === $existing || false === $existing ) {
			return false;
		}

		return is_scalar( $existing ) && '' !== (string) $existing;
	}
}
