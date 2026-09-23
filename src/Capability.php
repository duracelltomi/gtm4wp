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
 * The one place the capability guarding the plugin's settings is resolved:
 * the admin path, the settings page, the settings and Google REST routes,
 * the notice dismissal handler and the abilities all ask this class, so a
 * site delegating the settings to a non-admin role (#143) is honoured by all
 * of them at once.
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
		$capability = apply_filters( self::FILTER, self::DEFAULT_CAPABILITY );

		// A wrong-typed or empty return used to deny everyone silently (#285):
		// say so under WP_DEBUG and keep the default. 'do_not_allow' is the
		// documented way to deny every user.
		if ( ! is_string( $capability ) || '' === $capability ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: 1: filter name, 2: the type returned. */
						__( 'The %1$s filter must return a capability name (a non-empty string); %2$s was returned, so the default capability is used. Return "do_not_allow" to deny every user.', 'duracelltomi-google-tag-manager' ),
						self::FILTER,
						gettype( $capability )
					)
				),
				'2.1'
			);

			return self::DEFAULT_CAPABILITY;
		}

		return $capability;
	}

	/**
	 * Whether the current user holds the settings capability. Takes no parameter
	 * on purpose: it doubles as an ability permission callback, which core hands
	 * the input, and the decision never depends on it.
	 *
	 * @return bool
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( self::settings() );
	}
}
