<?php
/**
 * Test-only shim that lets a test control which commerce platforms the Google
 * Data Manager module believes are active.
 *
 * WHY THIS EXISTS
 * ---------------
 * GoogleDataManagerModule::register_frontend_hooks() gates the attribution
 * capture on function_exists( 'WC' ) / function_exists( 'EDD' ), because capture
 * exists to attach data to orders and there are none without a store. Brain
 * Monkey defines a mocked function process-wide and permanently, so as soon as
 * ANY test in the suite stubs WC() or EDD() - and several do - that guard can
 * never fail again. A test asserting the no-platform branch would then pass
 * alone and fail in the full run, or worse, quietly assert nothing (TS-16).
 *
 * HOW IT WORKS
 * ------------
 * PHP resolves an unqualified function call inside a namespace against that
 * namespace first and only then against the global scope, so defining
 * function_exists() here intercepts the unqualified calls in the production
 * files of this namespace. The global builtin cannot be redefined.
 *
 * IT IS NO MORE PERMISSIVE THAN THE REAL FUNCTION (.testing UC-3). With nothing
 * forced it delegates to the global builtin and answers exactly what PHP would,
 * so every other function_exists() call in this namespace behaves identically
 * whether or not this file is loaded.
 *
 * Loaded from tests/bootstrap.php rather than from a test file: a lazily
 * required shim would make behaviour depend on which test ran first, which is
 * the exact order-dependence it exists to remove.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Modules\GoogleDataManager;

/**
 * Function names forced to a fixed answer in this namespace, as
 * 'name' => bool. Process-wide state: a test that writes it MUST clear it in
 * tearDown().
 *
 * @var array<string, bool>
 */
$GLOBALS['gtm4wp_test_forced_functions'] = array();

/**
 * Namespaced override of function_exists(), delegating unless forced.
 *
 * @param string $function_name Function name to look up.
 * @return bool
 */
function function_exists( $function_name ): bool {
	$forced = isset( $GLOBALS['gtm4wp_test_forced_functions'] ) && is_array( $GLOBALS['gtm4wp_test_forced_functions'] )
		? $GLOBALS['gtm4wp_test_forced_functions']
		: array();

	if ( is_string( $function_name ) && array_key_exists( $function_name, $forced ) ) {
		return (bool) $forced[ $function_name ];
	}

	return \function_exists( $function_name );
}
