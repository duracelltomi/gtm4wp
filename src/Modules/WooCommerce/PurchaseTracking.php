<?php
/**
 * WooCommerce purchase tracking fallback.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Fallback purchase tracking on the woocommerce_thankyou hook for
 * customized order received pages where the is_order_received_page()
 * template tag returns false. Port of gtm4wp_woocommerce_thankyou()
 * from 1.x, including the _ga_tracked order meta duplicate prevention.
 */
final class PurchaseTracking {

	/**
	 * Constructor.
	 *
	 * @param Options     $options      The plugin options service.
	 * @param ProductData $product_data The product data builder.
	 * @param DataLayer   $datalayer    The data layer service.
	 * @param ScriptTag   $script_tag   The script tag helper.
	 */
	public function __construct(
		private Options $options,
		private ProductData $product_data,
		private DataLayer $datalayer,
		private ScriptTag $script_tag
	) {
	}

	/**
	 * Executed during woocommerce_thankyou.
	 * This is a fallback function to output the purchase data layer on customized order received pages where
	 * the is_order_received_page() template tag returns false for some reason.
	 *
	 * @param int $order_id The ID of the order placed by the user just recently.
	 * @return void
	 */
	public function on_thankyou( $order_id ): void {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}

		/*
		If this flag is set to true, it means that the purchase event was fired
		when capturing the is_order_received_page template tag therefore
		no need to handle this here twice.
		*/
		if ( ! empty( $GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] ) ) {
			return;
		}

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
		}

		$data_layer = array();

		if ( isset( $order ) && $this->product_data->is_order_older_than_max_age( $order ) ) {
			unset( $order );
		}

		$order_items = null;

		// Raw order data will be output regardless of whether the purchase has been already tracked previously, since this data is not meant to track using GA.
		if ( isset( $order ) && $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERDATA ) ) {
			$order_items = $this->product_data->process_order_items( $order );

			$data_layer['orderData'] = $this->product_data->get_raw_order_datalayer( $order, $order_items );
		}

		if ( isset( $order ) && $this->product_data->is_purchase_already_tracked( $order, (int) $order_id ) ) {
			unset( $order );
		}

		if ( isset( $order ) && ! $this->product_data->is_order_status_trackable( $order ) ) {
			// Only track orders whose status is configured as a purchase (default:
			// processing, on-hold, completed); skips failed and still-pending orders.
			unset( $order );
		}

		if ( isset( $order ) ) {
			$data_layer['new_customer'] = $this->product_data->is_new_customer( $order );

			$data_layer = array_merge(
				$data_layer,
				$this->product_data->get_purchase_datalayer( $order, $order_items )
			);

			$datalayer_name = $this->datalayer->name();

			$script_tag = '
' . $this->script_tag->opening_tag() . '
	window.' . esc_js( $datalayer_name ) . ' = window.' . esc_js( $datalayer_name ) . ' || [];
	window.' . esc_js( $datalayer_name ) . '.push(' . wp_json_encode( $data_layer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ');
</script>';

			$this->script_tag->print_script_block( $script_tag );

			$this->product_data->flag_order_tracked( $order );
		}
	}

	/**
	 * Remembers a placed order in the WooCommerce session so the reliable-purchase
	 * fallback can emit its purchase event on the next page the customer views.
	 * Hooked (only when the "purchase on any page" or custom order-received page
	 * option is on) to woocommerce_payment_complete, woocommerce_order_status_changed
	 * and woocommerce_thankyou - the union of these fires for every payment method:
	 * instant gateways and the order-pay flow (payment_complete), Cash on Delivery
	 * and bank transfer (status change to processing/on-hold) and any thank-you
	 * page render. Only trackable-status orders are remembered, so a still-pending
	 * order awaiting payment is never seeded.
	 *
	 * @param int $order_id The id of the order that reached a placed/paid state.
	 * @return void
	 */
	public function remember_order( $order_id ): void {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return;
		}

		$woo = function_exists( 'WC' ) ? WC() : null;
		if ( ! $woo || empty( $woo->session ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		if ( ! $this->product_data->is_order_status_trackable( $order ) ) {
			return;
		}

		$woo->session->set( ProductData::PENDING_PURCHASE_SESSION_KEY, $order_id );
	}
}
