<?php
/**
 * Test-only shim that lets a test hide a class member from the production feature
 * guards in the GTM4WP\Modules\WooCommerce namespace.
 *
 * WHY THIS EXISTS
 * ---------------
 * PageDataLayer::woocommerce_hides_order_from_visitor() guards its delegation to
 * WooCommerce's Internal Users helper with class_exists() + method_exists(), and
 * the branch taken when that helper is UNAVAILABLE decides whether the buyer's
 * identity is published. That branch was executed zero times by the whole suite
 * (measured, .security #173): wc-users-stub.php defines the stub class
 * unconditionally, so once it is loaded the guard can never fail, and the one
 * assertion a reader would expect to cover it silently covers the other branch.
 *
 * HOW IT WORKS
 * ------------
 * PHP resolves an unqualified function call inside a namespace against that
 * namespace first and only then against the global scope, so defining
 * method_exists() here intercepts the unqualified calls in the production files
 * of this namespace. It cannot be done by redefining the global function - that
 * one is a builtin.
 *
 * IT IS NO MORE PERMISSIVE THAN THE REAL FUNCTION (.testing UC-3). With nothing
 * hidden it delegates to the global builtin and returns exactly what PHP would
 * have, so the other guards in this namespace - StoreApiData's ExtendSchema check
 * and WooCommerceModule's block-default checks - behave identically whether or not
 * this file is loaded.
 *
 * Loaded from tests/bootstrap.php rather than from a test file: a lazily-required
 * shim would make behaviour depend on which test ran first, which is the exact
 * order-dependence TS-16 is about.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Modules\WooCommerce;

/**
 * Members hidden from method_exists() in this namespace, as 'Class::method'.
 * Process-wide state: a test that adds to it MUST clear it in tearDown().
 *
 * @var array<int, string>
 */
$GLOBALS['gtm4wp_test_hidden_members'] = array();

/**
 * Namespaced override of method_exists(), delegating unless the member is hidden.
 *
 * @param object|string $object_or_class Object instance or class name.
 * @param string        $method          Method name.
 * @return bool
 */
function method_exists( $object_or_class, $method ): bool {
	$hidden = isset( $GLOBALS['gtm4wp_test_hidden_members'] ) && is_array( $GLOBALS['gtm4wp_test_hidden_members'] )
		? $GLOBALS['gtm4wp_test_hidden_members']
		: array();

	if ( is_string( $object_or_class ) && in_array( $object_or_class . '::' . $method, $hidden, true ) ) {
		return false;
	}

	return \method_exists( $object_or_class, $method );
}
