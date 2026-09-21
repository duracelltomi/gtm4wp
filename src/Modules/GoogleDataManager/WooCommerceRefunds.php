<?php
/**
 * WooCommerce refund adapter.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WooCommerce refunds (WC_Order_Refund: an order-ish child object with
 * negated line quantities and totals) into the platform-neutral shape. Item
 * ids come from ProductData::process_product(), the purchase event's own
 * builder: the id is the join key Analytics matches a refunded line by, and
 * it depends on store settings (use SKU, master language) a second
 * implementation would have to mirror forever.
 */
final class WooCommerceRefunds implements RefundSource {

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
		return self::PLATFORM_WOOCOMMERCE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return function_exists( 'WC' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Registers the refund hook.
	 *
	 * @param callable $enqueue Receives ( int $order_id, int $refund_id ).
	 * @return void
	 */
	public function register_hooks( callable $enqueue ): void {
		add_action(
			'woocommerce_order_refunded',
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
	 * @param int $refund_id The refund.
	 * @return RefundData|null
	 */
	public function load( int $order_id, int $refund_id ): ?RefundData {
		list( $refund, $order ) = $this->objects( $order_id, $refund_id );

		if ( ! ( $order instanceof \WC_Order ) || ! ( $refund instanceof \WC_Order_Refund ) ) {
			return null;
		}

		// The refund must belong to this order: wc_get_order() serves both
		// kinds, and a mismatched pair would reverse the wrong transaction.
		// Guards the stored references, not a caller (#242).
		if ( (int) $refund->get_parent_id() !== $order_id ) {
			return null;
		}

		$prefix = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX );

		$created   = $refund->get_date_created();
		$timestamp = ( is_object( $created ) && method_exists( $created, 'getTimestamp' ) )
			? (int) $created->getTimestamp()
			: time();

		$consent = $order->get_meta( AttributionCapture::META_CONSENT_STATE, true );

		return new RefundData(
			$this->platform(),
			$order_id,
			$refund_id,
			// The PARENT order's number, prefix included: the transaction the
			// purchase reported. The refund's own number Analytics has never seen.
			$prefix . $order->get_order_number(),
			(string) $order->get_currency(),
			abs( (float) $refund->get_total() ),
			(float) $order->get_total(),
			$timestamp,
			$this->items( $refund ),
			(string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ),
			is_array( $consent ) ? $consent : null,
			(string) $order->get_billing_country(),
			// The purchase event's two totals, read off the refund and made
			// positive; get_total_tax() covers the shipping tax too.
			abs( (float) $refund->get_shipping_total() ),
			abs( (float) $refund->get_total_tax() )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id  The parent order.
	 * @param int $refund_id The refund.
	 * @return array{0: mixed, 1: mixed}
	 */
	public function objects( int $order_id, int $refund_id ): array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array( null, null );
		}

		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		return array(
			is_object( $refund ) ? $refund : null,
			is_object( $order ) ? $order : null,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $refund_id The refund.
	 * @return bool
	 */
	public function is_sent( int $refund_id ): bool {
		list( $refund ) = $this->objects( 0, $refund_id );

		if ( ! is_object( $refund ) || ! method_exists( $refund, 'get_meta' ) ) {
			return false;
		}

		return '' !== (string) $refund->get_meta( self::META_SENT, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $refund_id  The refund.
	 * @param string $request_id The API request id.
	 * @return void
	 */
	public function mark_sent( int $refund_id, string $request_id ): void {
		list( $refund ) = $this->objects( 0, $refund_id );

		if ( ! is_object( $refund ) || ! method_exists( $refund, 'update_meta_data' ) || ! method_exists( $refund, 'save' ) ) {
			return;
		}

		// The request id, or a plain marker: '' would read as "not sent" to is_sent().
		$refund->update_meta_data( self::META_SENT, ( '' !== $request_id ) ? $request_id : '1' );
		$refund->save();
	}

	/**
	 * The refunded lines, as positive quantities and unit prices. Zero-quantity
	 * lines (a by-amount refund) are left out: the API's item shape cannot
	 * express them, and the value still travels in conversionValue.
	 *
	 * @param \WC_Order_Refund $refund The refund.
	 * @return array<int, array<string, mixed>> Items in the API shape, see RefundEvent::item().
	 */
	private function items( \WC_Order_Refund $refund ): array {
		$product_data = new ProductData( $this->options );
		$items        = array();

		// The same tax basis the purchase event's items use, so a refunded
		// line and the line it refunds are expressed the same way.
		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX ) ) {
			$inc_tax = false;
		} else {
			$inc_tax = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) );
		}

		foreach ( $refund->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}

			$quantity = (int) abs( (int) $item->get_quantity() );

			if ( $quantity <= 0 ) {
				continue;
			}

			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;

			// Line and quantity are both negative on a refund, so the quotient
			// is already positive; abs() guards a store storing it the other way.
			$unit_price = round( abs( (float) $refund->get_item_total( $item, $inc_tax ) ), 2 );

			$attributes = array(
				'quantity' => $quantity,
				'price'    => $unit_price,
			);

			// The per-unit discount as the purchase item reports it, on the
			// magnitudes since both are negated on a refund line.
			if ( method_exists( $item, 'get_subtotal' ) && method_exists( $item, 'get_total' ) ) {
				$line_discount = round( ( abs( (float) $item->get_subtotal() ) - abs( (float) $item->get_total() ) ) / $quantity, 2 );

				if ( $line_discount > 0 ) {
					$attributes['discount'] = $line_discount;
				}
			}

			$built = $product_data->process_product(
				$product,
				$attributes,
				'refund',
				$item
			);

			if ( ! is_array( $built ) || ! isset( $built['item_id'] ) ) {
				continue;
			}

			$items[] = RefundEvent::item( $built, $unit_price, $quantity );
		}

		return $items;
	}
}
