<?php
/**
 * WooCommerce product and order data builders.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds GA4 ecommerce item, order and purchase arrays from WooCommerce
 * objects. Ports gtm4wp_woocommerce_process_product(),
 * gtm4wp_woocommerce_process_order_items(),
 * gtm4wp_woocommerce_get_raw_order_datalayer() and
 * gtm4wp_woocommerce_get_purchase_datalayer() from 1.x.
 */
final class ProductData {

	/**
	 * WooCommerce session key that holds the id of an order placed in this
	 * browser session and still waiting to have its purchase event emitted.
	 * Written by PurchaseTracking::remember_order() and consumed by
	 * PageDataLayer's session fallback / custom order-received page resolution.
	 */
	public const PENDING_PURCHASE_SESSION_KEY = 'gtm4wp_pending_purchase';

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Returns the configured Google Ads business vertical, falling back to
	 * retail for invalid stored values (behavior ported from
	 * gtm4wp_reload_options() in 1.x).
	 *
	 * @return string
	 */
	public function business_vertical(): string {
		$vertical = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL );

		if ( ! in_array( $vertical, Helpers::BUSINESS_VERTICALS, true ) ) {
			return 'retail';
		}

		return $vertical;
	}

	/**
	 * Given a WC_Product instance, this function returns an array of product attributes in the format of
	 * GA4 ecommerce item data.
	 *
	 * @param mixed  $product An instance of WC_Product that needs to be transformed into an ecommerce item object.
	 * @param array  $additional_product_attributes Any key-value pair that needs to be added into the ecommerce item object.
	 * @param string $attributes_used_for The placement ID of the product that is passed to the apply_filters hook so that 3rd party code can be notified where this product data is being used.
	 * @return array|false The ecommerce item object of the WooCommerce product, or false if the product does not exist.
	 */
	public function process_product( $product, array $additional_product_attributes, string $attributes_used_for ) {
		if ( ! $product ) {
			return false;
		}

		if ( ! ( $product instanceof \WC_Product ) ) {
			return false;
		}

		$use_full_category_path = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH );

		$product_id     = $product->get_id();
		$product_type   = $product->get_type();
		$remarketing_id = $product_id;
		$product_sku    = $product->get_sku();

		if ( 'variation' === $product_type ) {
			$parent_product_id = $product->get_parent_id();
			$product_cat       = Helpers::get_product_category( $parent_product_id, $use_full_category_path );
		} else {
			$product_cat = Helpers::get_product_category( $product_id, $use_full_category_path );
		}
		$product_cat_parts = explode( '/', $product_cat );

		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCUSESKU ) && ( '' !== $product_sku ) ) {
			$remarketing_id = $product_sku;
		}

		$_temp_productdata = array(
			'internal_id'              => $product_id,
			'item_id'                  => $remarketing_id,
			'item_name'                => $product->get_title(),
			'sku'                      => $product_sku ? $product_sku : $product_id,
			'price'                    => round( (float) wc_get_price_to_display( $product ), 2 ), // Unfortunately this does not force a .00 postfix for integers.
			'stocklevel'               => $product->get_stock_quantity(),
			'stockstatus'              => $product->get_stock_status(),
			'google_business_vertical' => $this->business_vertical(),
		);

		if ( 'variation' === $product_type ) {
			$_temp_productdata['item_group_id'] = $parent_product_id;
		}

		if ( 1 === count( $product_cat_parts ) ) {
			$_temp_productdata['item_category'] = $product_cat_parts[0];
		} elseif ( count( $product_cat_parts ) > 1 ) {
			$_temp_productdata['item_category'] = $product_cat_parts[0];

			$max_category_levels = min( 5, count( $product_cat_parts ) );
			for ( $i = 1; $i < $max_category_levels; $i++ ) {
				$_temp_productdata[ 'item_category' . ( $i + 1 ) ] = $product_cat_parts[ $i ];
			}
		}

		$_temp_productdata[ Helpers::get_gads_product_id_variable_name( $this->business_vertical() ) ] = Helpers::prefix_productid( $_temp_productdata['item_id'], (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX ) );

		$brand_taxonomy = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY );
		if ( '' !== $brand_taxonomy ) {
			if ( isset( $parent_product_id ) && ( 0 !== $parent_product_id ) ) {
				$product_id_to_query = $parent_product_id;
			} else {
				$product_id_to_query = $product_id;
			}

			$_temp_productdata['item_brand'] = Helpers::get_product_term( $product_id_to_query, $brand_taxonomy );
		}

		if ( 'variation' === $product_type ) {
			$_temp_productdata['item_variant'] = implode( ',', $product->get_variation_attributes() );
		}

		$_temp_productdata = array_merge( $_temp_productdata, $additional_product_attributes );

		/**
		 * Filters the ecommerce array before using it for tracking.
		 * Can be used to add custom dimensions and metrics on your own or to alter existing product attributes based on your own logic.
		 *
		 * Called before outputting any of the following ecommerce action.
		 * The action can be identified using the attributes_used_for parameter of the filter.
		 *
		 * purchase: order received page
		 * cart: cart page
		 * checkout: checkout page
		 * productdetail: product detail page
		 * readdedtocart: user clicked on the "Undo" link on the cart page after removing an item
		 * addtocartsingle: product added to cart
		 * widgetproduct: product shown in a sidebar widget
		 * productlist: product shown in a product list (category page or special product list like 'New products')
		 * groupedproductlist: product shown on a product detail page of a grouped product
		 *
		 * @param array  $_temp_productdata   An associative array containing all GA4 product attributes as well as any custom attribute
		 * @param string $attributes_used_for The name of the ecommerce action where this product will be used
		 */
		return apply_filters( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY, $_temp_productdata, $attributes_used_for );
	}

	/**
	 * Takes a WooCommerce order and returns an array of GA4 ecommerce items.
	 *
	 * @param mixed $order The order that needs to be processed.
	 * @return array An array of product data arrays.
	 */
	public function process_order_items( $order ): array {
		$order_data = array();

		if ( ! $order ) {
			return $order_data;
		}

		if ( ! ( $order instanceof \WC_Order ) ) {
			return $order_data;
		}

		$order_items = $order->get_items();

		if ( $order_items ) {
			foreach ( $order_items as $order_item ) {
				/**
				 * This filter allows 3rd party code to exclude specific products from reporting.
				 *
				 * @param bool          true        Constant value telling 3rd party code that the order item will be included in reporting if not changed by the filter.
				 * @param WC_Order_Item $order_item The order item object retrieved from WooCommerce.
				 *
				 * return bool If the filter returns false, the order item will be omitted from processing.
				 */
				if ( ! apply_filters( GTM4WP_WPFILTER_EEC_ORDER_ITEM, true, $order_item ) ) {
					continue;
				}

				$product       = $order_item->get_product();
				$inc_tax       = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) );
				$product_price = round( (float) $order->get_item_total( $order_item, $inc_tax ), 2 );

				$eec_product_array = $this->process_product(
					$product,
					array(
						'quantity' => $order_item->get_quantity(),
						'price'    => $product_price,
					),
					'purchase'
				);

				unset( $eec_product_array['internal_id'] );

				if ( $eec_product_array ) {
					$order_data[] = $eec_product_array;
				}
			}
		}

		// No need to apply a filter here since all products in the array have been already filtered in process_product().
		return $order_data;
	}

	/**
	 * Returns an associative array that can be used in the data layer to output the raw order data.
	 *
	 * @param mixed $order       The WooCommerce order object.
	 * @param mixed $order_items An array including product data generated with process_product().
	 * @return array
	 */
	public function get_raw_order_datalayer( $order, $order_items ): array {
		$order_data = array();

		if ( ! ( $order instanceof \WC_Order ) ) {
			return $order_data;
		}

		if ( ! is_array( $order_items ) ) {
			return $order_data;
		}

		$billing_email_hash = Helpers::normalize_and_hash_email_address( 'sha256', $order->get_billing_email() );
		$billing_first_hash = Helpers::normalize_and_hash( 'sha256', $order->get_billing_first_name(), false );
		$billing_last_hash  = Helpers::normalize_and_hash( 'sha256', $order->get_billing_last_name(), false );
		$billing_phone_hash = Helpers::normalize_and_hash( 'sha256', $order->get_billing_phone(), true );

		// Values are passed raw: the single output sink (the purchase data
		// layer) runs everything through wp_json_encode() with the full hex
		// flag set, which is the correct escaper for an inline-script context.
		// Pre-escaping with esc_js() here would corrupt the data instead
		// (e.g. "Marks & Spencer" -> "Marks &amp; Spencer") because
		// ScriptTag::print_script_block() no longer HTML-decodes the block.
		$order_data = array(
			'attributes' => array(
				'date'                 => $order->get_date_created()->date( 'c' ),

				'order_number'         => $order->get_order_number(),
				'order_key'            => $order->get_order_key(),

				'payment_method'       => $order->get_payment_method(),
				'payment_method_title' => $order->get_payment_method_title(),

				'shipping_method'      => $order->get_shipping_method(),

				'status'               => $order->get_status(),

				'coupons'              => implode( ', ', $order->get_coupon_codes() ),
			),
			'totals'     => array(
				'currency'       => $order->get_currency(),
				'discount_total' => $order->get_discount_total(),
				'discount_tax'   => $order->get_discount_tax(),
				'shipping_total' => $order->get_shipping_total(),
				'shipping_tax'   => $order->get_shipping_tax(),
				'cart_tax'       => $order->get_cart_tax(),
				'total'          => $order->get_total(),
				'total_tax'      => $order->get_total_tax(),
				'total_discount' => $order->get_total_discount(),
				'subtotal'       => $order->get_subtotal(),
				'tax_totals'     => $order->get_tax_totals(),
			),
			'customer'   => array(
				'id'       => $order->get_customer_id(),

				'billing'  => array(
					'first_name'      => $order->get_billing_first_name(),
					'first_name_hash' => $billing_first_hash,
					'last_name'       => $order->get_billing_last_name(),
					'last_name_hash'  => $billing_last_hash,
					'company'         => $order->get_billing_company(),
					'address_1'       => $order->get_billing_address_1(),
					'address_2'       => $order->get_billing_address_2(),
					'city'            => $order->get_billing_city(),
					'state'           => $order->get_billing_state(),
					'postcode'        => $order->get_billing_postcode(),
					'country'         => $order->get_billing_country(),
					'email'           => $order->get_billing_email(),
					'emailhash'       => $billing_email_hash, // deprecated.
					'email_hash'      => $billing_email_hash,
					'phone'           => $order->get_billing_phone(),
					'phone_hash'      => $billing_phone_hash,
				),

				'shipping' => array(
					'first_name' => $order->get_shipping_first_name(),
					'last_name'  => $order->get_shipping_last_name(),
					'company'    => $order->get_shipping_company(),
					'address_1'  => $order->get_shipping_address_1(),
					'address_2'  => $order->get_shipping_address_2(),
					'city'       => $order->get_shipping_city(),
					'state'      => $order->get_shipping_state(),
					'postcode'   => $order->get_shipping_postcode(),
					'country'    => $order->get_shipping_country(),
				),

			),
			'items'      => $order_items,
		);

		/**
		 * Filters the orderData array before using it for tracking.
		 * Can be used to add custom order or even product data into the data layer.
		 *
		 * @param array  $order_data An associative array containing all data (head data and products) about the currently placed order.
		 * @param WC_Order $order       The WooCommerce order object.
		 */
		return apply_filters( GTM4WP_WPFILTER_EEC_ORDER_DATA, $order_data, $order );
	}

	/**
	 * Takes a WooCommerce order and order items and generates the GA4 purchase data layer content.
	 *
	 * @param mixed      $order The WooCommerce order that needs to be transformed into an ecommerce data layer.
	 * @param array|null $order_items The array returned by process_order_items(). If not set, the function will call process_order_items().
	 * @return array The data layer content as an associative array.
	 */
	public function get_purchase_datalayer( $order, $order_items = null ): array {
		$data_layer = array();

		if ( $order instanceof \WC_Order ) {
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX ) ) {
				$order_revenue = (float) ( $order->get_total() - $order->get_total_tax() );
			} else {
				$order_revenue = (float) $order->get_total();
			}

			$order_shipping_cost = (float) $order->get_shipping_total();

			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING ) ) {
				$order_revenue -= $order_shipping_cost;
			}

			$order_currency = $order->get_currency();

			$data_layer['event']     = 'purchase';
			$data_layer['ecommerce'] = array(
				'currency'       => $order_currency,
				'transaction_id' => $order->get_order_number(),
				'affiliation'    => '',
				'value'          => $order_revenue,
				'tax'            => (float) $order->get_total_tax(),
				'shipping'       => (float) ( $order->get_shipping_total() ),
				'coupon'         => implode( ', ', $order->get_coupon_codes() ),
			);

			if ( isset( $order_items ) ) {
				$_order_items = $order_items;
			} else {
				$_order_items = $this->process_order_items( $order );
			}

			if ( true === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
				$data_layer['ecommerce']['items'] = $_order_items;
			}
		}

		/**
		 * Filters the ecommerce purchase data layer content.
		 * Can be used to add custom data to the data layer when the purchase ecommerce action is included.
		 *
		 * @param array $data_layer An associative array containing the full data layer including purchase header attributes.
		 * @param WC_Order $order The WooCommerce order that needs to be transformed into an ecommerce data layer.
		 */
		return apply_filters( GTM4WP_WPFILTER_ECC_PURCHASE_DATALAYER, $data_layer, $order );
	}

	/**
	 * Whether the order is older than the configured maximum tracking age.
	 * Returns false when no maximum age is configured. The timezone passed to
	 * "now" does not change the computed difference (DateTime::diff compares
	 * instants), so the paid/created reference date is used directly.
	 *
	 * @param \WC_Order $order The order to check.
	 * @return bool
	 */
	public function is_order_older_than_max_age( \WC_Order $order ): bool {
		$max_age = (int) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE );
		if ( $max_age <= 0 ) {
			return false;
		}

		if ( $order->is_paid() && $order->get_date_paid() ) {
			$reference = $order->get_date_paid();
		} else {
			$reference = $order->get_date_created();
		}

		$now     = new \DateTime( 'now', $reference->getTimezone() );
		$diff    = $now->diff( $reference );
		$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;

		return $minutes > $max_age;
	}

	/**
	 * Whether the order's current status makes it eligible for the GA4 purchase
	 * event. The purchase fires at order *placement* - whenever the order reaches
	 * one of the configured statuses - not when payment physically clears. So a
	 * Cash on Delivery order (processing) or a bank-transfer order (on-hold) is
	 * tracked at checkout even though the money arrives later, while a failed or
	 * still-pending order is not.
	 *
	 * The status list is the WCPURCHASESTATUSES option (default: processing,
	 * on-hold, completed) and is filterable. An empty list falls back to tracking
	 * any order that did not outright fail, so a misconfiguration can never
	 * silently disable all purchase tracking.
	 *
	 * @param \WC_Order $order The order to check.
	 * @return bool
	 */
	public function is_order_status_trackable( \WC_Order $order ): bool {
		$statuses = (array) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES );

		/**
		 * Filters the order statuses whose orders are eligible for the purchase event.
		 *
		 * @param string[]  $statuses Order status slugs without the wc- prefix.
		 * @param \WC_Order $order    The order being evaluated.
		 */
		$statuses = (array) apply_filters( 'gtm4wp_purchase_trackable_statuses', $statuses, $order );

		if ( array() === $statuses ) {
			return 'failed' !== $order->get_status();
		}

		return in_array( $order->get_status(), $statuses, true );
	}

	/**
	 * Whether this purchase has already been tracked - either flagged on the
	 * order (_ga_tracked meta) or recorded in the visitor's browser cookie.
	 * Always false when the "do not use the order tracked flag" option is on,
	 * matching the 1.x behavior where that option disables both checks.
	 *
	 * @param \WC_Order $order    The order to check.
	 * @param int       $order_id The order id of the current request.
	 * @return bool
	 */
	public function is_purchase_already_tracked( \WC_Order $order, int $order_id ): bool {
		if ( (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ) ) {
			return false;
		}

		if ( 1 === (int) $order->get_meta( '_ga_tracked', true ) ) {
			return true;
		}

		if ( isset( $_COOKIE['gtm4wp_orderid_tracked'] ) ) {
			$tracked_order_id = filter_var( wp_unslash( $_COOKIE['gtm4wp_orderid_tracked'] ), FILTER_VALIDATE_INT );

			if ( $tracked_order_id && ( $tracked_order_id === $order_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flags the order as tracked (via the _ga_tracked meta) so the purchase is
	 * not counted twice. No-op when the "do not use the order tracked flag"
	 * option is on.
	 *
	 * @param \WC_Order $order The order to flag.
	 * @return void
	 */
	public function flag_order_tracked( \WC_Order $order ): void {
		if ( (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG ) ) {
			return;
		}

		$order->update_meta_data( '_ga_tracked', 1 );
		$order->save();
	}

	/**
	 * Whether the order belongs to a new (first time) customer, used for the
	 * Google Smart Shopping campaign new-customer reporting variable.
	 *
	 * @see https://support.google.com/google-ads/answer/9917012
	 *
	 * @param \WC_Order $order The order to check.
	 * @return bool
	 */
	public function is_new_customer( \WC_Order $order ): bool {
		return \Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::is_returning_customer( $order ) === false;
	}
}
