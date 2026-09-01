<?php
/**
 * Easy Digital Downloads download and order data builders.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\EasyDigitalDownloads;

use GTM4WP\Ecommerce\Helpers;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds GA4 ecommerce item, order and purchase arrays from Easy Digital
 * Downloads objects (EDD_Download, cart content details, EDD\Orders\Order),
 * plus the purchase eligibility gauntlet (age / status / already-tracked)
 * and the order tracked flag. The EDD counterpart of the WooCommerce
 * module's ProductData class.
 */
final class DownloadData {

	/**
	 * Taxonomy EDD registers for download categories.
	 */
	public const CATEGORY_TAXONOMY = 'download_category';

	/**
	 * Order meta key that flags an order as already tracked. Deliberately the
	 * same key the WooCommerce integration uses, so store owners find one
	 * familiar flag regardless of the store plugin.
	 */
	public const ORDER_TRACKED_META = '_ga_tracked';

	/**
	 * The item contexts the server-side list-attribution merge applies to
	 * (#405). Only never-cached contexts belong here: the cart and checkout
	 * pages are excluded from full-page caches by the store and the purchase
	 * data layer renders for a per-order URL. The cacheable download-detail
	 * page and the purchase-form/list markup are enriched client-side instead,
	 * so their HTML stays identical for every visitor.
	 *
	 * @var string[]
	 */
	private const LIST_ATTRIBUTION_CONTEXTS = array( 'cart', 'checkout', 'purchase' );

	/**
	 * Lazily loaded list-attribution cookie map for this request.
	 *
	 * @var array<int, array{item_list_name: string, item_list_id: string}>|null
	 */
	private ?array $list_attribution_map = null;

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Reads a property of an EDD row object (Order, Order_Item,
	 * Order_Adjustment, Order_Address). These expose data through magic
	 * getters, and a class implementing __get() without __isset() makes
	 * isset()/?? report false even when a value exists - so the read goes
	 * through __get() directly, gated on property_exists()/__get presence.
	 *
	 * @param mixed  $row_object    The row object to read.
	 * @param string $property_name The property to read.
	 * @param mixed  $default_value Value returned when the property cannot be read.
	 * @return mixed
	 */
	public static function row_prop( $row_object, string $property_name, $default_value = '' ) {
		if ( ! is_object( $row_object ) ) {
			return $default_value;
		}

		if ( property_exists( $row_object, $property_name ) || method_exists( $row_object, '__get' ) ) {
			$value = $row_object->$property_name;

			return null === $value ? $default_value : $value;
		}

		return $default_value;
	}

	/**
	 * Returns the configured Google Ads business vertical, falling back to
	 * retail for invalid stored values.
	 *
	 * @return string
	 */
	public function business_vertical(): string {
		$vertical = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDBUSINESSVERTICAL );

		if ( ! in_array( $vertical, Helpers::BUSINESS_VERTICALS, true ) ) {
			return 'retail';
		}

