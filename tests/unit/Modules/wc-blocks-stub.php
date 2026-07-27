<?php
/**
 * Minimal WooCommerce Blocks stub for unit testing the block-page detection.
 *
 * @package GTM4WP
 */

// phpcs:disable

namespace Automattic\WooCommerce\Blocks\Utils;

if ( ! class_exists( __NAMESPACE__ . '\CartCheckoutUtils' ) ) {
	/**
	 * Stub of WooCommerce's CartCheckoutUtils exposing only the two static
	 * checks GTM4WP uses. Tests toggle the public properties.
	 */
	class CartCheckoutUtils {
		public static bool $checkout_block = false;
		public static bool $cart_block = false;

		public static function is_checkout_block_default(): bool {
			return self::$checkout_block;
		}

		public static function is_cart_block_default(): bool {
			return self::$cart_block;
		}
	}
}

// phpcs:enable
