<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

/**
 * change PLUGIN_FILE env in phpunit.xml
 */
define('PLUGIN_FILE', getenv('PLUGIN_FILE') );
define('PLUGIN_FOLDER', basename( dirname( __DIR__ ) ) );
define('PLUGIN_PATH', PLUGIN_FOLDER.'/'.PLUGIN_FILE);

// Activates this plugin in WordPress so it can be tested.
$GLOBALS['wp_tests_options'] = array(
  'active_plugins' => array( PLUGIN_PATH ),
);

require_once $_tests_dir . '/includes/functions.php';

/*
 * Activate this plugin automatically
 */
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/'. PLUGIN_FILE;
}

require $_tests_dir . '/includes/bootstrap.php';