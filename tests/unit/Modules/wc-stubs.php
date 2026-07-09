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
			return $this->value( 'item_total', 0 );
		}
	}
}

// phpcs:enable
