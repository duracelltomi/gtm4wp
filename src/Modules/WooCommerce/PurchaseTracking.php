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

		if ( isset( $order ) && $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ) ) {
			$now = new \DateTime( 'now', $order->get_date_created()->getTimezone() );
			if ( $order->is_paid() && $order->get_date_paid() ) {
				$diff    = $now->diff( $order->get_date_paid() );
				$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;
			} else {
				$diff    = $now->diff( $order->get_date_created() );
				$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;
			}

			if ( $minutes > $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ) ) {
				unset( $order );
			}
		}

		$order_items = null;

		// Raw order data will be output regardless of whether the purchase has been already tracked previously, since this data is not meant to track using GA.
		if ( isset( $order ) && $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERDATA ) ) {
			$order_items = $this->product_data->process_order_items( $order );

			$data_layer['orderData'] = $this->product_data->get_raw_order_datalayer( $order, $order_items );
		}

		$do_not_flag_tracked_order = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG );
		if ( isset( $order ) && ( 1 === (int) $order->get_meta( '_ga_tracked', true ) ) && ! $do_not_flag_tracked_order ) {
			unset( $order );
		}

		if ( isset( $_COOKIE['gtm4wp_orderid_tracked'] ) ) {
			$tracked_order_id = filter_var( wp_unslash( $_COOKIE['gtm4wp_orderid_tracked'] ), FILTER_VALIDATE_INT );

			if ( $tracked_order_id && ( $tracked_order_id === $order_id ) && ! $do_not_flag_tracked_order ) {
				unset( $order );
			}
		}

		if ( isset( $order ) && ( 'failed' === $order->get_status() ) ) {
			// Do not track order where payment failed.
			unset( $order );
		}

		if ( isset( $order ) ) {
			/**
			 * Variable for Google Smart Shopping campaign new customer reporting.
			 *
			 * @see https://support.google.com/google-ads/answer/9917012?hl=en-AU#zippy=%2Cinstall-with-google-tag-manager
			 */
			$data_layer['new_customer'] = \Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::is_returning_customer( $order ) === false;

			$purchase_data_layer = $this->product_data->get_purchase_datalayer( $order, $order_items );

			$data_layer = array_merge(
				$data_layer,
				$purchase_data_layer
			);

			$datalayer_name = $this->datalayer->name();

			$script_tag = '
' . $this->script_tag->opening_tag() . '
	window.' . esc_js( $datalayer_name ) . ' = window.' . esc_js( $datalayer_name ) . ' || [];
	window.' . esc_js( $datalayer_name ) . '.push(' . wp_json_encode( $data_layer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ');
</script>';

			$this->script_tag->print_script_block( $script_tag );

			if ( ! $do_not_flag_tracked_order ) {
				$order->update_meta_data( '_ga_tracked', 1 );
				$order->save();
			}
		}
	}
}
