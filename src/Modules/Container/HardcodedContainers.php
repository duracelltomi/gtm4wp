<?php
/**
 * Container overrides coming from wp-config.php.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Single authority on the GTM4WP_HARDCODED_* wp-config.php constants: it
 * validates them, applies them onto the container rows (apply(), used by the
 * Options service on every request) and reports which parts of the container
 * table they take over (locks(), used by the admin).
 *
 * Both answers come from the same resolve() pass on purpose. A constant that
 * overrides the container setup at output time but leaves the settings screen
 * editable is exactly the 1.x regression this class exists to prevent: the
 * admin could save a container ID that never loads, with nothing on screen
 * explaining why.
 *
 * Loaded on frontend requests through the Options service, therefore this class
 * must not contain translated strings or other admin-only code.
 */
final class HardcodedContainers {

	public const CONSTANT_GTM_ID  = 'GTM4WP_HARDCODED_GTM_ID';
	public const CONSTANT_AUTH    = 'GTM4WP_HARDCODED_GTM_ENV_AUTH';
	public const CONSTANT_PREVIEW = 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW';

	/**
	 * Reads and validates the three constants.
	 *
	 * A malformed constant is rejected (it would otherwise put an unvalidated
	 * value into every container's loader URL) and its name is collected so the
	 * admin notices can name it instead of leaving the operator to debug a
	 * silently discarded wp-config setting. A rejected constant overrides
	 * nothing, so it must not lock anything on the settings screen either.
	 *
	 * @return array{ids: string[]|null, auth: string|null, preview: string|null, errors: string[]}
	 */
	private static function resolve(): array {
		$ids     = null;
		$auth    = null;
		$preview = null;
		$errors  = array();

		if ( defined( self::CONSTANT_GTM_ID ) ) {
			$raw       = self::constant_string( self::CONSTANT_GTM_ID );
			$id_list   = explode( ',', (string) $raw );
			$has_error = null === $raw;

			foreach ( $id_list as $one_gtm_id ) {
				$has_error = $has_error || ! preg_match( ContainerRows::GTM_ID_PATTERN, $one_gtm_id );
			}

			if ( $has_error ) {
				$errors[] = self::CONSTANT_GTM_ID;
			} else {
				$ids = $id_list;
			}
		}

		// An empty string is a deliberate "clear the environment", not a typo, so
		// only non-empty values are pattern checked.
		if ( defined( self::CONSTANT_AUTH ) ) {
			$auth = self::constant_string( self::CONSTANT_AUTH );

			if ( ( null === $auth ) || ( ( '' !== $auth ) && ! preg_match( ContainerRows::AUTH_PATTERN, $auth ) ) ) {
				$errors[] = self::CONSTANT_AUTH;
				$auth     = null;
			}
		}

		if ( defined( self::CONSTANT_PREVIEW ) ) {
			$preview = self::constant_string( self::CONSTANT_PREVIEW );

			if ( ( null === $preview ) || ( ( '' !== $preview ) && ! preg_match( ContainerRows::PREVIEW_PATTERN, $preview ) ) ) {
				$errors[] = self::CONSTANT_PREVIEW;
				$preview  = null;
			}
		}

		return array(
			'ids'     => $ids,
			'auth'    => $auth,
			'preview' => $preview,
			'errors'  => $errors,
		);
	}

	/**
	 * Returns the value of a constant as a string, or null when it is not usable
	 * at all. An array constant is legal in PHP, so wp-config.php can hand a
	 * non-scalar to any of these constants: null keeps it away from
	 * explode()/preg_match() (which would fatal or warn on a frontend request)
	 * and, unlike an empty string, is never mistaken for the deliberate
	 * "clear the environment" value.
	 *
	 * @param string $name Constant name.
	 * @return string|null
	 */
	private static function constant_string( string $name ): ?string {
		$value = constant( $name );

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Whether a complete environment override is in effect. Both environment
	 * parameters together belong to exactly one container, so 1.x only loaded
	 * the first one - the row set is decided by wp-config.php in that case.
	 *
	 * @param array{ids: string[]|null, auth: string|null, preview: string|null, errors: string[]} $resolved Resolved constants.
	 * @return bool
	 */
	private static function overrides_row_set( array $resolved ): bool {
		return ( null !== $resolved['auth'] ) && ( '' !== $resolved['auth'] )
			&& ( null !== $resolved['preview'] ) && ( '' !== $resolved['preview'] );
	}

	/**
	 * Applies the valid constants onto the stored container rows and returns the
	 * rows that are actually loaded, together with the names of the constants
	 * that were rejected.
	 *
	 * @param array<int, array<string, string>> $rows Normalized stored container rows.
	 * @return array{0: array<int, array<string, string>>, 1: string[]} [ effective rows, rejected constant names ].
	 */
	public static function apply( array $rows ): array {
		$resolved = self::resolve();

		if ( null !== $resolved['ids'] ) {
			$rows = ContainerRows::for_hardcoded_ids( $resolved['ids'], $rows );
		}

		// The hard coded environment parameters are site wide overrides:
		// they replace the environment values of every row.
		if ( ( null !== $resolved['auth'] ) || ( null !== $resolved['preview'] ) ) {
			foreach ( $rows as &$one_row ) {
				if ( null !== $resolved['auth'] ) {
					$one_row[ ContainerRows::COLUMN_AUTH ] = $resolved['auth'];
				}
				if ( null !== $resolved['preview'] ) {
					$one_row[ ContainerRows::COLUMN_PREVIEW ] = $resolved['preview'];
				}
			}
			unset( $one_row );
		}

		if ( self::overrides_row_set( $resolved ) ) {
			$rows = array_slice( $rows, 0, 1 );
		}

		return array( $rows, $resolved['errors'] );
	}

	/**
	 * Reports which parts of the container table wp-config.php takes over, so
	 * the settings screen can render them read-only and the save route can keep
	 * the admin's own stored values out of harm's way:
	 *
	 * - 'columns': column key => name of the constant that fixes that column.
	 * - 'rows': names of the constants that decide WHICH containers are loaded.
	 *   Non-empty means the whole table is read-only - with the row set fixed
	 *   there is no row of the admin's own left for an edit to be saved into.
	 *
	 * Only constants that survived validation are listed: a rejected one changes
	 * nothing at output time, so the field must stay editable (the admin notice
	 * names it separately).
	 *
	 * @return array{columns: array<string, string>, rows: string[]}
	 */
	public static function locks(): array {
		$resolved = self::resolve();

		$columns = array();
		$rows    = array();

		if ( null !== $resolved['ids'] ) {
			$columns[ ContainerRows::COLUMN_ID ] = self::CONSTANT_GTM_ID;
			$rows[]                              = self::CONSTANT_GTM_ID;
		}

		if ( null !== $resolved['auth'] ) {
			$columns[ ContainerRows::COLUMN_AUTH ] = self::CONSTANT_AUTH;
		}

		if ( null !== $resolved['preview'] ) {
			$columns[ ContainerRows::COLUMN_PREVIEW ] = self::CONSTANT_PREVIEW;
		}

		if ( self::overrides_row_set( $resolved ) ) {
			$rows[] = self::CONSTANT_AUTH;
			$rows[] = self::CONSTANT_PREVIEW;
		}

		return array(
			'columns' => $columns,
			'rows'    => array_values( array_unique( $rows ) ),
		);
	}

	/**
	 * Whether any valid constant currently overrides the container setup.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return array() !== self::locks()['columns'];
	}
}
