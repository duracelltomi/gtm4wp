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
 * Single authority on the GTM4WP_HARDCODED_* wp-config.php constants: validates
 * them, applies them onto the container rows (apply(), every request) and
 * reports which parts of the table they lock (locks(), admin). Both answers
 * come from the same resolve() pass so the settings screen can never disagree
 * with what the frontend loads. Loaded on frontend requests: no translated
 * strings here.
 */
final class HardcodedContainers {

	public const CONSTANT_GTM_ID  = 'GTM4WP_HARDCODED_GTM_ID';
	public const CONSTANT_AUTH    = 'GTM4WP_HARDCODED_GTM_ENV_AUTH';
	public const CONSTANT_PREVIEW = 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW';

	/**
	 * Reads and validates the three constants. A malformed one is rejected and
	 * its name collected for the admin notices; a rejected constant overrides
	 * nothing and locks nothing.
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
	 * The value of a constant as a string, or null for a non-scalar (an array
	 * constant is legal PHP): null keeps it away from explode()/preg_match() and
	 * is never mistaken for the deliberate empty "clear the environment" value.
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
	 * Reports which parts of the container table wp-config.php takes over:
	 * 'columns' (column key => constant) and 'rows' (constants deciding WHICH
	 * containers load; non-empty means the whole table is read-only). Only
	 * constants that survived validation are listed.
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
