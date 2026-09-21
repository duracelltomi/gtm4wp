<?php
/**
 * The region set of Google's EU user consent policy.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Google;

defined( 'ABSPATH' ) || exit;

/**
 * The countries Google's EU user consent policy covers: the EEA, the United
 * Kingdom and Switzerland (extended to it 2024-07-31; the list matches what
 * Google enforces, not the "EEA" name). A mirror of somebody else's list
 * (UD-1): one definition here for both platforms, a registry row naming the
 * policy page, and a test pinning the exact set.
 */
final class ConsentRegions {

	/**
	 * ISO 3166-1 alpha-2 codes of the covered countries, uppercase.
	 *
	 * EU (27): AT BE BG CY CZ DE DK EE ES FI FR GR HR HU IE IT LT LU LV MT NL
	 * PL PT RO SE SI SK. EEA additions (3): IS LI NO. Plus GB and CH.
	 */
	public const COUNTRIES = array(
		'AT',
		'BE',
		'BG',
		'CH',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'ES',
		'FI',
		'FR',
		'GB',
		'GR',
		'HR',
		'HU',
		'IE',
		'IS',
		'IT',
		'LI',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'NO',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	);

	/**
	 * Whether a billing country falls under Google's EU user consent policy.
	 * Empty or unknown answers false: no country recorded is not evidence of
	 * an EEA buyer; the "always" policy gates regardless of country.
	 *
	 * @param string $country Two-letter country code, any case.
	 * @return bool
	 */
	public static function covers( string $country ): bool {
		return in_array( strtoupper( trim( $country ) ), self::COUNTRIES, true );
	}
}
