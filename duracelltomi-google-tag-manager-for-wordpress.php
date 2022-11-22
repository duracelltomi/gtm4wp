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
 * Plugin Name: GTM4WP
 * Plugin URI: https://gtm4wp.com/
 * Description: The first Google Tag Manager plugin for WordPress with business goals in mind
 * Version: 1.16.2
 * Requires at least: 3.4.0
 * Requires PHP: 7.4
 * Author: Thomas Geiger
 * Author URI: https://gtm4wp.com/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: duracelltomi-google-tag-manager
 * Domain Path: /languages

 * WC requires at least: 5.0
 * WC tested up to: 7.1
 */

define( 'GTM4WP_VERSION', '1.16.2' );
define( 'GTM4WP_PATH', plugin_dir_path( __FILE__ ) );

global $gtp4wp_plugin_url, $gtp4wp_plugin_basename;
$gtp4wp_plugin_url      = plugin_dir_url( __FILE__ );
$gtp4wp_plugin_basename = plugin_basename( __FILE__ );
require_once GTM4WP_PATH . '/common/readoptions.php';

/**
 * WordPress hook function run after plugins have been loaded.
 *
 * @see https://developer.wordpress.org/reference/hooks/plugins_loaded/
 *
 * @return void
 */
function gtm4wp_init() {
	load_plugin_textdomain( 'duracelltomi-google-tag-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	if ( is_admin() ) {
		require_once GTM4WP_PATH . '/admin/admin.php';
	} else {
		require_once GTM4WP_PATH . '/public/frontend.php';
	}
}
add_action( 'plugins_loaded', 'gtm4wp_init' );
