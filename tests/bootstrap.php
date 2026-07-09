<?php
/**
 * PHPUnit bootstrap file for GTM4WP unit tests.
 *
 * Loads the Composer autoloader (plugin classes + Brain Monkey) and the
 * backward compatible plugin constants. WordPress itself is never loaded;
 * WordPress functions are stubbed per test through Brain Monkey.
 *
 * @package GTM4WP
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Satisfy the direct-access guards of the plugin files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}

if ( ! defined( 'GTM4WP_VERSION' ) ) {
	define( 'GTM4WP_VERSION', '2.0.0-test' );
}

if ( ! defined( 'GTM4WP_PATH' ) ) {
	define( 'GTM4WP_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'GTM4WP_PLUGIN_FILE' ) ) {
	define( 'GTM4WP_PLUGIN_FILE', dirname( __DIR__ ) . '/duracelltomi-google-tag-manager-for-wordpress.php' );
}

require_once dirname( __DIR__ ) . '/compat/constants.php';
require_once __DIR__ . '/unit/wp-class-stubs.php';
