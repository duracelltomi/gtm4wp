<?php
/**
 * Minimal WooCommerce class stubs for unit testing.
 *
 * Only the getters used by the GTM4WP WooCommerce module are implemented;
 * values are injected through the constructor.
 *
 * @package GTM4WP
 */

// phpcs:disable

if ( ! class_exists( 'WC_DateTime' ) ) {
	// WooCommerce's WC_DateTime extends \DateTime and adds a date() helper.
	class WC_DateTime extends \DateTime {
		public function date( $format ) {
			return $this->format( $format );
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function __construct( private array $data = array() ) {}

		private function value( string $key, $fallback = null ) {
			return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
		}

		public function get_id() {
			return $this->value( 'id', 0 );
		}
		public function get_type() {
			return $this->value( 'type', 'simple' );
		}
		public function get_sku() {
			return $this->value( 'sku', '' );
		}
		public function get_title() {
			return $this->value( 'title', '' );
		}
		public function get_stock_quantity() {
			return $this->value( 'stock_quantity', null );
		}
		public function get_stock_status() {
			return $this->value( 'stock_status', 'instock' );
		}
		public function get_parent_id() {
			return $this->value( 'parent_id', 0 );
		}
		public function get_variation_attributes() {
			return $this->value( 'variation_attributes', array() );
		}
		public function get_permalink() {
			return $this->value( 'permalink', '' );
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	// Real product variations - and WooCommerce Subscriptions variations, which
	// report get_type() === 'subscription_variation' - both extend this class.
	// The 'type' is still supplied through the constructor data.
	class WC_Product_Variation extends WC_Product {}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public function __construct( private array $data = array() ) {}

		private function value( string $key, $fallback = null ) {
			return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
		}

		public function get_total() {
			return $this->value( 'total', 0 );
		}
		public function get_total_tax() {
			return $this->value( 'total_tax', 0 );
		}
		public function get_shipping_total() {
			return $this->value( 'shipping_total', 0 );
		}
		public function get_currency() {
			return $this->value( 'currency', 'USD' );
		}
		public function get_order_number() {
			return $this->value( 'order_number', '' );
		}
		public function get_coupon_codes() {
			return $this->value( 'coupon_codes', array() );
		}
		public function get_items() {
			return $this->value( 'items', array() );
		}
		public function get_item_total( $order_item, $inc_tax ) {
			// Reflect the requested tax basis when fixtures provide both, so tests
			// can assert whether tax was included; otherwise fall back to item_total.
			if ( $inc_tax && array_key_exists( 'item_total_incl', $this->data ) ) {
				return $this->data['item_total_incl'];
			}
			if ( ! $inc_tax && array_key_exists( 'item_total_excl', $this->data ) ) {
				return $this->data['item_total_excl'];
			}
			return $this->value( 'item_total', 0 );
		}

		// Records meta writes so tests can assert the _ga_tracked flag.
		public array $saved_meta = array();

		// Getters used by ProductData::get_raw_order_datalayer() and by the
		// purchase-flow eligibility checks (max age, tracked flag, status).
		public function get_date_created() {
			$value = $this->value( 'date_created', 'now' );
			return $value instanceof \DateTimeInterface ? $value : new \WC_DateTime( (string) $value );
		}
		public function get_date_paid() {
			$value = $this->value( 'date_paid', null );
			if ( null === $value ) {
				return null;
			}
			return $value instanceof \DateTimeInterface ? $value : new \WC_DateTime( (string) $value );
		}
		public function is_paid() {
			return (bool) $this->value( 'is_paid', false );
		}
		public function get_meta( $key, $single = true ) {
			$meta = $this->value( 'meta', array() );
			return array_key_exists( $key, $meta ) ? $meta[ $key ] : '';
		}
		public function update_meta_data( $key, $value ) {
			$this->saved_meta[ $key ] = $value;
		}
		public function save() {
			return true;
		}
		public function get_order_key() {
			return $this->value( 'order_key', '' );
		}
		public function get_payment_method() {
			return $this->value( 'payment_method', '' );
		}
		public function get_payment_method_title() {
			return $this->value( 'payment_method_title', '' );
		}
		public function get_shipping_method() {
			return $this->value( 'shipping_method', '' );
		}
		public function get_status() {
			return $this->value( 'status', 'processing' );
		}
		public function get_discount_total() {
			return $this->value( 'discount_total', 0 );
		}
		public function get_discount_tax() {
			return $this->value( 'discount_tax', 0 );
		}
		public function get_shipping_tax() {
			return $this->value( 'shipping_tax', 0 );
		}
		public function get_cart_tax() {
			return $this->value( 'cart_tax', 0 );
		}
		public function get_total_discount() {
			return $this->value( 'total_discount', 0 );
		}
		public function get_subtotal() {
			return $this->value( 'subtotal', 0 );
		}
		public function get_tax_totals() {
			return $this->value( 'tax_totals', array() );
		}
		public function get_customer_id() {
			return $this->value( 'customer_id', 0 );
		}
		public function get_billing_first_name() {
			return $this->value( 'billing_first_name', '' );
		}
		public function get_billing_last_name() {
			return $this->value( 'billing_last_name', '' );
		}
		public function get_billing_company() {
			return $this->value( 'billing_company', '' );
		}
		public function get_billing_address_1() {
			return $this->value( 'billing_address_1', '' );
		}
		public function get_billing_address_2() {
			return $this->value( 'billing_address_2', '' );
		}
		public function get_billing_city() {
			return $this->value( 'billing_city', '' );
		}
		public function get_billing_state() {
			return $this->value( 'billing_state', '' );
		}
		public function get_billing_postcode() {
			return $this->value( 'billing_postcode', '' );
		}
		public function get_billing_country() {
			return $this->value( 'billing_country', '' );
		}
		public function get_billing_email() {
			return $this->value( 'billing_email', '' );
		}
		public function get_billing_phone() {
			return $this->value( 'billing_phone', '' );
		}
		public function get_shipping_first_name() {
			return $this->value( 'shipping_first_name', '' );
		}
		public function get_shipping_last_name() {
			return $this->value( 'shipping_last_name', '' );
		}
		public function get_shipping_company() {
			return $this->value( 'shipping_company', '' );
		}
		public function get_shipping_address_1() {
			return $this->value( 'shipping_address_1', '' );
		}
		public function get_shipping_address_2() {
			return $this->value( 'shipping_address_2', '' );
		}
		public function get_shipping_city() {
			return $this->value( 'shipping_city', '' );
		}
		public function get_shipping_state() {
			return $this->value( 'shipping_state', '' );
		}
		public function get_shipping_postcode() {
			return $this->value( 'shipping_postcode', '' );
		}
		public function get_shipping_country() {
			return $this->value( 'shipping_country', '' );
		}
	}
}

if ( ! class_exists( 'WC_Customer' ) ) {
	class WC_Customer {
		// Fixtures keyed by customer id, since the code builds a fresh
		// WC_Customer from an id (new WC_Customer( $existing->get_id() )).
		public static array $fixtures = array();

		private array $data;

		public function __construct( $id = 0 ) {
			$this->data       = self::$fixtures[ $id ] ?? array();
			$this->data['id'] = $id;
		}

		private function value( string $key, $fallback = '' ) {
			return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
		}

		public function get_id() {
			return $this->value( 'id', 0 );
		}
		public function get_order_count() {
			return $this->value( 'order_count', 0 );
		}
		public function get_total_spent() {
			return $this->value( 'total_spent', 0 );
		}
		public function get_first_name() {
			return $this->value( 'first_name', '' );
		}
		public function get_last_name() {
			return $this->value( 'last_name', '' );
		}
		public function get_billing_first_name() {
			return $this->value( 'billing_first_name', '' );
		}
		public function get_billing_last_name() {
			return $this->value( 'billing_last_name', '' );
		}
		public function get_billing_company() {
			return $this->value( 'billing_company', '' );
		}
		public function get_billing_address_1() {
			return $this->value( 'billing_address_1', '' );
		}
		public function get_billing_address_2() {
			return $this->value( 'billing_address_2', '' );
		}
		public function get_billing_city() {
			return $this->value( 'billing_city', '' );
		}
		public function get_billing_state() {
			return $this->value( 'billing_state', '' );
		}
		public function get_billing_postcode() {
			return $this->value( 'billing_postcode', '' );
		}
		public function get_billing_country() {
			return $this->value( 'billing_country', '' );
		}
		public function get_billing_email() {
			return $this->value( 'billing_email', '' );
		}
		public function get_billing_phone() {
			return $this->value( 'billing_phone', '' );
		}
		public function get_shipping_first_name() {
			return $this->value( 'shipping_first_name', '' );
		}
		public function get_shipping_last_name() {
			return $this->value( 'shipping_last_name', '' );
		}
		public function get_shipping_company() {
			return $this->value( 'shipping_company', '' );
		}
		public function get_shipping_address_1() {
			return $this->value( 'shipping_address_1', '' );
		}
		public function get_shipping_address_2() {
			return $this->value( 'shipping_address_2', '' );
		}
		public function get_shipping_city() {
			return $this->value( 'shipping_city', '' );
		}
		public function get_shipping_state() {
			return $this->value( 'shipping_state', '' );
		}
		public function get_shipping_postcode() {
			return $this->value( 'shipping_postcode', '' );
		}
		public function get_shipping_country() {
			return $this->value( 'shipping_country', '' );
		}
	}
}

// phpcs:enable
