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

// Per-user dismissed notice states.
delete_metadata( 'user', 0, 'gtm4wp_user_notices_dismisses_json', '', true );
