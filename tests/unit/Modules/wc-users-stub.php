<?php
/**
 * Minimal stub of the WooCommerce user utilities the order-received page uses to
 * decide whether a guest must verify their email address before being shown an
 * order. Lives in its own file because it needs the namespaced WooCommerce
 * location, and the production code reaches it by that name.
 *
 * @package GTM4WP
 */

namespace Automattic\WooCommerce\Internal\Utilities;

// phpcs:disable

if ( ! class_exists( Users::class ) ) {
	class Users {
		/**
		 * What should_user_verify_order_email() reports. Settable so BOTH answers
		 * are reachable: a hardcoded false would make the withholding branch
		 * untestable while still showing the call site as covered. Any test that
		 * changes it MUST restore false (use try/finally or tearDown), because this
		 * is process-wide state and leaking it breaks test-order independence.
		 */
		public static $verify_order_email = false;

		/**
		 * Every call, as array{0:mixed,1:mixed,2:mixed} of the arguments received,
		 * so a test can assert WHICH order and context were asked about rather than
		 * only that the answer was honored.
		 *
		 * @var array<int, array<int, mixed>>
		 */
		public static array $calls = array();

		/**
		 * Signature kept identical to WooCommerce's (order ID first, not an order
		 * object; optional supplied email; context) so the double cannot be more
		 * permissive than the real collaborator and absorb a wrong call.
		 */
		public static function should_user_verify_order_email( $order_id, $supplied_email = null, $context = 'view' ) {
			self::$calls[] = array( $order_id, $supplied_email, $context );

			return self::$verify_order_email;
		}
	}
}

// phpcs:enable
