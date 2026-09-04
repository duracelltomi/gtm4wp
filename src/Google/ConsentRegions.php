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
 * The countries whose visitors Google's EU user consent policy covers: the
 * European Economic Area (the EU member states plus Iceland, Liechtenstein and
 * Norway), the United Kingdom and Switzerland.
 *
 * This is a copy of a list somebody else maintains, so it is a snapshot that
 * looks correct long after the source has moved - which is why it has exactly
 * one definition here (WooCommerce carries an EU helper and Easy Digital
 * Downloads carries none, so owning the list keeps both platforms on the same
 * answer), a registry row naming the policy page, and a test pinning the exact
 * set rather than a spot check.
 *
 * Switzerland is deliberately part of the set: the policy was extended to it
 * on 2024-07-31, and the point of the list is to match what Google enforces,
 * not the narrower "EEA" reading of the name.
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
	 *
	 * An empty or unknown country answers false: this decides whether consent
	 * signals are *required*, and an order with no country recorded is not
	 * evidence of an EEA buyer. The "always" consent policy is how a site owner
	 * asks for the gate regardless of country.
	 *
	 * @param string $country Two-letter country code, any case.
	 * @return bool
	 */
	public static function covers( string $country ): bool {
		return in_array( strtoupper( trim( $country ) ), self::COUNTRIES, true );
	}
}
