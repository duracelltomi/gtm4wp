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
	 * REST route (relative to VisitorDataEndpoint::REST_NAMESPACE) of the
	 * authenticated POST that confirms the reliable-purchase fallback was delivered
	 * (issue #398): it consumes the session delivery marker and flags the order
	 * _ga_tracked. The session endpoint is a public GET, so it must not mutate
	 * anything — it only READS the marker and returns the payload; this companion
	 * POST (nonce protected, order id taken only from the session marker, never the
	 * request body, so no IDOR) performs every state change. Must match the client
	 * beacon target baked into the visitor-data config (the pendingPurchase
	 * VisitorField's confirm_url).
	 */
	public const REST_ROUTE_CONFIRM_PURCHASE = '/confirm-purchase-tracked';

	/**
	 * REST route (relative to VisitorDataEndpoint::REST_NAMESPACE) of the
	 * authenticated POST that confirms the re-added-to-cart one-shot was delivered
	 * (issue #398), consuming its session marker. The sibling of
	 * REST_ROUTE_CONFIRM_PURCHASE, and for the same reason: the GET that delivers the
	 * event stays read-only, so every state change happens here, behind a nonce.
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

		// Under the cache-safe data layer (issue #398) the customer details and the
		// cart are visitor/session specific, so they must not be baked into
		// cacheable page HTML. They are omitted here and delivered client-side on
		// WooCommerce's cart-fragments response instead (see visitor_cart_datalayer()),
		// where they arrive as the gtm4wp.customerData and gtm4wp.cartData events.
		//
		// That response is one WooCommerce already makes on a store showing a mini-cart;
		// on a store that is not, WooCommerceModule::enqueue_visitor_cart_channel() loads
		// the script itself and the store therefore does pay one uncached wc-ajax round
		// trip per browser tab. Only for a visitor who already has WooCommerce state -
		// the enqueue is gated on that, because the script has no empty-cart bail-out of
		// its own. This comment used to say "no new per-page request is added", which was
		// true before that enqueue existed and is the sort of promise worth correcting
		// rather than leaving for someone to trust.
		//
		// The content-driven events below (view_item / view_cart / begin_checkout /
		// purchase) are URL-scoped or fire only on cache-excluded pages, so they stay
		// server-side.
		$cache_safe = (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );

		if ( ! $cache_safe ) {
			$data_layer = $this->add_customer_data( $data_layer, $woo );
			$data_layer = $this->add_cart_content( $data_layer, $woo );
		}

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

		// The one-shot cookie/session events are visitor/session specific, so they
		// are also withheld from cacheable HTML under the cache-safe data layer.
		if ( ! $cache_safe ) {
			$this->maybe_add_readded_to_cart( $woo );

			// Reliable purchase tracking: if the order-received page was missed (custom
			// thank-you page, order-pay landing, a gateway that never reached it), emit
			// the purchase for the order remembered in this session on whatever page the
			// customer views next. No-op unless the feature is enabled.
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
	 * Builds the process_product() attributes for a cart line, adding the display
	 * price derived from WooCommerce's already-calculated line totals so
	 * process_product() does not recompute wc_get_price_to_display() for every cart
	 * item - the cause of the reported cart/checkout memory exhaustion (#436). The
	 * price is omitted (and process_product() computes it) when the line totals are
	 * not yet available.
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

		// The money totals are cast to float: the WC_Cart getters pass through
		// woocommerce_cart_* filters that third-party code may answer with decimal
		// strings, and the data layer encode no longer numeric-coerces
		// (JSON_NUMERIC_CHECK removed), so the totals are typed here to stay real
		// JSON numbers. Coupon codes are identifiers and stay strings.
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

		// GA4 list attribution (#405): a product page is full-page cacheable, so the
		// list the visitor came from must never be baked into this HTML server-side.
		// Instead the push is wrapped in a JS call that merges it from the first-party
		// cookie in the browser - the payload below stays identical for every visitor.
		// The cookie is keyed by the list item's product id, which is what internal_id
		// carries here (the same value the client-side variation path looks up).
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

		/*
		 * Nothing on the page reads these globals unless the classic tracker is on
		 * it, so "the handle is not there" is not a missed attach - it means there
		 * is no reader. That is the ordinary state of a BLOCK-based checkout:
		 * WooCommerceModule::enqueue_scripts() deliberately loads
		 * gtm4wp-woocommerce-blocks INSTEAD of gtm4wp-woocommerce there (the block
		 * tracker reads the wc/store data registry, never these globals) while
		 * is_checkout() is still true here, so this method still runs. Emitting the
		 * fallback in that case would print a payload with no reader, duplicating
		 * the items already queued into the begin_checkout push above.
		 *
		 * 'enqueued' rather than 'registered': a handle another plugin has dequeued
		 * stays registered but is never printed, so it has no reader either. It
		 * stays true after the script is printed (WP_Dependencies::do_items() clears
		 * to_do, not queue), so the already-done case below is still reached.
		 */
		if ( ! wp_script_is( 'gtm4wp-woocommerce', 'enqueued' ) ) {
			return;
		}

		/*
		 * Replaces the deprecated wc_enqueue_js() (WooCommerce 10.4, PA-8). The two
		 * window.* assignments never needed jQuery; 'before' placement emits them
		 * just ahead of the gtm4wp-woocommerce tracker that reads them.
		 *
		 * An inline script can only be attached while its handle is still pending.
		 * This runs on the data layer compile filter fired from wp_head priority 10,
		 * after wp_print_head_scripts() (priority 9), so on a site that filters the
		 * tracker into the <head> instead of the footer the handle is already done
		 * and the attach would silently drop the checkout data - leaving
		 * add_shipping_info / add_payment_info to report an empty item list and a
		 * value of 0. Print the block ourselves in the footer in that case; it is
		 * the same placement the WooCommerce queue used to give us, without the
		 * deprecated call.
		 *
		 * The return value of wp_add_inline_script() is still honoured as a second
		 * leg. With the enqueued check above, core can only return false here for
		 * an empty payload, which this one never is - but the documented contract
		 * of the function is what this depends on, not the internals of
		 * WP_Scripts::add_data().
		 */
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
	 * Prints the checkout globals that could not be attached to the tracker
	 * handle, as a standalone inline script block in the footer. Registered on
	 * wp_footer by add_begin_checkout() only in that fallback case; a no-op
	 * otherwise.
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
				// hash_equals(), not !==: this compares a secret the request supplies
				// against the stored one, and it guards the very same URL as the check
				// in WC_Shortcode_Checkout::order_received(), which has always used
				// hash_equals(). Against a long random key over HTTP the timing channel
				// is not a practical attack; the two checks simply should not differ in
				// kind. Both sides are cast because the value reaching the comparison
				// has passed through a public filter (see above) and hash_equals()
				// throws on a non-string.
				if ( ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
					$order = null;
				}
			} else {
				$order = null;
			}
		}

		// Whether the order was resolved from the REQUEST - the ?order= id plus a
		// matching ?key=. Anyone holding that URL is "the request", which is what
		// woocommerce_hides_order_from_visitor() below reasons about; the session
		// fallback that follows resolves the buyer's own order instead, so it is
		// deliberately exempt.
		$from_request = $order instanceof \WC_Order;

		// Custom order-received page: a bespoke thank-you page (selected in the
		// "Custom order received page" option) carries no order id or key in its
		// URL, so resolve the order from this browser's session instead. The
		// session belongs to the buyer, so no order-key check is possible or needed.
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

		/**
		 * From this point if for any reason purchase data is not pushed
		 * that is because for a specific reason.
		 * In any other case the woocommerce_thankyou hook will be the fallback if
		 * is_order_received_page does not work.
		 */
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		if ( ! ( $order instanceof \WC_Order ) ) {
			return $data_layer;
		}

		// A forwarded or otherwise leaked order-received URL still carries a valid
		// order key, so the checks above cannot tell it apart from the buyer's own
		// visit - but WooCommerce may already have decided not to show that visitor
		// the order (see woocommerce_hides_order_from_visitor()). The purchase event
		// keeps firing on the key alone, since the buyer arriving straight from
		// checkout is logged in or well inside the verification grace period and
		// would otherwise lose the conversion; only the customer identity blocks are
		// withheld, in exactly the cases WooCommerce itself would have declined to
		// render the order.
		$withhold_customer_data = $from_request && $this->woocommerce_hides_order_from_visitor( $order );

		return $this->add_purchase_for_order( $data_layer, $order, (int) $order_id, $withhold_customer_data );
	}

	/**
	 * Whether WooCommerce itself would refuse to render this order to whoever is
	 * making the current request, in which case the data layer withholds the
	 * customer identity blocks (orderData.customer and the purchase event's
	 * user_data) even though the purchase event still fires.
	 *
	 * WooCommerce grew two gates in WC_Shortcode_Checkout::order_received() that
	 * run AFTER the order-key check and return before woocommerce_thankyou, so
	 * nothing on this side observes them:
	 *
	 * 1. A known (non-guest) shopper who is not logged in as that customer gets a
	 *    login form instead of the order (WooCommerce 8.4.0). There is no grace
	 *    period, and the site can turn the gate off with the filter read below.
	 * 2. A guest order past the email-verification grace period gets a "confirm
	 *    your email" form (WooCommerce 7.9.0, grace period filterable through
	 *    woocommerce_order_email_verification_grace_period, 10 minutes by default).
	 *
	 * The second decision is delegated rather than copied: it also weighs the
	 * WooCommerce session, the read_private_shop_orders capability and two more
	 * filters, and re-implementing that here would be a second copy free to drift.
	 * Its home is an Internal namespace with no compatibility promise (UC-2), hence
	 * the guard.
	 *
	 * What the guard must NOT do is treat "helper unavailable" as "nothing to
	 * withhold". That reading was written from the gate's @since and is wrong for a
	 * supported version range: WC_Shortcode_Checkout::order_received() grew
	 * guest_should_verify_email() in WooCommerce 7.9.0, but the Users helper it
	 * delegates to did not exist until 8.6.0 (verified absent in 7.9.0, 8.0.0,
	 * 8.4.0 and 8.5.0). On 7.9.0-8.5.x - inside the declared 5.0 floor - WooCommerce
	 * hides the order while the helper cannot be asked, so returning false there
	 * publishes the identity to a visitor WooCommerce refused to render for.
	 *
	 * So the fallback re-derives the narrow part of the decision that needs no
	 * version test at all: a guest order past the grace period. It is deliberately
	 * more conservative than upstream - it does not model the WooCommerce session or
	 * read_private_shop_orders - which can only ever withhold MORE, the same trade
	 * already accepted for the supplied-email escape hatch below. Fail-closed on a
	 * version number was measured and rejected: this branch runs AFTER the
	 * known-shopper gate, so "withhold" there withholds from the buyer reading their
	 * own order too.
	 *
	 * The supplied-email escape hatch is deliberately not reproduced: WooCommerce
	 * lets a guest POST the billing address into the verification form and unlocks
	 * the render for that one request, which needs its own nonce and field names to
	 * read. Skipping it means the customer block can stay withheld on a render
	 * WooCommerce does show - it fails closed, and the purchase event is unaffected.
	 *
	 * @param \WC_Order $order The order resolved from the request.
	 * @return bool True when the order data must not be attributed to this visitor.
	 */
	private function woocommerce_hides_order_from_visitor( \WC_Order $order ): bool {
		/**
		 * Indicates if known (non-guest) shoppers need to be logged in before we let
		 * them access the order received page. Documented and applied by WooCommerce
		 * itself; read here so the data layer follows the same decision.
		 *
		 * @since WooCommerce 8.4.0
		 *
		 * @param bool $verify_known_shoppers If verification is required.
		 */
		$verify_known_shoppers = (bool) apply_filters( 'woocommerce_order_received_verify_known_shoppers', true );
		$order_customer_id     = (int) $order->get_customer_id();

		if ( $verify_known_shoppers && $order_customer_id > 0 && get_current_user_id() !== $order_customer_id ) {
			return true;
		}

		$users_class = 'Automattic\WooCommerce\Internal\Utilities\Users';

		if ( ! class_exists( $users_class ) || ! method_exists( $users_class, 'should_user_verify_order_email' ) ) {
			// A known shopper is already covered: the gate above returned for the
			// verify-on case, and where the site filtered it off the admin has said
			// out loud that it does not want that check.
			if ( $order_customer_id > 0 ) {
				return false;
			}

			/**
			 * Documented and applied by WooCommerce itself; read here so the
			 * fallback uses the site's own grace period rather than a second
			 * hardcoded one.
			 *
			 * @since WooCommerce 7.9.0
			 *
			 * @param int $grace_period Seconds an order stays viewable without verification.
			 */
			$grace_period = (int) apply_filters( 'woocommerce_order_email_verification_grace_period', 10 * MINUTE_IN_SECONDS );
			$created      = $order->get_date_created();

			// No date is the "cannot tell" case, and this is the branch where we
			// cannot ask upstream either - so it withholds rather than guesses.
			return ! ( $created && ( time() - $created->getTimestamp() ) < $grace_period );
		}

		return (bool) $users_class::should_user_verify_order_email( $order->get_id(), null, 'order-received' );
	}

	/**
	 * Reliable purchase tracking fallback. When the "purchase on any page" option
	 * is on and the order-received page did not already fire the purchase this
	 * request, emit the purchase for the order remembered in this browser's
	 * session (seeded by PurchaseTracking::remember_order() at payment/status
	 * time). This fires on whatever page the customer views next - so a customized
	 * thank-you page, or landing on the order-pay page, no longer loses the sale.
	 * The order-tracked flag, age gate and browser cookie prevent double counting.
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

		// Consume the pending marker regardless of the outcome so the fallback does
		// not re-evaluate the same order on every subsequent page view.
		$this->clear_pending_session_order();
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		if ( ! ( $order instanceof \WC_Order ) ) {
			return $data_layer;
		}

		return $this->add_purchase_for_order( $data_layer, $order, $order_id );
	}

	/**
	 * Runs the purchase eligibility gauntlet on a resolved order and, when it
	 * passes, adds the raw order data, queues the GA4 purchase event wrapped in the
	 * browser-side duplicate guard and flags the order as tracked. Shared by the
	 * standard order-received page and the session fallback so both apply the same
	 * age / already-tracked / trackable-status rules and produce identical output.
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

			// Dropped after the GTM4WP_WPFILTER_EEC_ORDER_DATA filter rather than
			// never built, so the filter keeps seeing the shape it has always been
			// handed. Be precise about what that ordering buys: this is the last
			// write to the 'customer' KEY, not to orderData as a whole, so a filter
			// that copies billing details onto a key of its own survives this unset.
			// The gate has the final say over the key it names and no say over
			// anything else a third party writes.
			//
			// The line drawn here is IDENTITY, not sensitivity. 'customer' holds the
			// names, addresses, email, phone and their hashes, and that is the whole
			// of what is withheld; everything else in orderData describes the ORDER
			// rather than the buyer, and this visitor is already being told about the
			// order by the purchase event that deliberately keeps firing.
			//
			// Do NOT extend this unset to 'attributes' or 'totals' on the theory that
			// the purchase event duplicates them. Measured, it does not: it carries
			// neither the creation date, the payment method, the payment method title,
			// the shipping method nor the status, and it omits six of the ten totals.
			// Extending it would also delete values this visitor demonstrably already
			// holds - the order key they supplied in the URL, and the order number the
			// duplicate guard prints beside this block - and orderData.attributes.order_number
			// is named in readme.txt as a variable containers read, so removing it
			// breaks them with no error.
			//
			// No is_array() guard: get_raw_order_datalayer() declares an array return,
			// so a filter handing back a scalar is a TypeError there, not here.
			if ( $withhold_customer_data ) {
				unset( $data_layer['orderData']['customer'] );
			}
		}

		// The canonical eligibility gauntlet (age / already-tracked / status).
		// The separate age check above only exists so orderData is skipped for
		// too-old orders as well; the composite re-runs it for free.
		if ( ! $this->product_data->is_order_trackable( $order, $order_id ) ) {
			return $data_layer;
		}

		// new_customer / customer_type are derived from the BUYER's order history,
		// which makes them a fact about the person rather than about the order -
		// the same side of the line 'customer' and user_data sit on - so they are
		// withheld with them. They are the only retained values that were on the
		// identity side of that line.
		//
		// Omitted entirely rather than emitted falsy: a consumer's GTM trigger may
		// test for key presence, so inventing a 'returning' would be a behaviour
		// change where an absent key is honest (RI-13's omit-don't-invent, and #121
		// is the recorded case of emitting both keys with one meaningless).
		//
		// The two sibling call sites are deliberately NOT gated. resolve_pending_purchase()
		// resolves the order from the caller's own WC session, and
		// PurchaseTracking::on_thankyou() only runs once WooCommerce has already
		// rendered the order - in both, the visitor is the buyer by construction.
		if ( ! $withhold_customer_data ) {
			$data_layer = array_merge( $data_layer, $this->product_data->customer_signals( $order ) );
		}

		$purchase_data_layer = $this->product_data->get_purchase_datalayer( $order, $order_items );

		// The Enhanced Conversions block is the purchase event's own copy of the
		// customer identity - hashed email and phone, plus the plaintext address
		// Google expects - so it is withheld with orderData.customer, and for the
		// same reason. The event itself (transaction id, value, items) is untouched.
		if ( $withhold_customer_data ) {
			unset( $purchase_data_layer['user_data'] );
		}

		// The browser-side duplicate guard records this order in the
		// gtm4wp_orderid_tracked cookie / localStorage. When the "Do not flag orders
		// as being tracked" option is on, the admin has asked the plugin not to
		// remember tracked orders anywhere, so the browser guard is skipped as well -
		// matching the server-side is_purchase_already_tracked() / flag_order_tracked()
		// short-circuits, which otherwise leave a stale localStorage flag behind (#369).
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
	 * Builds the browser-side duplicate-tracking guard wrapped around the purchase
	 * push: a "before" fragment that only pushes when this order id is not already
	 * recorded in the cookie / local storage, and an "after" fragment that records
	 * it. Extracted so the order-received page and the session fallback share the
	 * exact same guard.
	 *
	 * The cookie read/write idiom emitted below is the PHP-side copy of the
	 * shared helpers in js/frontend/lib/gtm4wp-cookies.js (this inline script
	 * cannot import a bundle module); the storage key and byte format must stay
	 * compatible with that lib and with gtm4wp-visitor-data.js, which reuses the
	 * same gtm4wp_orderid_tracked guard for the fallback purchase.
	 *
	 * @param \WC_Order $order The order being tracked.
	 * @return array{0:string,1:string} The before and after JavaScript fragments.
	 */
	private function purchase_dedupe_guard( \WC_Order $order ): array {
		// Emitted as a JSON string literal (quotes included, so it is NOT wrapped
		// in quotes below) with the full hex flag set - the RI-2 escaper for an
		// inline-script context. It replaces an esc_js() call, which was wrong on
		// two counts: esc_js() is for HTML-attribute JS, not a raw <script> body
		// (PA-4), and it is an ENCODING - it rewrote &, " and < in the order
		// number to &amp;/&quot;/&lt;, which this inline-script path never decodes.
		// The value stored here therefore differed from the raw order number that
		// gtm4wp-visitor-data.js writes to the very same key, so the two guards
		// stopped recognising each other's entries. See the contract on
		// ProductData::ORDER_TRACKED_COOKIE - all three sites store the order
		// number verbatim.
		// json_literal(), not a bare wp_json_encode(): the result is interpolated
		// into three JavaScript EXPRESSION positions below, and the encoder returns
		// false - which PHP renders as '' - for a value it cannot encode, leaving
		// `( == gtm4wp_orderid_tracked )`. That is a SyntaxError taking the whole
		// duplicate-purchase guard with it (RI-21/#141).
		//
		// The value is a (string) cast scalar, so today the encoder cannot actually
		// fail on it. Routed through the shared helper anyway, because an
		// undocumented exemption is indistinguishable from an oversight, and the
		// next person to widen this line should not have to re-derive the argument.
		$order_number = ScriptTag::json_literal(
			(string) $order->get_order_number(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		$storage_key = ProductData::ORDER_TRACKED_COOKIE;

		$before_purchase_dl_push = '
			// Check whether this order has been already tracked in this browser.

			// Read order number already tracked from cookies or local storage.
			let gtm4wp_orderid_tracked = "";

			if ( !window.localStorage ) {
				let gtm4wp_cookie = "; " + document.cookie;
				let gtm4wp_cookie_parts = gtm4wp_cookie.split( "; ' . $storage_key . '=" );
				if ( gtm4wp_cookie_parts.length == 2 ) {
					gtm4wp_orderid_tracked = gtm4wp_cookie_parts.pop().split(";").shift();
				}
			} else {
				gtm4wp_orderid_tracked = window.localStorage.getItem( "' . $storage_key . '" );
			}

			// Check whether this order has been already tracked before in this browser.
			let gtm4wp_order_already_tracked = false;
			if ( gtm4wp_orderid_tracked && ( ' . $order_number . ' == gtm4wp_orderid_tracked ) ) {
				gtm4wp_order_already_tracked = true;
			}

			// only push purchase action if not tracked already.
			if ( !gtm4wp_order_already_tracked ) {';

		$after_purchase_dl_push = '
			}

			// Store the order number to prevent tracking this purchase again.
			if ( !window.localStorage ) {
				var gtm4wp_orderid_cookie_expire = new Date();
				gtm4wp_orderid_cookie_expire.setTime( gtm4wp_orderid_cookie_expire.getTime() + (365*24*60*60*1000) );
				var gtm4wp_orderid_cookie_expires_part = "expires=" + gtm4wp_orderid_cookie_expire.toUTCString();
				document.cookie = "' . $storage_key . '=" + ' . $order_number . ' + ";" + gtm4wp_orderid_cookie_expires_part + ";path=/";
			} else {
				window.localStorage.setItem( "' . $storage_key . '", ' . $order_number . ' );
			}';

		return array( $before_purchase_dl_push, $after_purchase_dl_push );
	}

	/**
	 * Returns the id of the order remembered in this browser's WooCommerce session
	 * waiting for its purchase event, or 0 when there is none. Prefers the marker
	 * seeded by PurchaseTracking::remember_order() and falls back to WooCommerce's
	 * own "order awaiting payment" value (useful for a custom thank-you page where
	 * the seed hooks did not run). The eligibility gauntlet still gates whether the
	 * resolved order is actually tracked.
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
	 * or null when WooCommerce cannot provide them.
	 *
	 * WooCommerce does NOT initialize its session on a REST request: WooCommerce::init()
	 * calls initialize_session() only when is_request( 'frontend' ) is true, and that
	 * check ends in `&& ! $this->is_rest_api_request()`. So on the cache-safe session
	 * endpoint WC()->session is null, every one-shot resolver takes its "no session"
	 * guard and silently returns null — and because the cache-safe mode ALSO omits
	 * these events from the page HTML, the purchase / add_to_cart would be lost
	 * outright rather than merely delayed. wc_load_cart() is WooCommerce's own remedy
	 * for exactly this context; its Store API calls it per request
	 * (StoreApi AbstractCartRoute::load_cart_session()).
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
	 * Same as load_wc(), but only when a WooCommerce one-shot event is actually
	 * pending for this browser — used by the read-only GET resolvers.
	 *
	 * The gate matters for more than speed. Loading the session + cart on EVERY
	 * session-endpoint request would make WooCommerce hand a fresh session cookie to
	 * visitors who have none, and page caches routinely bypass the cache for any
	 * visitor carrying one — which would defeat the very mode this code serves. The
	 * client only fetches a one-shot while its event cookie is present, so honouring
	 * the same gate server-side keeps the common path (an anonymous visitor fetching
	 * Tier 2 once per session) free of any WooCommerce session work.
	 *
	 * Only the cookie's PRESENCE is read, never its value: PurchaseTracking and
	 * ListTracking set it (to a constant '1') alongside the session marker, so
	 * "marker pending" and "cookie present" are written together. The one exception is
	 * pending_session_order_id()'s WooCommerce `order_awaiting_payment` fallback, which
	 * WooCommerce sets without our cookie; under the cache-safe mode that fallback
	 * therefore only resolves once a real one-shot has flagged the cookie — acceptable,
	 * because remember_order()'s three hooks already cover every payment method.
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
	 * Whether the WooCommerce customer/cart data layer block is delivered
	 * client-side over the cart-fragments AJAX (cache-safe data layer, issue #398):
	 * the mode is on and at least one of the customer-data / cart-content features
	 * is enabled. When on, add_datalayer_data() omits the same block from the
	 * cacheable page HTML and it rides the fragments response instead.
	 *
	 * Static, and taking the Options service rather than reading $this, because four
	 * separate behaviours have to agree on this one answer — the wp_footer
	 * placeholder, the fragments filter, the gtm4wp-visitor-data runtime and the
	 * wc-cart-fragments enqueue — and two of them are wired from WooCommerceModule,
	 * which holds no PageDataLayer instance. A second copy of the condition there
	 * would break the delivery silently the first time the two drifted. Mirrors
	 * VisitorDataModule::is_enabled(), the same kind of shared read.
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
	 * Builds the customer + cart data layer block for the current session, reusing
	 * the exact server-path builders so the client receives identical values under
	 * identical key names. Empty when neither feature is enabled or WooCommerce is
	 * unavailable. Derives everything from the current request's WC session/customer
	 * — no id parameter — so a caller only ever gets its own data.
	 *
	 * The two families are returned as SEPARATE parts rather than one flat array
	 * because each is delivered as its own data layer event (gtm4wp.customerData /
	 * gtm4wp.cartData), so a Google Tag Manager setup can tell from the event name
	 * alone which keys arrived. The split is made here, where the builder that
	 * produced each key is known, so the client never has to classify keys by their
	 * name prefix — that would freeze today's naming into a client-side validator and
	 * mis-file the first key that does not match it.
	 *
	 * A part is omitted when its builder wrote no keys at all (its feature is off, or
	 * there is no WC_Customer on this request). Note that this is deliberately NOT a
	 * test of the part's contents: an EMPTY CART still produces a cart part (with
	 * items: [] and zeroed totals), because "the cart is now empty" is exactly the
	 * signal a tag reads after the last remove_from_cart. Likewise an anonymous
	 * visitor still produces a customer part, with the same blank values the
	 * server-rendered path emits for them — the contract of the cache-safe mode is the
	 * same keys with the same values, only delivered differently.
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
	 * Outputs the empty, cache-safe placeholder element the cart-fragments AJAX
	 * fills with the customer/cart block. It carries no visitor data itself, so it
	 * is safe to bake into the cached HTML; WooCommerce replaces it with the filled
	 * version (from the fragments response and its sessionStorage cache) on every
	 * page. Hooked to wp_footer.
	 *
	 * @return void
	 */
	public function output_visitor_cart_placeholder(): void {
		echo '<div class="gtm4wp-wc-visitor-data" style="display:none"></div>';
	}

	/**
	 * Carries the two-part customer/cart data layer block on the WooCommerce
	 * cart-fragments response, so it is delivered — and refreshed on every cart
	 * change — without any new per-page request (the fragments AJAX already fires on
	 * cart mutation). The block is JSON encoded into a data attribute of the
	 * placeholder; esc_attr() is the correct escaper for the attribute context (the
	 * client reads it back via dataset and JSON.parse, and the JSON_HEX_* flags keep
	 * any hostile customer field free of a raw break-out). JSON_FORCE_OBJECT must NOT
	 * be added to the flag set: it would turn cartContent.items and
	 * totals.applied_coupons into objects and break every setup that iterates them.
	 * Hooked to woocommerce_add_to_cart_fragments.
	 *
	 * The fragment key is emitted even when the payload is empty, so WooCommerce
	 * always replaces the placeholder: dropping the key would leave the previously
	 * cached fragment — with its stale customer/cart data — in the DOM.
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
	 * Declares the two WooCommerce one-shot EVENTS the cache-safe data layer
	 * delivers client-side in Phase 3 (issue #398): the add_to_cart fired after a
	 * product is re-added to the cart (the cart "Undo"), and the reliable-purchase
	 * fallback for a missed order-received page. Both are omitted from the cacheable
	 * HTML (see add_datalayer_data) and delivered via the session endpoint instead.
	 *
	 * Each is a Tier 3 one-shot field gated by the shared event cookie
	 * (Helpers::ONESHOT_EVENT_COOKIE): PHP sets that cookie when the event is queued
	 * in the session, and the client fetches, fires once + de-dupes, then clears it.
	 * They are declared whenever the cache-safe mode is on (the fallback additionally
	 * requires the "purchase on any page" option) and independent of the current
	 * marker/cookie state, because the delivering fetch happens on a LATER page than
	 * the one that queued the event — so the client config must always advertise the
	 * event cookie to watch. When nothing is pending the resolvers return null and
	 * the event is simply not delivered.
	 *
	 * Hooked to GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS.
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
			// The client fires this authenticated POST beacon after delivering the
			// re-add, so the GET stays read-only while the session marker is still
			// consumed server-side (issue #398).
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
				// The client fires this authenticated POST beacon after delivering the
				// fallback purchase, so the GET stays read-only while _ga_tracked is
				// still written server-side — closing the cross-device double-count.
				rest_url( VisitorDataEndpoint::REST_NAMESPACE . self::REST_ROUTE_CONFIRM_PURCHASE )
			);
		}

		return $fields;
	}

	/**
	 * Session-endpoint resolver for the re-added-to-cart one-shot (Phase 3). Mirrors
	 * maybe_add_readded_to_cart() but RETURNS the add_to_cart payload for the client
	 * to push (under the same event name the server path used) instead of rendering
	 * it into cacheable HTML, and carries the session cart-item key as the per-event
	 * de-dupe token so a page reload does not re-fire it. Derives everything from the
	 * current request's WC session/cart — no id parameter, so a caller only ever gets
	 * its own re-add. Returns null when nothing is pending.
	 *
	 * READ-ONLY: this runs on a public, unauthenticated GET, so it must not change
	 * state — otherwise any cross-site top-level navigation to the endpoint (which
	 * carries the visitor's SameSite=Lax cookies) would consume a real visitor's
	 * pending event and destroy it. The marker is consumed by the authenticated POST
	 * beacon (confirm_readded_to_cart_tracked) once the client has actually delivered
	 * the event; until then the client's own per-token guard stops a re-push.
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
			// The de-dupe token: the WC session re-add key. Recorded in localStorage
			// after the push so a reload with the same token does not re-fire.
			'token' => (string) $cart_readded_hash,
		);
	}

	/**
	 * Session-endpoint resolver for the reliable-purchase fallback one-shot (Phase 3).
	 * Mirrors maybe_add_pending_purchase()/add_purchase_for_order() but RETURNS the GA4
	 * purchase event payload for the client to push (under the same event name the
	 * server path used) instead of rendering it into cacheable HTML, and carries the
	 * order NUMBER so the client de-dupes against the SAME gtm4wp_orderid_tracked guard
	 * the order-received page's inline block writes — so a fallback fire on one page and
	 * a real order-received purchase for the same order can never both count.
	 *
	 * The order is resolved from the CURRENT request's WC session (no id parameter, no
	 * IDOR) and runs the same age / already-tracked / trackable-status gauntlet as the
	 * page path. Returns null when nothing is eligible.
	 *
	 * READ-ONLY: this runs on a public, unauthenticated GET, so it changes nothing —
	 * no _ga_tracked order meta, and (since issue #398's review) no session write
	 * either. A GET that consumed the delivery marker could be fired by any cross-site
	 * top-level navigation, which carries the visitor's SameSite=Lax cookies, and would
	 * silently destroy a real buyer's purchase event. Every state change happens in the
	 * authenticated POST beacon (confirm_pending_purchase_tracked), which consumes the
	 * marker and writes _ga_tracked once the client has actually delivered the event.
	 * Until the beacon lands the marker stays put, so a repeat fetch simply re-resolves
	 * the same order and the shared client-side gtm4wp_orderid_tracked guard (keyed on
	 * the order number) stops it being pushed twice.
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
			return null;
		}

		$purchase_data_layer = array_merge(
			$this->product_data->get_purchase_datalayer( $order ),
			$this->product_data->customer_signals( $order )
		);

		// Whether the client should consult/record the gtm4wp_orderid_tracked browser
		// guard. When "Do not flag orders as being tracked" is on the plugin writes no
		// order-tracked state anywhere - server meta OR browser - so the client pushes
		// without the guard, matching the page path (#369).
		$flag = ! (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG );

		return array(
			'push'        => $purchase_data_layer,
			'orderNumber' => (string) $order->get_order_number(),
			'flag'        => $flag,
		);
	}

	/**
	 * Registers the authenticated POST routes that confirm a one-shot event was
	 * delivered (issue #398) and perform every state change the read-only GET session
	 * endpoint deliberately does not: consuming the session delivery marker and, for
	 * the purchase fallback, writing the _ga_tracked order meta so a later
	 * order-received render on ANOTHER device is suppressed. Registered by
	 * WooCommerceModule on rest_api_init only when the cache-safe mode is on (the
	 * purchase route additionally requires the reliable-purchase feature). Hooked to
	 * rest_api_init.
	 *
	 * @return void
	 */
	public function register_confirm_purchase_route(): void {
		// Unlike the read-only GET session endpoint, these routes change state, so they
		// must reject a cross-origin request: the wp_rest REST nonce is verified (PA-1).
		// They are POSTs, so the HTTP semantics are correct.
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
	 * Permission callback for the confirm-purchase POST. A guest checkout is common, so
	 * this cannot be a capability gate — the request only ever flags the caller's own
	 * session order (FP-5). Two checks, and it matters which one is load bearing:
	 *
	 * 1. The wp_rest nonce (X-WP-Nonce header for fetch keepalive, or the _wpnonce
	 *    parameter for the navigator.sendBeacon fallback, which cannot set headers).
	 *    This is a malformed-request FILTER, not the gate: for a logged-out caller
	 *    WordPress derives wp_rest from uid 0 with an empty session token, so the value
	 *    is identical for every guest on the site for the whole nonce tick — and this
	 *    plugin hands one out from its own public GET endpoint. It proves the caller
	 *    obtained a site-wide constant. It authenticates nobody (#78, FP-5 cond. 3).
	 * 2. The request Origin. THIS is the gate. A browser sets Origin on every POST and
	 *    a page cannot forge it, so a cross-site request fails here.
	 *
	 * Binding the token to the WC session instead was considered and does NOT work:
	 * WordPress registers rest_send_cors_headers() on rest_pre_serve_request by default,
	 * which reflects the request Origin and sends Access-Control-Allow-Credentials: true
	 * — so a third-party page can read any token this site hands out, with the visitor's
	 * own cookies attached, and replay it here. A session-bound nonce would look like a
	 * fix and would not be one. (GTM4WP\RestCors::restrict_cors() now stops that
	 * reflection for this plugin's namespace, but the Origin check does not depend on
	 * it - which is the point: the two controls are independent, so neither one being
	 * moved, disabled or missed can quietly take the other with it.)
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public function check_confirm_purchase_permission( \WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}

		return self::is_same_origin_request();
	}

	/**
	 * Whether this request demonstrably originated from a page on this site.
	 *
	 * Origin is the primary signal: browsers send it on every POST, including
	 * same-origin ones, and script cannot set it. Referer is the fallback for the rare
	 * client that omits Origin; it is weaker (a referrer policy can strip it) but it is
	 * only ever consulted when Origin is absent. When neither is present the request is
	 * refused — a state change on behalf of a visitor should come from a page, and
	 * "no evidence" is not the same as "same origin".
	 *
	 * The Referer is read from $_SERVER, NOT through wp_get_raw_referer(): that helper
	 * returns $_REQUEST['_wp_http_referer'] in preference to the header, and a request
	 * parameter is supplied by the very request this function is deciding about. It is
	 * the right helper for restoring a form's return URL and the wrong one for an
	 * access decision — the value has to come from the transport, not the payload.
	 *
	 * @return bool
	 */
	private static function is_same_origin_request(): bool {
		$site = wp_parse_url( home_url() );

		if ( ! is_array( $site ) || empty( $site['host'] ) ) {
			return false;
		}

		$origin = get_http_origin();
		if ( is_string( $origin ) && '' !== $origin ) {
			return self::url_matches_site( $origin, $site );
		}

		// esc_url_raw(), not sanitize_text_field(): the value is a URL that is about
		// to be parsed, and sanitize_text_field() strips every %XX sequence out of
		// whatever it is given. That cannot change this decision today (only host
		// and port are compared, and removing characters can never turn a foreign
		// host into ours), but a gate should not be built on a sanitizer that
		// silently rewrites the thing being judged. The sibling HTTP_REFERER read in
		// PageVariablesModule already uses esc_url_raw().
		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		if ( '' !== $referer ) {
			return self::url_matches_site( $referer, $site );
		}

		return false;
	}

	/**
	 * Whether a URL's host and port are this site's.
	 *
	 * Scheme is deliberately not compared: TLS-terminating proxies and mixed
	 * http/https home_url configurations make it an unreliable signal, while host and
	 * port are what separate this site from an attacker's. A subdomain is a different
	 * host and is therefore refused.
	 *
	 * @param string               $url  The Origin or Referer value.
	 * @param array<string, mixed> $site Parsed home_url() parts.
	 * @return bool
	 */
	private static function url_matches_site( string $url, array $site ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) $site['host'] ) ) {
			return false;
		}

		return self::normalized_port( $parts ) === self::normalized_port( $site );
	}

	/**
	 * A URL's port, with its own scheme's default port reported as "absent".
	 *
	 * A browser never puts the default port in Origin, so a site whose home_url
	 * carries one explicitly (`https://example.com:443`, which some reverse-proxy
	 * setups produce) would otherwise compare 443 against null and refuse every
	 * guest beacon on that site - silently, and fail-closed, which is the shape
	 * that never generates a bug report.
	 *
	 * Each side is normalized against ITS OWN scheme rather than against the
	 * other's. That is what keeps this from reintroducing the scheme comparison
	 * url_matches_site() deliberately leaves out: an http home_url behind a
	 * TLS-terminating proxy and an https Origin both reduce to "no explicit
	 * port" and still match, which is the case the scheme exclusion exists for.
	 * A genuinely different port (:8080, :8443) survives normalization and is
	 * still refused.
	 *
	 * @param array<string, mixed> $parts Parsed URL parts from wp_parse_url().
	 * @return int|null The significant port, or null when it is the scheme default.
	 */
	private static function normalized_port( array $parts ): ?int {
		$defaults = array(
			'http'  => 80,
			'https' => 443,
		);

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		if ( null === $port ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		return ( isset( $defaults[ $scheme ] ) && $defaults[ $scheme ] === $port ) ? null : $port;
	}

	/**
	 * POST callback: confirms the client delivered the reliable-purchase fallback for
	 * the order this browser's session queued (issue #398). It consumes the delivery
	 * marker and flags the order tracked, so a later order-received render on another
	 * device is suppressed by is_purchase_already_tracked().
	 *
	 * This is where the fallback's state changes happen, because the GET that delivers
	 * it is public and unauthenticated (see resolve_pending_purchase). The order id
	 * comes ONLY from the session marker — never from the request body, so a forged
	 * order id flags nothing (no IDOR) — and the marker is consumed unconditionally so
	 * the write happens at most once (a second POST finds no marker and no-ops).
	 * flag_order_tracked() itself no-ops when "Do not flag orders as being tracked" is
	 * on, so that option is honoured here too. The request body is intentionally not
	 * read.
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
	 * POST callback: confirms the client delivered the re-added-to-cart one-shot, so
	 * its session marker can be consumed (issue #398). The sibling of
	 * confirm_pending_purchase_tracked() and, for the same reason, the only place that
	 * re-add's state change happens — the GET that delivers it is public and
	 * unauthenticated. Takes nothing from the request body: the marker is this
	 * browser's own session key, so a caller can only ever consume its own re-add.
	 * Idempotent — a second POST finds no marker and no-ops.
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
