<?php
/**
 * Minimal stub of the WooCommerce checkout shortcode class whose
 * guest_should_verify_email() member is the production feature-detect for
 * "this WooCommerce has the order-received visitor gates at all" - both gates
 * and this member shipped together in WooCommerce 7.9.0, and the member is
 * still present on 11.0.0.
 *
 * Production never CALLS the member; it only probes it with method_exists().
 * The signature is still kept identical to WooCommerce's - private static,
 * order object plus context - so this double cannot drift more permissive
 * than the real collaborator (.testing UC-3), and because "method_exists()
 * sees a private static" is itself one of the probed facts the production
 * comment relies on.
 *
 * Defined unconditionally, like wc-users-stub.php - which is exactly why the
 * "gates absent" branch would be unreachable without the namespaced
 * method_exists() shim (wc-feature-guard-shim.php): a test reaches it by
 * hiding the member through $GLOBALS['gtm4wp_test_hidden_members'] and MUST
 * clear that in tearDown().
 *
 * @package GTM4WP
 */

// phpcs:disable

if ( ! class_exists( 'WC_Shortcode_Checkout' ) ) {
	class WC_Shortcode_Checkout {
		/**
		 * Signature copied from WooCommerce 7.9.0-11.0.0. Never invoked by
		 * production code (the probe is method_exists()); the body exists only
		 * so the stub stays honest about what the real member returns for "no
		 * verification needed".
		 */
		private static function guest_should_verify_email( \WC_Order $order, string $context ): bool {
			return false;
		}
	}
}

// phpcs:enable
