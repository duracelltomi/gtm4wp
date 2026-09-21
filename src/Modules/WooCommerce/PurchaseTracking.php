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
	 * Executed during woocommerce_thankyou: fallback purchase data layer for
	 * customized order received pages where is_order_received_page() is false.
	 * No visitor check is needed here, unlike
	 * PageDataLayer::woocommerce_hides_order_from_visitor(): the stock
	 * thankyou.php fires this hook only after order_received()'s gates passed, so
	 * reaching it is the evidence the page rendered.
	 *
	 * @param int $order_id The ID of the order placed by the user just recently.
	 * @return void
	 */
	public function on_thankyou( $order_id ): void {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}

		// Already fired on the is_order_received_page path.
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

		// The canonical eligibility gauntlet; the separate age check above only
		// keeps orderData off too-old orders as well.
		if ( isset( $order ) && ! $this->product_data->is_order_trackable( $order, (int) $order_id ) ) {
			unset( $order );
		}

		if ( isset( $order ) ) {
			$data_layer = array_merge( $data_layer, $this->product_data->customer_signals( $order ) );

			$data_layer = array_merge(
				$data_layer,
				$this->product_data->get_purchase_datalayer( $order, $order_items )
			);

			$datalayer_name = $this->datalayer->name();

			// An unencodable payload (a filter can inject INF/NAN, a resource) must
			// not be flagged as tracked (#141, RI-21): false would concatenate as
			// `.push()` and the purchase would be suppressed permanently. Bailing
			// leaves the order un-flagged, so the next request tries again.
			$encoded_data_layer = wp_json_encode( $data_layer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

			if ( false === $encoded_data_layer ) {
				return;
			}

			$script_tag = '
' . $this->script_tag->opening_tag() . '
	window.' . esc_js( $datalayer_name ) . ' = window.' . esc_js( $datalayer_name ) . ' || [];
	window.' . esc_js( $datalayer_name ) . '.push(' . $encoded_data_layer . ');
</script>';

			$this->script_tag->print_script_block( $script_tag );

			$this->product_data->flag_order_tracked( $order );

			// Mark the purchase handled for THIS request: remember_order() runs on
			// this same hook right after and must not re-arm the fallback.
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;
		}
	}

	/**
	 * Remembers a placed order in the WooCommerce session so the reliable-purchase
	 * fallback can emit its purchase on the next page. Hooked (when "purchase on
	 * any page" or the custom order-received page is on) to
	 * woocommerce_payment_complete, woocommerce_order_status_changed and
	 * woocommerce_thankyou, which together cover every payment method. Only
	 * trackable-status orders are remembered.
	 *
	 * @param int $order_id The id of the order that reached a placed/paid state.
	 * @return void
	 */
	public function remember_order( $order_id ): void {
		// A render that already emitted the purchase inline must not re-arm the
		// fallback for the same order: the order-received render consumes the
		// marker in wp_head and this hook fires later in the body.
		if ( ! empty( $GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] ) ) {
			return;
		}

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

		// Cache-safe data layer (issue #398): flag the pending one-shot so the
		// client fetches it on the next page. No-op unless the mode is on.
		Helpers::flag_oneshot_event( (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER ) );
	}
}
