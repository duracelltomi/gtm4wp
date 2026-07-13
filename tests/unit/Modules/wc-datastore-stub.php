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
		public static function is_returning_customer( $order ) {
			return false;
		}
	}
}

// phpcs:enable
