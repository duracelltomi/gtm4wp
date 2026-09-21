<?php
/**
 * Data Manager destination row list helper.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Google\KeyVault;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Value helper for the GTM4WP_OPTION_GDM_DESTINATIONS option: rows naming a
 * GA4 property and web data stream plus the GoogleAuth service account that
 * authenticates the send. User configuration verified empirically: the API
 * has no "list my destinations" call, only a validateOnly request can answer.
 * Loaded on frontend requests: no translated strings here.
 */
final class DestinationRows {

	public const COLUMN_LABEL       = 'label';
	public const COLUMN_ACCOUNT     = 'service_account';
	public const COLUMN_TYPE        = 'type';
	public const COLUMN_PROPERTY    = 'property_id';
	public const COLUMN_MEASUREMENT = 'measurement_id';

	/**
	 * Destination types. Only Google Analytics 4 exists today; the column is a
	 * select from day one so a later type (Google Ads) adds a choice instead of
	 * re-shaping stored rows.
	 */
	public const TYPE_GA4 = 'ga4';

	/**
	 * Every known destination type.
	 *
	 * @var string[]
	 */
	public const TYPES = array( self::TYPE_GA4 );

	/**
	 * Longest destination label kept. Matches the service-account label cap:
	 * long enough to describe a property, short enough that a label is never a
	 * place to stash data.
	 */
	public const LABEL_MAX_LENGTH = 100;

	/**
	 * The D modifier anchors to the true end of the subject (the ContainerRows
	 * lesson). The account pattern is the vault's own id format (ours to pin);
	 * the property and measurement id grammars are Google's (U124), with
	 * generous length caps (UC-5). The *_BODY constants are the delimiter-free
	 * middles the admin schema hands to the client as the column `pattern`, so
	 * the table's inline validation and this class share one definition (PA-2).
	 */
	public const PROPERTY_PATTERN_BODY    = '[0-9]{1,20}';
	public const MEASUREMENT_PATTERN_BODY = 'G-[A-Z0-9]{1,30}';

	public const ACCOUNT_PATTERN     = '/^' . KeyVault::ID_PATTERN . '$/D';
	public const PROPERTY_PATTERN    = '/^' . self::PROPERTY_PATTERN_BODY . '$/D';
	public const MEASUREMENT_PATTERN = '/^' . self::MEASUREMENT_PATTERN_BODY . '$/D';

	/**
	 * Returns one row with every column present as a trimmed string; the
	 * measurement id is uppercased so a lowercase paste is repaired, not refused.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, string>
	 */
	public static function normalize_row( array $row ): array {
		$normalized = array();

		foreach ( array( self::COLUMN_LABEL, self::COLUMN_ACCOUNT, self::COLUMN_TYPE, self::COLUMN_PROPERTY, self::COLUMN_MEASUREMENT ) as $column ) {
			$value = $row[ $column ] ?? '';

			$normalized[ $column ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		$normalized[ self::COLUMN_MEASUREMENT ] = strtoupper( $normalized[ self::COLUMN_MEASUREMENT ] );

		return $normalized;
	}

	/**
	 * Whether a normalized row is a complete, well-formed destination: the
	 * single predicate shared by the sanitizer, rows() and the test route
	 * (PA-2). The label is display only and may be empty.
	 *
	 * @param array<string, string> $row Normalized row.
	 * @return bool
	 */
	public static function is_valid_row( array $row ): bool {
		return in_array( $row[ self::COLUMN_TYPE ] ?? '', self::TYPES, true )
			&& ( 1 === preg_match( self::ACCOUNT_PATTERN, $row[ self::COLUMN_ACCOUNT ] ?? '' ) )
			&& ( 1 === preg_match( self::PROPERTY_PATTERN, $row[ self::COLUMN_PROPERTY ] ?? '' ) )
			&& ( 1 === preg_match( self::MEASUREMENT_PATTERN, $row[ self::COLUMN_MEASUREMENT ] ?? '' ) );
	}

	/**
	 * The runtime destination list: the stored rows through the public filter,
	 * every surviving row re-validated so an invalid filtered row never
	 * reaches a request body. Every consumer reads through here except the
	 * delete veto and the health notice, which read the STORED rows on purpose.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<int, array<string, string>>
	 */
	public static function rows( Options $options ): array {
		$stored = $options->get( GTM4WP_OPTION_GDM_DESTINATIONS );

		/**
		 * Filters the runtime list of Google Data Manager destinations (arrays
		 * with the DestinationRows column keys). Rows added or changed here are
		 * re-validated; an invalid row is dropped, never sent.
		 *
		 * @since 2.1.0
		 *
		 * @param array $rows The stored destination rows.
		 */
		$filtered = apply_filters( GTM4WP_WPFILTER_GDM_DESTINATIONS, is_array( $stored ) ? $stored : array() );

		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$rows = array();

		foreach ( $filtered as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row = self::normalize_row( $row );

			if ( self::is_valid_row( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Whether any stored destination row references a service account (the
	 * GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE veto). Reads the STORED
	 * rows on purpose: a runtime-injected destination is its author's to keep
	 * consistent.
	 *
	 * @param Options $options    The plugin options service.
	 * @param string  $account_id Service-account id about to be deleted.
	 * @return bool
	 */
	public static function references_account( Options $options, string $account_id ): bool {
		$stored = $options->get( GTM4WP_OPTION_GDM_DESTINATIONS );

		if ( ! is_array( $stored ) || ( '' === $account_id ) ) {
			return false;
		}

		foreach ( $stored as $row ) {
			if ( is_array( $row ) && ( ( $row[ self::COLUMN_ACCOUNT ] ?? '' ) === $account_id ) ) {
				return true;
			}
		}

		return false;
	}
}
