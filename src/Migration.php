<?php
/**
 * Version-stamped one-time migrations.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans up stored options of features removed in 2.0. Runs at most once
 * per plugin version, only in the admin, and never touches options of
 * features that still exist - a downgrade to 1.x therefore stays possible
 * except for the removed features themselves.
 */
final class Migration {

	private const VERSION_OPTION = 'gtm4wp-plugin-version';

	/**
	 * Option keys of features removed in 2.0:
	 * weather + geo data, WP e-Commerce integration and the unused
	 * blacklist-sandboxed flag.
	 *
	 * @var string[]
	 */
	private const REMOVED_OPTION_KEYS = array(
		GTM4WP_OPTION_INCLUDE_MISCGEO,
		GTM4WP_OPTION_INCLUDE_MISCGEOAPI,
		GTM4WP_OPTION_INCLUDE_WEATHER,
		GTM4WP_OPTION_INCLUDE_WEATHERUNITS,
		GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI,
		GTM4WP_OPTION_INTEGRATE_WPECOMMERCE,
		GTM4WP_OPTION_BLACKLIST_SANDBOXED,
	);

	/**
	 * Blacklist entity ids that are no longer documented by Google and were
	 * removed from the entity table in 2.0.
	 *
	 * @var string[]
	 */
	private const REMOVED_BLACKLIST_ENTITIES = array( 'ua', 'mf' );

	/**
	 * Runs the pending migrations once per plugin version.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( GTM4WP_VERSION === get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		self::cleanup_removed_options();

		update_option( self::VERSION_OPTION, GTM4WP_VERSION, false );
	}

	/**
	 * Removes option keys of dropped features and stale blacklist entries
	 * from the stored option row.
	 *
	 * @return void
	 */
	private static function cleanup_removed_options(): void {
		$stored = get_option( GTM4WP_OPTIONS, array() );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return;
		}

		$updated = $stored;

		foreach ( self::REMOVED_OPTION_KEYS as $removed_key ) {
			unset( $updated[ $removed_key ] );
		}

		if ( isset( $updated[ GTM4WP_OPTION_BLACKLIST_STATUS ] ) && is_string( $updated[ GTM4WP_OPTION_BLACKLIST_STATUS ] ) && '' !== $updated[ GTM4WP_OPTION_BLACKLIST_STATUS ] ) {
			$entities = explode( ',', $updated[ GTM4WP_OPTION_BLACKLIST_STATUS ] );
			$entities = array_values(
				array_filter(
					$entities,
					static fn ( $entity ) => ! in_array( $entity, self::REMOVED_BLACKLIST_ENTITIES, true )
				)
			);

			$updated[ GTM4WP_OPTION_BLACKLIST_STATUS ] = implode( ',', $entities );
		}

		if ( $updated !== $stored ) {
			update_option( GTM4WP_OPTIONS, $updated );
		}
	}
}
