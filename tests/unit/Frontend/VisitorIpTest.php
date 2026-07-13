<?php
/**
 * Unit tests for the VisitorIp service.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\VisitorIp;
use GTM4WP\Tests\unit\TestCase;

/**
 * Ports the behavioral contract of gtm4wp_get_user_ip() from 1.x.
 *
 * VisitorIp is a security-sensitive reader of $_SERVER: it only trusts
 * REMOTE_ADDR and a single admin-configured custom header, rejects private and
 * reserved ranges (so a spoofed internal IP cannot be injected) and parses the
 * X-Forwarded-For list defensively.
 */
final class VisitorIpTest extends TestCase {

	/**
	 * Snapshot of $_SERVER restored after every test.
	 *
	 * @var array<string, mixed>
	 */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->server_backup = $_SERVER;

		// Start every test from a clean slate for the keys under test so the
		// PHPUnit runner's own $_SERVER values never leak into an assertion.
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_X_REAL_IP'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_CF_CONNECTING_IP']
		);
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;

		parent::tearDown();
	}

	public function test_returns_remote_addr_when_no_custom_header_configured(): void {
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';

		$this->assertSame( '8.8.8.8', VisitorIp::get() );
	}

	public function test_custom_header_takes_precedence_over_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']    = '8.8.8.8';
		$_SERVER['HTTP_X_REAL_IP'] = '8.8.4.4';

		$this->assertSame( '8.8.4.4', VisitorIp::get( 'X-Real-IP' ) );
	}

	public function test_header_name_is_translated_to_the_server_key(): void {
		// A dashed, mixed-case header name maps to HTTP_<UPPER_SNAKE_CASE>.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.1.1.1';

		$this->assertSame( '1.1.1.1', VisitorIp::get( 'CF-Connecting-IP' ) );
	}

	public function test_rejects_spoofed_private_ip_in_custom_header_and_falls_back(): void {
		// A private-range IP in the custom header is a spoof attempt: it is
		// rejected (FILTER_FLAG_NO_PRIV_RANGE) and REMOTE_ADDR wins.
		$_SERVER['REMOTE_ADDR']    = '8.8.8.8';
		$_SERVER['HTTP_X_REAL_IP'] = '192.168.1.50';

		$this->assertSame( '8.8.8.8', VisitorIp::get( 'X-Real-IP' ) );
	}

	public function test_rejects_reserved_ip_in_custom_header_and_falls_back(): void {
		// Loopback is a reserved range (FILTER_FLAG_NO_RES_RANGE).
		$_SERVER['REMOTE_ADDR']    = '8.8.8.8';
		$_SERVER['HTTP_X_REAL_IP'] = '127.0.0.1';

		$this->assertSame( '8.8.8.8', VisitorIp::get( 'X-Real-IP' ) );
	}

	public function test_empty_custom_header_value_falls_back_to_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']    = '8.8.8.8';
		$_SERVER['HTTP_X_REAL_IP'] = '';

		$this->assertSame( '8.8.8.8', VisitorIp::get( 'X-Real-IP' ) );
	}

	public function test_invalid_header_name_is_ignored_and_falls_back(): void {
		// A header name that sanitizes to nothing usable is dropped and the
		// function falls back to REMOTE_ADDR instead of erroring.
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';

		$this->assertSame( '8.8.8.8', VisitorIp::get( '!!!' ) );
	}

	public function test_xforwardedfor_returns_first_public_ip_and_skips_private(): void {
		// The list is scanned left to right; private/reserved entries are
		// skipped and the first public IP is returned.
		$_SERVER['REMOTE_ADDR']          = '203.0.113.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1,192.168.0.1,8.8.8.8';

		$this->assertSame( '8.8.8.8', VisitorIp::get( 'X-Forwarded-For' ) );
	}

	public function test_xforwardedfor_falls_back_when_all_entries_are_private(): void {
		$_SERVER['REMOTE_ADDR']          = '8.8.8.8';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1,192.168.0.1';

		$this->assertSame( '8.8.8.8', VisitorIp::get( 'X-Forwarded-For' ) );
	}

	public function test_private_remote_addr_yields_empty_string(): void {
		// On a private/local network REMOTE_ADDR itself is a private range, so
		// the filtered result is empty - 1.x parity.
		$_SERVER['REMOTE_ADDR'] = '192.168.1.10';

		$this->assertSame( '', VisitorIp::get() );
	}

	public function test_returns_empty_string_when_remote_addr_is_missing(): void {
		$this->assertSame( '', VisitorIp::get() );
	}
}
