<?php
/**
 * The settings capability.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the capability that guards the plugin's settings is resolved.
 *
 * Every gate that decides whether a user may see or change GTM4WP settings -
 * the admin code path, the settings page, the settings and Google REST routes,
 * the notice dismissal handler and the abilities - asks this class, so the
 * filter has a single definition and a site that delegates the settings to a
 * non-admin role (issue #143) is honoured by all of them at once. Before this
 * class each site spelled the filter call out itself, and a sixth copy was one
 * more place to forget.
 */
final class Capability {

	/**
	 * The capability required when the filter is not used.
	 */
	public const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Name of the filter that changes the required capability.
	 */
	public const FILTER = 'gtm4wp_admin_page_capability';

	/**
	 * The capability needed to see and manage the GTM4WP settings.
	 *
	 * @return string
	 */
	public static function settings(): string {
		/**
		 * Filters the capability needed to see and manage the GTM4WP settings page.
		 *
		 * The same capability gates the settings REST routes, the Google
		 * service-account and Data Manager routes, the notice dismissal handler
		 * and every ability the plugin registers.
		 *
		 * @since 1.20
		 *
		 * @param string $capability The required capability. Default 'manage_options'.
		 */
		return (string) apply_filters( self::FILTER, self::DEFAULT_CAPABILITY );
	}

	/**
	 * Whether the current user holds the settings capability.
	 *
	 * Takes no parameter on purpose: it doubles as an ability permission
	 * callback, which is handed the ability's input, and PHP lets a userland
	 * function ignore arguments it does not declare. The input plays no part in
	 * the decision - every ability is gated on the capability alone.
	 *
	 * @return bool
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( self::settings() );
	}
}
