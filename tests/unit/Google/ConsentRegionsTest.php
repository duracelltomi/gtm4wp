<?php
/**
 * Unit tests for the EU user consent policy region set.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use GTM4WP\Google\ConsentRegions;
use GTM4WP\Tests\unit\TestCase;

/**
 * A copied list is a snapshot that looks correct forever while the source
 * moves, so the assertions here pin the exact set and its size rather than
 * spot-checking a few members: adding or dropping a country has to be a
 * deliberate edit of this test, which is the moment to re-read the policy page
 * named in the upstream registry row.
 */
final class ConsentRegionsTest extends TestCase {

	/**
	 * The EU member states, written out independently of the source list so
	 * that a country dropped from ConsentRegions fails here.
	 */
	private const EU_27 = array(
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'ES',
		'FI',
		'FR',
		'GR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	);

	public function test_the_set_is_the_eu_27_plus_the_three_eea_states_the_uk_and_switzerland(): void {
		$expected = array_merge( self::EU_27, array( 'IS', 'LI', 'NO', 'GB', 'CH' ) );

		sort( $expected );
		$actual = ConsentRegions::COUNTRIES;
		sort( $actual );

		$this->assertSame( $expected, $actual );
		$this->assertCount( 32, ConsentRegions::COUNTRIES );
	}

	/**
	 * Switzerland is the member most easily lost to the "EEA" reading of the
	 * name, and the UK the one lost to "EU" - both are in Google's policy
	 * scope, so both get their own assertion.
	 */
	public function test_the_uk_and_switzerland_are_covered(): void {
		$this->assertTrue( ConsentRegions::covers( 'GB' ) );
		$this->assertTrue( ConsentRegions::covers( 'CH' ) );
	}

	public function test_the_eea_states_outside_the_eu_are_covered(): void {
		$this->assertTrue( ConsentRegions::covers( 'IS' ) );
		$this->assertTrue( ConsentRegions::covers( 'LI' ) );
		$this->assertTrue( ConsentRegions::covers( 'NO' ) );
	}

	public function test_countries_outside_the_policy_are_not_covered(): void {
		$this->assertFalse( ConsentRegions::covers( 'US' ) );
		$this->assertFalse( ConsentRegions::covers( 'TR' ) );
		$this->assertFalse( ConsentRegions::covers( 'CA' ) );
		$this->assertFalse( ConsentRegions::covers( 'AU' ) );
		$this->assertFalse( ConsentRegions::covers( 'RS' ) );
	}

	/**
	 * Order billing countries arrive in whatever case the platform stored, and
	 * with the odd stray space; matching must not depend on that.
	 */
	public function test_the_match_ignores_case_and_surrounding_space(): void {
		$this->assertTrue( ConsentRegions::covers( 'de' ) );
		$this->assertTrue( ConsentRegions::covers( ' De ' ) );
		$this->assertTrue( ConsentRegions::covers( "\tCH\n" ) );
	}

	/**
	 * No country recorded is not evidence of an EEA buyer: this decides
	 * whether consent is *required*, so the empty answer has to be false. The
	 * "always" policy is how a site owner asks for the gate regardless.
	 */
	public function test_an_empty_or_nonsense_country_is_not_covered(): void {
		$this->assertFalse( ConsentRegions::covers( '' ) );
		$this->assertFalse( ConsentRegions::covers( '   ' ) );
		$this->assertFalse( ConsentRegions::covers( 'DEU' ) );
		$this->assertFalse( ConsentRegions::covers( 'D' ) );
		$this->assertFalse( ConsentRegions::covers( '<script>' ) );
	}
}
