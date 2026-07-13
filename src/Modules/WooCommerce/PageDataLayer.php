<?php
/**
 * WooCommerce page load data layer content.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Frontend\DataLayer;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles all WooCommerce related content of the page load data layer:
 * customer data, cart content, view_item / view_cart / begin_checkout
 * events and the purchase event on the order received page.
 *
 * Port of gtm4wp_woocommerce_datalayer_filter_items() from 1.x with
 * identical event payloads and extensibility filter call points.
 */
final class PageDataLayer {

	/**
	 * Constructor.
	 *
	 * @param Options     $options      The plugin options service.
	 * @param ProductData $product_data The product data builder.
	 * @param DataLayer   $datalayer    The data layer service.
	 */
	public function __construct(
		private Options $options,
		private ProductData $product_data,
		private DataLayer $datalayer
	) {
	}

	/**
	 * Function executed when the main GTM4WP data layer generation happens.
	 * Hooks into gtm4wp_compile_datalayer.
	 *
	 * @param array $data_layer An array of key-value pairs that will be converted into a JavaScript object on the frontend for GTM.
	 * @return array Extended data layer content with WooCommerce data added.
	 */
	public function add_datalayer_data( $data_layer ) {
		if ( array_key_exists( 'HTTP_X_REQUESTED_WITH', $_SERVER ) ) {
			return $data_layer;
		}

		$woo = WC();

		$data_layer = $this->add_customer_data( $data_layer, $woo );
		$data_layer = $this->add_cart_content( $data_layer, $woo );

		// Product detail view data layer content.
		if ( is_product() ) {
			$data_layer = $this->add_product_view( $data_layer );
		} elseif ( is_cart() ) {
			$this->add_cart_view( $woo );
		} elseif ( is_order_received_page() ) {
			$data_layer = $this->add_order_received_data( $data_layer );
		} elseif ( is_checkout() ) {
			$this->add_begin_checkout( $woo );
		}

		$this->maybe_add_readded_to_cart( $woo );

		$this->datalayer->flush_pushes();

		return apply_filters( GTM4WP_WPFILTER_EEC_DATALAYER_PAGELOAD, $data_layer );
	}

