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
 * Value helper for the GTM4WP_OPTION_GDM_DESTINATIONS option: an array of
 * rows where every row names a place the Data Manager API integration sends
 * events to - a Google Analytics 4 property and web data stream - together
 * with the stored service account (GoogleAuth) that authenticates the send.
 *
 * The destination list is user configuration verified empirically: the API
 * has no "list my destinations" call, and what a service account can reach is
 * a function of key, API and role that only an actual (validateOnly) request
 * can answer. This module owns its destinations, their validation probe and
 * their health records; GoogleAuth owns identity only.
 *
 * Loaded on frontend requests by the Options service, therefore this class
 * must not contain translated strings or other admin-only code.
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
	 * The D modifier on all three patterns anchors to the true end of the
	 * subject: without it PCRE lets `$` match before one trailing newline, so
	 * "value\n" passes a pattern that reads as though it could not (the
	 * ContainerRows lesson).
	 *
	 * The account pattern is the vault's own id format (ours to define, so
	 * pinning it is not the UC-5 mistake). The property id is numeric per the
	 * confirmed Destination shape (accountId = the GA4 property id); the
	 * measurement id grammar (G- plus uppercase alphanumerics) is Google's -
	 * both registered as U124 in .upstream/upstream-review-checklist.md, with
	 * generous length caps so a longer future id is not refused as user error.
	 */
	public const ACCOUNT_PATTERN     = '/^' . KeyVault::ID_PATTERN . '$/D';
	public const PROPERTY_PATTERN    = '/^[0-9]{1,20}$/D';
	public const MEASUREMENT_PATTERN = '/^G-[A-Z0-9]{1,30}$/D';

	/**
	 * Returns one row with every column present as a trimmed string. The
	 * measurement id is uppercased: the grammar is uppercase and the value is
	 * pasted from the GA admin either way, so a stray lowercase paste is
	 * repaired rather than refused.
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
	 * Whether a normalized row is a complete, well-formed destination.
	 *
	 * The single predicate shared by the save-time sanitizer (which turns each
	 * failing aspect into its own WP_Error), the runtime accessor below and
	 * the test route - one rule, never copies of it (PA-2). The label is not
	 * part of validity: it is display only and may be empty.
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
	 * every surviving row re-validated.
	 *
	 * Consumers (the send paths, the capture script's measurement ids) read
	 * destinations only through this method, so a third party sees one
	 * extension point and an invalid filtered row is dropped here instead of
	 * reaching a request body.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<int, array<string, string>>
	 */
	public static function rows( Options $options ): array {
		$stored = $options->get( GTM4WP_OPTION_GDM_DESTINATIONS );

		/**
		 * Filters the runtime list of Google Data Manager destinations.
		 *
		 * Receives the validated rows of the gdm-destinations option; each row
		 * is an array with the DestinationRows column keys. Rows added or
		 * changed here are re-validated before use - an invalid row is
		 * dropped, never sent.
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
	 * Whether any stored destination row references a service account.
	 *
	 * Backs the GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE veto: deleting a
	 * key that a destination still points at would leave that destination
	 * failing quietly, so the settings screen refuses it with an explanation
	 * instead. Deliberately reads the STORED rows, not the filtered runtime
	 * list - the veto protects stored configuration; a destination a third
	 * party adds at runtime is that party's to keep consistent.
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
