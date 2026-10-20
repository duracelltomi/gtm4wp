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

use GTM4WP\Options\Options;

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
	 * @param CaptureStats $stats   Capture-rate counters.
	 * @param Options|null $options Plugin options, for the destination check; null skips it (tests).
	 */
	public function __construct( private CaptureStats $stats, private ?Options $options = null ) {
	}

	/**
	 * Whether there is anything to capture for: a destination deleted after a
	 * lane was saved leaves the lane on with nothing to send to (#484). Read
	 * at order time, not at boot, so a destinations filter a theme adds counts.
	 *
	 * @return bool
	 */
	private function has_destination(): bool {
		return null === $this->options || array() !== DestinationRows::rows( $this->options );
	}

	/**
	 * Wires the capture from Plugin::boot(), before the admin/frontend split:
	 * EDD's Stripe, PayPal Commerce and Square checkouts build the order on
	 * admin-ajax, where is_admin() is true (#467, U132). Self-gated like
	 * ReliablePurchase::register_flag_hooks(); no core path of either store
	 * creates an order through these hooks on a non-AJAX admin screen.
	 *
	 * @param Options $options The plugin options service.
	 * @return void
	 */
	public static function register_for( Options $options ): void {
		if ( GoogleDataManagerModule::capture_enabled( $options ) ) {
			( new self( new CaptureStats(), $options ) )->register_hooks();
		}
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
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) || ! $this->has_destination() ) {
			return;
		}

		// An order that already carries capture is left alone: a second pass
		// could only overwrite good data.
		if ( method_exists( $order, 'get_meta' ) && '' !== (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) ) {
			return;
		}

		// A resumed order (failed payment, then a retry) runs this again: the
		// newest pass replaces what the first stored, so a click id cannot sit
		// beside a newer denial, and the order is counted once (#481).
		$stored = array();

		if ( method_exists( $order, 'get_meta' ) ) {
			foreach ( AttributionCapture::meta_keys() as $key ) {
				if ( ! in_array( $order->get_meta( $key, true ), array( '', null, false ), true ) ) {
					$stored[] = $key;
				}
			}
		}

		$meta = AttributionCapture::meta_for_order( $order );

		// A pass that resolved nothing replaces nothing.
		foreach ( array() === $meta ? array() : array_diff( $stored, array_keys( $meta ) ) as $key ) {
			if ( method_exists( $order, 'delete_meta_data' ) ) {
				$order->delete_meta_data( $key );
			}
		}

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		if ( array() !== $meta && method_exists( $order, 'save' ) ) {
			$order->save();
		}

		if ( array() === $stored ) {
			$this->stats->record( AttributionCapture::is_usable( $meta ) );
		}
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

		if ( $order_id <= 0 || ! function_exists( 'edd_update_order_meta' ) || ! $this->has_destination() ) {
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
