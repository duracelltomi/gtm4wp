<?php
/**
 * Container row list helper.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Value helper for the GTM4WP_OPTION_GTM_CONTAINERS option: an array of rows
 * where every Google Tag Manager container has its own environment
 * parameters, custom domain, custom path and an optional flag to omit the
 * container ID from the loader URL (server side GTM setups).
 *
 * Introduced in 2.0 as the replacement of the flat 1.x options (a single
 * comma separated gtm-code plus one shared environment/domain/path). The
 * flat keys stay part of the public API as read-only mirrors, see
 * legacy_values().
 *
 * Loaded on frontend requests by the Options service, therefore this class
 * must not contain translated strings or other admin-only code.
 */
final class ContainerRows {

	public const COLUMN_ID      = 'id';
	public const COLUMN_AUTH    = 'gtm_auth';
	public const COLUMN_PREVIEW = 'gtm_preview';
	public const COLUMN_DOMAIN  = 'domain';
	public const COLUMN_PATH    = 'path';
	public const COLUMN_NO_ID   = 'no_id';

	/**
	 * The D modifier on every pattern is load-bearing: without it PCRE lets `$`
	 * match before a trailing newline, and the GTM4WP_HARDCODED_* constants are
	 * validated untrimmed - an accepted "value\n" reached the loader snippet raw
	 * and made the whole <script> block a SyntaxError. Rejecting is the
	 * contract: the admin notice names the constant.
	 */
	public const GTM_ID_PATTERN  = '/^GTM-[A-Z0-9]+$/D';
	public const AUTH_PATTERN    = '/^[a-zA-Z0-9\-_]+$/D';
	public const PREVIEW_PATTERN = '/^env-[0-9]+$/D';
	public const PATH_PATTERN    = '/^[a-zA-Z0-9\.\-\_\/]*$/D';

	/**
	 * A valid JavaScript identifier (ASCII subset). Not cosmetic: the data layer
	 * name and the GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY names are emitted UNQUOTED
	 * into a <script> body, where no escaping is possible, so this pattern IS
	 * the control and must encode the grammar the sink parses (a `-` is the
	 * subtraction operator). Narrower than ECMAScript on purpose, keeping the
	 * snippet byte-identical to 1.x. The D modifier anchors to the true end of
	 * the subject (see above).
	 */
	public const JS_IDENTIFIER_PATTERN = '/^[A-Za-z_$][A-Za-z0-9_$]*$/D';

	/**
	 * Whether a string is usable as a bare JavaScript identifier: the single
	 * predicate shared by the save side, the read side (DataLayer::name(),
	 * Compat\Globals) and the admin notice (PA-2).
	 *
	 * @param string $value The candidate identifier.
	 * @return bool
	 */
	public static function is_valid_js_identifier( string $value ): bool {
		return 1 === preg_match( self::JS_IDENTIFIER_PATTERN, $value );
	}

	/**
	 * Resolves the data layer variable name from its stored value, falling back
	 * to `dataLayer`. Re-validated at the reader (PA-2) because a 1.x install
	 * stored names this rule rejects; Admin\Notices tells the admin when the
	 * configured name was ignored.
	 *
	 * @param mixed $stored The stored option value.
	 * @return string A name that is safe to emit unquoted.
	 */
	public static function datalayer_name( $stored ): string {
		// Also what keeps WP CLI working (Patrick Holberg Hesselberg, 1.x).
		if ( ! is_string( $stored ) ) {
			return 'dataLayer';
		}

		$stored = trim( $stored );

		return self::is_valid_js_identifier( $stored ) ? $stored : 'dataLayer';
	}

