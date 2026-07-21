<?php
/**
 * Minimal Easy Digital Downloads class stubs for unit testing.
 *
 * Only what the GTM4WP EDD module reads is implemented; values are injected
 * through the constructors. The row classes (Order, Order_Item,
 * Order_Adjustment, EDD_Customer) expose their data through __get WITHOUT
 * __isset on purpose: real EDD rows are magic-getter backed, and this shape
 * is exactly what RI-12 / TS-13 require a test double to reproduce - a stub
 * with plain public properties would hide isset()-based read bugs.
 *
 * @package GTM4WP
 */

// phpcs:disable

namespace EDD\Orders {

	if ( ! class_exists( 'EDD\Orders\Order' ) ) {
		class Order {
			public function __construct( private array $data = array() ) {}

			public function __get( $name ) {
				return $this->data[ $name ] ?? null;
			}

			public function get_items(): array {
				return $this->data['items'] ?? array();
			}

			public function get_number() {
				// Sequential order number when present, otherwise the id -
				// mirroring EDD\Orders\Order::get_number().
				if ( ! empty( $this->data['order_number'] ) ) {
					return $this->data['order_number'];
				}

				return $this->data['id'] ?? 0;
			}

			public function get_address(): object {
				return (object) array_merge(
					array(
						'first_name'  => '',
						'last_name'   => '',
						'address'     => '',
						'address2'    => '',
						'city'        => '',
						'region'      => '',
						'postal_code' => '',
						'country'     => '',
					),
					$this->data['address'] ?? array()
				);
			}

			public function get_discounts(): array {
				$discounts = array();
				foreach ( $this->data['adjustments'] ?? array() as $adjustment ) {
					if ( 'discount' === $adjustment->type ) {
						$discounts[] = $adjustment;
					}
				}

				return $discounts;
			}
		}
	}

	if ( ! class_exists( 'EDD\Orders\Order_Item' ) ) {
		class Order_Item {
			public function __construct( private array $data = array() ) {}

			public function __get( $name ) {
				return $this->data[ $name ] ?? null;
			}
		}
	}

	if ( ! class_exists( 'EDD\Orders\Order_Adjustment' ) ) {
		class Order_Adjustment {
			public function __construct( private array $data = array() ) {}

			public function __get( $name ) {
				return $this->data[ $name ] ?? null;
			}
		}
	}
}

namespace {

	if ( ! class_exists( 'EDD_Download' ) ) {
		class EDD_Download {
			public function __construct( private array $data = array() ) {}

			public function get_ID() {
				return $this->data['id'] ?? 0;
			}

			public function get_name() {
				return $this->data['name'] ?? '';
			}

			public function get_price() {
				return $this->data['price'] ?? 0.0;
			}

			public function has_variable_prices(): bool {
				return ! empty( $this->data['has_variable_prices'] );
			}

			public function get_type() {
				return $this->data['type'] ?? 'default';
			}
		}
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		// Minimal WP_Post stand-in for get_post() driven page detection.
		class WP_Post {
			public $post_content = '';
		}
	}

	if ( ! class_exists( 'EDD_Customer' ) ) {
		class EDD_Customer {
			public function __construct( private array $data = array() ) {}

			public function __get( $name ) {
				return $this->data[ $name ] ?? null;
			}
		}
	}
}
