<?php
/**
 * Unit tests for RequestOrigin's host comparison.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Functions;
use GTM4WP\RequestOrigin;

/**
 * RequestOrigin::url_matches_site(): the guest-beacon same-origin gate.
 */
final class RequestOriginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_url' )->alias( static fn ( $url ) => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for core's wrapper.
	}

	/**
	 * Parsed home_url() parts.
	 *
	 * @param string $home The home URL.
	 * @return array<string, mixed>
	 */
	private static function site( string $home ): array {
		return parse_url( $home ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test fixture.
	}

	/**
	 * #656: browsers send an internationalised host in punycode, while WordPress
	 * keeps a home URL typed in Unicode as it is.
	 */
	public function test_a_unicode_home_host_matches_its_punycode_origin(): void {
		$site = self::site( 'https://bücher.example' );

		$this->assertTrue( RequestOrigin::url_matches_site( 'https://xn--bcher-kva.example', $site ) );
		$this->assertTrue( RequestOrigin::url_matches_site( 'https://xn--bcher-kva.example/checkout/', self::site( 'https://BÜCHER.example' ) ), 'Case is folded before encoding.' );
		$this->assertFalse( RequestOrigin::url_matches_site( 'https://xn--bcher-kva.example.evil', $site ) );
		$this->assertFalse( RequestOrigin::url_matches_site( 'https://bucher.example', $site ) );
	}

	public function test_ascii_hosts_compare_case_insensitively_and_refuse_subdomains(): void {
		$site = self::site( 'https://Shop.Example' );

		$this->assertTrue( RequestOrigin::url_matches_site( 'https://shop.example', $site ) );
		$this->assertFalse( RequestOrigin::url_matches_site( 'https://www.shop.example', $site ) );
		$this->assertFalse( RequestOrigin::url_matches_site( 'https://shop.example:8443', $site ) );
	}
}
