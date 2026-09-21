<?php
/**
 * Easy Digital Downloads refund adapter.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Modules\EasyDigitalDownloads\DownloadData;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Easy Digital Downloads refunds into the platform-neutral shape. EDD
 * 3.x models a refund as a child order of type `refund` with negated
 * amounts and negated item copies, so the same abs()-once rule as WooCommerce
 * applies (U132).
 *
 * ⚠ Do NOT read `edd_refund_order`'s third argument `$all_refunded`: it means
 * "the parent is now fully refunded", true for the last slice of a sequence,
 * and deciding from it would reverse the whole transaction on top of the
 * slices already reversed. RefundEvent decides from amounts.
 */
final class EddRefunds implements RefundSource {

	/**
	 * The `type` an EDD refund order carries.
	 */
	private const ORDER_TYPE_REFUND = 'refund';

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function platform(): string {
		return self::PLATFORM_EDD;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return function_exists( 'EDD' ) && function_exists( 'edd_get_order' );
	}

	/**
	 * Registers the refund hook. The callback takes only two arguments on
	 * purpose: the third is the trap in the class docblock.
	 *
	 * @param callable $enqueue Receives ( int $order_id, int $refund_id ).
	 * @return void
	 */
	public function register_hooks( callable $enqueue ): void {
		add_action(
			'edd_refund_order',
			static function ( $order_id, $refund_id ) use ( $enqueue ) {
				$enqueue( (int) $order_id, (int) $refund_id );
			},
			10,
			2
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id  The parent order.
	 * @param int $refund_id The refund order.
	 * @return RefundData|null
	 */
	public function load( int $order_id, int $refund_id ): ?RefundData {
		list( $refund, $order ) = $this->objects( $order_id, $refund_id );

		if ( ! ( $order instanceof \EDD\Orders\Order ) || ! ( $refund instanceof \EDD\Orders\Order ) ) {
			return null;
		}

		// The refund must belong to this order (both come from the same table).
		if ( self::ORDER_TYPE_REFUND !== (string) DownloadData::row_prop( $refund, 'type' ) ) {
			return null;
		}

		if ( (int) DownloadData::row_prop( $refund, 'parent', 0 ) !== $order_id ) {
			return null;
		}

		$prefix  = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDTRANSACTIONIDPREFIX );
		$consent = edd_get_order_meta( $order_id, AttributionCapture::META_CONSENT_STATE, true );

		return new RefundData(
			$this->platform(),
			$order_id,
			$refund_id,
			// The parent order's number, as the purchase event reported it.
			$prefix . $order->get_number(),
			(string) DownloadData::row_prop( $order, 'currency' ),
			abs( (float) DownloadData::row_prop( $refund, 'total', 0 ) ),
			(float) DownloadData::row_prop( $order, 'total', 0 ),
			self::timestamp( $refund ),
			$this->items( $refund ),
			(string) edd_get_order_meta( $order_id, AttributionCapture::META_CLIENT_ID, true ),
			is_array( $consent ) ? $consent : null,
			self::billing_country( $order ),
			// EDD has no shipping; the tax is negated on the refund order.
			0.0,
			abs( (float) DownloadData::row_prop( $refund, 'tax', 0 ) )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id  The parent order.
	 * @param int $refund_id The refund order.
	 * @return array{0: mixed, 1: mixed}
	 */
	public function objects( int $order_id, int $refund_id ): array {
		if ( ! function_exists( 'edd_get_order' ) ) {
			return array( null, null );
		}

		$refund = ( $refund_id > 0 ) ? edd_get_order( $refund_id ) : null;
		$order  = ( $order_id > 0 ) ? edd_get_order( $order_id ) : null;

		return array(
			is_object( $refund ) ? $refund : null,
			is_object( $order ) ? $order : null,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $refund_id The refund order.
	 * @return bool
	 */
	public function is_sent( int $refund_id ): bool {
		if ( ! function_exists( 'edd_get_order_meta' ) ) {
			return false;
		}

		return '' !== (string) edd_get_order_meta( $refund_id, self::META_SENT, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $refund_id  The refund order.
	 * @param string $request_id The API request id.
	 * @return void
	 */
	public function mark_sent( int $refund_id, string $request_id ): void {
		if ( ! function_exists( 'edd_update_order_meta' ) ) {
			return;
		}

		edd_update_order_meta( $refund_id, self::META_SENT, ( '' !== $request_id ) ? $request_id : '1' );
	}

	/**
	 * The refunded lines, as positive quantities and unit prices. Item ids come
	 * from DownloadData::process_download(), the purchase event's builder (see
	 * the WooCommerce sibling for why).
	 *
	 * @param \EDD\Orders\Order $refund The refund order.
	 * @return array<int, array<string, mixed>> Items in the API shape, see RefundEvent::item().
	 */
	private function items( \EDD\Orders\Order $refund ): array {
		$download_data = new DownloadData( $this->options );
		$items         = array();
		$exclude_tax   = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX );

		foreach ( (array) $refund->get_items() as $item ) {
			$quantity = (int) abs( (int) DownloadData::row_prop( $item, 'quantity', 0 ) );

			if ( $quantity <= 0 ) {
				continue;
			}

			// Both negated, so subtract the tax BEFORE abs(), as the purchase
			// builder does on the positive originals.
			$line_total = (float) DownloadData::row_prop( $item, 'total', 0 );

			if ( $exclude_tax ) {
				$line_total -= (float) DownloadData::row_prop( $item, 'tax', 0 );
			}

			$unit_price = round( abs( $line_total ) / $quantity, 2 );
			$price_id   = DownloadData::row_prop( $item, 'price_id', null );

			$attributes = array(
				'quantity' => $quantity,
				'price'    => $unit_price,
			);

			// The per-unit discount as the purchase item reports it; negated on
			// the refund order item like every other amount there.
			$line_discount = round( abs( (float) DownloadData::row_prop( $item, 'discount', 0 ) ) / $quantity, 2 );

			if ( $line_discount > 0 ) {
				$attributes['discount'] = $line_discount;
			}

			$built = $download_data->process_download(
				(int) DownloadData::row_prop( $item, 'product_id', 0 ),
				$attributes,
				'refund',
				$item,
				is_numeric( $price_id ) ? (int) $price_id : null
			);

			if ( ! is_array( $built ) || ! isset( $built['item_id'] ) ) {
				continue;
			}

			$items[] = RefundEvent::item( $built, $unit_price, $quantity );
		}

		return $items;
	}

	/**
	 * Unix time of an EDD order row (`date_created` is a UTC MySQL datetime, so
	 * the zone is stated explicitly); an unreadable value falls back to now.
	 *
	 * @param \EDD\Orders\Order $order The order row.
	 * @return int
	 */
	private static function timestamp( \EDD\Orders\Order $order ): int {
		$created = (string) DownloadData::row_prop( $order, 'date_created' );

		if ( '' === $created ) {
			return time();
		}

		$timestamp = strtotime( $created . ' UTC' );

		return ( false === $timestamp ) ? time() : (int) $timestamp;
	}

	/**
	 * Two-letter billing country of an EDD order, empty when it has none.
	 *
	 * @param \EDD\Orders\Order $order The order.
	 * @return string
	 */
	private static function billing_country( \EDD\Orders\Order $order ): string {
		if ( ! method_exists( $order, 'get_address' ) ) {
			return '';
		}

		return (string) DownloadData::row_prop( $order->get_address(), 'country' );
	}
}
