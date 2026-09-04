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
 * whose attribution is still missing, and if so what the capture script needs
 * in order to post it.
 *
 * The flag has to come from the server because the client cannot see order
 * meta: only this side knows whether the capture already succeeded. That is
 * also why it is not simply "always try" - a backfill POST on every receipt
 * view would be a write attempt per pageview for nothing.
 *
 * What it prints is deliberately minimal: the order id and the purchase secret
 * that is **already in the address bar** of the page being rendered, plus the
 * route and a nonce. Nothing is disclosed that the visitor did not arrive
 * holding, which is why the receipt-visibility rules that govern showing the
 * buyer's name and address do not apply here - this discloses no order data at
 * all.
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

		// Resolved through EDD's own verified receipt chain rather than from a
		// bare id: the payment key, or an id plus the matching receipt hash,
		// or the buyer's own purchase session.
		$payment_key = EddPageDataLayer::resolve_payment_key();

		if ( '' === $payment_key ) {
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

		return self::config( BackfillEndpoint::PLATFORM_EDD, (string) $order_id, $payment_key );
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
