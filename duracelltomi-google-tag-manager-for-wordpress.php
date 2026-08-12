<?php
/**
 * Google Tag Manager for WordPress
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 *
 * @wordpress-plugin
 * Plugin Name: GTM4WP - A Google Tag Manager (GTM) plugin for WordPress
 * Plugin URI: https://gtm4wp.com/
 * Description: The first Google Tag Manager plugin for WordPress with business goals in mind
 * Version: 2.0.0-beta2
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author: Thomas Geiger
 * Author URI: https://gtm4wp.com/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: duracelltomi-google-tag-manager
 * Domain Path: /languages

 * WC requires at least: 5.0
 * WC tested up to: 11.0.0
 */

// This file must stay parseable on outdated PHP versions so that the
// requirements notice below can render instead of a fatal error.
// Do not use PHP 8+ syntax here.

defined( 'ABSPATH' ) || exit;

define( 'GTM4WP_VERSION', '2.0.0-beta2' );
define( 'GTM4WP_PATH', plugin_dir_path( __FILE__ ) );
define( 'GTM4WP_PLUGIN_FILE', __FILE__ );

/*
 * Deprecated in 2.0, to be removed in 2.1.
 *
 * These three globals carry a 1.x typo in their prefix: gtp4wp_, not gtm4wp_.
 * Nothing inside the plugin reads them. They are set only for third-party code
 * written against 1.x, which is why the typo was never worth correcting.
 *
 * A global variable cannot raise a deprecation notice the way a function can, so
 * this comment and the changelog entry are the only warning a consumer gets.
 *
 * Replacements, available on both admin and frontend requests and defined at the
 * same point in the load order:
 *
 *   $gtp4wp_plugin_url      -> plugin_dir_url( GTM4WP_PLUGIN_FILE )
 *   $gtp4wp_plugin_basename -> plugin_basename( GTM4WP_PLUGIN_FILE )
 *   $gtp4wp_script_path     -> plugin_dir_url( GTM4WP_PLUGIN_FILE ) . 'build/'
 */
global $gtp4wp_plugin_url, $gtp4wp_plugin_basename, $gtp4wp_script_path;
$gtp4wp_plugin_url      = plugin_dir_url( __FILE__ );
$gtp4wp_plugin_basename = plugin_basename( __FILE__ );
$gtp4wp_script_path     = $gtp4wp_plugin_url . 'build/';

/**
 * Outputs an admin notice when the site does not fulfill the minimum
 * PHP or WordPress version requirements of GTM4WP 2.x.
 *
 * @return void
 */
function gtm4wp_requirements_notice() {
	echo '<div class="notice notice-error"><p>';
	printf(
		/* translators: 1: required PHP version, 2: required WordPress version, 3: current PHP version, 4: current WordPress version. */
		esc_html__(
			'GTM4WP 2.x requires PHP %1$s and WordPress %2$s or newer. Your site runs PHP %3$s and WordPress %4$s, therefore the plugin is currently inactive. Please update your environment or downgrade to GTM4WP 1.x.',
			'duracelltomi-google-tag-manager'
		),
		'8.0',
		'6.3',
		esc_html( PHP_VERSION ),
		esc_html( $GLOBALS['wp_version'] )
	);
	echo '</p></div>';
}

if (
	version_compare( PHP_VERSION, '8.0', '<' ) ||
	version_compare( $GLOBALS['wp_version'], '6.3', '<' )
) {
	add_action( 'admin_notices', 'gtm4wp_requirements_notice' );
	return;
}

require_once GTM4WP_PATH . 'src/Autoloader.php';
\GTM4WP\Autoloader::register();

require_once GTM4WP_PATH . 'compat/constants.php';

/**
 * WordPress hook function run after plugins have been loaded.
 * Boots the plugin core which routes between admin and frontend code paths.
 *
 * @see https://developer.wordpress.org/reference/hooks/plugins_loaded/
 *
 * @return void
 */
function gtm4wp_bootstrap() {
	\GTM4WP\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'gtm4wp_bootstrap' );

/**
 * Adds an action to declare compatibility with High Performance Order Storage (HPOS)
 * before WooCommerce initialization.
 *
 * @since 1.17
 */
add_action(
	'before_woocommerce_init',
	function () {
		// Check if the FeaturesUtil class exists in the \Automattic\WooCommerce\Utilities namespace.
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			// Declare compatibility with custom order tables using the FeaturesUtil class.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
