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

// Capture-rate counters. The attribution meta on the orders is deliberately
// left behind (sweeping every order is expensive; the personal-data eraser is
// the per-person removal path).
delete_option( 'gtm4wp_gdm_capture_stats' );

// Diagnostics ring of the server-side send lanes.
delete_option( 'gtm4wp_gdm_send_log' );

// Queued sends and status checks. Hook names written out because this file
// runs without the autoloader; SendQueueTest pins them to SendQueue::HOOKS.
// Action Scheduler is asked by hook name ALONE: with a group,
// as_unschedule_all_actions() also matches arguments and cancels nothing for
// actions scheduled with arguments. Only reached while the commerce plugin
// bundling the library is still active.
foreach ( array( 'gtm4wp_gdm_send_refund', 'gtm4wp_gdm_poll_status' ) as $gtm4wp_queue_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $gtm4wp_queue_hook );
	}

	wp_unschedule_hook( $gtm4wp_queue_hook );
}
unset( $gtm4wp_queue_hook );

// Per-user dismissed notice states.
delete_metadata( 'user', 0, 'gtm4wp_user_notices_dismisses_json', '', true );
