<?php
/**
 * Minimal stub of the WooCommerce Analytics DataStore used by the purchase
 * tracking flow (new-customer reporting). Lives in its own file because it
 * needs the namespaced WooCommerce location.
 *
 * @package GTM4WP
 */

namespace Automattic\WooCommerce\Admin\API\Reports\Orders\Stats;

// phpcs:disable

if ( ! class_exists( DataStore::class ) ) {
	class DataStore {
		/**
		 * What is_returning_customer() reports. Settable so the returning-customer
		 * branch is reachable at all - with a hardcoded false, half of the
		 * new_customer / customer_type mapping could never be exercised. Any test
		 * that changes it MUST restore false (use try/finally), because this is
		 * process-wide state and leaking it breaks test-order independence.
		 */
		public static $is_returning = false;

		public static function is_returning_customer( $order ) {
			return self::$is_returning;
		}
	}
}

// phpcs:enable