	/**
	 * Adds the logged-in customer's account, billing and shipping details to
	 * the data layer when the customer-data feature is enabled. Present on
	 * every page view. A fresh WC_Customer is loaded from the id so the order
	 * count and total spent come from the database, not the session.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @param mixed                $woo        The WooCommerce store object (WC()).
	 * @return array<string, mixed>
	 */
	private function add_customer_data( array $data_layer, $woo ): array {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA ) ) {
			return $data_layer;
		}

		if ( ! ( $woo->customer instanceof \WC_Customer ) ) {
			return $data_layer;
		}

		$woo_customer = new \WC_Customer( $woo->customer->get_id() );

		$data_layer['customerTotalOrders']     = $woo_customer->get_order_count();
		$data_layer['customerTotalOrderValue'] = $woo_customer->get_total_spent();

		$data_layer['customerFirstName'] = $woo_customer->get_first_name();
		$data_layer['customerLastName']  = $woo_customer->get_last_name();

		$data_layer['customerBillingFirstName'] = $woo_customer->get_billing_first_name();
		$data_layer['customerBillingLastName']  = $woo_customer->get_billing_last_name();
		$data_layer['customerBillingCompany']   = $woo_customer->get_billing_company();
		$data_layer['customerBillingAddress1']  = $woo_customer->get_billing_address_1();
		$data_layer['customerBillingAddress2']  = $woo_customer->get_billing_address_2();
		$data_layer['customerBillingCity']      = $woo_customer->get_billing_city();
		$data_layer['customerBillingState']     = $woo_customer->get_billing_state();
		$data_layer['customerBillingPostcode']  = $woo_customer->get_billing_postcode();
		$data_layer['customerBillingCountry']   = $woo_customer->get_billing_country();
		$data_layer['customerBillingEmail']     = $woo_customer->get_billing_email();
		$data_layer['customerBillingEmailHash'] = Helpers::normalize_and_hash_email_address( 'sha256', $woo_customer->get_billing_email() );
		$data_layer['customerBillingPhone']     = $woo_customer->get_billing_phone();

		$data_layer['customerShippingFirstName'] = $woo_customer->get_shipping_first_name();
		$data_layer['customerShippingLastName']  = $woo_customer->get_shipping_last_name();
		$data_layer['customerShippingCompany']   = $woo_customer->get_shipping_company();
		$data_layer['customerShippingAddress1']  = $woo_customer->get_shipping_address_1();
		$data_layer['customerShippingAddress2']  = $woo_customer->get_shipping_address_2();
		$data_layer['customerShippingCity']      = $woo_customer->get_shipping_city();
		$data_layer['customerShippingState']     = $woo_customer->get_shipping_state();
		$data_layer['customerShippingPostcode']  = $woo_customer->get_shipping_postcode();
		$data_layer['customerShippingCountry']   = $woo_customer->get_shipping_country();

		return $data_layer;
	}

	/**
	 * Adds the current cart content (totals + visible items) to the data layer
	 * when the cart-content feature is enabled. Present on every page view.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @param mixed                $woo        The WooCommerce store object (WC()).
	 * @return array<string, mixed>
	 */
	private function add_cart_content( array $data_layer, $woo ): array {
		if (
			! $this->options->get( GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL ) ||
			! isset( $woo ) ||
			! isset( $woo->cart )
		) {
			return $data_layer;
		}

		$current_cart = $woo->cart;

		$data_layer['cartContent'] = array(
			'totals' => array(
				'applied_coupons' => $current_cart->get_applied_coupons(),
				'discount_total'  => $current_cart->get_discount_total(),
				'subtotal'        => $current_cart->get_subtotal(),
				'total'           => $current_cart->get_cart_contents_total(),
			),
			'items'  => array(),
		);

		foreach ( $current_cart->get_cart() as $cart_item_id => $cart_item_data ) {
			/**
			 * Applying WooCommerce's own woocommerce_cart_item_product filter here is essential in order to hide everything
			 * from tracking codes that is not visible to the user as well.
			 */
			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

			/**
			 * This filter allows 3rd party code to exclude specific products from reporting.
			 *
			 * @param bool  true            Constant value telling 3rd party code that the order item will be included in reporting if not changed by the filter.
			 * @param array $cart_item_data Associative array generated by WooCommerce returned by the WC()->cart->get_cart() function call.
			 *
			 * return bool If the filter returns false, the cart item will be omitted from processing.
			 */
			if (
				! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data )
				|| ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item_data, $cart_item_id )
				) {
				continue;
			}

			$eec_product_array = $this->product_data->process_product(
				$product,
				array(
					'quantity' => $cart_item_data['quantity'],
				),
				'cart'
			);

			unset( $eec_product_array['internal_id'] );

			$data_layer['cartContent']['items'][] = $eec_product_array;
		}

		return $data_layer;
	}

	/**
	 * Builds the product-detail (view_item) data layer content and fires the
	 * view_item event for simple products and, when enabled, variable products
	 * on the parent. No-op unless e-commerce tracking is enabled.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function add_product_view( array $data_layer ): array {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			return $data_layer;
		}

		$postid  = get_the_ID();
		$product = wc_get_product( $postid );

		$eec_product_array = $this->product_data->process_product(
			$product,
			array(),
			'productdetail'
		);

		$data_layer['productRatingCounts']  = $product->get_rating_counts();
		$data_layer['productAverageRating'] = (float) $product->get_average_rating();
		$data_layer['productReviewCount']   = (int) $product->get_review_count();
		$data_layer['productType']          = $product->get_type();

		switch ( $data_layer['productType'] ) {
			case 'variable':
				$data_layer['productIsVariable'] = 1;

				if ( true === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT ) ) {
					$gtm4wp_currency = get_woocommerce_currency();
					unset( $eec_product_array['internal_id'] );

					$this->datalayer->queue_push(
						'view_item',
						array(
							'ecommerce' => array(
								'currency' => $gtm4wp_currency,
								'value'    => $eec_product_array['price'],
								'items'    => array(
									$eec_product_array,
								),
							),
						)
					);
				}

				break;

			case 'grouped':
				$data_layer['productIsVariable'] = 0;

				break;

			default:
				$data_layer['productIsVariable'] = 0;

				$gtm4wp_currency = get_woocommerce_currency();
				unset( $eec_product_array['internal_id'] );

				$this->datalayer->queue_push(
					'view_item',
					array(
						'ecommerce' => array(
							'currency' => $gtm4wp_currency,
							'value'    => $eec_product_array['price'],
							'items'    => array(
								$eec_product_array,
							),
						),
					)
				);
		}

		return $data_layer;
	}

	/**
	 * Fires the GA4 view_cart event for the current cart. No-op unless
	 * e-commerce tracking is enabled or the cart is empty.
	 *
	 * @param mixed $woo The WooCommerce store object (WC()).
	 * @return void
	 */
	private function add_cart_view( $woo ): void {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			return;
		}

		$gtm4wp_cart_products = array();
		$gtm4wp_cart_total    = 0;

		$gtm4wp_currency = get_woocommerce_currency();

		foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
			/**
			 * Applying WooCommerce's own woocommerce_cart_item_product filter here is essential in order to hide everything
			 * from tracking codes that is not visible to the user as well.
			 */
			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

			if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
				continue;
			}

			$eec_product_array = $this->product_data->process_product(
				$product,
				array(
					'quantity' => $cart_item_data['quantity'],
				),
				'cart'
			);

			unset( $eec_product_array['internal_id'] );

			$gtm4wp_cart_products[] = $eec_product_array;
			$gtm4wp_cart_total     += $eec_product_array['price'] * $eec_product_array['quantity'];
		}

		// Do not fire GTM event if no products are in the cart.
		if ( count( $gtm4wp_cart_products ) > 0 ) {
			$this->datalayer->queue_push(
				'view_cart',
				array(
					'ecommerce' => array(
						'currency' => $gtm4wp_currency,
						'value'    => $gtm4wp_cart_total,
						'items'    => $gtm4wp_cart_products,
					),
				)
			);
		}
	}

	/**
	 * Fires an add_to_cart event when a product was just re-added to the cart
	 * after being removed (the "Undo" link on the cart page). The pending
	 * re-add is flagged in the WooCommerce session by cart_item_restored().
	 *
	 * @param mixed $woo The WooCommerce store object (WC()).
	 * @return void
	 */
	private function maybe_add_readded_to_cart( $woo ): void {
		if ( ! $woo || ! $woo->session ) {
			return;
		}

		$cart_readded_hash = $woo->session->get( 'gtm4wp_product_readded_to_cart' );

		if ( ! isset( $cart_readded_hash ) ) {
			return;
		}

		$cart_item = $woo->cart->get_cart_item( $cart_readded_hash );

		if ( ! empty( $cart_item ) ) {
			$product = $cart_item['data'];

			$eec_product_array = $this->product_data->process_product(
				$product,
				array(
					'quantity' => $cart_item['quantity'],
				),
				'readdedtocart'
			);

			$gtm4wp_currency = get_woocommerce_currency();
			unset( $eec_product_array['internal_id'] );

			$this->datalayer->queue_push(
				'add_to_cart',
				array(
					'ecommerce' => array(
						'currency' => $gtm4wp_currency,
						'value'    => $eec_product_array['price'] * $eec_product_array['quantity'],
						'items'    => array( $eec_product_array ),
					),
				)
			);
		}

		$woo->session->set( 'gtm4wp_product_readded_to_cart', null );
	}

	/**
	 * Fires the GA4 begin_checkout event for the current cart and exposes the
	 * cart products to the checkout tracker via wc_enqueue_js. No-op unless
	 * e-commerce tracking is enabled.
	 *
	 * @param mixed $woo The WooCommerce store object (WC()).
	 * @return void
	 */
	private function add_begin_checkout( $woo ): void {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			return;
		}

		$gtm4wp_checkout_products = array();
		$gtm4wp_checkout_total    = 0;

		$gtm4wp_currency = get_woocommerce_currency();

		foreach ( $woo->cart->get_cart() as $cart_item_id => $cart_item_data ) {
			/**
			 * Applying WooCommerce's own woocommerce_cart_item_product filter here is essential in order to hide everything
			 * from tracking codes that is not visible to the user as well.
			 */
			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item_data['data'], $cart_item_data, $cart_item_id );

			if ( ! apply_filters( GTM4WP_WPFILTER_EEC_CART_ITEM, true, $cart_item_data ) ) {
				continue;
			}

			$eec_product_array = $this->product_data->process_product(
				$product,
				array(
					'quantity' => $cart_item_data['quantity'],
				),
				'checkout'
			);

			unset( $eec_product_array['internal_id'] );

			$gtm4wp_checkout_products[] = $eec_product_array;
			$gtm4wp_checkout_total     += $eec_product_array['quantity'] * $eec_product_array['price'];
		} // end foreach cart item

		// Do not fire GTM event if no products are in the cart.
		if ( count( $gtm4wp_checkout_products ) > 0 ) {
			$this->datalayer->queue_push(
				'begin_checkout',
				array(
					'ecommerce' => array(
						'currency' => $gtm4wp_currency,
						'value'    => $gtm4wp_checkout_total,
						'items'    => $gtm4wp_checkout_products,
					),
				)
			);
		}

		wc_enqueue_js(
			'
			window.gtm4wp_checkout_products = ' . wp_json_encode( $gtm4wp_checkout_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';
			window.gtm4wp_checkout_value    = ' . (float) $gtm4wp_checkout_total . ';'
		);
	}

	/**
	 * Builds the order-received (thankyou) page data layer: the raw order data
	 * plus the GA4 purchase event, queued together with the browser-side
	 * duplicate-tracking guard. The order is only exposed when its key matches
	 * the request, it is within the tracking age and it has not been tracked
	 * yet (see ProductData::is_purchase_already_tracked()).
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function add_order_received_data( array $data_layer ): array {
		global $wp;

		// Suppressing 'Processing form data without nonce verification.' message as there is no nonce accessible in this case.
		$order_id = filter_var( wp_unslash( isset( $_GET['order'] ) ? $_GET['order'] : '' ), FILTER_VALIDATE_INT ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id && isset( $wp->query_vars['order-received'] ) ) {
			$order_id = $wp->query_vars['order-received'];
		}
		$order_id = absint( $order_id );

		$order_id_filtered = apply_filters( 'woocommerce_thankyou_order_id', $order_id );
		if ( '' !== $order_id_filtered ) {
			$order_id = $order_id_filtered;
		}

		// Suppressing 'Processing form data without nonce verification.' message as there is no nonce accessible in this case.
		$order_key = isset( $_GET['key'] ) ? wc_clean( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = apply_filters( 'woocommerce_thankyou_order_key', $order_key );

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order ) {
				if ( $order->get_order_key() !== $order_key ) {
					unset( $order );
				}
			} else {
				unset( $order );
			}
		}

		/**
		 * From this point if for any reason purchase data is not pushed
		 * that is because for a specific reason.
		 * In any other case the woocommerce_thankyou hook will be the fallback if
		 * is_order_received_page does not work.
		 */
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		if ( isset( $order ) && $this->product_data->is_order_older_than_max_age( $order ) ) {
			unset( $order );
		}

		$order_items = null;

		// Raw order data will be output regardless of whether the purchase has been already tracked previously, since this data is not meant to track using GA.
		if ( isset( $order ) && $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERDATA ) ) {
			$order_items             = $this->product_data->process_order_items( $order );
			$data_layer['orderData'] = $this->product_data->get_raw_order_datalayer( $order, $order_items );
		}

		if ( isset( $order ) && $this->product_data->is_purchase_already_tracked( $order, (int) $order_id ) ) {
			unset( $order );
		}

		if ( isset( $order ) && ( 'failed' === $order->get_status() ) ) {
			// Do not track order where payment failed.
			unset( $order );
		}

		if ( ! isset( $order ) ) {
			return $data_layer;
		}

		$data_layer['new_customer'] = $this->product_data->is_new_customer( $order );

		$purchase_data_layer = $this->product_data->get_purchase_datalayer( $order, $order_items );

		$before_purchase_dl_push = '
			// Check whether this order has been already tracked in this browser.

			// Read order id already tracked from cookies or local storage.
			let gtm4wp_orderid_tracked = "";

			if ( !window.localStorage ) {
				let gtm4wp_cookie = "; " + document.cookie;
				let gtm4wp_cookie_parts = gtm4wp_cookie.split( "; gtm4wp_orderid_tracked=" );
				if ( gtm4wp_cookie_parts.length == 2 ) {
					gtm4wp_orderid_tracked = gtm4wp_cookie_parts.pop().split(";").shift();
				}
			} else {
				gtm4wp_orderid_tracked = window.localStorage.getItem( "gtm4wp_orderid_tracked" );
			}

			// Check whether this order has been already tracked before in this browser.
			let gtm4wp_order_already_tracked = false;
			if ( gtm4wp_orderid_tracked && ( "' . esc_js( $order->get_order_number() ) . '" == gtm4wp_orderid_tracked ) ) {
				gtm4wp_order_already_tracked = true;
			}

			// only push purchase action if not tracked already.
			if ( !gtm4wp_order_already_tracked ) {';

		$after_purchase_dl_push = '
			}

			// Store order ID to prevent tracking this purchase again.
			if ( !window.localStorage ) {
				var gtm4wp_orderid_cookie_expire = new Date();
				gtm4wp_orderid_cookie_expire.setTime( gtm4wp_orderid_cookie_expire.getTime() + (365*24*60*60*1000) );
				var gtm4wp_orderid_cookie_expires_part = "expires=" + gtm4wp_orderid_cookie_expire.toUTCString();
				document.cookie = "gtm4wp_orderid_tracked=" + "' . esc_js( $order->get_order_number() ) . '" + ";" + gtm4wp_orderid_cookie_expires_part + ";path=/";
			} else {
				window.localStorage.setItem( "gtm4wp_orderid_tracked", "' . esc_js( $order->get_order_number() ) . '" );
			}';

		$this->datalayer->queue_push(
			$purchase_data_layer['event'],
			$purchase_data_layer,
			$before_purchase_dl_push,
			$after_purchase_dl_push
		);

		$this->product_data->flag_order_tracked( $order );

		return $data_layer;
	}
}
