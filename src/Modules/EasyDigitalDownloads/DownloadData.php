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
use GTM4WP\Frontend\DefaultLanguage;
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
	 * Statuses that end the reliable-purchase re-check: edd_get_payment_statuses()
	 * (EDD 3.7.0) minus the waiting ones (U132, pinned by a test).
	 *
	 * @var string[]
	 */
	public const PENDING_PURCHASE_TERMINAL_STATUSES = array( 'refunded', 'partially_refunded', 'revoked', 'failed', 'abandoned' );

	/**
	 * Minutes from creation a not-yet-trackable order is re-checked (the
	 * WooCommerce window).
	 */
	public const PENDING_PURCHASE_RECHECK_WINDOW_MINUTES = 24 * 60;

	/**
	 * The never-cached item contexts the server-side list-attribution merge
	 * applies to (#405); the cacheable detail page and list markup are
	 * enriched client-side instead.
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
	 * The store base tax rate, resolved once per instance: edd_get_tax_rate()
	 * looks the logged-in user's customer record up on every call, uncached
	 * when there is none, and a download grid prices dozens of amounts (#545).
	 *
	 * @var float|null
	 */
	private ?float $base_tax_rate = null;

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Reads a property of an EDD row object, which exposes data through __get()
	 * without __isset(), so isset()/?? report false (RI-12).
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
	 * @param string   $attributes_used_for The placement ID of the product passed to the item filters so 3rd party code knows where the data is used (productdetail, productlist, addtocartsingle, cart, checkout, purchase, and refund: a Google Data Manager refund line built in a background job, no visitor or cart).
	 * @param mixed    $source_item Optional. The raw cart content details array or Order_Item the item is built from, passed only to the GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE filter. Default null.
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

		$download_id = (int) $download->get_ID();

		// Master-language reporting (#145): item identity/text from the
		// default-language download, price and internal id per language, as
		// in the WooCommerce module.
		$data_download    = $download;
		$data_download_id = $download_id;

		// No DefaultLanguage::is_active() gate: the resolution filters must work
		// without WPML/Polylang, as in the WooCommerce module.
		if ( true === $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDMASTERLANGUAGE ) ) {
			$master_id = DefaultLanguage::post_id( $download_id, 'download' );
			if ( $master_id !== $download_id ) {
				$master_download = edd_get_download( $master_id );
				if ( $master_download instanceof \EDD_Download ) {
					$data_download    = $master_download;
					$data_download_id = $master_id;
				}
			}
		}

		$remarketing_id = $data_download_id;

		// edd_get_download_sku() returns false/'-' when SKUs are disabled or unset.
		$download_sku = (string) edd_get_download_sku( $data_download_id );
		if ( '-' === $download_sku ) {
			$download_sku = '';
		}

		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDUSESKU ) && ( '' !== $download_sku ) ) {
			$remarketing_id = $download_sku;
		}

		$download_cat       = Helpers::get_product_category( $data_download_id, $use_full_category_path, self::CATEGORY_TAXONOMY );
		$download_cat_parts = explode( '/', $download_cat );

		if ( array_key_exists( 'price', $additional_product_attributes ) ) {
			$display_price = (float) $additional_product_attributes['price'];
		} else {
			$display_price = $this->download_price( $download, $price_id );
		}

		$_temp_productdata = array(
			'internal_id'              => $download_id,
			// Always a string, matching the WooCommerce module: Merchant Center
			// feed ids are strings and GA4 matches item_id against them.
			'item_id'                  => (string) $remarketing_id,
			'item_name'                => $data_download->get_name(),
			'sku'                      => ( '' !== $download_sku ) ? $download_sku : $data_download_id,
			'price'                    => round( $display_price, 2 ),
			'google_business_vertical' => $this->business_vertical(),
		);

		// Variable prices are options on one download: the option name is the
		// item_variant, item_id stays the download id (or SKU). Under master
		// language the master's option at the same price id, falling back to
		// the current name (translations can differ in option count).
		$variant_name = $this->price_option_name( $data_download_id, $price_id );
		if ( '' === $variant_name && $data_download_id !== $download_id ) {
			$variant_name = $this->price_option_name( $download_id, $price_id );
		}
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
			$_temp_productdata['item_brand'] = Helpers::get_product_term( $data_download_id, $brand_taxonomy );
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
		// contexts only (see LIST_ATTRIBUTION_CONTEXTS).
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

		// GA4 affiliation, added only when third party code supplies one
		// (the filter shared with the WooCommerce module).
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
		 * Filters the ecommerce item array before using it for tracking: the
		 * same source-aware filter the WooCommerce module applies. On EDD the
		 * source is the cart content details array, the Order_Item, or null.
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
	 * download without a selection, and the plain price otherwise - on the
	 * purchase's tax basis (basis_price()).
	 *
	 * @param \EDD_Download $download The download.
	 * @param int|null      $price_id The selected variable price id, or null.
	 * @return float
	 */
	private function download_price( \EDD_Download $download, ?int $price_id ): float {
		$download_id = (int) $download->get_ID();

		if ( $download->has_variable_prices() ) {
			$amount = ( null !== $price_id )
				? (float) edd_get_price_option_amount( $download_id, $price_id )
				: (float) edd_get_lowest_price_option( $download_id );
		} else {
			$amount = (float) $download->get_price();
		}

		return $this->basis_price( $amount, $download_id );
	}

	/**
	 * Whether item prices include tax, on every surface: EDD order and cart
	 * line totals are gross, so only "exclude tax" makes them net (#519).
	 *
	 * @return bool
	 */
	private function item_prices_include_tax(): bool {
		return ! $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX );
	}

	/**
	 * A catalogue amount on the purchase's tax basis (#519), taxed at the store
	 * base rate so a cached download page is the same for every visitor. The tax
	 * is rounded as EDD's cart rounds it (round_like_the_cart()), so a one-item
	 * cart line reports the same price (U189). A visitor taxed at another rate,
	 * and qty > 1 lines, can differ by that rate or a minor unit.
	 *
	 * @param float $amount      The catalogue amount as entered in EDD.
	 * @param int   $download_id The download id.
	 * @return float
	 */
	public function basis_price( float $amount, int $download_id ): float {
		if ( ! function_exists( 'edd_use_taxes' ) || ! edd_use_taxes() || edd_download_is_tax_exclusive( $download_id ) ) {
			return $amount;
		}

		$this->base_tax_rate ??= (float) edd_get_tax_rate( edd_get_shop_country(), edd_get_shop_state(), false );

		$tax = self::round_like_the_cart( (float) edd_calculate_tax( $amount, '', '', false, $this->base_tax_rate ) );

		if ( edd_prices_include_tax() ) {
			return $this->item_prices_include_tax() ? $amount : $amount - $tax;
		}

		return $this->item_prices_include_tax() ? $amount + $tax : $amount;
	}

	/**
	 * Rounds a line tax the way EDD's cart does (#533, U189): from 3.3.6 through
	 * edd_format_amount( …, 'data' ), which uses the currency's own decimals (0
	 * for KRW, CLP, VND and the other zero-decimal codes); before that with
	 * round() to edd_currency_decimal_filter(). 3.3.5 knows no 'data' context.
	 *
	 * @param float $tax The unrounded tax.
	 * @return float
	 */
	private static function round_like_the_cart( float $tax ): float {
		if ( defined( 'EDD_VERSION' ) && version_compare( (string) EDD_VERSION, '3.3.6', '>=' ) && function_exists( 'edd_format_amount' ) ) {
			return (float) edd_format_amount( $tax, true, '', 'data' );
		}

		return round( $tax, function_exists( 'edd_currency_decimal_filter' ) ? (int) edd_currency_decimal_filter() : 2 );
	}

	/**
	 * Returns the name of a variable price option, or an empty string when the
	 * download has no variable prices or the option is unknown.
	 *
	 * @param int      $download_id The download id.
	 * @param int|null $price_id    The price option id, or null.
	 * @return string
	 */
	private function price_option_name( int $download_id, ?int $price_id ): string {
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

		// The line total ("price") includes tax; the purchase rule takes it out
		// when the admin excludes tax, so item and transaction revenue reconcile.
		$line_total = Helpers::purchase_rule_value(
			(float) ( $cart_item_details['price'] ?? 0 ),
			(float) ( $cart_item_details['tax'] ?? 0 ),
			0.0,
			! $this->item_prices_include_tax(),
			false
		);

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
			$line_total = Helpers::purchase_rule_value(
				(float) self::row_prop( $order_item, 'total', 0 ),
				(float) self::row_prop( $order_item, 'tax', 0 ),
				0.0,
				! $this->item_prices_include_tax(),
				false
			);

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
		$country    = (string) self::row_prop( $address, 'country' );
		$phone      = $this->order_phone( $order );
		$discount   = (float) self::row_prop( $order, 'discount', 0 );
		$tax        = (float) self::row_prop( $order, 'tax', 0 );

		// EDD stores dates in UTC; ISO 8601 like the WooCommerce orderData.
		$date_created = (string) self::row_prop( $order, 'date_created' );
		$timestamp    = '' !== $date_created ? strtotime( $date_created . ' UTC' ) : false;

		// Same key names as the WooCommerce orderData, so one GTM container serves
		// both stores; EDD has no shipping, company or order key to map. Values are
		// passed raw: the sink escapes with wp_json_encode() + hex flags.
		$order_data = array(
			'attributes' => array(
				'date'           => false !== $timestamp ? gmdate( 'c', $timestamp ) : $date_created,
				'order_number'   => (string) $order->get_number(),
				'payment_method' => (string) self::row_prop( $order, 'gateway' ),
				'mode'           => (string) self::row_prop( $order, 'mode' ),
				'status'         => (string) self::row_prop( $order, 'status' ),
				'coupons'        => implode( ', ', $this->order_discount_codes( $order ) ),
			),
			'totals'     => array(
				'currency'       => (string) self::row_prop( $order, 'currency' ),
				'discount_total' => $discount,
				'cart_tax'       => $tax,
				'total'          => (float) self::row_prop( $order, 'total', 0 ),
				'total_tax'      => $tax,
				'total_discount' => $discount,
				'subtotal'       => (float) self::row_prop( $order, 'subtotal', 0 ),
			),
			'customer'   => array(
				'id'      => (int) self::row_prop( $order, 'customer_id', 0 ),
				'billing' => array(
					'first_name'      => $first_name,
					'first_name_hash' => Helpers::normalize_and_hash( 'sha256', $first_name, false ),
					'last_name'       => $last_name,
					'last_name_hash'  => Helpers::normalize_and_hash( 'sha256', $last_name, false ),
					'address_1'       => (string) self::row_prop( $address, 'address' ),
					'address_2'       => (string) self::row_prop( $address, 'address2' ),
					'city'            => (string) self::row_prop( $address, 'city' ),
					'state'           => (string) self::row_prop( $address, 'region' ),
					'postcode'        => (string) self::row_prop( $address, 'postal_code' ),
					'country'         => $country,
					'email'           => $email,
					'email_hash'      => $email_hash,
					'phone'           => $phone,
					'phone_hash'      => '' !== $phone ? Helpers::normalize_and_hash_phone_number( 'sha256', $phone, $country ) : '',
				),
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
		$order_data = apply_filters( GTM4WP_WPFILTER_EEC_EDD_ORDER_DATA, $order_data, $order );

		// A filter returning a non-array leaves orderData out rather than stopping the page (#529).
		return is_array( $order_data ) ? $order_data : array();
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
			$order_revenue = $this->current_purchase_basis( $order )['value'];

			// Optional transaction id prefix; only this event is affected.
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

			// Enhanced Conversions user data, built from the order so guest
			// checkouts are covered; attached only when it carries an identifier.
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
		$data_layer = apply_filters( GTM4WP_WPFILTER_EDD_PURCHASE_DATALAYER, $data_layer, $order );

		// A filter returning a non-array drops the purchase rather than stopping the page (#529).
		return is_array( $data_layer ) ? $data_layer : array();
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
			// Omitted when folding leaves nothing to hash (RI-13), as in the
			// WooCommerce module's user_data block.
			$email_hash = Helpers::normalize_and_hash_email_address( 'sha256', $email );
			if ( '' !== $email_hash ) {
				$user_data['sha256_email_address'] = $email_hash;
			}
		}

		$address_row = $order->get_address();

		$phone = $this->order_phone( $order );
		if ( '' !== $phone ) {
			// Omitted when the number cannot be placed in E.164 (RI-13).
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
	 * The buyer's phone number for an order, or '': EDD core's opt-in checkout
	 * Phone field (order meta '_edd_phone', EDD 3.3.8+) first, then the
	 * community conventions (order meta 'phone', the legacy payment meta
	 * 'phone'), then the gtm4wp_edd_order_phone filter.
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
		 * user_data block of an EDD purchase (normalized to E.164 and SHA-256
		 * hashed) and for orderData.customer.billing.phone / phone_hash;
		 * returning '' removes the phone from both.
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

		return $this->is_order_older_than( $order, $max_age );
	}

	/**
	 * Whether the order was created more than $minutes ago (unreadable date: no).
	 *
	 * @param \EDD\Orders\Order $order   The order to check.
	 * @param int               $minutes The age limit in minutes.
	 * @return bool
	 */
	private function is_order_older_than( \EDD\Orders\Order $order, int $minutes ): bool {
		$date_created = (string) self::row_prop( $order, 'date_created' );
		if ( '' === $date_created ) {
			return false;
		}

		try {
			$reference = new \DateTime( $date_created, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return false;
		}

		$now  = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$diff = $now->diff( $reference );

		return ( ( $diff->days * 24 * 60 ) + ( $diff->h * 60 ) + $diff->i ) > $minutes;
	}

	/**
	 * The purchase eligibility gauntlet for the session-endpoint fallback: not
	 * too old, not already tracked, status trackable. The confirmation page keeps
	 * its own sequence, because orderData sits between the age and tracked checks.
	 *
	 * @param \EDD\Orders\Order $order The order to check.
	 * @return bool
	 */
	public function is_order_trackable( \EDD\Orders\Order $order ): bool {
		return ! $this->is_order_older_than_max_age( $order )
			&& ! $this->is_purchase_already_tracked( $order )
			&& $this->is_order_status_trackable( $order, false );
	}

	/**
	 * Whether an order NOT trackable now may still become so (a store that does
	 * not track `pending`, awaiting the webhook). Mirrors ProductData's.
	 *
	 * @param \EDD\Orders\Order $order The order to check.
	 * @return bool
	 */
	public function may_become_trackable( \EDD\Orders\Order $order ): bool {
		if ( $this->is_order_older_than_max_age( $order ) || $this->is_purchase_already_tracked( $order ) ) {
			return false;
		}

		if ( $this->is_order_status_trackable( $order, false ) ) {
			return false;
		}

		if ( in_array( (string) self::row_prop( $order, 'status' ), self::PENDING_PURCHASE_TERMINAL_STATUSES, true ) ) {
			return false;
		}

		return ! $this->is_order_older_than( $order, self::PENDING_PURCHASE_RECHECK_WINDOW_MINUTES );
	}

	/**
	 * Whether the order's status makes it eligible for the purchase event: the
	 * filterable EDDPURCHASESTATUSES option (pending is in the default because
	 * an offsite gateway can land the buyer on the confirmation page early);
	 * an empty list tracks any order that did not fail. Pending counts only
	 * when the buyer reached the confirmation page: the fallback lanes run when
	 * they did not, e.g. an abandoned PayPal redirect (#471).
	 *
	 * @param \EDD\Orders\Order $order     The order to check.
	 * @param bool              $confirmed Whether the buyer reached the confirmation page; false counts no pending order.
	 * @return bool
	 */
	public function is_order_status_trackable( \EDD\Orders\Order $order, bool $confirmed = false ): bool {
		if ( ! $confirmed && 'pending' === (string) self::row_prop( $order, 'status' ) ) {
			return false;
		}

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

		if ( 1 === (int) edd_get_order_meta( $order_id, Helpers::ORDER_TRACKED_META, true ) ) {
			return true;
		}

		if ( isset( $_COOKIE[ Helpers::ORDER_TRACKED_COOKIE ] ) ) {
			$tracked_order = sanitize_text_field( wp_unslash( $_COOKIE[ Helpers::ORDER_TRACKED_COOKIE ] ) );

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
	 * purchase is not counted twice, and freezes the purchase basis the first
	 * time (#466). The flag is skipped when the "do not use the order tracked
	 * flag" option is on; the basis is not.
	 *
	 * @param \EDD\Orders\Order $order The order to flag.
	 * @return void
	 */
	public function flag_order_tracked( \EDD\Orders\Order $order ): void {
		$order_id = (int) self::row_prop( $order, 'id', 0 );

		if ( ! is_array( edd_get_order_meta( $order_id, Helpers::PURCHASE_BASIS_META, true ) ) ) {
			edd_update_order_meta( $order_id, Helpers::PURCHASE_BASIS_META, $this->current_purchase_basis( $order ) );
		}

		if ( (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG ) ) {
			return;
		}

		edd_update_order_meta( $order_id, Helpers::ORDER_TRACKED_META, 1 );
	}

	/**
	 * The purchase basis under the current options: the order total through the
	 * shared purchase rule (EDD orders carry no shipping), which the refund value
	 * applies too (RI-43, #548).
	 *
	 * @param \EDD\Orders\Order $order The order.
	 * @return array{value: float, total: float, exclude_tax: bool}
	 */
	private function current_purchase_basis( \EDD\Orders\Order $order ): array {
		$exclude_tax = ! $this->item_prices_include_tax();
		$total       = (float) self::row_prop( $order, 'total', 0 );

		return array(
			'value'       => Helpers::purchase_rule_value( $total, (float) self::row_prop( $order, 'tax', 0 ), 0.0, $exclude_tax, false ),
			'total'       => $total,
			'exclude_tax' => $exclude_tax,
		);
	}

	/**
	 * The basis the purchase was tracked on: the frozen meta when well-formed,
	 * else the current options (orders tracked before 2.1, or never tracked).
	 *
	 * @param \EDD\Orders\Order $order The order.
	 * @return array{value: float, total: float, exclude_tax: bool}
	 */
	public function purchase_basis( \EDD\Orders\Order $order ): array {
		$stored = edd_get_order_meta( (int) self::row_prop( $order, 'id', 0 ), Helpers::PURCHASE_BASIS_META, true );

		if ( is_array( $stored ) && is_numeric( $stored['value'] ?? null ) && is_numeric( $stored['total'] ?? null ) ) {
			return array(
				'value'       => (float) $stored['value'],
				'total'       => (float) $stored['total'],
				'exclude_tax' => ! empty( $stored['exclude_tax'] ),
			);
		}

		return $this->current_purchase_basis( $order );
	}

	/**
	 * The order of the buyer's own EDD purchase session (U116), or null. One
	 * definition for the page fallback and the session endpoint (#520).
	 *
	 * @return \EDD\Orders\Order|null
	 */
	public function session_order(): ?\EDD\Orders\Order {
		if ( ! function_exists( 'edd_get_purchase_session' ) || ! function_exists( 'edd_get_order_by' ) ) {
			return null;
		}

		$session = edd_get_purchase_session();
		if ( ! is_array( $session ) || empty( $session['purchase_key'] ) ) {
			return null;
		}

		$order = edd_get_order_by( 'payment_key', (string) $session['purchase_key'] );

		return $order instanceof \EDD\Orders\Order ? $order : null;
	}

	/**
	 * Whether the order belongs to a new (first time) customer, used for the
	 * Google new-customer reporting variable: the customer has no OTHER sale
	 * order in a status EDD counts. Not the customer's purchase_count, which
	 * leaves out this order while it is pending or processing (#513). Guest
	 * orders without a customer record report as new.
	 *
	 * @param \EDD\Orders\Order $order The order to check.
	 * @return bool
	 */
	public function is_new_customer( \EDD\Orders\Order $order ): bool {
		$customer_id = (int) self::row_prop( $order, 'customer_id', 0 );
		if ( $customer_id <= 0 ) {
			return true;
		}

		$others = edd_count_orders(
			array(
				'customer_id' => $customer_id,
				'type'        => 'sale',
				'status__in'  => edd_get_net_order_statuses(),
				'id__not_in'  => array( (int) self::row_prop( $order, 'id', 0 ) ),
			)
		);

		return 0 === (int) $others;
	}

	/**
	 * Both new/returning customer signals for the purchase event (Google Ads
	 * reads `new_customer`, GA4 `customer_type`; dropping either breaks one
	 * integration). Mirrors ProductData::customer_signals().
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
