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
		global $wp;

		if ( array_key_exists( 'HTTP_X_REQUESTED_WITH', $_SERVER ) ) {
			return $data_layer;
		}

		$woo = WC();

		// Customer data will be present on every pageview if feature is enabled.
		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA ) ) {
			if ( $woo->customer instanceof \WC_Customer ) {
				// We need to use this instead of $woo->customer as this will load proper total order number and value from the database instead of the session.
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
			}
		}

		// Cart content will be present on every pageview if feature is enabled.
		if (
			$this->options->get( GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL ) &&
			isset( $woo ) &&
			isset( $woo->cart )
		) {
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
		}

		// Product detail view data layer content.
		if ( is_product() ) {
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
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
			}
		} elseif ( is_cart() ) {
			// Cart page data layer content.

			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
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
		} elseif ( is_order_received_page() ) {
			// Order received page data layer content.

			$do_not_flag_tracked_order = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG );

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

					$this_order_key = $order->get_order_key();

					if ( $this_order_key !== $order_key ) {
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

			if ( isset( $order ) && $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ) ) {

				if ( $order->is_paid() && $order->get_date_paid() ) {
					$now     = new \DateTime( 'now', $order->get_date_paid()->getTimezone() );
					$diff    = $now->diff( $order->get_date_paid() );
					$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;
				} else {
					$now     = new \DateTime( 'now', $order->get_date_created()->getTimezone() );
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

				if ( ! $do_not_flag_tracked_order ) {
					$order->update_meta_data( '_ga_tracked', 1 );
					$order->save();
				}
			}
		} elseif ( is_checkout() ) {
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
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
				window.gtm4wp_checkout_products = ' . wp_json_encode( $gtm4wp_checkout_products ) . ';
				window.gtm4wp_checkout_value    = ' . (float) $gtm4wp_checkout_total . ';'
				);
			}
		}

		// Handle add_to_cart event when product was re-added after removing from the cart.
		if ( $woo && $woo->session ) {
			$cart_readded_hash = $woo->session->get( 'gtm4wp_product_readded_to_cart' );

			if ( isset( $cart_readded_hash ) ) {
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
		}

		$this->datalayer->flush_pushes();

		return apply_filters( GTM4WP_WPFILTER_EEC_DATALAYER_PAGELOAD, $data_layer );
	}
}
