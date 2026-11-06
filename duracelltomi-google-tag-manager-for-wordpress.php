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
 * Version: 2.1.1
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author: Thomas Geiger
 * Author URI: https://gtm4wp.com/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: duracelltomi-google-tag-manager
 *
 * WC requires at least: 5.0
 * WC tested up to: 11.2.0
 */

// This file must stay parseable on outdated PHP versions so that the
// requirements notice below can render instead of a fatal error.
// Do not use PHP 8+ syntax here.

defined( 'ABSPATH' ) || exit;

define( 'GTM4WP_VERSION', '2.1.1' );
define( 'GTM4WP_PATH', plugin_dir_path( __FILE__ ) );
define( 'GTM4WP_PLUGIN_FILE', __FILE__ );

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
 * The classes the boot needs that cannot be loaded. Checked before anything
 * boots, since plugin core and modules reference each other directly: an
 * incomplete package or upload would otherwise stop every request with a fatal
 * (#488, #454). This code lives here because src/ is what may be missing.
 *
 * @return string[]
 */
function gtm4wp_missing_classes() {
	$missing = array();

	foreach ( array( 'GTM4WP\Plugin', 'GTM4WP\Module\Registry' ) as $required_class ) {
		if ( ! class_exists( $required_class ) ) {
			$missing[] = $required_class;
		}
	}

	if ( class_exists( 'GTM4WP\Module\Registry' ) ) {
		$missing = array_merge( $missing, \GTM4WP\Module\Registry::missing_builtin_modules() );
	}

	return $missing;
}

/**
 * Whether the files the boot needs are in place.
 *
 * @return bool
 */
function gtm4wp_package_is_complete() {
	return array() === gtm4wp_missing_classes();
}

/**
 * What an administrator reads about an incomplete copy, in every place it is shown.
 *
 * @return string
 */
function gtm4wp_incomplete_package_message() {
	return __(
		'GTM4WP is inactive because some of its files are missing from the plugin folder, so this site outputs no container code and no data layer. Please install the plugin again from the ZIP file on wordpress.org. Your settings are kept, but do not use Delete on the Plugins screen, because that removes them.',
		'duracelltomi-google-tag-manager'
	);
}

/**
 * Outputs an admin notice when the plugin stayed inactive on an incomplete copy.
 *
 * @return void
 */
function gtm4wp_incomplete_package_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html( gtm4wp_incomplete_package_message() ) . '</p></div>';
}

/**
 * Adds the Site Health test for an incomplete copy. The plugin's own collectors
 * do not boot in that state, so this stands alone.
 *
 * @param mixed $tests The Site Health tests.
 * @return mixed
 */
function gtm4wp_add_incomplete_package_test( $tests ) {
	if ( ! is_array( $tests ) ) {
		return $tests;
	}

	if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
		$tests['direct'] = array();
	}

	$tests['direct']['gtm4wp_incomplete_package'] = array(
		'label' => __( 'GTM4WP plugin files', 'duracelltomi-google-tag-manager' ),
		'test'  => 'gtm4wp_incomplete_package_site_health_test',
	);

	return $tests;
}

/**
 * The Site Health result for an incomplete copy, naming what is missing.
 *
 * @return array<string, mixed>
 */
function gtm4wp_incomplete_package_site_health_test() {
	return array(
		'label'       => __( 'GTM4WP is inactive because its plugin files are incomplete', 'duracelltomi-google-tag-manager' ),
		'status'      => 'critical',
		'badge'       => array(
			'label' => __( 'Google Tag Manager', 'duracelltomi-google-tag-manager' ),
			'color' => 'red',
		),
		'description' => '<p>' . esc_html( gtm4wp_incomplete_package_message() ) . '</p><p>' . esc_html(
			sprintf(
				/* translators: %s: comma separated list of PHP class names. */
				__( 'Missing: %s', 'duracelltomi-google-tag-manager' ),
				implode( ', ', gtm4wp_missing_classes() )
			)
		) . '</p>',
		'actions'     => '',
		'test'        => 'gtm4wp_incomplete_package',
	);
}

/**
 * WordPress hook function run after plugins have been loaded.
 * Boots the plugin core which routes between admin and frontend code paths.
 *
 * @see https://developer.wordpress.org/reference/hooks/plugins_loaded/
 *
 * @return void
 */
function gtm4wp_bootstrap() {
	if ( ! gtm4wp_package_is_complete() ) {
		add_action( 'admin_notices', 'gtm4wp_incomplete_package_notice' );
		add_action( 'network_admin_notices', 'gtm4wp_incomplete_package_notice' );
		add_filter( 'site_status_tests', 'gtm4wp_add_incomplete_package_test' );
		return;
	}

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
