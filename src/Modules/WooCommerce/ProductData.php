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

use GTM4WP\Frontend\DefaultLanguage;
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
	 * Written by remember_pending_purchase() - from PurchaseTracking::remember_order()
	 * at payment/status time, and from the order-received renders for an order
	 * that arrived there before its status became trackable - and consumed by
	 * PageDataLayer's session fallback / custom order-received page resolution.
	 */
	public const PENDING_PURCHASE_SESSION_KEY = 'gtm4wp_pending_purchase';

	/**
	 * Statuses an order cannot leave towards a paid one, so a remembered order
	 * in one of them is dropped rather than re-checked. Mirrors
	 * wc_get_order_statuses() (WooCommerce 11.1.1) minus the placement and
	 * pending statuses (U160, pinned by ProductDataTest); WooCommerce's own
	 * wc_cancel_unpaid_orders() lands an abandoned order here too.
	 *
	 * @var string[]
	 */
	public const PENDING_PURCHASE_TERMINAL_STATUSES = array( 'failed', 'cancelled', 'refunded' );

	/**
	 * How long (minutes from the order's creation) an order that reached the
	 * order-received page before its status became trackable is re-checked on
	 * later page views. A clock, not a page-view count: the gateway's webhook is
	 * a matter of minutes, a customer may browse twenty pages in the first one,
	 * and a logged-in customer's session renews on every visit, so without this
	 * ceiling a never-paid order would be re-checked forever. "Maximum order
	 * age", when set, applies on top.
	 */
	public const PENDING_PURCHASE_RECHECK_WINDOW_MINUTES = 24 * 60;

	/**
	 * Name of the browser-side duplicate-purchase guard (localStorage, cookie
	 * fallback). Defined once on GTM4WP\Ecommerce\Helpers, where the byte-for-byte
	 * contract between its three writers/readers is documented; this delegating
	 * constant keeps the 2.0 name alive for existing consumers.
	 */
	public const ORDER_TRACKED_COOKIE = \GTM4WP\Ecommerce\Helpers::ORDER_TRACKED_COOKIE;

	/**
	 * The process_product() contexts built on never-cached pages/requests, where
	 * the list attribution cookie may be merged server-side. Product detail / list are
	 * excluded on purpose and enriched client-side instead (#405): the view_item
	 * wrapper in PageDataLayer::add_product_view(), the found_variation handler
	 * and the Quick View push in gtm4wp-woocommerce.js. Removing that client path
	 * silently loses the attribution; there is no server fallback.
	 *
	 * @var string[]
	 */
	private const LIST_ATTRIBUTION_CONTEXTS = array( 'cart', 'checkout', 'purchase', 'readdedtocart', 'block' );

	/**
	 * Per-request cache of the parsed list-attribution cookie (product id => list
	 * data); null before the first read.
	 *
	 * @var array<int, array{item_list_name: string, item_list_id: string}>|null
	 */
	private ?array $list_attribution_map = null;

	/**
	 * Whether the gtm4wp_eec_product_array deprecation notice was emitted this
	 * request; apply_filters_deprecated() would otherwise notify once per product.
	 *
	 * @var bool
	 */
	private bool $deprecated_filter_notified = false;

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
	 * @param mixed  $source_item Optional. The raw cart item array or WC_Order_Item the item is built from, passed only to the GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE filter (never merged into the item). Null when there is no per-line source.
	 * @return array|false The ecommerce item object of the WooCommerce product, or false if the product does not exist.
	 */
	public function process_product( $product, array $additional_product_attributes, string $attributes_used_for, $source_item = null ) {
		if ( ! $product ) {
			return false;
		}

		if ( ! ( $product instanceof \WC_Product ) ) {
			return false;
		}

		$use_full_category_path = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH );

		$product_id   = $product->get_id();
		$product_type = $product->get_type();
		$product_sku  = $product->get_sku();

		// Detect variations structurally: WooCommerce Subscriptions reports
		// "subscription_variation" but still extends WC_Product_Variation (#264).
		$is_variation = ( 'variation' === $product_type ) || ( $product instanceof \WC_Product_Variation );

		if ( $is_variation ) {
			$parent_product_id = $product->get_parent_id();
		}

		// Master-language consolidation (#145): item identity and text (item_id,
		// item_name, sku, item_category*, item_brand, item_variant) come from the
		// default-language product so one product sold in several languages is one
		// GA4 item; price, stock and internal_id stay on the current product.
		$data_product     = $product;
		$data_product_id  = $product_id;
		$data_product_sku = $product_sku;
		if ( isset( $parent_product_id ) ) {
			$data_parent_id = $parent_product_id;
		}

		if (
			true === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE )
			&& DefaultLanguage::is_active()
		) {
			$master_id = DefaultLanguage::post_id( $product_id, $is_variation ? 'product_variation' : 'product' );
			if ( $master_id !== $product_id ) {
				$master_product = wc_get_product( $master_id );
				if ( $master_product instanceof \WC_Product ) {
					$data_product     = $master_product;
					$data_product_id  = $master_id;
					$data_product_sku = (string) $master_product->get_sku();
				}
			}

			if ( $is_variation && isset( $parent_product_id ) && ( $parent_product_id > 0 ) ) {
				$data_parent_id = DefaultLanguage::post_id( $parent_product_id, 'product' );
			}
		}

		if ( $is_variation ) {
			$product_cat = Helpers::get_product_category( $data_parent_id, $use_full_category_path );
		} else {
			$product_cat = Helpers::get_product_category( $data_product_id, $use_full_category_path );
		}
		$product_cat_parts = explode( '/', $product_cat );

		$remarketing_id = $data_product_id;
		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCUSESKU ) && ( '' !== $data_product_sku ) ) {
			$remarketing_id = $data_product_sku;
		}

		// wc_get_price_to_display() is expensive; skip it when the caller supplies a
		// price, which array_merge() applies below anyway (#436, memory exhaustion).
		if ( array_key_exists( 'price', $additional_product_attributes ) ) {
			$display_price = (float) $additional_product_attributes['price'];
		} else {
			$display_price = (float) wc_get_price_to_display( $product );
		}

		$_temp_productdata = array(
			'internal_id'              => $product_id,
			// Always a string: Merchant Center feed ids are strings and GA4 matches
			// item_id against them, so a numeric id must not become a JSON number.
			'item_id'                  => (string) $remarketing_id,
			'item_name'                => $data_product->get_title(),
			'sku'                      => (string) ( $data_product_sku ? $data_product_sku : $data_product_id ),
			'price'                    => round( $display_price, 2 ), // Unfortunately this does not force a .00 postfix for integers.
			'stocklevel'               => $product->get_stock_quantity(),
			'stockstatus'              => $product->get_stock_status(),
			'google_business_vertical' => $this->business_vertical(),
		);

		if ( $is_variation ) {
			$_temp_productdata['item_group_id'] = $data_parent_id;
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
			if ( isset( $data_parent_id ) && ( 0 !== $data_parent_id ) ) {
				$product_id_to_query = $data_parent_id;
			} else {
				$product_id_to_query = $data_product_id;
			}

			$_temp_productdata['item_brand'] = Helpers::get_product_term( $product_id_to_query, $brand_taxonomy );
		}

		if ( $is_variation ) {
			$_temp_productdata['item_variant'] = implode( ',', $data_product->get_variation_attributes() );
		}

		$_temp_productdata = array_merge( $_temp_productdata, $additional_product_attributes );

		// GA4 list attribution: pair every item_list_name with a stable
		// item_list_id (slug of the name) so lists can be reported by id too.
		// A caller may pass its own item_list_id to override this default.
		if (
			isset( $_temp_productdata['item_list_name'] )
			&& '' !== $_temp_productdata['item_list_name']
			&& ! isset( $_temp_productdata['item_list_id'] )
		) {
			$_temp_productdata['item_list_id'] = sanitize_title( (string) $_temp_productdata['item_list_name'] );
		}

		// GA4 list attribution across the funnel (#405): fill item_list_name / id
		// from the cookie the tracker wrote on select_item, in the never-cached
		// contexts only (see LIST_ATTRIBUTION_CONTEXTS). The cookie is keyed by the
		// list item's product id, the parent for a variation, so both are tried.
		if (
			! isset( $_temp_productdata['item_list_name'] )
			&& in_array( $attributes_used_for, self::LIST_ATTRIBUTION_CONTEXTS, true )
			&& true === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION )
		) {
			$map    = $this->list_attribution_map();
			$stored = $map[ $product_id ] ?? ( isset( $parent_product_id ) ? ( $map[ $parent_product_id ] ?? null ) : null );

			if ( null !== $stored ) {
				$_temp_productdata['item_list_name'] = $stored['item_list_name'];
				$_temp_productdata['item_list_id']   = $stored['item_list_id'];
			}
		}

		// GA4 affiliation has no native WooCommerce value; added only when third
		// party code supplies one, so the payload carries no empty string (#348).
		if ( ! isset( $_temp_productdata['affiliation'] ) ) {
			/**
			 * Filters the GA4 item-level affiliation for a product.
			 *
			 * @param string $affiliation         The affiliation value; empty by default.
			 * @param mixed  $product             The WC_Product being processed.
			 * @param string $attributes_used_for The ecommerce action the item is used for.
			 */
			$affiliation = (string) apply_filters( GTM4WP_WPFILTER_EEC_ITEM_AFFILIATION, '', $product, $attributes_used_for );
			if ( '' !== $affiliation ) {
				$_temp_productdata['affiliation'] = $affiliation;
			}
		}

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
		 * productlist: product shown in a product list (category page or special product list like 'New products')
		 * groupedproductlist: product shown on a product detail page of a grouped product
		 *
		 * @deprecated 2.0 Use {@see 'gtm4wp_eec_item_with_source'} instead, which receives the same
		 *                 arguments plus the raw source object (cart item / order item) the item was built from.
		 *
		 * @param array  $_temp_productdata   An associative array containing all GA4 product attributes as well as any custom attribute
		 * @param string $attributes_used_for The name of the ecommerce action where this product will be used
		 */
		if ( ! $this->deprecated_filter_notified ) {
			// First product this request: the deprecation notice fires once, not per product.
			$_temp_productdata                = apply_filters_deprecated(
				GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY,
				array( $_temp_productdata, $attributes_used_for ),
				'2.0',
				GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE
			);
			$this->deprecated_filter_notified = true;
		} else {
			/** This filter is documented above (deprecated in favor of gtm4wp_eec_item_with_source). */
			$_temp_productdata = apply_filters( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY, $_temp_productdata, $attributes_used_for );
		}

		/**
		 * Filters the ecommerce item array before using it for tracking.
		 *
		 * Source-aware successor of the deprecated gtm4wp_eec_product_array filter
		 * (same placement context values, runs after it): the extra argument is the
		 * raw cart item array / WC_Order_Item the item was built from, so a callback
		 * can read custom item meta. The source is never merged into the item.
		 *
		 * @param array  $_temp_productdata   An associative array containing all GA4 product attributes as well as any custom attribute.
		 * @param string $attributes_used_for The name of the ecommerce action where this product will be used.
		 * @param mixed  $source_item         The raw source object the item was built from: a WooCommerce cart item array, a WC_Order_Item, or null when there is no per-line source.
		 */
		return apply_filters(
			GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE,
			$_temp_productdata,
			$attributes_used_for,
			$source_item
		);
	}

	/**
	 * The parsed list-attribution cookie map, read and validated once per request
	 * (Helpers::read_item_list_cookie()).
	 *
	 * @return array<int, array{item_list_name: string, item_list_id: string}>
	 */
	private function list_attribution_map(): array {
		if ( null === $this->list_attribution_map ) {
			$this->list_attribution_map = Helpers::read_item_list_cookie();
		}

		return $this->list_attribution_map;
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

				$product = $order_item->get_product();

				// Item price on the same tax basis as the transaction value, so GA4
				// item revenue reconciles with the total (#176).
				if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX ) ) {
					$inc_tax = false;
				} else {
					$inc_tax = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) );
				}
				$product_price = round( (float) $order->get_item_total( $order_item, $inc_tax ), 2 );

				$item_attributes = array(
					'quantity' => $order_item->get_quantity(),
					'price'    => $product_price,
				);

				// GA4 per-item discount: the gap between the line subtotal (pre-discount)
				// and total (post-discount), per unit. WooCommerce tracks these ex-tax on
				// the order item; only added when the line was actually discounted (#348).
				$quantity = (float) $order_item->get_quantity();
				if ( $quantity > 0 ) {
					$line_discount = round( ( (float) $order_item->get_subtotal() - (float) $order_item->get_total() ) / $quantity, 2 );
					if ( $line_discount > 0 ) {
						$item_attributes['discount'] = $line_discount;
					}
				}

				$eec_product_array = $this->process_product(
					$product,
					$item_attributes,
					'purchase',
					$order_item
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
		// The phone is normalized to E.164 against the billing country, which is
		// what Google matches on.
		$billing_phone_hash = Helpers::normalize_and_hash_phone_number( 'sha256', (string) $order->get_billing_phone(), (string) $order->get_billing_country() );

		// Values are passed raw: the output sink escapes with wp_json_encode() +
		// hex flags. Do NOT pre-escape with esc_js(); it would corrupt the data.
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
			// Totals cast to float: several WC_Order getters return decimal STRINGS
			// and the encode no longer numeric-coerces (JSON_NUMERIC_CHECK mangled
			// leading-zero SKUs). The order number stays a string: it is an identifier.
			'totals'     => array(
				'currency'       => $order->get_currency(),
				'discount_total' => (float) $order->get_discount_total(),
				'discount_tax'   => (float) $order->get_discount_tax(),
				'shipping_total' => (float) $order->get_shipping_total(),
				'shipping_tax'   => (float) $order->get_shipping_tax(),
				'cart_tax'       => (float) $order->get_cart_tax(),
				'total'          => (float) $order->get_total(),
				'total_tax'      => (float) $order->get_total_tax(),
				'total_discount' => (float) $order->get_total_discount(),
				'subtotal'       => (float) $order->get_subtotal(),
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

			// Optional transaction id prefix (several stores in one GA4 property).
			// Only this event is affected; orderData and the duplicate guards keep
			// the raw order number.
			$transaction_id_prefix = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX );

			$data_layer['event']     = 'purchase';
			$data_layer['ecommerce'] = array(
				'currency'       => $order_currency,
				'transaction_id' => $transaction_id_prefix . $order->get_order_number(),
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

			// Enhanced Conversions user data, built from the order so guest
			// checkouts are covered; attached only when it carries an identifier.
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA ) ) {
				$user_data = $this->get_enhanced_conversion_user_data( $order );
				if ( array() !== $user_data ) {
					$data_layer['user_data'] = $user_data;
				}
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
	 * Builds the GA4 / Google Ads Enhanced Conversions user-provided data block
	 * from an order's billing details: SHA-256 hashed email, phone and name, plus
	 * the plaintext address components Google expects. Empty fields are omitted,
	 * and an empty array is returned when there is no usable identifier.
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web
	 *
	 * @param \WC_Order $order The order to read the customer identifiers from.
	 * @return array<string, mixed>
	 */
	private function get_enhanced_conversion_user_data( \WC_Order $order ): array {
		$user_data = array();

		$email = (string) $order->get_billing_email();
		if ( '' !== $email ) {
			// Omitted when folding leaves nothing to hash (a gmail local part that is
			// only a "+" tag): a present-but-empty identifier is not ours to send (RI-13).
			$email_hash = Helpers::normalize_and_hash_email_address( 'sha256', $email );
			if ( '' !== $email_hash ) {
				$user_data['sha256_email_address'] = $email_hash;
			}
		}

		$phone = (string) $order->get_billing_phone();
		if ( '' !== $phone ) {
			// Omitted when the number cannot be placed in E.164 (RI-13).
			$phone_hash = Helpers::normalize_and_hash_phone_number( 'sha256', $phone, (string) $order->get_billing_country() );
			if ( '' !== $phone_hash ) {
				$user_data['sha256_phone_number'] = $phone_hash;
			}
		}

		$address    = array();
		$first_name = (string) $order->get_billing_first_name();
		if ( '' !== $first_name ) {
			$address['sha256_first_name'] = Helpers::normalize_and_hash( 'sha256', $first_name, false );
		}

		$last_name = (string) $order->get_billing_last_name();
		if ( '' !== $last_name ) {
			$address['sha256_last_name'] = Helpers::normalize_and_hash( 'sha256', $last_name, false );
		}

		$street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
		if ( '' !== $street ) {
			$address['street'] = $street;
		}

		$address_fields = array(
			'city'        => (string) $order->get_billing_city(),
			'region'      => (string) $order->get_billing_state(),
			'postal_code' => (string) $order->get_billing_postcode(),
			'country'     => (string) $order->get_billing_country(),
		);
		foreach ( $address_fields as $key => $value ) {
			if ( '' !== $value ) {
				$address[ $key ] = $value;
			}
		}

		if ( array() !== $address ) {
			$user_data['address'] = $address;
		}

		return $user_data;
	}

	/**
	 * Whether the order is older than the configured maximum tracking age
	 * (false when none is configured).
	 *
	 * @param \WC_Order $order The order to check.
	 * @return bool
	 */
	public function is_order_older_than_max_age( \WC_Order $order ): bool {
		$max_age = (int) $this->options->get( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE );
		if ( $max_age <= 0 ) {
			return false;
		}

		return $this->is_order_older_than( $order, $max_age );
	}

	/**
	 * Whether the order's reference date (paid date when paid, creation date
	 * otherwise) lies more than the given number of minutes in the past.
	 *
	 * @param \WC_Order $order   The order to check.
	 * @param int       $minutes The age limit in minutes.
	 * @return bool
	 */
	private function is_order_older_than( \WC_Order $order, int $minutes ): bool {
		if ( $order->is_paid() && $order->get_date_paid() ) {
			$reference = $order->get_date_paid();
		} else {
			$reference = $order->get_date_created();
		}

		$now = new \DateTime( 'now', $reference->getTimezone() );
		$age = $now->diff( $reference );

		return ( ( $age->days * 24 * 60 ) + ( $age->h * 60 ) + $age->i ) > $minutes;
	}

	/**
	 * Whether the order's status makes it eligible for the purchase event. The
	 * purchase fires at placement (a COD or bank-transfer order is tracked at
	 * checkout), not when payment clears. The list is the filterable
	 * WCPURCHASESTATUSES option; an empty list tracks any order that did not fail,
	 * so a misconfiguration cannot silently disable all purchase tracking.
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

		if ( isset( $_COOKIE[ self::ORDER_TRACKED_COOKIE ] ) ) {
			// The browser writes the order NUMBER (see the constant); the id is
			// still accepted so guards written by an older version keep working.
			$tracked = sanitize_text_field( wp_unslash( $_COOKIE[ self::ORDER_TRACKED_COOKIE ] ) );

			if ( '' !== $tracked
				&& ( (string) $order->get_order_number() === $tracked || (string) $order_id === $tracked )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The canonical purchase-eligibility gauntlet: not too old, not already
	 * tracked, status trackable. Every purchase emitter calls this so the
	 * sequence cannot drift per call site.
	 *
	 * @param \WC_Order $order    The order to check.
	 * @param int       $order_id The order id of the current request (cookie dedupe).
	 * @return bool
	 */
	public function is_order_trackable( \WC_Order $order, int $order_id ): bool {
		return ! $this->is_order_older_than_max_age( $order )
			&& ! $this->is_purchase_already_tracked( $order, $order_id )
			&& $this->is_order_status_trackable( $order );
	}

	/**
	 * Whether an order that is NOT trackable right now may still become so, and
	 * is therefore worth remembering in the buyer's session and re-checking on
	 * their later page views. True for an order that is recent, not already
	 * tracked, and in a status that is neither in the tracked list nor one of
	 * the terminal statuses - typically an order the customer carried to the
	 * order-received page while still "Pending payment", because the gateway
	 * confirms the payment through a webhook that arrives a moment later, in a
	 * request that has no access to the buyer's session (Stripe with webhooks
	 * enabled is the reported case). It also holds for an order in a placement
	 * status the store chose not to track, e.g. `processing` on a store that
	 * only tracks `completed`; the status list stays the only authority on
	 * WHEN the purchase counts, this only decides whether to keep looking.
	 *
	 * A trackable order returns false: there is nothing to wait for, the caller
	 * emits the purchase instead.
	 *
	 * @param \WC_Order $order    The order to check.
	 * @param int       $order_id The order id of the current request (cookie dedupe).
	 * @return bool
	 */
	public function may_become_trackable( \WC_Order $order, int $order_id ): bool {
		if ( $this->is_order_older_than_max_age( $order ) ) {
			return false;
		}

		if ( $this->is_purchase_already_tracked( $order, $order_id ) ) {
			return false;
		}

		if ( $this->is_order_status_trackable( $order ) ) {
			return false;
		}

		if ( in_array( $order->get_status(), self::PENDING_PURCHASE_TERMINAL_STATUSES, true ) ) {
			return false;
		}

		return ! $this->is_order_older_than( $order, self::PENDING_PURCHASE_RECHECK_WINDOW_MINUTES );
	}

	/**
	 * Remembers an order that reached an order-received render before its status
	 * became trackable, so the reliable-purchase fallback re-checks it on the
	 * buyer's later page views and emits the purchase once the status has
	 * caught up. Only when "Reliable purchase tracking" is on (the fallback is
	 * the only reader of the marker), and only for an order may_become_trackable()
	 * accepts - never for a trackable one (the caller emits that), an already
	 * tracked one, a terminal one or one outside the re-check window.
	 *
	 * @param \WC_Order $order    The order that was withheld from the purchase event.
	 * @param int       $order_id The order id of the current request (cookie dedupe).
	 * @return bool Whether the order was remembered.
	 */
	public function remember_if_may_become_trackable( \WC_Order $order, int $order_id ): bool {
		if ( true !== $this->options->get( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE ) ) {
			return false;
		}

		if ( ! $this->may_become_trackable( $order, $order_id ) ) {
			return false;
		}

		return $this->remember_pending_purchase( $order_id );
	}

	/**
	 * Writes the pending-purchase marker for the given order into the current
	 * request's WooCommerce session and, under the cache-safe data layer, flags
	 * the one-shot event cookie so the client fetches the session endpoint on the
	 * next page. The single definition of the write; PurchaseTracking::remember_order()
	 * and remember_if_may_become_trackable() both end here, so the marker's key
	 * and the cookie it travels with cannot drift apart between the two seeds.
	 *
	 * Performs no eligibility check of its own - each caller applies the gate
	 * that suits its hook (status trackable at payment/status time; may become
	 * trackable at render time).
	 *
	 * @param int $order_id The id of the order to remember.
	 * @return bool Whether the marker was written (false without a WooCommerce session).
	 */
	public function remember_pending_purchase( int $order_id ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}

		$woo = function_exists( 'WC' ) ? WC() : null;
		if ( ! $woo || empty( $woo->session ) ) {
			return false;
		}

		$woo->session->set( self::PENDING_PURCHASE_SESSION_KEY, $order_id );

		// Cache-safe data layer (issue #398, Phase 3): flag that a one-shot event
		// (the reliable-purchase fallback) is pending so the client fetches it on the
		// next page it can. No-op unless the cache-safe mode is on.
		Helpers::flag_oneshot_event( (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER ) );

		return true;
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

	/**
	 * Both new/returning customer signals for the purchase event: Google Ads
	 * reads the boolean `new_customer`, GA4 the `customer_type` string. Both are
	 * sent; dropping either breaks one integration. Returned together so
	 * is_new_customer() (an analytics-store query) runs once per purchase.
	 *
	 * @see https://support.google.com/google-ads/answer/12077475 Google Ads: the new_customer parameter.
	 * @see https://developers.google.com/analytics/devguides/collection/ga4/reference/events?client_type=gtm GA4: the customer_type parameter on purchase.
	 *
	 * @param \WC_Order $order The order being tracked.
	 * @return array<string, bool|string>
	 */
	public function customer_signals( \WC_Order $order ): array {
		$is_new_customer = $this->is_new_customer( $order );

		return array(
			'new_customer'  => $is_new_customer,
			'customer_type' => $is_new_customer ? 'new' : 'returning',
		);
	}
}
