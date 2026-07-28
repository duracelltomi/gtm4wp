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
	 * No visitor check is needed here, unlike on the standard order-received page
	 * (PageDataLayer::woocommerce_hides_order_from_visitor()). Verified on
	 * WooCommerce trunk 2026-08-12: WC_Shortcode_Checkout::order_received() renders
	 * checkout/thankyou.php with a real order ONLY after its known-shopper and
	 * guest-email gates pass - each declining branch renders
	 * checkout/order-received.php and returns - and that template fires
	 * woocommerce_thankyou inside its own `if ( $order )`. Reaching this hook is
	 * therefore the evidence the page rendered. That holds for the stock template;
	 * a theme overriding thankyou.php owns its own decision, as it does for every
	 * other hook it chooses to fire.
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

		// The canonical eligibility gauntlet (age / already-tracked / status).
		// The separate age check above only exists so orderData is skipped for
		// too-old orders as well; the composite re-runs it for free.
		if ( isset( $order ) && ! $this->product_data->is_order_trackable( $order, (int) $order_id ) ) {
			unset( $order );
		}

		if ( isset( $order ) ) {
			$data_layer = array_merge( $data_layer, $this->product_data->customer_signals( $order ) );

			$data_layer = array_merge(
				$data_layer,
				$this->product_data->get_purchase_datalayer( $order, $order_items )
			);

			// An unencodable payload must not be reported as tracked (#141). Order
			// data passes through the public GTM4WP_WPFILTER_EEC_ORDER_DATA /
			// _ORDER_ITEM filters, so a third party can put a value in here that
			// wp_json_encode() refuses (INF/NAN, a resource, over-deep nesting), and
			// it then returns false. push_snippet() below encodes this same payload
			// internally but its inline snippet cannot report the failure, so probe
			// it here first: emitting a broken push AND flagging the order tracked
			// would suppress this purchase permanently, on every later page view
			// too. Bailing leaves the order un-flagged, so the next request tries
			// again.
			if ( false === wp_json_encode( $data_layer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) ) {
				return;
			}

			// The consent-aware guarded push (DataLayer::push_snippet) carries
			// the same hex-flag JSON encoding this block always used.
			$script_tag = '
' . $this->script_tag->opening_tag() . '
	' . $this->datalayer->push_snippet( $data_layer ) . '
</script>';

			$this->script_tag->print_script_block( $script_tag );

			$this->product_data->flag_order_tracked( $order );

			// Mark the purchase as handled for THIS request, exactly like the
			// order-received page path does. remember_order() runs on this same
			// woocommerce_thankyou hook right after this callback, and without the
			// flag it would re-arm the reliable-purchase fallback for the very
			// order whose purchase this page already carries.
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;
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
		/*
		The render that emits the purchase inline sets this flag (the standard
		order-received page in PageDataLayer::add_order_received_data(), the
		on_thankyou fallback above, and the any-page session fallback). Seeding
		the marker from such a request would re-arm the reliable-purchase
		fallback for an order whose purchase this very page already carries:
		the standard order-received render consumes the marker in wp_head and
		this hook fires later in the template body, so without this guard the
		session endpoint delivered the same purchase a second time after page
		load whenever nothing else suppressed it ("Do not flag orders as being
		tracked" disables every one of those suppressors by design).
		*/
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

		// Cache-safe data layer (issue #398, Phase 3): flag that a one-shot event
		// (the reliable-purchase fallback) is pending so the client fetches it on the
		// next page it can. No-op unless the cache-safe mode is on.
		Helpers::flag_oneshot_event( (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER ) );
	}
}