		return $vertical;
	}

	/**
	 * Given an EDD download, this function returns an array of product
	 * attributes in the format of GA4 ecommerce item data.
	 *
	 * @param mixed    $download   An EDD_Download instance or a download id.
	 * @param array    $additional_product_attributes Any key-value pair that needs to be added into the ecommerce item object.
	 * @param string   $attributes_used_for The placement ID of the product passed to the item filters so 3rd party code knows where the data is used (productdetail, productlist, addtocartsingle, cart, checkout, purchase).
	 * @param mixed    $source_item Optional. The raw source the item is built from - the EDD cart content details array on the cart/checkout paths or the EDD\Orders\Order_Item on the purchase path - passed only to the GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE filter. Default null.
	 * @param int|null $price_id   Optional. The selected variable price id, used for the price and the item_variant. Default null.
	 * @return array|false The ecommerce item object of the download, or false if the download does not exist.
	 */
	public function process_download( $download, array $additional_product_attributes, string $attributes_used_for, $source_item = null, ?int $price_id = null ) {
		if ( is_numeric( $download ) ) {
			$download = edd_get_download( (int) $download );
		}

		if ( ! ( $download instanceof \EDD_Download ) ) {
			return false;
		}

		$use_full_category_path = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDUSEFULLCATEGORYPATH );

		$download_id    = (int) $download->get_ID();
		$remarketing_id = $download_id;

		// edd_get_download_sku() returns false/'-' when SKUs are disabled or unset.
		$download_sku = (string) edd_get_download_sku( $download_id );
		if ( '-' === $download_sku ) {
			$download_sku = '';
		}

		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDUSESKU ) && ( '' !== $download_sku ) ) {
			$remarketing_id = $download_sku;
		}

		$download_cat       = Helpers::get_product_category( $download_id, $use_full_category_path, self::CATEGORY_TAXONOMY );
		$download_cat_parts = explode( '/', $download_cat );

		if ( array_key_exists( 'price', $additional_product_attributes ) ) {
			$display_price = (float) $additional_product_attributes['price'];
		} else {
			$display_price = $this->download_price( $download, $price_id );
		}

		$_temp_productdata = array(
			'internal_id'              => $download_id,
			'item_id'                  => $remarketing_id,
			'item_name'                => $download->get_name(),
			'sku'                      => ( '' !== $download_sku ) ? $download_sku : $download_id,
			'price'                    => round( $display_price, 2 ),
			'google_business_vertical' => $this->business_vertical(),
		);

		// EDD variable prices are options on the same download, not separate
		// products, so the option's name becomes the GA4 item_variant while the
		// item_id stays the download id (or SKU).
		$variant_name = $this->price_option_name( $download_id, $price_id );
		if ( '' !== $variant_name ) {
			$_temp_productdata['item_variant'] = $variant_name;
		}

		if ( 1 === count( $download_cat_parts ) ) {
			$_temp_productdata['item_category'] = $download_cat_parts[0];
		} elseif ( count( $download_cat_parts ) > 1 ) {
			$_temp_productdata['item_category'] = $download_cat_parts[0];

			$max_category_levels = min( 5, count( $download_cat_parts ) );
			for ( $i = 1; $i < $max_category_levels; $i++ ) {
				$_temp_productdata[ 'item_category' . ( $i + 1 ) ] = $download_cat_parts[ $i ];
			}
		}

		$_temp_productdata[ Helpers::get_gads_product_id_variable_name( $this->business_vertical() ) ] = Helpers::prefix_productid( $_temp_productdata['item_id'], (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDPRODIDPREFIX ) );

		$brand_taxonomy = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDBRANDTAXONOMY );
		if ( '' !== $brand_taxonomy ) {
			$_temp_productdata['item_brand'] = Helpers::get_product_term( $download_id, $brand_taxonomy );
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

		// GA4 list attribution carried across the funnel (#405): when the
		// opt-in option is on and this item is not already part of a rendered
		// list, fill item_list_name / item_list_id from the first-party cookie
		// the tracker wrote on the originating select_item click. Restricted
		// to the never-cached contexts (see LIST_ATTRIBUTION_CONTEXTS).
		if (
			! isset( $_temp_productdata['item_list_name'] )
			&& in_array( $attributes_used_for, self::LIST_ATTRIBUTION_CONTEXTS, true )
			&& true === $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION )
		) {
			$stored = $this->list_attribution_map()[ $download_id ] ?? null;

			if ( null !== $stored ) {
				$_temp_productdata['item_list_name'] = $stored['item_list_name'];
				$_temp_productdata['item_list_id']   = $stored['item_list_id'];
			}
		}

		// GA4 item-level affiliation: empty by default and only added when 3rd
		// party code supplies one, keeping the item payload free of empty
		// affiliation strings. Shared filter with the WooCommerce module.
		if ( ! isset( $_temp_productdata['affiliation'] ) ) {
			/**
			 * Filters the GA4 item-level affiliation for a download.
			 *
			 * @param string $affiliation         The affiliation value; empty by default.
			 * @param mixed  $download            The EDD_Download being processed.
			 * @param string $attributes_used_for The ecommerce action the item is used for.
			 */
			$affiliation = (string) apply_filters( GTM4WP_WPFILTER_EEC_ITEM_AFFILIATION, '', $download, $attributes_used_for );
			if ( '' !== $affiliation ) {
				$_temp_productdata['affiliation'] = $affiliation;
			}
		}

		/**
		 * Filters the ecommerce item array before using it for tracking.
		 *
		 * The same source-aware filter the WooCommerce module applies, so
		 * store-agnostic extensions work on both integrations. On the EDD paths
		 * the source is the cart content details array (cart/checkout), the
		 * EDD\Orders\Order_Item (purchase), or null when there is no per-line
		 * source (download detail page, download lists).
		 *
		 * @param array  $_temp_productdata   An associative array containing all GA4 product attributes as well as any custom attribute.
		 * @param string $attributes_used_for The name of the ecommerce action where this product will be used.
		 * @param mixed  $source_item         The raw source the item was built from, or null.
		 */
		return apply_filters(
			GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE,
			$_temp_productdata,
			$attributes_used_for,
			$source_item
		);
	}

	/**
	 * Returns the sanitized list-attribution cookie map, read once per request
	 * (#405).
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
	 * Resolves the display price of a download: the given price option when a
	 * price id is supplied, the lowest price option for a variable-priced
	 * download without a selection, and the plain price otherwise.
	 *
	 * @param \EDD_Download $download The download.
	 * @param int|null      $price_id The selected variable price id, or null.
	 * @return float
	 */
	private function download_price( \EDD_Download $download, ?int $price_id ): float {
		$download_id = (int) $download->get_ID();

		if ( $download->has_variable_prices() ) {
			if ( null !== $price_id ) {
				return (float) edd_get_price_option_amount( $download_id, $price_id );
			}

			return (float) edd_get_lowest_price_option( $download_id );
		}

		return (float) $download->get_price();
	}

	/**
	 * Returns the name of a variable price option, or an empty string when the
	 * download has no variable prices or the option is unknown.
	 *
	 * @param int      $download_id The download id.
	 * @param int|null $price_id    The price option id, or null.
	 * @return string
	 */
	public function price_option_name( int $download_id, ?int $price_id ): string {
		if ( null === $price_id ) {
			return '';
		}

		$prices = edd_get_variable_prices( $download_id );
		if ( ! is_array( $prices ) || ! isset( $prices[ $price_id ] ) || ! is_array( $prices[ $price_id ] ) ) {
			return '';
		}

		return (string) ( $prices[ $price_id ]['name'] ?? '' );
	}

	/**
	 * Extracts the selected variable price id from an EDD cart item's options,
	 * or null when there is none. Cart content details carry the raw cart item
	 * under item_number.
	 *
	 * @param array<string, mixed> $cart_item_details One entry of edd_get_cart_content_details().
	 * @return int|null
	 */
	public static function cart_item_price_id( array $cart_item_details ): ?int {
		$options = $cart_item_details['item_number']['options'] ?? array();

		if ( is_array( $options ) && isset( $options['price_id'] ) && is_numeric( $options['price_id'] ) ) {
			return (int) $options['price_id'];
		}

		return null;
	}

	/**
	 * Builds the process_download() attributes for a cart line from the totals
	 * EDD has already calculated: quantity, the per-unit price on the same tax
	 * basis as the purchase revenue, and the per-unit discount when a discount
	 * actually reduced the line.
	 *
	 * @param array<string, mixed> $cart_item_details One entry of edd_get_cart_content_details().
	 * @return array<string, mixed>
	 */
	public function cart_line_attributes( array $cart_item_details ): array {
		$quantity = (float) ( $cart_item_details['quantity'] ?? 1 );
		if ( $quantity <= 0 ) {
			$quantity = 1;
		}

		$attributes = array(
			'quantity' => (int) $quantity,
		);

		// The line total ("price") includes tax; subtract it when the admin
		// excludes tax from revenue so item-level and transaction-level revenue
		// reconcile in GA4.
		$line_total = (float) ( $cart_item_details['price'] ?? 0 );
		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX ) ) {
			$line_total -= (float) ( $cart_item_details['tax'] ?? 0 );
		}

		$attributes['price'] = round( $line_total / $quantity, 2 );

		$line_discount = round( (float) ( $cart_item_details['discount'] ?? 0 ) / $quantity, 2 );
		if ( $line_discount > 0 ) {
			$attributes['discount'] = $line_discount;
		}

		return $attributes;
	}

	/**
	 * Takes an EDD order and returns an array of GA4 ecommerce items.
	 *
	 * @param mixed $order The EDD\Orders\Order that needs to be processed.
	 * @return array An array of product data arrays.
	 */
	public function process_order_items( $order ): array {
		$order_data = array();

		if ( ! ( $order instanceof \EDD\Orders\Order ) ) {
			return $order_data;
		}

		foreach ( (array) $order->get_items() as $order_item ) {
			/**
			 * This filter allows 3rd party code to exclude specific downloads from reporting.
			 *
			 * @param bool                  true        Constant value telling 3rd party code that the order item will be included in reporting if not changed by the filter.
			 * @param \EDD\Orders\Order_Item $order_item The order item object retrieved from Easy Digital Downloads.
			 *
			 * return bool If the filter returns false, the order item will be omitted from processing.
			 */
			if ( ! apply_filters( GTM4WP_WPFILTER_EEC_EDD_ORDER_ITEM, true, $order_item ) ) {
				continue;
			}

			$quantity = (float) self::row_prop( $order_item, 'quantity', 1 );
			if ( $quantity <= 0 ) {
				$quantity = 1;
			}

			// Report the per-item price on the same tax basis as the transaction
			// value so GA4 item-level revenue reconciles with the transaction
			// total. The order item total includes tax (subtotal - discount + tax).
			$line_total = (float) self::row_prop( $order_item, 'total', 0 );
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX ) ) {
				$line_total -= (float) self::row_prop( $order_item, 'tax', 0 );
			}

			$item_attributes = array(
				'quantity' => (int) $quantity,
				'price'    => round( $line_total / $quantity, 2 ),
			);

			$line_discount = round( (float) self::row_prop( $order_item, 'discount', 0 ) / $quantity, 2 );
			if ( $line_discount > 0 ) {
				$item_attributes['discount'] = $line_discount;
			}

			$price_id = self::row_prop( $order_item, 'price_id', null );

			$eec_product_array = $this->process_download(
				(int) self::row_prop( $order_item, 'product_id', 0 ),
				$item_attributes,
				'purchase',
				$order_item,
				is_numeric( $price_id ) ? (int) $price_id : null
			);

			if ( ! $eec_product_array ) {
				continue;
			}

			unset( $eec_product_array['internal_id'] );

			$order_data[] = $eec_product_array;
		}

		// No need to apply a filter here since all downloads in the array have been already filtered in process_download().
		return $order_data;
	}

	/**
	 * Returns the discount codes applied to an order, read from its
	 * discount-type adjustments (EDD stores the code in the adjustment's
	 * description).
	 *
	 * @param \EDD\Orders\Order $order The order.
	 * @return string[]
	 */
	public function order_discount_codes( \EDD\Orders\Order $order ): array {
		$codes = array();

		foreach ( (array) $order->get_discounts() as $adjustment ) {
			$code = (string) self::row_prop( $adjustment, 'description' );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * Returns an associative array that can be used in the data layer to output the raw order data.
	 *
	 * The order's payment key is deliberately NOT included: it is the
	 * credential that authorizes viewing the receipt, and the data layer is
	 * readable by every tag and script on the page.
	 *
	 * @param mixed $order       The EDD\Orders\Order object.
	 * @param mixed $order_items An array including product data generated with process_order_items().
	 * @return array
	 */
	public function get_raw_order_datalayer( $order, $order_items ): array {
		$order_data = array();

		if ( ! ( $order instanceof \EDD\Orders\Order ) ) {
			return $order_data;
		}

		if ( ! is_array( $order_items ) ) {
			return $order_data;
		}

		$address = $order->get_address();

		$email      = (string) self::row_prop( $order, 'email' );
		$email_hash = Helpers::normalize_and_hash_email_address( 'sha256', $email );
		$first_name = (string) self::row_prop( $address, 'first_name' );
		$last_name  = (string) self::row_prop( $address, 'last_name' );

		// Values are passed raw: the single output sink (the data layer) runs
		// everything through wp_json_encode() with the full hex flag set, which
		// is the correct escaper for an inline-script context.
		$order_data = array(
			'attributes' => array(
				'date'            => (string) self::row_prop( $order, 'date_created' ),
				'order_number'    => (string) $order->get_number(),
				'payment_gateway' => (string) self::row_prop( $order, 'gateway' ),
				'mode'            => (string) self::row_prop( $order, 'mode' ),
				'status'          => (string) self::row_prop( $order, 'status' ),
				'coupons'         => implode( ', ', $this->order_discount_codes( $order ) ),
			),
			'totals'     => array(
				'currency' => (string) self::row_prop( $order, 'currency' ),
				'subtotal' => (float) self::row_prop( $order, 'subtotal', 0 ),
				'discount' => (float) self::row_prop( $order, 'discount', 0 ),
				'tax'      => (float) self::row_prop( $order, 'tax', 0 ),
				'total'    => (float) self::row_prop( $order, 'total', 0 ),
			),
			'customer'   => array(
				'id'              => (int) self::row_prop( $order, 'customer_id', 0 ),
				'first_name'      => $first_name,
				'first_name_hash' => Helpers::normalize_and_hash( 'sha256', $first_name, false ),
				'last_name'       => $last_name,
				'last_name_hash'  => Helpers::normalize_and_hash( 'sha256', $last_name, false ),
				'address_1'       => (string) self::row_prop( $address, 'address' ),
				'address_2'       => (string) self::row_prop( $address, 'address2' ),
				'city'            => (string) self::row_prop( $address, 'city' ),
				'state'           => (string) self::row_prop( $address, 'region' ),
				'postcode'        => (string) self::row_prop( $address, 'postal_code' ),
				'country'         => (string) self::row_prop( $address, 'country' ),
				'email'           => $email,
				'email_hash'      => $email_hash,
			),
			'items'      => $order_items,
		);

		/**
		 * Filters the orderData array before using it for tracking.
		 * Can be used to add custom order or even product data into the data layer.
		 *
		 * @param array             $order_data An associative array containing all data (head data and products) about the currently placed order.
		 * @param \EDD\Orders\Order $order      The EDD order object.
		 */
		return apply_filters( GTM4WP_WPFILTER_EEC_EDD_ORDER_DATA, $order_data, $order );
	}

	/**
	 * Takes an EDD order and order items and generates the GA4 purchase data layer content.
	 *
	 * @param mixed      $order The EDD\Orders\Order that needs to be transformed into an ecommerce data layer.
	 * @param array|null $order_items The array returned by process_order_items(). If not set, the function will call process_order_items().
	 * @return array The data layer content as an associative array.
	 */
	public function get_purchase_datalayer( $order, $order_items = null ): array {
		$data_layer = array();

		if ( $order instanceof \EDD\Orders\Order ) {
			$order_revenue = (float) self::row_prop( $order, 'total', 0 );

			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX ) ) {
				$order_revenue -= (float) self::row_prop( $order, 'tax', 0 );
			}

			// Optional fixed prefix in front of the transaction id, e.g. to tell
			// several stores apart in one GA4 property. Only this event is
			// affected: orderData and the duplicate-tracking guards keep using
			// the raw order number.
			$transaction_id_prefix = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDTRANSACTIONIDPREFIX );

			$data_layer['event']     = 'purchase';
			$data_layer['ecommerce'] = array(
				'currency'       => (string) self::row_prop( $order, 'currency' ),
				'transaction_id' => $transaction_id_prefix . $order->get_number(),
				'affiliation'    => '',
				'value'          => $order_revenue,
				'tax'            => (float) self::row_prop( $order, 'tax', 0 ),
				'coupon'         => implode( ', ', $this->order_discount_codes( $order ) ),
			);

			if ( isset( $order_items ) ) {
				$_order_items = $order_items;
			} else {
				$_order_items = $this->process_order_items( $order );
			}

			$data_layer['ecommerce']['items'] = $_order_items;

			// Google Ads / GA4 Enhanced Conversions user-provided data, built from
			// the order (so guest checkouts are covered too). Opt-in via the same
			// "Customer data in data layer" option; only attached when it carries
			// at least one identifier.
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA ) ) {
				$user_data = $this->get_enhanced_conversion_user_data( $order );
				if ( array() !== $user_data ) {
					$data_layer['user_data'] = $user_data;
				}
			}
		}

		/**
		 * Filters the ecommerce purchase data layer content of an EDD order.
		 * Can be used to add custom data to the data layer when the purchase ecommerce action is included.
		 *
		 * @param array $data_layer An associative array containing the full data layer including purchase header attributes.
		 * @param mixed $order      The EDD\Orders\Order that needs to be transformed into an ecommerce data layer.
		 */
		return apply_filters( GTM4WP_WPFILTER_EDD_PURCHASE_DATALAYER, $data_layer, $order );
	}

	/**
	 * Builds the GA4 / Google Ads Enhanced Conversions user-provided data block
	 * from an order's email and address: SHA-256 hashed email and name, plus
	 * the plaintext address components Google expects. Empty fields are
	 * omitted, and an empty array is returned when there is no usable
	 * identifier.
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web
	 *
	 * @param \EDD\Orders\Order $order The order to read the customer identifiers from.
	 * @return array<string, mixed>
	 */
	private function get_enhanced_conversion_user_data( \EDD\Orders\Order $order ): array {
		$user_data = array();

		$email = (string) self::row_prop( $order, 'email' );
		if ( '' !== $email ) {
			// Omitted rather than sent empty: the helper returns '' when folding
			// leaves no address to hash - a gmail.com address whose local part is
			// nothing but a "+" tag - and a present-but-empty identifier is the
			// consumer's call to interpret, not ours (RI-13). The guard belongs
			// here rather than in the helper: '' is the honest answer to "hash
			// this", and only the caller knows the key is optional. Mirrors the
			// WooCommerce module's user_data block.
			$email_hash = Helpers::normalize_and_hash_email_address( 'sha256', $email );
			if ( '' !== $email_hash ) {
				$user_data['sha256_email_address'] = $email_hash;
			}
		}

		$address_row = $order->get_address();

		$phone = $this->order_phone( $order );
		if ( '' !== $phone ) {
			// Same omission rule as the email above: '' from the normalizer
			// means the number could not be placed in E.164, and a
			// present-but-empty identifier is the consumer's call to
			// interpret, not ours (RI-13).
			$phone_hash = Helpers::normalize_and_hash_phone_number( 'sha256', $phone, (string) self::row_prop( $address_row, 'country' ) );
			if ( '' !== $phone_hash ) {
				$user_data['sha256_phone_number'] = $phone_hash;
			}
		}

		$address    = array();
		$first_name = (string) self::row_prop( $address_row, 'first_name' );
		if ( '' !== $first_name ) {
			$address['sha256_first_name'] = Helpers::normalize_and_hash( 'sha256', $first_name, false );
		}

		$last_name = (string) self::row_prop( $address_row, 'last_name' );
		if ( '' !== $last_name ) {
			$address['sha256_last_name'] = Helpers::normalize_and_hash( 'sha256', $last_name, false );
		}

		$street = trim( self::row_prop( $address_row, 'address' ) . ' ' . self::row_prop( $address_row, 'address2' ) );
		if ( '' !== $street ) {
			$address['street'] = $street;
		}

		$address_fields = array(
			'city'        => (string) self::row_prop( $address_row, 'city' ),
			'region'      => (string) self::row_prop( $address_row, 'region' ),
			'postal_code' => (string) self::row_prop( $address_row, 'postal_code' ),
			'country'     => (string) self::row_prop( $address_row, 'country' ),
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
	 * Returns the buyer's phone number for an order, or '' when none is
	 * stored. EDD core's own opt-in checkout Phone field (since EDD 3.3.8,
	 * enabled via the checkout address fields setting) stores the number as
	 * order meta '_edd_phone', so that is read first. After it come the
	 * de-facto community conventions: EDD 3 order meta under the key 'phone',
	 * then the 'phone' entry of the legacy payment meta array - the storage
	 * EDD's own documented checkout-phone-field recipe uses - and finally
	 * site code can supply or override the number through the
	 * gtm4wp_edd_order_phone filter (e.g. mapping a Checkout Fields Manager
	 * field).
	 *
	 * @param \EDD\Orders\Order $order The order to read the phone number from.
	 * @return string
	 */
	private function order_phone( \EDD\Orders\Order $order ): string {
		$order_id = (int) self::row_prop( $order, 'id', 0 );

		$phone = '';

		if ( $order_id > 0 && function_exists( 'edd_get_order_meta' ) ) {
			$phone = (string) edd_get_order_meta( $order_id, '_edd_phone', true );

			if ( '' === $phone ) {
				$phone = (string) edd_get_order_meta( $order_id, 'phone', true );
			}
		}

		if ( '' === $phone && $order_id > 0 && function_exists( 'edd_get_payment_meta' ) ) {
			$payment_meta = edd_get_payment_meta( $order_id );
			if ( is_array( $payment_meta ) && isset( $payment_meta['phone'] ) && is_scalar( $payment_meta['phone'] ) ) {
				$phone = (string) $payment_meta['phone'];
			}
		}

		/**
		 * Filters the buyer phone number used for the Enhanced Conversions
		 * user_data block of an EDD purchase. The number is normalized to
		 * E.164 and SHA-256 hashed before it reaches the data layer; returning
		 * '' removes the phone from the block.
		 *
		 * @param string            $phone The phone number found in order/payment meta, or ''.
		 * @param \EDD\Orders\Order $order The order being tracked.
		 */
		return sanitize_text_field( (string) apply_filters( GTM4WP_WPFILTER_EDD_ORDER_PHONE, $phone, $order ) );
	}

	/**
	 * Whether the order is older than the configured maximum tracking age.
	 * Returns false when no maximum age is configured. EDD stores order dates
	 * as UTC datetime strings.
	 *
	 * @param \EDD\Orders\Order $order The order to check.
	 * @return bool
	 */
	public function is_order_older_than_max_age( \EDD\Orders\Order $order ): bool {
		$max_age = (int) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDORDERMAXAGE );
		if ( $max_age <= 0 ) {
			return false;
		}

		$date_created = (string) self::row_prop( $order, 'date_created' );
		if ( '' === $date_created ) {
			return false;
		}

		try {
			$reference = new \DateTime( $date_created, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return false;
		}

		$now     = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$diff    = $now->diff( $reference );
		$minutes = ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i;

		return $minutes > $max_age;
	}

	/**
	 * Whether the order's current status makes it eligible for the GA4
	 * purchase event. The status list is the EDDPURCHASESTATUSES option
	 * (default: pending, processing, complete - offsite gateways can land the
	 * buyer on the confirmation page while the order is still pending) and is
	 * filterable. An empty list falls back to tracking any order that did not
	 * outright fail, so a misconfiguration can never silently disable all
	 * purchase tracking.
	 *
	 * @param \EDD\Orders\Order $order The order to check.
	 * @return bool
	 */
	public function is_order_status_trackable( \EDD\Orders\Order $order ): bool {
		$statuses = (array) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES );

		/**
		 * Filters the order statuses whose orders are eligible for the purchase event.
		 *
		 * @param string[]          $statuses Order status slugs.
		 * @param \EDD\Orders\Order $order    The order being evaluated.
		 */
		$statuses = (array) apply_filters( 'gtm4wp_edd_purchase_trackable_statuses', $statuses, $order );

		$order_status = (string) self::row_prop( $order, 'status' );

		if ( array() === $statuses ) {
			return 'failed' !== $order_status;
		}

		return in_array( $order_status, $statuses, true );
	}

	/**
	 * Whether this purchase has already been tracked - either flagged on the
	 * order (_ga_tracked order meta) or recorded in the visitor's browser
	 * cookie. Always false when the "do not use the order tracked flag" option
	 * is on, which disables both checks.
	 *
	 * @param \EDD\Orders\Order $order The order to check.
	 * @return bool
	 */
	public function is_purchase_already_tracked( \EDD\Orders\Order $order ): bool {
		if ( (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG ) ) {
			return false;
		}

		$order_id = (int) self::row_prop( $order, 'id', 0 );

		if ( 1 === (int) edd_get_order_meta( $order_id, self::ORDER_TRACKED_META, true ) ) {
			return true;
		}

		if ( isset( $_COOKIE['gtm4wp_orderid_tracked'] ) ) {
			$tracked_order = sanitize_text_field( wp_unslash( $_COOKIE['gtm4wp_orderid_tracked'] ) );

			// The browser guard stores the order number (which equals the id
			// unless sequential order numbers are enabled), so compare on both.
			if ( '' !== $tracked_order && in_array( $tracked_order, array( (string) $order_id, (string) $order->get_number() ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flags the order as tracked (via the _ga_tracked order meta) so the
	 * purchase is not counted twice. No-op when the "do not use the order
	 * tracked flag" option is on.
	 *
	 * @param \EDD\Orders\Order $order The order to flag.
	 * @return void
	 */
	public function flag_order_tracked( \EDD\Orders\Order $order ): void {
		if ( (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG ) ) {
			return;
		}

		edd_update_order_meta( (int) self::row_prop( $order, 'id', 0 ), self::ORDER_TRACKED_META, 1 );
	}

	/**
	 * Whether the order belongs to a new (first time) customer, used for the
	 * Google new-customer reporting variable. Unknown customers (guest orders
	 * without a customer record) report as new.
	 *
	 * @param \EDD\Orders\Order $order The order to check.
	 * @return bool
	 */
	public function is_new_customer( \EDD\Orders\Order $order ): bool {
		$customer_id = (int) self::row_prop( $order, 'customer_id', 0 );
		if ( $customer_id <= 0 ) {
			return true;
		}

		$customer = edd_get_customer( $customer_id );
		if ( ! is_object( $customer ) ) {
			return true;
		}

		return (int) self::row_prop( $customer, 'purchase_count', 0 ) <= 1;
	}

	/**
	 * Both new/returning customer signals for the purchase event.
	 *
	 * Google names the same idea differently on two surfaces: Google Ads
	 * customer acquisition reads the boolean `new_customer`, while the GA4
	 * e-commerce reference documents a `customer_type` string of `new` or
	 * `returning`. Both are sent - they are not alternatives, and dropping
	 * either breaks one integration while the other keeps working. Mirrors
	 * the WooCommerce module's ProductData::customer_signals().
	 *
	 * @see https://support.google.com/google-ads/answer/12077475 Google Ads: the new_customer parameter.
	 * @see https://developers.google.com/analytics/devguides/collection/ga4/reference/events?client_type=gtm GA4: the customer_type parameter on purchase.
	 *
	 * @param \EDD\Orders\Order $order The order being tracked.
	 * @return array<string, bool|string>
	 */
	public function customer_signals( \EDD\Orders\Order $order ): array {
		$is_new_customer = $this->is_new_customer( $order );

		return array(
			'new_customer'  => $is_new_customer,
			'customer_type' => $is_new_customer ? 'new' : 'returning',
		);
	}
}
