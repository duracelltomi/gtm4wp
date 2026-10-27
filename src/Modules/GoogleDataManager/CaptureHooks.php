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
 * platforms: a moment inside the request that places or pays the order
 * (cookies readable); a payment hook can fire from a gateway callback. A
 * resumed order (a failed payment retried, also through WooCommerce's
 * pay-for-order route) runs the pass again, so each pass merges onto the last
 * (pass()): whoever pays the order counts as the buyer (#598). What to store
 * is decided in AttributionCapture.
 */
final class CaptureHooks {

	/**
	 * Order meta marking the order as counted in CaptureStats, holding one of
	 * the COUNTED_* values. Not personal data, so not in AttributionCapture::meta_keys().
	 */
	public const META_COUNTED = '_gtm4wp_capture_counted';

	/** META_COUNTED value: counted uncaptured (a later client id is a rescue). */
	public const COUNTED_UNCAPTURED = '0';

	/** META_COUNTED value: counted captured. */
	public const COUNTED_CAPTURED = '1';

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
	 * Reads a stored META_COUNTED value: '' when never counted, else a COUNTED_* value.
	 *
	 * @param mixed $marker The stored meta value.
	 * @return string
	 */
	public static function counted_state( $marker ): string {
		return is_scalar( $marker ) ? (string) $marker : '';
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
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'get_meta' ) || ! $this->has_destination() ) {
			return;
		}

		$changed = $this->pass(
			static fn ( string $key ) => $order->get_meta( $key, true ),
			static function ( string $key, $value ) use ( $order ): void {
				$order->update_meta_data( $key, $value );
			},
			static function ( string $key ) use ( $order ): void {
				if ( method_exists( $order, 'delete_meta_data' ) ) {
					$order->delete_meta_data( $key );
				}
			},
			$order
		);

		if ( $changed && method_exists( $order, 'save' ) ) {
			$order->save();
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

		if ( $order_id <= 0 || ! function_exists( 'edd_update_order_meta' ) || ! function_exists( 'edd_get_order_meta' ) || ! $this->has_destination() ) {
			return;
		}

		unset( $order_data );

		$this->pass(
			static fn ( string $key ) => edd_get_order_meta( $order_id, $key, true ),
			static function ( string $key, $value ) use ( $order_id ): void {
				edd_update_order_meta( $order_id, $key, $value );
			},
			static function ( string $key ) use ( $order_id ): void {
				if ( function_exists( 'edd_delete_order_meta' ) ) {
					edd_delete_order_meta( $order_id, $key );
				}
			},
			$order_id
		);
	}

	/**
	 * One capture pass, either store (#534-#536). Consent merges per signal
	 * (AttributionCapture::merge_consent()), and the merged state gates this
	 * pass's ids and the stored ones. An id group this pass resolved replaces
	 * the stored group whole, so a new client id never sits beside the old
	 * client's sessions; a group it did not resolve is kept, so an empty pass
	 * changes nothing, and session ids without a client id never displace a
	 * stored client id (#599). The order is counted once, a later first client
	 * id as a rescue (META_COUNTED); orders stored before the marker count as
	 * counted.
	 *
	 * @param callable $get             Reads a meta value: fn( string $key ): mixed.
	 * @param callable $set             Writes one: fn( string $key, mixed $value ): void.
	 * @param callable $delete          Deletes one: fn( string $key ): void.
	 * @param mixed    $order_reference The WC_Order, or the EDD order id.
	 * @return bool Whether anything was written or deleted.
	 */
	private function pass( callable $get, callable $set, callable $delete, $order_reference ): bool {
		$stored = array();

		foreach ( AttributionCapture::meta_keys() as $key ) {
			$value = $get( $key );

			if ( ! in_array( $value, array( '', null, false ), true ) ) {
				$stored[ $key ] = $value;
			}
		}

		$meta    = AttributionCapture::meta_for_order( $order_reference );
		$consent = AttributionCapture::merge_consent(
			is_array( $stored[ AttributionCapture::META_CONSENT_STATE ] ?? null ) ? $stored[ AttributionCapture::META_CONSENT_STATE ] : null,
			is_array( $meta[ AttributionCapture::META_CONSENT_STATE ] ?? null ) ? $meta[ AttributionCapture::META_CONSENT_STATE ] : null
		);

		unset( $meta[ AttributionCapture::META_CONSENT_STATE ] );
		$meta = AttributionCapture::apply_consent_gate( $meta, $consent );

		// Sessions alone may be another browser's: never beside a stored client id.
		if ( ! isset( $meta[ AttributionCapture::META_CLIENT_ID ] ) && isset( $stored[ AttributionCapture::META_CLIENT_ID ] ) ) {
			unset( $meta[ AttributionCapture::META_SESSION_IDS ] );
		}

		$kept = $stored;
		unset( $kept[ AttributionCapture::META_CONSENT_STATE ] );

		foreach ( AttributionCapture::signal_groups() as $keys ) {
			if ( array() !== array_intersect_key( $meta, array_flip( $keys ) ) ) {
				$kept = array_diff_key( $kept, array_flip( $keys ) );
			}
		}

		$final = $meta + AttributionCapture::apply_consent_gate( $kept, $consent );

		if ( null !== $consent ) {
			$final[ AttributionCapture::META_CONSENT_STATE ] = $consent;
		}

		$changed = false;

		foreach ( array_keys( array_diff_key( $stored, $final ) ) as $key ) {
			$delete( $key );
			$changed = true;
		}

		foreach ( $final as $key => $value ) {
			if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $value ) {
				$set( $key, $value );
				$changed = true;
			}
		}

		$counted = self::counted_state( $get( self::META_COUNTED ) );
		$usable  = AttributionCapture::is_usable( $final );

		if ( '' === $counted ) {
			if ( array() === $stored ) {
				$this->stats->record( $usable );
			}

			$set( self::META_COUNTED, $usable ? self::COUNTED_CAPTURED : self::COUNTED_UNCAPTURED );
			$changed = true;
		} elseif ( self::COUNTED_UNCAPTURED === $counted && $usable ) {
			$this->stats->record_rescue();
			$set( self::META_COUNTED, self::COUNTED_CAPTURED );
			$changed = true;
		}

		return $changed;
	}
}
