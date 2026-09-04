<?php
/**
 * Loaded when GTM4WP is uninstalled.
 * Deletes plugin options and per-user notice states from the database.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

// If uninstall.php is not called by WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

delete_option( 'gtm4wp-options' );
delete_option( 'gtm4wp-plugin-version' );

// Uploaded Google service-account keys (encrypted, non-autoloaded row). The
// access tokens minted from them live in transients that expire within the
// hour on their own.
delete_option( 'gtm4wp_google_service_accounts' );

// Per-destination health records of the Data Manager integration (the
// destination rows themselves live in gtm4wp-options and go with it).
delete_option( 'gtm4wp_gdm_destination_health' );

// Capture-rate counters of the attribution capture. The attribution meta on
// the orders themselves is deliberately left behind: sweeping every order of a
// store is expensive, the existing purchase-tracking flag sets the same
// precedent, and the WordPress personal-data eraser is the per-person removal
// path for it.
delete_option( 'gtm4wp_gdm_capture_stats' );

// Per-user dismissed notice states.
delete_metadata( 'user', 0, 'gtm4wp_user_notices_dismisses_json', '', true );
