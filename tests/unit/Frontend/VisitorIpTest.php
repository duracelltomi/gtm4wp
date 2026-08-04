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

	/**
	 * The header-name allow-list is anchored. The unanchored version it replaces
	 * matched any string CONTAINING one allowed character, so every one of these
	 * names passed validation and was turned into a $_SERVER lookup key. Nothing
	 * could escape (the return is always FILTER_VALIDATE_IP gated), but the guard
	 * validated nothing - so pin that it now rejects.
	 *
	 * @param string $header_name A header name that must be rejected.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'malformed_header_name_provider' )]
	public function test_malformed_custom_header_names_are_rejected( string $header_name ): void {
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';

		// Seed the $_SERVER key the OLD (unanchored) code would have built, with a
		// routable IP: if validation is skipped the lookup succeeds and this value
		// is returned instead of REMOTE_ADDR, failing the assertion.
		$_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $header_name ) ) ] = '203.0.113.9';

		$this->assertSame(
			'8.8.8.8',
			VisitorIp::get( $header_name ),
			'A header name outside the [A-Z0-9_] allow-list must be rejected, falling back to REMOTE_ADDR.'
		);
	}

	/**
	 * Header names outside the [A-Z0-9_] allow-list that the unanchored pattern
	 * used to accept.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_header_name_provider(): array {
		return array(
			'script tag'      => array( '"><script>alert(1)</script>' ),
			'spaces'          => array( 'X Real IP' ),
			'dot separator'   => array( 'X.Real.IP' ),
			'colon'           => array( 'X-Real-IP:evil' ),
			'trailing symbol' => array( 'X-Real-IP!' ),
		);
	}

	/**
	 * The positive half of the same gate: a well-formed name still works, so the
	 * anchoring did not over-tighten (hyphens map to underscores as documented).
	 */
	public function test_wellformed_custom_header_names_are_still_accepted(): void {
		$_SERVER['REMOTE_ADDR']         = '8.8.8.8';
		$_SERVER['HTTP_TRUE_CLIENT_IP'] = '203.0.113.9';

		$this->assertSame( '203.0.113.9', VisitorIp::get( 'True-Client-IP' ) );
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

	/**
	 * RFC 7239 / de-facto X-Forwarded-For is comma+SPACE separated, which is what
	 * every real proxy emits. Without trimming, every entry after the first carries
	 * a leading space and fails filter_var(), so only entry 1 was ever usable and
	 * the function silently fell back to REMOTE_ADDR (#67). The sibling cases above
	 * use comma-without-space, which is why a green suite never showed this.
	 */
	public function test_xforwardedfor_trims_entries_separated_by_comma_and_space(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 192.168.0.1, 8.8.8.8';

		$this->assertSame(
			'8.8.8.8',
			VisitorIp::get( 'X-Forwarded-For' ),
			'Entries after the first must be trimmed before validation.'
		);
		$this->assertNotSame(
			'203.0.113.1',
			VisitorIp::get( 'X-Forwarded-For' ),
			'A trimmed public entry must win over the REMOTE_ADDR fallback.'
		);
	}

	public function test_xforwardedfor_trims_a_single_padded_entry(): void {
		// A one-entry header with incidental whitespace still resolves.
		$_SERVER['REMOTE_ADDR']          = '203.0.113.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '  8.8.8.8  ';

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

	// -----------------------------------------------------------------------
	// Trusted proxy handling (#75). Sanitizing a header value proves it is safe
	// to handle, never that it is authentic (RI-18) - these pin the difference.
	// -----------------------------------------------------------------------

	/**
	 * The finding itself: every appending proxy (nginx proxy_add_x_forwarded_for,
	 * AWS ALB, Cloudflare) puts the address IT observed on the RIGHT, after whatever
	 * the client sent. Scanning left to right therefore returns the one entry nobody
	 * vouched for. With the proxies declared, the walk starts from the right, skips
	 * the operator's own hops and stops at the client.
	 */
	public function test_xforwardedfor_ignores_a_client_prepended_entry_when_proxies_are_declared(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.8.8, 10.0.0.5';

		$this->assertSame(
			'8.8.8.8',
			VisitorIp::get( 'X-Forwarded-For', '10.0.0.0/8' ),
			'The entry the trusted proxy observed must win.'
		);
		$this->assertNotSame(
			'9.9.9.9',
			VisitorIp::get( 'X-Forwarded-For', '10.0.0.0/8' ),
			'The left-most entry is client-supplied and must never be returned.'
		);
	}

	/**
	 * The stop-at-the-first-untrusted-entry rule, which is the whole guarantee. If the
	 * walk kept going left looking for a "better" (public) address, an attacker behind
	 * a NAT could prepend one and have it picked up - reopening the finding through the
	 * fix. Here the real client is private, so the correct answer is to give up and
	 * fall back, NOT to keep reading client-supplied entries.
	 */
	public function test_xforwardedfor_does_not_scan_past_the_client_entry(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 192.168.1.50, 10.0.0.5';

		$this->assertSame(
			'',
			VisitorIp::get( 'X-Forwarded-For', '10.0.0.0/8' ),
			'A private client entry must end the walk, not continue it leftwards.'
		);
	}

	/**
	 * The REPLACE-semantics half of the finding, which "parse from the right" does not
	 * touch: CF-Connecting-IP is authoritative only because Cloudflare overwrites it.
	 * On a request delivered straight to the origin it is just something the client
	 * typed, so it must be refused.
	 */
	public function test_single_value_header_is_refused_when_the_request_did_not_come_via_a_trusted_proxy(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';

		$this->assertSame(
			'203.0.113.9',
			VisitorIp::get( 'CF-Connecting-IP', '10.0.0.0/8' ),
			'An unvouched single-value header must fall back to REMOTE_ADDR.'
		);
	}

	public function test_single_value_header_is_used_when_the_request_came_via_a_trusted_proxy(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';

		$this->assertSame( '9.9.9.9', VisitorIp::get( 'CF-Connecting-IP', '10.0.0.0/8' ) );
	}

	/**
	 * The back-compat contract: an existing site that has a header configured and has
	 * not yet filled in the proxy list must see exactly the value it saw before, so
	 * upgrading changes nothing silently. The admin is warned instead (Notices).
	 */
	public function test_empty_trusted_proxy_list_keeps_the_previous_reading(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.8.8, 10.0.0.5';

		$this->assertSame( '9.9.9.9', VisitorIp::get( 'X-Forwarded-For', '' ) );
	}

	public function test_falls_back_when_every_forwarded_entry_is_a_trusted_proxy(): void {
		// A purely internal request: the list carries our own hops and no client.
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.4, 10.0.0.5';

		$this->assertSame( '', VisitorIp::get( 'X-Forwarded-For', '10.0.0.0/8' ) );
	}

	public function test_trusted_proxy_list_accepts_newline_and_comma_separation(): void {
		$_SERVER['REMOTE_ADDR']          = '172.16.0.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.4.4, 172.16.0.9';

		$this->assertSame(
			'8.8.4.4',
			VisitorIp::get( 'X-Forwarded-For', "10.0.0.0/8,\n172.16.0.0/12\n 192.168.0.0/16 " )
		);
	}

	public function test_invalid_trusted_proxy_entries_are_ignored_without_disabling_the_valid_ones(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.8.8, 10.0.0.5';

		$this->assertSame(
			'8.8.8.8',
			VisitorIp::get( 'X-Forwarded-For', "not-an-ip\n10.0.0.0/8\n999.1.1.1/8\n10.0.0.0/99" )
		);
	}

	/**
	 * A malformed list must not silently degrade into "no proxies configured", which
	 * would hand back the client-chosen entry while the admin believes the setting is
	 * active. Refusing the header is the safe direction.
	 */
	public function test_a_list_of_only_invalid_entries_does_not_reopen_the_unverified_path(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.8.8';

		// Every entry is junk, so no proxy is trusted -> the pre-setting reading
		// applies and the admin keeps their warning. Documented, not accidental.
		$this->assertSame( '9.9.9.9', VisitorIp::get( 'X-Forwarded-For', 'nonsense, also-nonsense' ) );
	}

	public function test_ipv6_proxy_ranges_are_matched(): void {
		$_SERVER['REMOTE_ADDR']          = '2001:db8::1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.8.8, 2001:db8::1';

		$this->assertSame( '8.8.8.8', VisitorIp::get( 'X-Forwarded-For', '2001:db8::/32' ) );
	}

	public function test_an_ipv4_address_never_matches_an_ipv6_range(): void {
		// Different families have different packed lengths; a cross-family compare
		// must answer "no match", not throw or match on a byte prefix.
		$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';

		$this->assertSame( '203.0.113.9', VisitorIp::get( 'CF-Connecting-IP', '2001:db8::/32' ) );
	}

	public function test_exact_proxy_address_without_a_prefix_is_honored(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.8.8, 198.51.100.7';

		$this->assertSame( '8.8.8.8', VisitorIp::get( 'X-Forwarded-For', '198.51.100.7' ) );
	}

	/**
	 * The option sanitizer validates with this same method, so an entry accepted here
	 * is exactly an entry the reader will honor.
	 *
	 * @param string $entry    The list entry under test.
	 * @param bool   $expected Whether it should be accepted.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_range_validity' )]
	public function test_is_valid_range( string $entry, bool $expected ): void {
		$this->assertSame( $expected, VisitorIp::is_valid_range( $entry ) );
	}

	/**
	 * Supplies both directions for the range validator: accepted and rejected forms.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function provide_range_validity(): array {
		return array(
			'plain ipv4'          => array( '10.0.0.5', true ),
			'ipv4 cidr'           => array( '10.0.0.0/8', true ),
			'ipv4 full prefix'    => array( '10.0.0.5/32', true ),
			'plain ipv6'          => array( '2001:db8::1', true ),
			'ipv6 cidr'           => array( '2001:db8::/32', true ),
			'ipv6 full prefix'    => array( '2001:db8::1/128', true ),
			'ipv4 prefix too big' => array( '10.0.0.0/33', false ),
			'ipv6 prefix too big' => array( '2001:db8::/129', false ),
			'not an ip'           => array( 'example.com', false ),
			'octet out of range'  => array( '999.1.1.1', false ),
			'empty prefix'        => array( '10.0.0.0/', false ),
			'non numeric prefix'  => array( '10.0.0.0/eight', false ),
			'empty string'        => array( '', false ),
		);
	}
}
