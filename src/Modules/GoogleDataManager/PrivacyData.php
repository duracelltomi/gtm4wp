<?php
/**
 * Personal-data export and erasure for the captured attribution.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the `_gtm4wp_*` attribution meta into WordPress' personal-data export
 * and erasure requests, for both platforms. Uninstall leaves the meta behind,
 * so the eraser is the per-person removal path. Registered unconditionally: a
 * request must find data captured while the feature was on. The key list is
 * AttributionCapture::meta_keys(), the writers' own, so a new field cannot
 * escape an erasure that reported success.
 */
final class PrivacyData {

	/**
	 * Identifier of this exporter/eraser in a request's progress report.
	 */
	public const GROUP_ID = 'gtm4wp-google-attribution';

	/**
	 * Orders looked at per page of a request.
	 */
	public const PER_PAGE = 20;

	/**
	 * Registers the exporter and eraser.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Adds this exporter to the request.
	 *
	 * @param array<string, mixed> $exporters Registered exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			return $exporters;
		}

		$exporters[ self::GROUP_ID ] = array(
			'exporter_friendly_name' => __( 'Google attribution data stored with orders', 'duracelltomi-google-tag-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds this eraser to the request.
	 *
	 * @param array<string, mixed> $erasers Registered erasers.
	 * @return array<string, mixed>
	 */
	public function register_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			return $erasers;
		}

		$erasers[ self::GROUP_ID ] = array(
			'eraser_friendly_name' => __( 'Google attribution data stored with orders', 'duracelltomi-google-tag-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the attribution stored against this person's orders.
	 *
	 * @param string $email_address The person the request is about.
	 * @param int    $page          One-based page number.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email_address, $page = 1 ) {
		$page   = max( 1, (int) $page );
		$orders = $this->orders_for( (string) $email_address, $page );
		$data   = array();

		foreach ( $orders as $order ) {
			$items = array();

			foreach ( AttributionCapture::meta_keys() as $key ) {
				$value = $this->read_meta( $order, $key );

				if ( null === $value ) {
					continue;
				}

				$items[] = array(
					'name'  => self::label_for( $key ),
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				);
			}

			if ( array() === $items ) {
				continue;
			}

			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Google attribution data stored with orders', 'duracelltomi-google-tag-manager' ),
				'item_id'     => self::GROUP_ID . '-' . $order['platform'] . '-' . $order['id'],
				'data'        => $items,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * Removes the attribution stored against this person's orders.
	 *
	 * @param string $email_address The person the request is about.
	 * @param int    $page          One-based page number.
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase( $email_address, $page = 1 ) {
		$page    = max( 1, (int) $page );
		$orders  = $this->orders_for( (string) $email_address, $page );
		$removed = false;

		foreach ( $orders as $order ) {
			$deleted = false;

			foreach ( AttributionCapture::meta_keys() as $key ) {
				if ( null === $this->read_meta( $order, $key ) ) {
					continue;
				}

				$this->delete_meta( $order, $key );
				$deleted = true;
				$removed = true;
			}

			if ( $deleted ) {
				$this->persist( $order );
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * One page of this person's orders, across both platforms.
	 *
	 * @param string $email_address The person the request is about.
	 * @param int    $page          One-based page number.
	 * @return array<int, array{platform: string, id: int, object: mixed}>
	 */
	private function orders_for( string $email_address, int $page ): array {
		if ( '' === $email_address ) {
			return array();
		}

		$orders = array();

		if ( function_exists( 'wc_get_orders' ) ) {
			$found = wc_get_orders(
				array(
					'billing_email' => $email_address,
					'limit'         => self::PER_PAGE,
					'paged'         => $page,
					'type'          => 'shop_order',
				)
			);

			foreach ( is_array( $found ) ? $found : array() as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
					continue;
				}

				$orders[] = array(
					'platform' => BackfillEndpoint::PLATFORM_WC,
					'id'       => (int) $order->get_id(),
					'object'   => $order,
				);
			}
		}

		if ( function_exists( 'edd_get_orders' ) ) {
			$found = edd_get_orders(
				array(
					'email'  => $email_address,
					'number' => self::PER_PAGE,
					'offset' => ( $page - 1 ) * self::PER_PAGE,
				)
			);

			foreach ( is_array( $found ) ? $found : array() as $order ) {
				if ( ! is_object( $order ) ) {
					continue;
				}

				$orders[] = array(
					'platform' => BackfillEndpoint::PLATFORM_EDD,
					'id'       => (int) ( $order->id ?? 0 ),
					'object'   => $order,
				);
			}
		}

		return $orders;
	}

	/**
	 * Reads one attribution meta from an order of either platform.
	 *
	 * @param array{platform: string, id: int, object: mixed} $order The order.
	 * @param string                                          $key   Meta key.
	 * @return mixed|null Null when the order does not carry it.
	 */
	private function read_meta( array $order, string $key ) {
		$value = '';

		if ( BackfillEndpoint::PLATFORM_WC === $order['platform'] ) {
			if ( ! method_exists( $order['object'], 'get_meta' ) ) {
				return null;
			}

			$value = $order['object']->get_meta( $key, true );
		} elseif ( function_exists( 'edd_get_order_meta' ) ) {
			$value = edd_get_order_meta( $order['id'], $key, true );
		}

		if ( is_array( $value ) ) {
			return array() === $value ? null : $value;
		}

		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Deletes one attribution meta from an order of either platform.
	 *
	 * @param array{platform: string, id: int, object: mixed} $order The order.
	 * @param string                                          $key   Meta key.
	 * @return void
	 */
	private function delete_meta( array $order, string $key ): void {
		if ( BackfillEndpoint::PLATFORM_WC === $order['platform'] ) {
			if ( method_exists( $order['object'], 'delete_meta_data' ) ) {
				$order['object']->delete_meta_data( $key );
			}

			return;
		}

		if ( function_exists( 'edd_delete_order_meta' ) ) {
			edd_delete_order_meta( $order['id'], $key );
		}
	}

	/**
	 * Writes an order's staged deletions once per order (WooCommerce stages
	 * delete_meta_data() until save(); EDD writes directly).
	 *
	 * @param array{platform: string, id: int, object: mixed} $order The order.
	 * @return void
	 */
	private function persist( array $order ): void {
		if ( BackfillEndpoint::PLATFORM_WC === $order['platform'] && method_exists( $order['object'], 'save' ) ) {
			$order['object']->save();
		}
	}

	/**
	 * A human label for one meta key.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	private static function label_for( string $key ): string {
		switch ( $key ) {
			case AttributionCapture::META_CLIENT_ID:
				return __( 'Google Analytics client ID', 'duracelltomi-google-tag-manager' );

			case AttributionCapture::META_SESSION_IDS:
				return __( 'Google Analytics session IDs', 'duracelltomi-google-tag-manager' );

			case AttributionCapture::META_CONSENT_STATE:
				return __( 'Consent state at the time of the order', 'duracelltomi-google-tag-manager' );

			default:
				return sprintf(
					/* translators: %s: the name of a Google Ads click ID parameter, for example gclid. */
					__( 'Google Ads click ID (%s)', 'duracelltomi-google-tag-manager' ),
					str_replace( AttributionCapture::META_CLICK_ID_PREFIX, '', $key )
				);
		}
	}
}
