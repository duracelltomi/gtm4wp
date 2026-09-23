<?php
/**
 * Minimal WooCommerce OrderUtil stub for unit testing the HPOS reading.
 *
 * @package GTM4WP
 */

// phpcs:disable

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( __NAMESPACE__ . '\OrderUtil' ) ) {
	/**
	 * Stub of WooCommerce's OrderUtil exposing only the static check GTM4WP
	 * reads. Tests toggle the public property.
	 */
	class OrderUtil {
		public static bool $hpos = false;

		public static function custom_orders_table_usage_is_enabled(): bool {
			return self::$hpos;
		}
	}
}

// phpcs:enable
