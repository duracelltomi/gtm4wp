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
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\VisitorData\VisitorDataEndpoint;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Options\Options;
use GTM4WP\RequestOrigin;

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
	 * REST route (relative to VisitorDataEndpoint::REST_NAMESPACE) of the nonce-protected
	 * POST that confirms the reliable-purchase fallback was delivered (issue #398): it
	 * consumes the session marker and flags the order _ga_tracked. The public GET session
	 * endpoint stays read-only; every state change happens here, and the order id comes
	 * from the session marker only, never the request. Must match the pendingPurchase
	 * VisitorField's confirm_url.
	 */
	public const REST_ROUTE_CONFIRM_PURCHASE = '/confirm-purchase-tracked';

	/**
	 * Sibling of REST_ROUTE_CONFIRM_PURCHASE for the re-added-to-cart one-shot
	 * (issue #398): the POST that consumes its session marker.
	 */
	public const REST_ROUTE_CONFIRM_READD = '/confirm-readd-tracked';

	/**
	 * The checkout globals waiting to be printed by the wp_footer fallback,
	 * set only when the tracker handle could no longer take an inline script
	 * (see add_begin_checkout()). Empty on every ordinary request.
	 *
	 * @var string
	 */
	private string $deferred_checkout_js = '';

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

		// Cache-safe data layer (issue #398): customer details and the cart are
		// visitor-specific, so they are not baked into cacheable HTML but delivered
		// on WooCommerce's cart-fragments response (visitor_cart_datalayer()) as the
		// gtm4wp.customerData / gtm4wp.cartData events. On a store without a
		// mini-cart WooCommerceModule::enqueue_visitor_cart_channel() loads that
		// script itself, gated on the visitor having WooCommerce state. The
		// content-driven events below are URL-scoped or fire only on cache-excluded
		// pages, so they stay server-side.
		$cache_safe = (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );

		if ( ! $cache_safe ) {
			$data_layer = $this->add_customer_data( $data_layer, $woo );
			$data_layer = $this->add_cart_content( $data_layer, $woo );
		}

		// Arm order matters: is_cart() and is_checkout() can both be true (a theme
		// defining WOOCOMMERCE_CART or a cart shortcode on the checkout page), so
		// checkout is tested before cart, matching
		// WooCommerceModule::block_cart_or_checkout_context(); order-received stays
		// ahead of both because is_checkout() is true there too.
		if ( is_product() ) {
			$data_layer = $this->add_product_view( $data_layer );
		} elseif ( is_order_received_page() ) {
			$data_layer = $this->add_order_received_data( $data_layer );
		} elseif ( is_checkout() ) {
			$this->add_begin_checkout( $woo );
		} elseif ( is_cart() ) {
			$this->add_cart_view( $woo );
		}

		// The one-shot session events are visitor-specific too, so they are also
		// withheld from cacheable HTML under the cache-safe data layer.
		if ( ! $cache_safe ) {
			$this->maybe_add_readded_to_cart( $woo );

			// Reliable purchase tracking: a purchase whose order-received page was
			// missed is emitted on the next page the customer views.
			$data_layer = $this->maybe_add_pending_purchase( $data_layer );
		}

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

		$data_layer['customerTotalOrders'] = $woo_customer->get_order_count();

		// get_total_spent() returns a wc_format_decimal() STRING; typed here so it
		// keeps reaching GTM as a JSON number now that the data layer encode no
		// longer numeric-coerces (JSON_NUMERIC_CHECK removed).
		$data_layer['customerTotalOrderValue'] = (float) $woo_customer->get_total_spent();

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
	 * Builds the process_product() attributes for a cart line. The display price is
	 * derived from WooCommerce's already-calculated line totals so process_product()
	 * does not recompute wc_get_price_to_display() per item (#436, memory
	 * exhaustion); omitted when the totals are not available yet.
	 *
	 * @param array<string, mixed> $cart_item_data The WooCommerce cart item.
	 * @return array<string, mixed>
	 */
	private function cart_line_attributes( array $cart_item_data ): array {
		$attributes = array(
			'quantity' => $cart_item_data['quantity'],
		);

		$include_tax = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) );

		$price = Helpers::cart_line_display_price( $cart_item_data, $include_tax );
		if ( null !== $price ) {
			$attributes['price'] = $price;
		}

		// GA4 per-item discount, added only when a coupon/sale actually reduced the
		// line (#348); omitted otherwise so undiscounted items carry no discount key.
		$discount = Helpers::cart_line_discount( $cart_item_data, $include_tax );
		if ( null !== $discount ) {
			$attributes['discount'] = $discount;
		}

		return $attributes;
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

		// Totals are cast to float: the WC_Cart getters pass through filters that may
		// answer with decimal strings, and the encode no longer numeric-coerces
		// (JSON_NUMERIC_CHECK removed). Coupon codes are identifiers and stay strings.
		$data_layer['cartContent'] = array(
			'totals' => array(
				'applied_coupons' => $current_cart->get_applied_coupons(),
				'discount_total'  => (float) $current_cart->get_discount_total(),
				'subtotal'        => (float) $current_cart->get_subtotal(),
				'total'           => (float) $current_cart->get_cart_contents_total(),
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
				$this->cart_line_attributes( $cart_item_data ),
				'cart',
				$cart_item_data
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

		// GA4 expects a quantity on the view_item item; it defaults to 1 for a single
		// product view (#348). Making it explicit keeps the payload spec-complete.
		$eec_product_array = $this->product_data->process_product(
			$product,
			array( 'quantity' => 1 ),
			'productdetail'
		);

		$data_layer['productRatingCounts']  = $product->get_rating_counts();
		$data_layer['productAverageRating'] = (float) $product->get_average_rating();
		$data_layer['productReviewCount']   = (int) $product->get_review_count();
		$data_layer['productType']          = $product->get_type();

		// GA4 list attribution (#405): the product page is cacheable, so the list the
		// visitor came from is never baked into the HTML; the push is wrapped in a JS
		// call that merges it from the first-party cookie, keyed by the product id
		// internal_id carries (the same value the client-side variation path uses).
		$list_product_id   = $eec_product_array['internal_id'] ?? $postid;
		$list_wrapper      = '';
		$list_wrapper_args = array();

		if ( true === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION ) ) {
			$list_wrapper      = Helpers::LIST_ATTRIBUTION_JS_WRAPPER;
			$list_wrapper_args = array( $list_product_id );
		}

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
						),
						'',
						'',
						$list_wrapper,
						$list_wrapper_args
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
					),
					'',
					'',
					$list_wrapper,
					$list_wrapper_args
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
				$this->cart_line_attributes( $cart_item_data ),
				'cart',
				$cart_item_data
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
				$this->cart_line_attributes( $cart_item ),
				'readdedtocart',
				$cart_item
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
	 * cart products to the checkout tracker as an inline script. No-op unless
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
				$this->cart_line_attributes( $cart_item_data ),
				'checkout',
				$cart_item_data
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

		$checkout_js = '
			window.gtm4wp_checkout_products = ' . ScriptTag::json_literal( $gtm4wp_checkout_products, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';
			window.gtm4wp_checkout_value    = ' . (float) $gtm4wp_checkout_total . ';';

		// Only the classic tracker reads these globals. On a block checkout
		// WooCommerceModule::enqueue_scripts() loads gtm4wp-woocommerce-blocks instead
		// (it reads the wc/store registry), so no handle means no reader, not a missed
		// attach. 'enqueued' rather than 'registered': a dequeued handle has no reader
		// either, and 'enqueued' stays true after printing so the done case below is
		// still reached.
		if ( ! wp_script_is( 'gtm4wp-woocommerce', 'enqueued' ) ) {
			return;
		}

		// Replaces the deprecated wc_enqueue_js() (WooCommerce 10.4, PA-8). An inline
		// script can only attach while its handle is pending; this runs from wp_head
		// priority 10, after wp_print_head_scripts() (9), so a site that filters the
		// tracker into the <head> already has the handle done and the attach would
		// silently drop the checkout data (add_shipping_info / add_payment_info with
		// empty items). Print the block in the footer ourselves in that case. The
		// wp_add_inline_script() return value is honoured per its documented contract.
		if (
			wp_script_is( 'gtm4wp-woocommerce', 'done' )
			|| ! wp_add_inline_script( 'gtm4wp-woocommerce', $checkout_js, 'before' )
		) {
			if ( '' === $this->deferred_checkout_js ) {
				add_action( 'wp_footer', array( $this, 'print_deferred_checkout_js' ), 5 );
			}

			$this->deferred_checkout_js = $checkout_js;
		}
	}

	/**
	 * Prints the checkout globals that could not be attached to the tracker handle
	 * as a standalone footer script block (the add_begin_checkout() fallback).
	 *
	 * @return void
	 */
	public function print_deferred_checkout_js(): void {
		if ( '' === $this->deferred_checkout_js ) {
			return;
		}

		$block = "\n" . $this->script_tag->opening_tag() . $this->deferred_checkout_js . "\n</script>";

		// Cleared before printing so a second wp_footer pass cannot repeat the block.
		$this->deferred_checkout_js = '';

		$this->script_tag->print_script_block( $block );
	}

	/**
	 * Builds the order-received (thankyou) page data layer: the raw order data
	 * plus the GA4 purchase event, queued together with the browser-side
	 * duplicate-tracking guard. The order is only exposed when its key matches
	 * the request, it is within the tracking age and it has not been tracked
	 * yet (see ProductData::is_purchase_already_tracked()); the customer identity
	 * blocks additionally need this visitor to be one WooCommerce would show the
	 * order to (see woocommerce_hides_order_from_visitor()).
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

		$order = null;

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order ) {
				// hash_equals(), matching WC_Shortcode_Checkout::order_received() on the
				// same URL; both sides cast because the key passed a public filter and
				// hash_equals() throws on a non-string.
				if ( ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
					$order = null;
				}
			} else {
				$order = null;
			}
		}

		// Resolved from the REQUEST (?order= plus a matching ?key=) is what
		// woocommerce_hides_order_from_visitor() reasons about; the session fallback
		// below resolves the buyer's own order and is deliberately exempt.
		$from_request = $order instanceof \WC_Order;

		// Custom order-received page: no order id or key in the URL, so resolve the
		// order from this browser's session (the buyer's own, so no key check).
		if ( ! ( $order instanceof \WC_Order ) ) {
			$session_order_id = $this->pending_session_order_id();

			if ( $session_order_id > 0 ) {
				$session_order = wc_get_order( $session_order_id );

				if ( $session_order instanceof \WC_Order ) {
					$order    = $session_order;
					$order_id = $session_order_id;
				}
			}
		}

		// From here on, purchase data not being pushed is deliberate; otherwise the
		// woocommerce_thankyou hook is the fallback when is_order_received_page()
		// does not work.
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		if ( ! ( $order instanceof \WC_Order ) ) {
			return $data_layer;
		}

		// A forwarded order-received URL still carries a valid key. The purchase
		// event fires on the key alone (the buyer arriving from checkout must not
		// lose the conversion); only the customer identity blocks are withheld, in
		// exactly the cases WooCommerce itself would not render the order.
		$withhold_customer_data = $from_request && $this->woocommerce_hides_order_from_visitor( $order );

		return $this->add_purchase_for_order( $data_layer, $order, (int) $order_id, $withhold_customer_data );
	}

	/**
	 * Whether WooCommerce itself would refuse to render this order to the current
	 * request, in which case the customer identity blocks (orderData.customer,
	 * new_customer / customer_type, the purchase event's user_data) are withheld
	 * while the purchase event still fires.
	 *
	 * The two gates in WC_Shortcode_Checkout::order_received() run after the
	 * order-key check and return before woocommerce_thankyou, so the decision is
	 * re-derived here. The rule is upstream PARITY: withholding more than upstream
	 * only deletes tracking data (the page body already shows the order),
	 * publishing more is the real failure, so any term that cannot be read from
	 * here is dropped in the withhold direction. Version surface (measured at the
	 * release tags, registry row U113):
	 *
	 * - 5.0-7.8.x: no gates; nothing withheld. Detected by probing the symbol both
	 *   gates arrived with (guest_should_verify_email, 7.9.0), never by version.
	 * - 7.9.0+: a non-guest order requires being logged in as its customer;
	 *   filterable via woocommerce_order_received_verify_known_shoppers since 8.4.0.
	 * - 7.9.0+: a guest order requires billing-email verification, decided inline
	 *   by guest_should_verify_email() through 8.5.x (10-minute filterable grace
	 *   from 8.0.0) and by Users::should_user_verify_order_email() from 8.6.0,
	 *   which is asked directly when present (Internal namespace, UC-2 guard).
	 *
	 * The 7.9.0-8.5.x fallback mirrors guest_should_verify_email() term by term.
	 * Three request-identity terms are deliberately not modelled: the session
	 * email match, the POSTed-email escape hatch and read_private_shop_orders;
	 * each omission withholds where upstream renders, never the reverse, for any
	 * monotone filter callback. The accepted residuals are named at their lines.
	 *
	 * @param \WC_Order $order The order resolved from the request.
	 * @return bool True when the order data must not be attributed to this visitor.
	 */
	private function woocommerce_hides_order_from_visitor( \WC_Order $order ): bool {
		// Absent means 5.0-7.8.x, which renders the order to any valid key holder.
		// method_exists() triggers WC_Autoloader and sees private members, so the
		// probe works before the class is loaded. Residual 1: an unloadable class
		// publishes - accepted, that site's order-received page fatals first, and
		// U113 watches the symbol.
		if ( ! method_exists( 'WC_Shortcode_Checkout', 'guest_should_verify_email' ) ) {
			return false;
		}

		/**
		 * Whether known (non-guest) shoppers must be logged in to see the order.
		 * The gate is unconditional from 7.9.0 and filterable from 8.4.0; a false
		 * from a site callback is honoured on 7.9.0-8.3.x too (residual 2: the
		 * admin asked for the gate to be off, the mirror follows the admin).
		 *
		 * @since WooCommerce 8.4.0 (the login requirement itself: 7.9.0)
		 *
		 * @param bool $verify_known_shoppers If verification is required.
		 */
		$verify_known_shoppers = (bool) apply_filters( 'woocommerce_order_received_verify_known_shoppers', true );
		$order_customer_id     = (int) $order->get_customer_id();

		if ( $verify_known_shoppers && $order_customer_id > 0 && get_current_user_id() !== $order_customer_id ) {
			return true;
		}

		$users_class = 'Automattic\WooCommerce\Internal\Utilities\Users';

		if ( class_exists( $users_class ) && method_exists( $users_class, 'should_user_verify_order_email' ) ) {
			// WooCommerce 8.6.0+: ask the decision's owner. Email stays null (the
			// POSTed-email escape hatch is not reproduced); null only withholds more.
			return (bool) $users_class::should_user_verify_order_email( $order->get_id(), null, 'order-received' );
		}

		// 7.9.0-8.5.x: mirror of the inline guest_should_verify_email(), which
		// upstream also runs for a known shopper's order once the login gate is
		// filtered off.

		// No billing email: nothing to verify against, upstream renders.
		if ( empty( $order->get_billing_email() ) ) {
			return false;
		}

		// Logged-in owner: upstream renders, whatever the known-shopper filter said.
		if ( $order_customer_id > 0 && get_current_user_id() === $order_customer_id ) {
			return false;
		}

		/**
		 * The site's own grace period. All three arguments are passed because
		 * WooCommerce passes three (RI-25): WP_Hook never pads the list, so a
		 * callback written to the documented signature would raise an
		 * ArgumentCountError. Residual 3: 7.9.x has no grace period, so for ten
		 * minutes the mirror publishes where 7.9.x demands verification -
		 * bounded by the order max-age gate, and the behaviour 8.0.0 adopted.
		 *
		 * @since WooCommerce 8.0.0
		 *
		 * @param int       $grace_period Seconds an order stays viewable without verification.
		 * @param \WC_Order $order        The order whose visibility is being decided.
		 * @param string    $context      The context the check runs in.
		 */
		$grace_period = (int) apply_filters( 'woocommerce_order_email_verification_grace_period', 10 * MINUTE_IN_SECONDS, $order, 'order-received' );
		$created      = $order->get_date_created();

		// <= is upstream's own comparison. No creation date falls through to the
		// filter below, like upstream's is_a() check.
		if ( $created && ( time() - $created->getTimestamp() ) <= $grace_period ) {
			return false;
		}

		/**
		 * Upstream's final say, honoured so a store that disabled verification
		 * keeps a complete data layer; three arguments per RI-25. The value passed
		 * in is true where upstream may have computed false (unmodelled
		 * request-identity terms), so a passthrough changes nothing and only an
		 * explicit false publishes. Residual 4: a strictly value-inverting
		 * callback would publish in exactly those states. Applied after the grace
		 * short-circuit, as upstream does.
		 *
		 * @since WooCommerce 7.9.0
		 *
		 * @param bool      $email_verification_required Whether the visitor must verify the billing email first.
		 * @param \WC_Order $order                       The order whose visibility is being decided.
		 * @param string    $context                     The context the check runs in.
		 */
		return (bool) apply_filters( 'woocommerce_order_email_verification_required', true, $order, 'order-received' );
	}

	/**
	 * Reliable purchase tracking fallback: when "purchase on any page" is on and the
	 * order-received page did not fire the purchase this request, emit it for the
	 * order remembered in the session (PurchaseTracking::remember_order()) on the
	 * next page the customer views. The tracked flag, age gate and browser cookie
	 * prevent double counting.
	 *
	 * Also seeded by an order-received render for an order that got there before
	 * its status became trackable (Stripe with webhooks). The marker is consumed on
	 * every outcome but one: an order that is not trackable YET
	 * (ProductData::may_become_trackable()) is left in the session with nothing
	 * emitted - not even orderData, which GTM setups use as the "confirmation"
	 * signal - so the next page view asks again. Bounded: a terminal status, the
	 * re-check window, "Maximum order age" or a tracked flag written elsewhere all
	 * consume it on the next check.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function maybe_add_pending_purchase( array $data_layer ): array {
		if ( true !== $this->options->get( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE ) ) {
			return $data_layer;
		}

		if ( ! empty( $GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] ) ) {
			return $data_layer;
		}

		$order_id = $this->pending_session_order_id();
		if ( $order_id <= 0 ) {
			return $data_layer;
		}

		$order = wc_get_order( $order_id );

		// Still waiting for the status to catch up: keep the marker, emit nothing,
		// and leave the request-scoped flag down as well - nothing was pushed, and
		// a status change landing later in this very request (a gateway callback
		// rendered as a page) must still be able to seed through remember_order().
		if ( $order instanceof \WC_Order && $this->product_data->may_become_trackable( $order, $order_id ) ) {
			return $data_layer;
		}

		// Every other outcome consumes the pending marker - the purchase is emitted
		// below, or the order is gone, already tracked, terminal or too old - so the
		// fallback does not re-evaluate the same order on every subsequent page view.
		$this->clear_pending_session_order();
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		if ( ! ( $order instanceof \WC_Order ) ) {
			return $data_layer;
		}

		return $this->add_purchase_for_order( $data_layer, $order, $order_id );
	}

	/**
	 * Runs the purchase eligibility gauntlet on a resolved order and, when it passes,
	 * adds the raw order data, queues the GA4 purchase event inside the browser-side
	 * duplicate guard and flags the order as tracked. Shared by the order-received
	 * page and the session fallback so both produce identical output.
	 *
	 * @param array<string, mixed> $data_layer             The data layer collected so far.
	 * @param \WC_Order            $order                  The resolved order.
	 * @param int                  $order_id               The order id (for the cookie dedupe check).
	 * @param bool                 $withhold_customer_data Whether to leave the customer identity
	 *                                                     blocks out (see the caller).
	 * @return array<string, mixed>
	 */
	private function add_purchase_for_order( array $data_layer, \WC_Order $order, int $order_id, bool $withhold_customer_data = false ): array {
		if ( $this->product_data->is_order_older_than_max_age( $order ) ) {
			return $data_layer;
		}

		$order_items = null;

		// Raw order data will be output regardless of whether the purchase has been already tracked previously, since this data is not meant to track using GA.
		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERDATA ) ) {
			$order_items             = $this->product_data->process_order_items( $order );
			$data_layer['orderData'] = $this->product_data->get_raw_order_datalayer( $order, $order_items );

			// Dropped after the GTM4WP_WPFILTER_EEC_ORDER_DATA filter so the filter
			// keeps seeing its usual shape; a filter that copied billing details onto
			// its own key survives this. The line is IDENTITY, not sensitivity: only
			// 'customer' is withheld. Do NOT extend the unset to 'attributes' or
			// 'totals' - the purchase event does not duplicate them, and
			// orderData.attributes.order_number is named in readme.txt as a variable
			// containers read.
			if ( $withhold_customer_data ) {
				unset( $data_layer['orderData']['customer'] );
			}
		}

		// The canonical eligibility gauntlet (age / already-tracked / status); the
		// separate age check above only keeps orderData off too-old orders as well.
		if ( ! $this->product_data->is_order_trackable( $order, $order_id ) ) {
			// Withheld on the status alone, with the order still able to reach a
			// tracked status (the customer arrived before the gateway's webhook moved
			// it out of "Pending payment"): remember it in the buyer's session so the
			// reliable-purchase fallback re-checks it on their next page views. The
			// order stays unflagged, so a revisit of this page fires as before, and
			// orderData above was still written for this render. No-op unless
			// "Reliable purchase tracking" is on, and for every other reason to
			// withhold (already tracked, terminal, too old).
			//
			// Not for a visitor WooCommerce itself would hide the order from: the
			// re-check emits from the SESSION, where the order is taken to be the
			// buyer's own and the customer identity blocks are not withheld, so
			// seeding here for a holder of a forwarded confirmation URL would hand
			// them, on their next page view, exactly the identity data this render
			// just kept from them. The buyer arriving from checkout is logged in or
			// inside the verification grace period and passes this gate.
			if ( ! $withhold_customer_data ) {
				$this->product_data->remember_if_may_become_trackable( $order, $order_id );
			}

			return $data_layer;
		}

		// new_customer / customer_type describe the BUYER, so they are withheld with
		// 'customer' and user_data - omitted, not emitted falsy (RI-13, #121). The
		// sibling call sites (resolve_pending_purchase(), PurchaseTracking::on_thankyou())
		// are deliberately not gated: there the visitor is the buyer by construction.
		if ( ! $withhold_customer_data ) {
			$data_layer = array_merge( $data_layer, $this->product_data->customer_signals( $order ) );
		}

		$purchase_data_layer = $this->product_data->get_purchase_datalayer( $order, $order_items );

		// user_data is the purchase event's own copy of the customer identity, so it
		// is withheld with orderData.customer; the event itself is untouched.
		if ( $withhold_customer_data ) {
			unset( $purchase_data_layer['user_data'] );
		}

		// "Do not flag orders as being tracked" skips the browser guard as well as
		// the server-side flag, or a stale localStorage flag is left behind (#369).
		if ( (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ) ) {
			$before_purchase_dl_push = '';
			$after_purchase_dl_push  = '';
		} else {
			list( $before_purchase_dl_push, $after_purchase_dl_push ) = $this->purchase_dedupe_guard( $order );
		}

		$this->datalayer->queue_push(
			$purchase_data_layer['event'],
			$purchase_data_layer,
			$before_purchase_dl_push,
			$after_purchase_dl_push
		);

		$this->product_data->flag_order_tracked( $order );
		$this->clear_pending_session_order();

		return $data_layer;
	}

	/**
	 * The browser-side duplicate-tracking guard around the purchase push (a "before"
	 * fragment that pushes only when the order is not yet recorded, an "after" one
	 * that records it). Implemented in GTM4WP\Ecommerce\Helpers so every store
	 * integration shares it; escaping and storage-key contract documented there.
	 *
	 * @param \WC_Order $order The order being tracked.
	 * @return array{0:string,1:string} The before and after JavaScript fragments.
	 */
	private function purchase_dedupe_guard( \WC_Order $order ): array {
		return \GTM4WP\Ecommerce\Helpers::purchase_dedupe_guard( (string) $order->get_order_number() );
	}

	/**
	 * Id of the order remembered in this browser's WooCommerce session, or 0.
	 * Prefers the PurchaseTracking::remember_order() marker and falls back to
	 * WooCommerce's own "order awaiting payment" value; the eligibility gauntlet
	 * still decides whether it is tracked.
	 *
	 * @return int
	 */
	private function pending_session_order_id(): int {
		$woo = function_exists( 'WC' ) ? WC() : null;
		if ( ! $woo || empty( $woo->session ) ) {
			return 0;
		}

		$order_id = absint( $woo->session->get( ProductData::PENDING_PURCHASE_SESSION_KEY ) );
		if ( $order_id <= 0 ) {
			$order_id = absint( $woo->session->get( 'order_awaiting_payment' ) );
		}

		return $order_id;
	}

	/**
	 * Clears the pending-purchase marker from the WooCommerce session (only the
	 * plugin's own key, never WooCommerce's order_awaiting_payment).
	 *
	 * @return void
	 */
	private function clear_pending_session_order(): void {
		$woo = function_exists( 'WC' ) ? WC() : null;
		if ( $woo && ! empty( $woo->session ) ) {
			$woo->session->set( ProductData::PENDING_PURCHASE_SESSION_KEY, null );
		}
	}

	/**
	 * Returns WooCommerce with its session and cart loaded for the CURRENT request,
	 * or null. WooCommerce does not initialize its session on a REST request
	 * (WooCommerce::init() skips it when is_rest_api_request()), so on the session
	 * endpoint every one-shot resolver would silently return null and the event
	 * would be lost; wc_load_cart() is WooCommerce's own remedy (its Store API calls
	 * it per request).
	 *
	 * @return object|null WooCommerce, or null when it is unavailable.
	 */
	private function load_wc(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$woo = WC();
		if ( ! $woo ) {
			return null;
		}

		if (
			empty( $woo->session )
			&& function_exists( 'wc_load_cart' )
			&& did_action( 'before_woocommerce_init' )
		) {
			wc_load_cart();
		}

		return $woo;
	}

	/**
	 * Same as load_wc(), but only when a one-shot event is pending for this browser
	 * (used by the read-only GET resolvers). The gate is not just speed: loading the
	 * session on every endpoint request would hand a fresh WooCommerce session
	 * cookie to visitors who have none, and page caches bypass for any visitor
	 * carrying one, defeating the cache-safe mode. Only the cookie's PRESENCE is
	 * read; PurchaseTracking and ListTracking set it together with the session
	 * marker. The order_awaiting_payment fallback in pending_session_order_id()
	 * therefore only resolves once a real one-shot flagged the cookie - acceptable,
	 * remember_order()'s hooks cover every payment method.
	 *
	 * @return object|null WooCommerce with a live session, or null.
	 */
	private function oneshot_wc(): ?object {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the value is never read, and every id still comes from the server-side session.
		if ( ! isset( $_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] ) ) {
			return null;
		}

		return $this->load_wc();
	}

	/**
	 * Whether the customer/cart block is delivered client-side over the
	 * cart-fragments AJAX (cache-safe data layer, issue #398): the mode is on and at
	 * least one of customer data / cart content is enabled. Static and Options-based
	 * because four behaviours (footer placeholder, fragments filter, visitor-data
	 * runtime, wc-cart-fragments enqueue) must agree, two of them wired from
	 * WooCommerceModule; a second copy of the condition would drift silently.
	 *
	 * @param Options $options The plugin options service.
	 * @return bool
	 */
	public static function delivers_visitor_cart_client_side( Options $options ): bool {
		return (bool) $options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER )
			&& (
				(bool) $options->get( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA )
				|| (bool) $options->get( GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL )
			);
	}

	/**
	 * Builds the customer + cart block for the current session with the same
	 * builders as the server path, so the client gets identical keys and values.
	 * No id parameter: a caller only ever gets its own data. The two families are
	 * separate parts because each is its own event (gtm4wp.customerData /
	 * gtm4wp.cartData), split here where the producing builder is known rather
	 * than by key prefix on the client. A part is omitted only when its builder
	 * wrote no keys (feature off, no WC_Customer) - an empty cart still produces a
	 * cart part, since "now empty" is the signal after the last remove_from_cart.
	 *
	 * @return array<string, array<string, mixed>> The 'customer' and/or 'cart' part.
	 */
	public function visitor_cart_datalayer(): array {
		$woo = function_exists( 'WC' ) ? WC() : null;
		if ( ! $woo ) {
			return array();
		}

		$data = array();

		// Built independently, from a fresh array each — neither builder may read what
		// the other wrote, or a key could land under the wrong event name.
		$customer = $this->add_customer_data( array(), $woo );
		if ( array() !== $customer ) {
			$data['customer'] = $customer;
		}

		$cart = $this->add_cart_content( array(), $woo );
		if ( array() !== $cart ) {
			$data['cart'] = $cart;
		}

		return $data;
	}

	/**
	 * Outputs the empty placeholder the cart-fragments AJAX fills with the
	 * customer/cart block; carries no visitor data, so safe in cached HTML.
	 * Hooked to wp_footer.
	 *
	 * @return void
	 */
	public function output_visitor_cart_placeholder(): void {
		echo '<div class="gtm4wp-wc-visitor-data" style="display:none"></div>';
	}

	/**
	 * Carries the customer/cart block on the cart-fragments response, JSON encoded
	 * into a data attribute of the placeholder (esc_attr() for the attribute
	 * context; the client reads it via dataset + JSON.parse). Do NOT add
	 * JSON_FORCE_OBJECT: it would turn cartContent.items and applied_coupons into
	 * objects. The key is emitted even for an empty payload, or WooCommerce would
	 * leave the stale cached fragment in the DOM. Hooked to
	 * woocommerce_add_to_cart_fragments.
	 *
	 * @param mixed $fragments The cart fragments map (selector => HTML).
	 * @return array<string, string>
	 */
	public function add_visitor_cart_fragment( $fragments ): array {
		if ( ! is_array( $fragments ) ) {
			$fragments = array();
		}

		$json = wp_json_encode(
			$this->visitor_cart_datalayer(),
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		$fragments['div.gtm4wp-wc-visitor-data'] = '<div class="gtm4wp-wc-visitor-data" style="display:none" data-gtm4wp-visitor-cart="' . esc_attr( (string) $json ) . '"></div>';

		return $fragments;
	}

	/**
	 * Declares the two WooCommerce one-shot events the cache-safe data layer
	 * delivers via the session endpoint (issue #398): the add_to_cart after the cart
	 * "Undo", and the reliable-purchase fallback. Both are Tier 3 one-shots gated by
	 * Helpers::ONESHOT_EVENT_COOKIE, declared whenever the mode is on regardless of
	 * the current marker state, because the delivering fetch happens on a LATER page
	 * than the one that queued the event; a resolver returns null when nothing is
	 * pending. Hooked to GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS.
	 *
	 * @param array<int, VisitorField> $fields Visitor-scoped fields declared so far.
	 * @return array<int, VisitorField>
	 */
	public function declare_visitor_scoped_fields( array $fields ): array {
		if ( ! (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER ) ) {
			return $fields;
		}

		$event_cookie = Helpers::ONESHOT_EVENT_COOKIE;

		$fields[] = new VisitorField(
			'readdedToCart',
			VisitorField::TIER_ACTION,
			'',
			array( $this, 'resolve_readded_to_cart' ),
			$event_cookie,
			true,
			// POST beacon fired after delivery, so the GET stays read-only (issue #398).
			rest_url( VisitorDataEndpoint::REST_NAMESPACE . self::REST_ROUTE_CONFIRM_READD )
		);

		if ( true === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE ) ) {
			$fields[] = new VisitorField(
				'pendingPurchase',
				VisitorField::TIER_ACTION,
				'',
				array( $this, 'resolve_pending_purchase' ),
				$event_cookie,
				true,
				// POST beacon fired after delivery; it writes _ga_tracked, closing the
				// cross-device double-count while the GET stays read-only.
				rest_url( VisitorDataEndpoint::REST_NAMESPACE . self::REST_ROUTE_CONFIRM_PURCHASE )
			);
		}

		return $fields;
	}

	/**
	 * Session-endpoint resolver for the re-added-to-cart one-shot: mirrors
	 * maybe_add_readded_to_cart() but RETURNS the add_to_cart payload, with the
	 * session cart-item key as the de-dupe token. No id parameter, so a caller only
	 * gets its own re-add; null when nothing is pending.
	 *
	 * READ-ONLY: runs on a public GET, so it must not change state - a cross-site
	 * top-level navigation (SameSite=Lax cookies attached) could otherwise destroy a
	 * real visitor's pending event. The marker is consumed by the POST beacon
	 * (confirm_readded_to_cart_tracked).
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve_readded_to_cart(): ?array {
		$woo = $this->oneshot_wc();
		if ( ! $woo || empty( $woo->session ) || empty( $woo->cart ) ) {
			return null;
		}

		$cart_readded_hash = $woo->session->get( 'gtm4wp_product_readded_to_cart' );
		if ( ! isset( $cart_readded_hash ) ) {
			return null;
		}

		$cart_item = $woo->cart->get_cart_item( $cart_readded_hash );
		if ( empty( $cart_item ) ) {
			return null;
		}

		$product = $cart_item['data'];

		$eec_product_array = $this->product_data->process_product(
			$product,
			$this->cart_line_attributes( $cart_item ),
			'readdedtocart',
			$cart_item
		);

		unset( $eec_product_array['internal_id'] );

		$gtm4wp_currency = get_woocommerce_currency();

		return array(
			'push'  => array(
				'event'     => 'add_to_cart',
				'ecommerce' => array(
					'currency' => $gtm4wp_currency,
					'value'    => $eec_product_array['price'] * $eec_product_array['quantity'],
					'items'    => array( $eec_product_array ),
				),
			),
			// De-dupe token, recorded in localStorage after the push.
			'token' => (string) $cart_readded_hash,
		);
	}

	/**
	 * Session-endpoint resolver for the reliable-purchase fallback one-shot: mirrors
	 * maybe_add_pending_purchase()/add_purchase_for_order() but RETURNS the purchase
	 * payload, with the order NUMBER so the client de-dupes against the same
	 * gtm4wp_orderid_tracked guard the order-received page writes. The order comes
	 * from the current session (no id parameter, no IDOR) and runs the same
	 * gauntlet; null when nothing is eligible.
	 *
	 * READ-ONLY: runs on a public GET, so no _ga_tracked write and no session write
	 * (see resolve_readded_to_cart for why). Both happen in the POST beacon
	 * (confirm_pending_purchase_tracked); until it lands a repeat fetch re-resolves
	 * the same order and the client-side guard stops a second push.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve_pending_purchase(): ?array {
		if ( true !== $this->options->get( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE ) ) {
			return null;
		}

		$woo = $this->oneshot_wc();
		if ( ! $woo ) {
			return null;
		}

		$order_id = $this->pending_session_order_id();
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! ( $order instanceof \WC_Order ) ) {
			return null;
		}

		if ( ! $this->product_data->is_order_trackable( $order, $order_id ) ) {
			// Not trackable YET (the order-received render remembered a still-pending
			// order): tell the client so, with no event to push. The client then keeps
			// the event cookie instead of clearing it, so the next page view fetches
			// again - one request per page view, never a loop, and bounded by the
			// re-check window, after which this returns null and the client clears
			// the cookie. Still read-only: the marker is not consumed here for a
			// terminal or expired order either; it simply stops resolving, the
			// client stops asking, and the session expires with it.
			if ( $this->product_data->may_become_trackable( $order, $order_id ) ) {
				return array( 'pending' => true );
			}

			return null;
		}

		$purchase_data_layer = array_merge(
			$this->product_data->get_purchase_datalayer( $order ),
			$this->product_data->customer_signals( $order )
		);

		// Whether the client consults/records the browser guard; off under "Do not
		// flag orders as being tracked", matching the page path (#369).
		$flag = ! (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG );

		return array(
			'push'        => $purchase_data_layer,
			'orderNumber' => (string) $order->get_order_number(),
			'flag'        => $flag,
		);
	}

	/**
	 * Registers the POST routes that confirm a one-shot event was delivered (issue
	 * #398) and perform the state changes the read-only GET does not: consuming the
	 * session marker and, for the purchase fallback, writing _ga_tracked. Hooked to
	 * rest_api_init by WooCommerceModule only when the cache-safe mode is on.
	 *
	 * @return void
	 */
	public function register_confirm_purchase_route(): void {
		// These routes change state, so a cross-origin request is rejected (PA-1).
		if ( true === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE ) ) {
			register_rest_route(
				VisitorDataEndpoint::REST_NAMESPACE,
				self::REST_ROUTE_CONFIRM_PURCHASE,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'confirm_pending_purchase_tracked' ),
					'permission_callback' => array( $this, 'check_confirm_purchase_permission' ),
				)
			);
		}

		register_rest_route(
			VisitorDataEndpoint::REST_NAMESPACE,
			self::REST_ROUTE_CONFIRM_READD,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm_readded_to_cart_tracked' ),
				'permission_callback' => array( $this, 'check_confirm_purchase_permission' ),
			)
		);
	}

	/**
	 * Permission callback for the confirm POSTs. Guest checkout is common, so this is
	 * not a capability gate; the request only flags the caller's own session order
	 * (FP-5). Two checks: the wp_rest nonce (header, or _wpnonce for sendBeacon) is
	 * only a malformed-request filter - for a logged-out caller it is a site-wide
	 * constant per tick and authenticates nobody (#78, FP-5 cond. 3); the request
	 * Origin is the gate, since a page cannot forge it. Do NOT replace the Origin
	 * check with a session-bound token: WordPress's default CORS reflection lets a
	 * third-party page read any token this site hands out. RestCors::restrict_cors()
	 * now stops that reflection, but the two controls stay independent on purpose.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public function check_confirm_purchase_permission( \WP_REST_Request $request ): bool {
		if ( ! RequestOrigin::has_rest_nonce( $request ) ) {
			return false;
		}

		return RequestOrigin::is_same_origin_request();
	}

	/**
	 * POST callback: confirms the client delivered the reliable-purchase fallback
	 * (issue #398). Consumes the session marker and flags the order tracked, so a
	 * later order-received render on another device is suppressed. The order id
	 * comes ONLY from the session marker, never the request body (no IDOR); the
	 * marker is consumed unconditionally so the write happens at most once.
	 *
	 * @return \WP_REST_Response A 204 No Content response.
	 */
	public function confirm_pending_purchase_tracked(): \WP_REST_Response {
		$woo = $this->load_wc();

		if ( $woo && ! empty( $woo->session ) ) {
			$order_id = absint( $woo->session->get( ProductData::PENDING_PURCHASE_SESSION_KEY ) );

			// Consume the marker up front so the flag write happens at most once,
			// regardless of whether the order can still be loaded below (idempotent).
			$this->clear_pending_session_order();

			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );

				if ( $order instanceof \WC_Order ) {
					$this->product_data->flag_order_tracked( $order );
				}
			}
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * POST callback: consumes the re-added-to-cart session marker (issue #398).
	 * Sibling of confirm_pending_purchase_tracked(); reads nothing from the request
	 * body, so a caller can only consume its own re-add. Idempotent.
	 *
	 * @return \WP_REST_Response A 204 No Content response.
	 */
	public function confirm_readded_to_cart_tracked(): \WP_REST_Response {
		$woo = $this->load_wc();

		if ( $woo && ! empty( $woo->session ) ) {
			$woo->session->set( 'gtm4wp_product_readded_to_cart', null );
		}

		return new \WP_REST_Response( null, 204 );
	}
}
