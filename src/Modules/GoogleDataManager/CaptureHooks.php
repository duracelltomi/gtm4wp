<?php
/**
 * Order-creation hooks of the attribution capture.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the captured attribution into order meta at order creation, on both
 * platforms: the one moment inside the buyer's own request (cookies readable)
 * that happens exactly once; a payment hook can fire from a gateway callback.
 * What to store is decided in AttributionCapture; this is the wiring plus the
 * two platform-specific writers.
 */
final class CaptureHooks {

	/**
	 * Builds the hook set.
	 *
	 * @param CaptureStats $stats Capture-rate counters.
	 */
	public function __construct( private CaptureStats $stats ) {
	}

	/**
	 * Registers the order-creation hooks of whichever platforms are active.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( function_exists( 'WC' ) ) {
			// Classic checkout, and the Store API the block checkout posts to
			// (a hook newer than the WooCommerce floor; it simply never fires there).
			add_action( 'woocommerce_checkout_order_created', array( $this, 'capture_woocommerce_order' ) );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_woocommerce_order' ) );
		}

		if ( function_exists( 'EDD' ) ) {
			add_action( 'edd_built_order', array( $this, 'capture_edd_order' ), 10, 2 );
		}
	}

	/**
	 * Stores the captured attribution on a WooCommerce order.
	 *
	 * @param mixed $order The order just created.
	 * @return void
	 */
	public function capture_woocommerce_order( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		// An order that already carries capture is left alone: a second pass
		// could only overwrite good data.
		if ( method_exists( $order, 'get_meta' ) && '' !== (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) ) {
			return;
		}

		$meta = AttributionCapture::meta_for_order( $order );

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		if ( array() !== $meta && method_exists( $order, 'save' ) ) {
			$order->save();
		}

		$this->stats->record( AttributionCapture::is_usable( $meta ) );
	}

	/**
	 * Stores the captured attribution on an Easy Digital Downloads order.
	 *
	 * @param int   $order_id   The order just built.
	 * @param array $order_data The data it was built from.
	 * @return void
	 */
	public function capture_edd_order( $order_id, $order_data = array() ): void {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 || ! function_exists( 'edd_update_order_meta' ) ) {
			return;
		}

		unset( $order_data );

		$meta = AttributionCapture::meta_for_order( $order_id );

		foreach ( $meta as $key => $value ) {
			edd_update_order_meta( $order_id, $key, $value );
		}

		$this->stats->record( AttributionCapture::is_usable( $meta ) );
	}
}
