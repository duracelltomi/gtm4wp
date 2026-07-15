<?php
/**
 * Minimal WooCommerce Store API class stubs for unit testing
 * StoreApiData::register().
 *
 * Only the surface StoreApiData touches is implemented: a container that
 * resolves ExtendSchema, an ExtendSchema that records register_endpoint_data()
 * calls on a static array the test inspects, and the two endpoint-schema
 * identifier constants.
 *
 * @package GTM4WP
 */

// phpcs:disable

namespace {
	// WordPress core constant referenced by StoreApiData::register(); WP defines it,
	// the unit-test environment does not.
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
}

namespace Automattic\WooCommerce\StoreApi {

	if ( ! class_exists( __NAMESPACE__ . '\\StoreApi' ) ) {
		class StoreApi {
			/**
			 * Captured register_endpoint_data() argument arrays.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public static array $registered = array();

			public static function container(): object {
				return new class() {
					public function get( string $id ): object {
						return new \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema();
					}
				};
			}
		}
	}
}

namespace Automattic\WooCommerce\StoreApi\Schemas {

	if ( ! class_exists( __NAMESPACE__ . '\\ExtendSchema' ) ) {
		class ExtendSchema {
			public function register_endpoint_data( array $args ): void {
				\Automattic\WooCommerce\StoreApi\StoreApi::$registered[] = $args;
			}
		}
	}
}

namespace Automattic\WooCommerce\StoreApi\Schemas\V1 {

	if ( ! class_exists( __NAMESPACE__ . '\\ProductSchema' ) ) {
		class ProductSchema {
			const IDENTIFIER = 'product';
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\CartItemSchema' ) ) {
		class CartItemSchema {
			const IDENTIFIER = 'cart-item';
		}
	}
}

// phpcs:enable
