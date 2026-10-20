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
		$basis   = ( new ProductData( $this->options ) )->purchase_basis( $order );

		return new RefundData(
			$this->platform(),
			$order_id,
			$refund_id,
			// The PARENT order's number, prefix included: the transaction the
			// purchase reported. The refund's own number Analytics has never seen.
			$prefix . $order->get_order_number(),
			self::text( $order->get_currency() ),
			abs( (float) $refund->get_total() ),
			(float) $order->get_total(),
			$timestamp,
			$this->items( $refund, $basis['item_inc_tax'] ),
			self::text( $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) ),
			is_array( $consent ) ? $consent : null,
			self::text( $order->get_billing_country() ),
			// The purchase event's two totals, read off the refund and made
			// positive; get_total_tax() covers the shipping tax too.
			abs( (float) $refund->get_shipping_total() ),
			abs( (float) $refund->get_total_tax() ),
			self::refund_value( self::figures( $refund ), $basis, self::prior_figures( $order, $refund_id ) )
		);
	}

	/**
	 * A filtered getter's value as a string: these getters run through site
	 * filters, and casting an object that has no __toString() would be a
	 * fatal error inside the background job (#478, RI-39).
	 *
	 * @param mixed $value The getter's value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The refund's value on the purchase basis (#466). WooCommerce sets a
	 * refund's total to the amount typed in, independently of its lines, and a
	 * status-change refund has no lines at all (U188). So: the purchase rule on
	 * the money the lines account for, plus the rest of the amount at the
	 * ratio of the purchase value still unrefunded, clamped so the refunds of
	 * one order never reverse more than the purchase value.
	 *
	 * @param array{amount: float, line_money: float, tax: float, shipping: float}             $figures This refund.
	 * @param array{value: float, total: float, exclude_tax: bool, exclude_shipping: bool}     $basis   The purchase basis.
	 * @param array<int, array{amount: float, line_money: float, tax: float, shipping: float}> $priors The order's earlier refunds, oldest first.
	 * @return float
	 */
	public static function refund_value( array $figures, array $basis, array $priors = array() ): float {
		$prior_value  = 0.0;
		$prior_amount = 0.0;

		foreach ( $priors as $prior ) {
			$prior_value  += self::one_refund_value( $prior, $basis, $prior_value, $prior_amount );
			$prior_amount += $prior['amount'];
		}

		return self::one_refund_value( $figures, $basis, $prior_value, $prior_amount );
	}

	/**
	 * One refund's value, given what the earlier refunds already reversed.
	 *
	 * @param array{amount: float, line_money: float, tax: float, shipping: float}         $figures      The refund.
	 * @param array{value: float, total: float, exclude_tax: bool, exclude_shipping: bool} $basis        The purchase basis.
	 * @param float                                                                        $prior_value  Value reversed so far.
	 * @param float                                                                        $prior_amount Amount refunded so far.
	 * @return float
	 */
	private static function one_refund_value( array $figures, array $basis, float $prior_value, float $prior_amount ): float {
		$remaining_value = max( 0.0, $basis['value'] - $prior_value );
		$remaining_total = $basis['total'] - $prior_amount;
		$ratio           = $remaining_total > 0 ? $remaining_value / $remaining_total : 0.0;

		$value = ProductData::purchase_rule_value(
			$figures['line_money'],
			$figures['tax'],
			$figures['shipping'],
			$basis['exclude_tax'],
			$basis['exclude_shipping']
		) + ( $figures['amount'] - $figures['line_money'] ) * $ratio;

		return max( 0.0, min( $value, $figures['amount'], $remaining_value ) );
	}

	/**
	 * The figures refund_value() reads off one refund, all positive.
	 *
	 * @param \WC_Order_Refund $refund The refund.
	 * @return array{amount: float, line_money: float, tax: float, shipping: float}
	 */
	private static function figures( \WC_Order_Refund $refund ): array {
		$line_money = 0.0;

		foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_total' ) ) {
				$line_money += abs( (float) $item->get_total() );
			}

			if ( is_object( $item ) && method_exists( $item, 'get_total_tax' ) ) {
				$line_money += abs( (float) $item->get_total_tax() );
			}
		}

		return array(
			'amount'     => abs( (float) $refund->get_total() ),
			'line_money' => $line_money,
			'tax'        => abs( (float) $refund->get_total_tax() ),
			'shipping'   => abs( (float) $refund->get_shipping_total() ),
		);
	}

	/**
	 * The figures of the order's refunds created before this one, oldest first
	 * (by id, which follows creation).
	 *
	 * @param \WC_Order $order     The parent order.
	 * @param int       $refund_id The refund being sent.
	 * @return array<int, array{amount: float, line_money: float, tax: float, shipping: float}>
	 */
	private static function prior_figures( \WC_Order $order, int $refund_id ): array {
		$earlier = array();

		foreach ( (array) $order->get_refunds() as $prior ) {
			if ( $prior instanceof \WC_Order_Refund && (int) $prior->get_id() < $refund_id ) {
				$earlier[ (int) $prior->get_id() ] = self::figures( $prior );
			}
		}

		ksort( $earlier );

		return array_values( $earlier );
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

		return '' !== self::text( $refund->get_meta( self::META_SENT, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $refund_id The refund.
	 * @return string[]
	 */
	public function accepted_destinations( int $refund_id ): array {
		list( $refund ) = $this->objects( 0, $refund_id );

		if ( ! is_object( $refund ) || ! method_exists( $refund, 'get_meta' ) ) {
			return array();
		}

		return DestinationRows::measurement_ids( $refund->get_meta( self::META_ACCEPTED, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int         $refund_id       The refund.
	 * @param string[]    $measurements    Measurement ids that accepted it now.
	 * @param string|null $sent_request_id The request id that completes it, or null.
	 * @return void
	 */
	public function mark_accepted( int $refund_id, array $measurements, ?string $sent_request_id = null ): void {
		list( $refund ) = $this->objects( 0, $refund_id );

		if ( ! is_object( $refund ) || ! method_exists( $refund, 'get_meta' ) || ! method_exists( $refund, 'update_meta_data' ) || ! method_exists( $refund, 'save' ) ) {
			return;
		}

		$stored   = DestinationRows::measurement_ids( $refund->get_meta( self::META_ACCEPTED, true ) );
		$accepted = DestinationRows::measurement_ids( array_merge( $stored, $measurements ) );

		if ( $accepted !== $stored ) {
			$refund->update_meta_data( self::META_ACCEPTED, $accepted );
		}

		if ( null !== $sent_request_id ) {
			// The request id, or a plain marker: '' would read as "not sent" to is_sent().
			$refund->update_meta_data( self::META_SENT, ( '' !== $sent_request_id ) ? $sent_request_id : '1' );
		}

		// One save for both keys (#394: two fetch-and-save cycles per send).
		if ( $accepted !== $stored || null !== $sent_request_id ) {
			$refund->save();
		}
	}

	/**
	 * The refunded lines, as positive quantities and unit prices. Zero-quantity
	 * lines (a by-amount refund) are left out: the API's item shape cannot
	 * express them, and the value still travels in conversionValue.
	 *
	 * @param \WC_Order_Refund $refund  The refund.
	 * @param bool             $inc_tax The purchase items' tax basis, frozen with the purchase basis, so a
	 *                                  refunded line and the line it refunds are expressed the same way.
	 * @return array<int, array<string, mixed>> Items in the API shape, see RefundEvent::item().
	 */
	private function items( \WC_Order_Refund $refund, bool $inc_tax ): array {
		$product_data = new ProductData( $this->options );
		$items        = array();

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
