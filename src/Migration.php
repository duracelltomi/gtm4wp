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
	 * Option keys of features removed in 2.0: weather + geo data, WP e-Commerce,
	 * scroll tracking and the non-functional 1.x blacklist-sandboxed flag (not
	 * migrated to the `sandboxedScripts` group on purpose: a fresh opt-in avoids
	 * silently blocking custom templates on upgrade).
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
	 * Blacklist entity ids Google no longer documents, removed from the entity
	 * table in 2.0. Only `ua` belongs here: `mf` (Mouseflow) is still documented
	 * and must NOT be stripped, or a site restricting it loses the setting.
	 *
	 * @var string[]
	 */
	private const REMOVED_BLACKLIST_ENTITIES = array( 'ua' );

	/**
	 * Runs the pending migrations. The seeding steps run on every admin request
	 * (idempotent, self-guarded, served from the options cache) so they cannot
	 * be skipped when two builds share a version string; the cleanup runs once
	 * per plugin version.
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
	 * option it was split out of, which emitted BOTH terms and post meta: a site
	 * with the combined option ON gets the new one ON too, so the split never
	 * silently drops data a site already sends. Acts only while the new key is
	 * absent, so it never overrides the admin's later choice.
	 *
	 * @return void
	 */
	private static function seed_post_meta_option(): void {
		$stored = get_option( GTM4WP_OPTIONS, array() );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return;
		}

		// Already migrated (or saved by the admin): never touch it again.
		if ( array_key_exists( GTM4WP_OPTION_INCLUDE_POSTMETA, $stored ) ) {
			return;
		}

		// No legacy value: leave the key absent so the module default applies.
		if ( ! array_key_exists( GTM4WP_OPTION_INCLUDE_POSTTERMLIST, $stored ) ) {
			return;
		}

		$stored[ GTM4WP_OPTION_INCLUDE_POSTMETA ] = (bool) $stored[ GTM4WP_OPTION_INCLUDE_POSTTERMLIST ];

		update_option( GTM4WP_OPTIONS, $stored );
	}

	/**
	 * Builds the per-container row option (new in 2.0) from the flat 1.x
	 * container options (ContainerRows::from_legacy()). The flat keys stay in the
	 * row so a downgrade keeps working; an existing row option is never overwritten.
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
