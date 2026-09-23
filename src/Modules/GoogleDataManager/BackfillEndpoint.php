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

use GTM4WP\Modules\EasyDigitalDownloads\PageDataLayer as EddPageDataLayer;
use GTM4WP\RequestOrigin;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * The safety net for a buyer whose only pageview was the checkout: the Google
 * tag answers the ID lookups asynchronously, so the receipt page resolves
 * once more and posts what it has here. A guest-facing mutation, so:
 *
 * - **Authorization is the buyer's proof of purchase**: the WooCommerce order
 *   key, or EDD's payment key / its verification hash. The order id alone is
 *   sequential and guessable.
 * - **Write only if absent**: a valid key cannot replace real attribution.
 * - **The stored consent record wins**: identifiers it forbids are dropped
 *   whatever the posted map says (#249).
 * - **It discloses nothing**: the response carries no order data, so the
 *   route is no oracle for whether an order exists.
 * - Values pass the same parser the cookies do (one grammar).
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
	 * Permission callback. Not a capability gate (guest checkout); the
	 * authorization is the purchase proof checked in the callback (PA-10). The
	 * nonce and Origin checks are filters in front of it, not the gate: this
	 * route consults no cookie, so a non-browser client setting Origin freely
	 * proves nothing.
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
	public function backfill( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$platform = (string) $request->get_param( 'platform' );
		$order    = (string) $request->get_param( 'order' );
		$token    = (string) $request->get_param( 'token' );

		// Verification first, before the payload is considered: every refusal
		// must look the same, or the status code tests guessed order/key pairs.
		$verified = self::PLATFORM_WC === $platform
			? $this->woocommerce_writer( $order, $token )
			: $this->edd_writer( $order, $token );

		if ( null === $verified ) {
			// One answer for no such order, wrong key and inactive platform.
			return new \WP_Error(
				'gtm4wp_gdm_backfill_denied',
				__( 'This order could not be verified.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 403 )
			);
		}

		list( $writer, $order_reference ) = $verified;

		$values = AttributionCapture::parse_payload( (array) $request->get_param( 'values' ) );

		// The posted consent map goes through the site's override filter like
		// the cookie one at order creation (see AttributionCapture::filter_consent),
		// with the RESOLVED order reference, never the request's raw string (RI-31).
		$consent = AttributionCapture::filter_consent(
			AttributionCapture::parse_consent_payload( (array) $request->get_param( 'consent' ) ),
			$order_reference
		);

		$values = AttributionCapture::apply_consent_gate( $values, $consent );

		if ( null !== $consent ) {
			$values[ AttributionCapture::META_CONSENT_STATE ] = $consent;
		}

		if ( array() !== $values ) {
			$writer( $values );
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Drops the identifiers the order's STORED consent record forbids (the gate
	 * in backfill() ran against the posted map): a buyer who refused at
	 * checkout and posted identifiers on the receipt page must not have them
	 * stored beside a record that says denied (#249).
	 *
	 * @param array<string, mixed> $values The meta about to be written.
	 * @param mixed                $stored The order's stored consent state, as read from meta.
	 * @return array<string, mixed>
	 */
	private static function honour_stored_consent( array $values, $stored ): array {
		return AttributionCapture::apply_consent_gate( $values, is_array( $stored ) ? $stored : null );
	}

	/**
	 * A writer for a WooCommerce order, once the caller has proven they hold
	 * the order key WooCommerce itself uses to authorize the receipt.
	 *
	 * @param string $order_id The order id.
	 * @param string $token    The order key.
	 * @return array{0: callable, 1: \WC_Order}|null The writer and the resolved order, or null when the order cannot be verified.
	 */
	private function woocommerce_writer( string $order_id, string $token ): ?array {
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

		$writer = static function ( array $values ) use ( $order ): void {
			$written = false;
			$values  = self::honour_stored_consent( $values, $order->get_meta( AttributionCapture::META_CONSENT_STATE, true ) );

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

		return array( $writer, $order );
	}

	/**
	 * A writer for an Easy Digital Downloads order. The proof is whichever
	 * secret the receipt URL carried: the payment key (the order is looked up
	 * BY it) or, on EDD's own receipt links, the verification hash checked
	 * against the order the id names. The receipt page never prints a secret
	 * its URL does not already hold (#250).
	 *
	 * @param string $order_id The order id; the record the hash is checked against, or a cross-check on the key.
	 * @param string $token    The payment key, or the receipt-link verification hash.
	 * @return array{0: callable, 1: int}|null The writer and the resolved order id, or null when the order cannot be verified.
	 */
	private function edd_writer( string $order_id, string $token ): ?array {
		if ( ! function_exists( 'edd_get_order_by' )
			|| ! function_exists( 'edd_get_order' )
			|| ! function_exists( 'edd_get_order_meta' )
			|| ! function_exists( 'edd_update_order_meta' ) ) {
			return null;
		}

		if ( '' === $token ) {
			return null;
		}

		$requested_id = absint( $order_id );
		$resolved_id  = 0;

		$order = edd_get_order_by( 'payment_key', $token );

		if ( is_object( $order ) ) {
			$resolved_id = (int) ( $order->id ?? 0 );
		} elseif ( $requested_id > 0 ) {
			// A receipt-link hash: a digest of the order's own key, so a guessed
			// id still needs the key.
			$by_id = edd_get_order( $requested_id );

			if ( $by_id instanceof \EDD\Orders\Order && EddPageDataLayer::receipt_hash_matches( $by_id, $token ) ) {
				$resolved_id = $requested_id;
			}
		}

		if ( 0 >= $resolved_id || $requested_id !== $resolved_id ) {
			return null;
		}

		$writer = static function ( array $values ) use ( $resolved_id ): void {
			$values = self::honour_stored_consent( $values, edd_get_order_meta( $resolved_id, AttributionCapture::META_CONSENT_STATE, true ) );

			foreach ( $values as $key => $value ) {
				if ( self::already_present( edd_get_order_meta( $resolved_id, $key, true ) ) ) {
					continue;
				}

				edd_update_order_meta( $resolved_id, $key, $value );
			}
		};

		return array( $writer, $resolved_id );
	}

	/**
	 * Whether a stored meta value counts as already captured; two of the
	 * shapes are arrays (session map, consent state), handled explicitly.
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
