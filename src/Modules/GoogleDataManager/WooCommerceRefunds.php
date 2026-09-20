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
 * Reads WooCommerce refunds into the platform-neutral shape.
 *
 * WooCommerce models a refund as a WC_Order_Refund: its own order-ish object
 * whose parent is the order, carrying the refunded lines as items with negated
 * quantities and totals. `woocommerce_order_refunded` fires for both kinds -
 * a full refund and a per-line or by-amount partial one - which is why the
 * full-versus-partial question is answered from the amounts here rather than
 * from which hook fired.
 *
 * Item ids come from ProductData::process_product(), the very builder that
 * produced the purchase event's items. That is not a convenience: the item id
 * is the join key Google Analytics matches a refunded line against the line it
 * is refunding, and it depends on store settings (the "use SKU" option, the
 * master-language consolidation) that a second implementation would have to
 * mirror forever. Calling the same builder makes them identical by
 * construction, and a test asserts it rather than assuming it.
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
			// The PARENT order's number, prefix included: this is the
			// transaction the purchase event reported, and reversing it is the
			// whole point. The refund has an order number of its own, which
			// Analytics has never seen.
			$prefix . $order->get_order_number(),
			(string) $order->get_currency(),
			abs( (float) $refund->get_total() ),
			(float) $order->get_total(),
			$timestamp,
			$this->items( $refund ),
			(string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ),
			is_array( $consent ) ? $consent : null,
			(string) $order->get_billing_country(),
			// The same two totals the purchase event reports, read off the
			// refund instead of the order and turned positive. get_total_tax()
			// covers the shipping tax as well, exactly as it does there.
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

		// A request id when there is one, otherwise a plain marker: the meta's
		// job is to say "sent", and an empty string would read as "not sent"
		// to is_sent() above.
		$refund->update_meta_data( self::META_SENT, ( '' !== $request_id ) ? $request_id : '1' );
		$refund->save();
	}

	/**
	 * The refunded lines, as positive quantities and unit prices.
	 *
	 * Lines whose quantity is zero are left out. WooCommerce records a
	 * by-amount refund that way - money returned against a line without
	 * returning any of it - and the API's item shape has no way to express
	 * that. Those refunds still report their full value through
	 * conversionValue; only the per-item breakdown is unavailable, which is
	 * what the store recorded.
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

			// get_item_total() divides the line by its quantity, and both are
			// negative on a refund, so the result is already positive; abs()
			// only guards against a store that stores it the other way round.
			$unit_price = round( abs( (float) $refund->get_item_total( $item, $inc_tax ) ), 2 );

			$attributes = array(
				'quantity' => $quantity,
				'price'    => $unit_price,
			);

			// The per-unit discount, the way the purchase item reports it: the
			// gap between the line's pre-discount subtotal and its total, per
			// unit, only when there is one. Both are negated on a refund line,
			// so the difference is taken on their magnitudes.
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
