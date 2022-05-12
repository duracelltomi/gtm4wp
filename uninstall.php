<?php
/**
 * Loaded when GTM4WP was uninstalled.
 * Deletes plugin options from database.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

// if uninstall.php is not called by WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

require_once dirname( __FILE__ ) . '/common/readoptions.php';

delete_option( GTM4WP_OPTIONS );