	/**
	 * Returns one row with every column present as a trimmed string.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, string>
	 */
	public static function normalize_row( array $row ): array {
		$normalized = array();

		foreach ( array( self::COLUMN_ID, self::COLUMN_AUTH, self::COLUMN_PREVIEW, self::COLUMN_DOMAIN, self::COLUMN_PATH, self::COLUMN_NO_ID ) as $column ) {
			$value = $row[ $column ] ?? '';

			$normalized[ $column ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		return $normalized;
	}

	/**
	 * Normalizes a raw stored/submitted value into a clean list of rows.
	 * Non-array entries and rows without a container ID are dropped.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array<int, array<string, string>>
	 */
	public static function normalize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row = self::normalize_row( $row );

			if ( '' === $row[ self::COLUMN_ID ] ) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Builds container rows from the flat 1.x options: every ID of the comma
	 * separated gtm-code inherits the shared environment, domain and path. 1.x
	 * loaded only the first container when an environment was set; that
	 * workaround is gone, every ID is kept.
	 *
	 * @param array<string, mixed> $options Flat option values (stored row or merged values).
	 * @return array<int, array<string, string>>
	 */
	public static function from_legacy( array $options ): array {
		$ids = array_values(
			array_filter(
				array_map(
					'trim',
					explode( ',', (string) ( $options[ GTM4WP_OPTION_GTM_CODE ] ?? '' ) )
				)
			)
		);

		$auth    = trim( (string) ( $options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ?? '' ) );
		$preview = trim( (string) ( $options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ?? '' ) );
		$domain  = trim( (string) ( $options[ GTM4WP_OPTION_GTMDOMAIN ] ?? '' ) );
		$path    = trim( (string) ( $options[ GTM4WP_OPTION_GTMCUSTOMPATH ] ?? '' ) );

		$rows = array();

		foreach ( $ids as $one_gtm_id ) {
			$rows[] = array(
				self::COLUMN_ID      => $one_gtm_id,
				self::COLUMN_AUTH    => $auth,
				self::COLUMN_PREVIEW => $preview,
				self::COLUMN_DOMAIN  => $domain,
				self::COLUMN_PATH    => $path,
			);
		}

		return $rows;
	}

	/**
	 * Derives the flat 1.x option values from the container rows. These
	 * mirrors keep $GLOBALS['gtm4wp_options'] readers and a downgrade to
	 * 1.x working: gtm-code lists every container ID, the shared
	 * environment/domain/path values come from the first row.
	 *
	 * @param array<int, array<string, string>> $rows Normalized container rows.
	 * @return array<string, string>
	 */
	public static function legacy_values( array $rows ): array {
		$first = $rows[0] ?? array();

		return array(
			GTM4WP_OPTION_GTM_CODE        => implode( ',', array_column( $rows, self::COLUMN_ID ) ),
			GTM4WP_OPTION_ENV_GTM_AUTH    => (string) ( $first[ self::COLUMN_AUTH ] ?? '' ),
			GTM4WP_OPTION_ENV_GTM_PREVIEW => (string) ( $first[ self::COLUMN_PREVIEW ] ?? '' ),
			GTM4WP_OPTION_GTMDOMAIN       => (string) ( $first[ self::COLUMN_DOMAIN ] ?? '' ),
			GTM4WP_OPTION_GTMCUSTOMPATH   => (string) ( $first[ self::COLUMN_PATH ] ?? '' ),
		);
	}

	/**
	 * Builds the row list for a GTM4WP_HARDCODED_GTM_ID wp-config override.
	 * Every hard coded ID keeps the settings of the stored row with the
	 * same ID; unknown IDs inherit the settings of the first stored row
	 * (the shared values of the flat 1.x options after migration).
	 *
	 * @param string[]                          $ids  Validated hard coded container IDs.
	 * @param array<int, array<string, string>> $rows Normalized stored rows.
	 * @return array<int, array<string, string>>
	 */
	public static function for_hardcoded_ids( array $ids, array $rows ): array {
		$rows_by_id = array_column( $rows, null, self::COLUMN_ID );

		$template = $rows[0] ?? array();
		unset( $template[ self::COLUMN_ID ] );

		$result = array();

		foreach ( $ids as $one_gtm_id ) {
			if ( isset( $rows_by_id[ $one_gtm_id ] ) ) {
				$result[] = $rows_by_id[ $one_gtm_id ];
				continue;
			}

			$result[] = self::normalize_row( array_merge( $template, array( self::COLUMN_ID => $one_gtm_id ) ) );
		}

		return $result;
	}
}
