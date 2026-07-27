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

use GTM4WP\Modules\Container\ContainerRows;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans up stored options of features removed in 2.0 and seeds the
 * per-container row option from the flat 1.x container options. Runs at
 * most once per plugin version, only in the admin, and never touches
 * options of features that still exist - a downgrade to 1.x therefore
 * stays possible except for the removed features themselves.
 */
final class Migration {

	private const VERSION_OPTION = 'gtm4wp-plugin-version';

	/**
	 * Option keys of features removed in 2.0:
	 * weather + geo data, WP e-Commerce integration, scroll tracking and the
	 * non-functional 1.x blacklist-sandboxed flag (2.0 restricts sandboxed
	 * template scripts through the `sandboxedScripts` group class in the
	 * blacklist-status list instead, so the standalone flag is not migrated -
	 * a fresh opt-in avoids silently blocking custom templates on upgrade).
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
		GTM4WP_OPTION_SCROLLER_ENABLED,
		GTM4WP_OPTION_SCROLLER_DEBUGMODE,
		GTM4WP_OPTION_SCROLLER_CALLBACKTIME,
		GTM4WP_OPTION_SCROLLER_DISTANCE,
		GTM4WP_OPTION_SCROLLER_CONTENTID,
		GTM4WP_OPTION_SCROLLER_READERTIME,
	);

	/**
	 * Blacklist entity ids that are no longer documented by Google and were
	 * removed from the entity table in 2.0.
	 *
	 * @var string[]
	 */
	private const REMOVED_BLACKLIST_ENTITIES = array( 'ua', 'mf' );

	/**
	 * Runs the pending migrations.
	 *
	 * The container row seeding runs on every admin request (it is
	 * idempotent, self-guarded and served from the options cache) so that
	 * it cannot be skipped when two builds share the same version string -
	 * e.g. upgrading between 2.0.0-dev snapshots. The remaining cleanup
	 * steps run once per plugin version.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		self::seed_container_rows();
		self::seed_post_meta_option();

		if ( GTM4WP_VERSION === get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		self::cleanup_removed_options();

		update_option( self::VERSION_OPTION, GTM4WP_VERSION, false );
	}

	/**
	 * Seeds the post-meta option (new in 2.0) from the legacy "Post Terms"
	 * option it was split out of.
	 *
	 * Until 2.0 a single option emitted BOTH the taxonomy terms and every
	 * non-underscore-prefixed post meta value, while its description named only
	 * the taxonomies. Splitting them gives the admin an informed choice, but the
	 * split must not silently drop data an existing site is already sending to
	 * Google Tag Manager - so a site that had the combined option ON gets the new
	 * meta option turned ON as well, preserving the exact data layer it had. A
	 * site that had it OFF gets the new option OFF.
	 *
	 * Runs on every admin request rather than once per version (like
	 * seed_container_rows()) because it is self-guarded and cheap: it acts only
	 * while the new key is absent from the stored row, so it can neither run
	 * twice nor override the admin's own later choice. Sites that never saved
	 * settings at all have nothing to migrate and fall through to the defaults.
	 *
	 * @return void
	 */
	private static function seed_post_meta_option(): void {
		$stored = get_option( GTM4WP_OPTIONS, array() );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return;
		}

		// Already migrated (or explicitly saved by the admin): never touch it again.
		if ( array_key_exists( GTM4WP_OPTION_INCLUDE_POSTMETA, $stored ) ) {
			return;
		}

		// No legacy value to migrate from - leave the key absent so the module
		// default (off) applies, exactly as for a fresh install.
		if ( ! array_key_exists( GTM4WP_OPTION_INCLUDE_POSTTERMLIST, $stored ) ) {
			return;
		}

		$stored[ GTM4WP_OPTION_INCLUDE_POSTMETA ] = (bool) $stored[ GTM4WP_OPTION_INCLUDE_POSTTERMLIST ];

		update_option( GTM4WP_OPTIONS, $stored );
	}

	/**
	 * Builds the per-container row option (new in 2.0) from the flat 1.x
	 * container options: every container ID inherits the previously shared
	 * environment, domain and path values (see ContainerRows::from_legacy()
	 * for the exact rules).
	 *
	 * The flat 1.x keys stay untouched in the option row so a downgrade to
	 * 1.x keeps working; they are also kept in sync on every save of the
	 * container table. Runs only once: an existing row option is never
	 * overwritten.
	 *
	 * @return void
	 */
	private static function seed_container_rows(): void {
		$stored = get_option( GTM4WP_OPTIONS, array() );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return;
		}

		if ( array_key_exists( GTM4WP_OPTION_GTM_CONTAINERS, $stored ) ) {
			return;
		}

		$stored[ GTM4WP_OPTION_GTM_CONTAINERS ] = ContainerRows::from_legacy( $stored );

		update_option( GTM4WP_OPTIONS, $stored );
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
