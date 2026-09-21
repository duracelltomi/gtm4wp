<?php
/**
 * Receipt-page detection for the attribution backfill.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Modules\EasyDigitalDownloads\PageDataLayer as EddPageDataLayer;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the page being rendered is a confirmation page for an order
 * whose attribution is still missing (only the server can see order meta),
 * and what the capture script needs to post it: the order id, the route, a
 * nonce and the purchase proof ALREADY IN THE ADDRESS BAR (the WooCommerce
 * order key, the EDD payment key, or the verification hash EDD's own receipt
 * links carry instead). Nothing the visitor did not arrive holding is
 * printed; the rule is exact, the hash branch once printed the key (#250).
 */
final class ReceiptPage {

	/**
	 * The backfill configuration for this pageview, if one is needed.
	 *
	 * @return array<string, mixed>|null Null when this is not a receipt page, or the order already has attribution.
	 */
	public static function backfill_config(): ?array {
		$order = self::woocommerce_order();

		if ( null !== $order ) {
			return $order;
		}

		return self::edd_order();
	}

	/**
	 * The WooCommerce order-received page.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function woocommerce_order(): ?array {
		if ( ! function_exists( 'is_order_received_page' ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		if ( ! is_order_received_page() ) {
			return null;
		}

		// The order id and key come from the URL of the page being rendered -
		// the same pair WooCommerce itself validated to show this receipt.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the receipt URL of the page being rendered, not acting on a submission.
		$order_id = isset( $_GET['order-received'] ) ? absint( wp_unslash( $_GET['order-received'] ) ) : 0;

		if ( 0 === $order_id ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( 0 === $order_id || '' === $key ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_order_key' ) ) {
			return null;
		}

		if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}

		if ( '' !== (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) ) {
			return null;
		}

		return self::config( BackfillEndpoint::PLATFORM_WC, (string) $order_id, $key );
	}

	/**
	 * The Easy Digital Downloads success page.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function edd_order(): ?array {
		if ( ! function_exists( 'edd_is_success_page' ) || ! function_exists( 'edd_get_order_by' ) ) {
			return null;
		}

		if ( ! edd_is_success_page() ) {
			return null;
		}

		// Through EDD's own verified receipt chain, never a bare id.
		$payment_key = EddPageDataLayer::resolve_payment_key();

		if ( '' === $payment_key ) {
			return null;
		}

		// Whichever proof the URL itself carries. A key resolved from the
		// buyer's session is NOT printed (a durable secret in the HTML for any
		// third-party script); that visitor simply does not backfill.
		$token = self::edd_url_token( $payment_key );

		if ( '' === $token ) {
			return null;
		}

		$order = edd_get_order_by( 'payment_key', $payment_key );

		if ( ! is_object( $order ) ) {
			return null;
		}

		$order_id = (int) ( $order->id ?? 0 );

		if ( $order_id <= 0 ) {
			return null;
		}

		if ( function_exists( 'edd_get_order_meta' ) ) {
			$stored = edd_get_order_meta( $order_id, AttributionCapture::META_CLIENT_ID, true );

			if ( is_scalar( $stored ) && '' !== (string) $stored ) {
				return null;
			}
		}

		return self::config( BackfillEndpoint::PLATFORM_EDD, (string) $order_id, $token );
	}

	/**
	 * The purchase proof the page URL carries, or '': `?payment_key=` holds the
	 * key, EDD's receipt links hold `?id=` plus the `?order=` hash of it. Each
	 * branch hands over exactly what its URL holds; the hash is only reached
	 * once resolve_payment_key() verified it, and the route verifies it again.
	 *
	 * @param string $payment_key The resolved payment key.
	 * @return string The key, the verification hash, or ''.
	 */
	private static function edd_url_token( string $payment_key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the receipt URL of the page being rendered, not acting on a submission.
		if ( isset( $_GET['payment_key'] ) && sanitize_text_field( wp_unslash( $_GET['payment_key'] ) ) === $payment_key ) {
			return $payment_key;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		if ( ! empty( $_GET['order'] ) && ! empty( $_GET['id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			return sanitize_text_field( wp_unslash( $_GET['order'] ) );
		}

		return '';
	}

	/**
	 * The printed configuration object.
	 *
	 * @param string $platform Platform identifier.
	 * @param string $order    Order id.
	 * @param string $token    The purchase secret already present in the page URL.
	 * @return array<string, mixed>
	 */
	private static function config( string $platform, string $order, string $token ): array {
		return array(
			'url'      => rest_url( RestCors::REST_NAMESPACE . BackfillEndpoint::REST_ROUTE ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'platform' => $platform,
			'order'    => $order,
			'token'    => $token,
		);
	}
}
