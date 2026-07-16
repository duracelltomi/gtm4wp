<?php
/**
 * Unit tests for the cache-safe data layer module (issue #398).
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\VisitorData\VisitorDataModule;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the client-side runtime wiring: only the Tier 1 (browser-knows-it)
 * visitor-scoped fields are turned into the gtm4wp.visitorData client config,
 * the config is hex-encoded for its inline-script context, and nothing is
 * enqueued when there is no Tier 1 field to deliver.
 */
final class VisitorDataModuleTest extends TestCase {

	/**
	 * Handles passed to wp_enqueue_script() during a test.
	 *
	 * @var string[]
	 */
	private array $enqueued = array();

	/**
	 * Inline scripts captured from wp_add_inline_script(): each entry is
	 * array( handle, code, position ).
	 *
	 * @var array<int, array{0:string,1:string,2:string}>
	 */
	private array $inline_scripts = array();

	/**
	 * Visitor-scoped fields the stubbed GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS
	 * filter returns for the current test.
	 *
	 * @var array<int, mixed>
	 */
	private array $scoped_fields = array();

	/**
	 * Snapshot of $_COOKIE, restored in tearDown so the login-gate tests do not
	 * leak state into siblings even if an assertion fails early (TS-7).
	 *
	 * @var array<string, mixed>
	 */
	private array $cookie_backup = array();

	protected function setUp(): void {
		parent::setUp();

		$this->enqueued       = array();
		$this->inline_scripts = array();
		$this->scoped_fields  = array();
		$this->cookie_backup  = $_COOKIE;

		// Default: no stored options; individual tests override via make_module().
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'plugins_url' )->alias( static fn ( $path, $plugin ) => 'https://example.com/' . $path );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
				$this->enqueued[] = $handle;
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_scripts[] = array( $handle, $code, $position );
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		// The endpoint URL + nonce baked into the config for Tier 2/3 fields.
		Functions\when( 'rest_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-json/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		// The module collects the declared fields through this filter; feed it the
		// per-test fixture deterministically (Brain Monkey's apply_filters does not
		// run add_filter callbacks by default).
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS === $tag ? $this->scoped_fields : $value;
			}
		);
	}

	/**
	 * Boots a module with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return VisitorDataModule
	 */
	private function make_module( array $stored = array() ): VisitorDataModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module = new VisitorDataModule();
		$module->frontend( new Options( $module->defaults() ) );

		return $module;
	}

	/**
	 * Returns the concatenated inline-script code attached to a handle.
	 *
	 * @param string $handle The script handle.
	 * @return string
	 */
	private function inline_for( string $handle ): string {
		$code = '';
		foreach ( $this->inline_scripts as $script ) {
			if ( $handle === $script[0] ) {
				$code .= $script[1];
			}
		}
		return $code;
	}

	public function test_is_enabled_reflects_the_option(): void {
		$on  = new Options( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );
		$off = new Options( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false ) );

		$this->assertTrue( VisitorDataModule::is_enabled( $on ) );
		$this->assertFalse( VisitorDataModule::is_enabled( $off ) );
	}

	public function test_enqueues_and_maps_tier1_fields_into_the_client_config(): void {
		$this->scoped_fields = array(
			new VisitorField( 'siteSearchTerm', VisitorField::TIER_CLIENT, 'searchTerm' ),
			new VisitorField( 'siteSearchFrom', VisitorField::TIER_CLIENT, 'searchReferrer' ),
		);

		$module = $this->make_module( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-visitor-data', $this->enqueued, 'The client runtime must be enqueued when a Tier 1 field is active.' );

		$code = $this->inline_for( 'gtm4wp-visitor-data' );
		$this->assertStringContainsString( 'var gtm4wp_visitordata_config = ', $code );
		$this->assertStringContainsString( '"event":"gtm4wp.visitorData"', $code );
		// Each moved data layer key maps to its client producer token, under the
		// SAME variable name the server used.
		$this->assertStringContainsString( '"siteSearchTerm":"searchTerm"', $code );
		$this->assertStringContainsString( '"siteSearchFrom":"searchReferrer"', $code );

		// The config is injected before the runtime reads it.
		$this->assertSame( 'before', $this->inline_scripts[0][2] );
	}

	public function test_does_not_enqueue_when_no_tier1_field_is_active(): void {
		// No visitor-scoped field declared on this request (e.g. cache-safe on but
		// not a search page): nothing to push, so the runtime is not loaded at all.
		$this->scoped_fields = array();

		$module = $this->make_module( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-visitor-data', $this->enqueued );
		$this->assertSame( array(), $this->inline_scripts, 'No inline config may be emitted when there is nothing to deliver.' );
	}

	public function test_tier2_and_tier3_fields_produce_the_endpoint_config(): void {
		// Phase 2: a Tier 2 (session) field and a Tier 3 (action, cookie-gated) field
		// enqueue the runtime and bake the session-endpoint metadata — the endpoint
		// URL, the REST nonce, the once-per-session Tier 2 key list and the gate =>
		// keys map — but NO visitor value (those come from the endpoint at runtime).
		$this->scoped_fields = array(
			new VisitorField( 'visitorIP', VisitorField::TIER_SESSION, '', static fn () => '1.2.3.4' ),
			new VisitorField( 'visitorEmail', VisitorField::TIER_ACTION, '', static fn () => 'x@example.com', 'gtm4wp_login' ),
		);

		$module = $this->make_module( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-visitor-data', $this->enqueued, 'Tier 2/3 fields must load the runtime to fetch the endpoint.' );

		$code = $this->inline_for( 'gtm4wp-visitor-data' );
		$this->assertStringContainsString( '"endpoint":"https:\/\/example.com\/wp-json\/gtm4wp\/v2\/visitor-data"', $code );
		$this->assertStringContainsString( '"nonce":"test-nonce"', $code );
		$this->assertStringContainsString( '"session":["visitorIP"]', $code );
		$this->assertStringContainsString( '"gates":[{"cookie":"gtm4wp_login","keys":["visitorEmail"]}]', $code );

		// No visitor value is ever baked into the cacheable config.
		$this->assertStringNotContainsString( '1.2.3.4', $code, 'The Tier 2 value must not be in the cacheable config.' );
		$this->assertStringNotContainsString( 'x@example.com', $code, 'The Tier 3 value must not be in the cacheable config.' );
	}

	public function test_action_field_without_a_gate_is_omitted_from_the_config(): void {
		// A Tier 3 field with no cookie gate cannot be delivered safely (nothing tells
		// the client when to fetch it), so it contributes no gate and — being the only
		// field — nothing is enqueued.
		$this->scoped_fields = array(
			new VisitorField( 'visitorEmail', VisitorField::TIER_ACTION, '', static fn () => 'x@example.com' ),
		);

		$module = $this->make_module( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-visitor-data', $this->enqueued );
		$this->assertSame( array(), $this->inline_scripts );
	}

	public function test_client_config_is_hex_encoded_for_the_inline_script(): void {
		// The config is developer data, but it still lands in a raw inline <script>,
		// so it must go through wp_json_encode() with the full hex flag set (RI-2).
		// A field key carrying every break-out character proves all four flags are on:
		// dropping any one of JSON_HEX_TAG/AMP/QUOT/APOS changes the encoded output.
		$this->scoped_fields = array(
			new VisitorField( "</script>\x22\x26\x27", VisitorField::TIER_CLIENT, 'searchTerm' ),
		);

		$module = $this->make_module( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );
		$module->enqueue_scripts();

		$code = $this->inline_for( 'gtm4wp-visitor-data' );
		// Build the expected encoded key with the SAME flags the source uses (TC-2);
		// dropping any one of the four flags changes this fragment and fails here.
		$expected = json_encode( "</script>\x22\x26\x27", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( trim( $expected, '"' ), $code, 'The key must be hex-encoded with the full flag set.' );
		$this->assertStringNotContainsString( '</script>', $code, 'No raw </script> may survive in the inline config (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( '&', $code, 'No raw & may survive in the inline config (JSON_HEX_AMP).' );
	}

	/**
	 * Cookie writes captured from the stubbed internal setcookie() during a gate
	 * test: each entry is array( name, value, options ).
	 *
	 * @var array<int, array{0:string,1:string,2:array}>
	 */
	private array $cookie_writes = array();

	/**
	 * Stubs the WordPress functions the login-gate maintenance reads and captures
	 * the internal setcookie() calls it makes (setcookie/headers_sent are made
	 * redefinable via patchwork.json).
	 *
	 * @param bool $logged_in Whether is_user_logged_in() should report a logged-in user.
	 * @return void
	 */
	private function arrange_gate( bool $logged_in ): void {
		$this->cookie_writes = array();

		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( $logged_in );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_get_session_token' )->justReturn( 'tok123' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_hash' )->alias( static fn ( $data ) => md5( (string) $data ) );
		Functions\when( 'setcookie' )->alias(
			function ( $name, $value = '', $options = array() ) {
				$this->cookie_writes[] = array( $name, (string) $value, (array) $options );
				return true;
			}
		);
	}

	/**
	 * The stable-per-session gate value the module derives from the stubbed
	 * get_current_user_id() (7) and wp_get_session_token() ('tok123').
	 *
	 * @return string
	 */
	private function expected_gate_value(): string {
		return substr( md5( '7|tok123' ), 0, 20 );
	}

	public function test_login_gate_cookie_is_set_for_a_logged_in_visitor_without_it(): void {
		$this->arrange_gate( true );
		unset( $_COOKIE[ VisitorDataModule::LOGIN_GATE_COOKIE ] );

		( new VisitorDataModule() )->maintain_login_gate_cookie();

		$expected = $this->expected_gate_value();

		$this->assertCount( 1, $this->cookie_writes );
		$this->assertSame( VisitorDataModule::LOGIN_GATE_COOKIE, $this->cookie_writes[0][0] );
		$this->assertSame( $expected, $this->cookie_writes[0][1] );
		$this->assertFalse( $this->cookie_writes[0][2]['httponly'], 'The gate cookie must be JS-readable.' );
		$this->assertGreaterThan( time(), $this->cookie_writes[0][2]['expires'], 'A set uses a future expiry.' );
		// Reflected into $_COOKIE so a same-request read sees the new value.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- test assertion reading the value the code under test just set.
		$this->assertSame( $expected, $_COOKIE[ VisitorDataModule::LOGIN_GATE_COOKIE ] );
	}

	public function test_login_gate_cookie_not_rewritten_when_already_current(): void {
		$this->arrange_gate( true );
		$_COOKIE[ VisitorDataModule::LOGIN_GATE_COOKIE ] = $this->expected_gate_value();

		( new VisitorDataModule() )->maintain_login_gate_cookie();

		$this->assertSame( array(), $this->cookie_writes, 'An unchanged gate value must not rewrite the cookie.' );
	}

	public function test_login_gate_cookie_is_cleared_on_logout(): void {
		$this->arrange_gate( false );
		$_COOKIE[ VisitorDataModule::LOGIN_GATE_COOKIE ] = 'stale-value';

		( new VisitorDataModule() )->maintain_login_gate_cookie();

		$this->assertCount( 1, $this->cookie_writes );
		$this->assertSame( '', $this->cookie_writes[0][1], 'Logout clears the cookie value.' );
		$this->assertLessThan( time(), $this->cookie_writes[0][2]['expires'], 'A clear uses a past expiry.' );
		$this->assertArrayNotHasKey( VisitorDataModule::LOGIN_GATE_COOKIE, $_COOKIE );
	}

	public function test_login_gate_cookie_untouched_for_anonymous_visitor_without_it(): void {
		// An anonymous visitor with no gate cookie: nothing is written, so a cached
		// page stays cacheable (no Set-Cookie) and the visitor never fetches user data.
		$this->arrange_gate( false );
		unset( $_COOKIE[ VisitorDataModule::LOGIN_GATE_COOKIE ] );

		( new VisitorDataModule() )->maintain_login_gate_cookie();

		$this->assertSame( array(), $this->cookie_writes );
	}

	public function test_login_gate_cookie_not_written_after_headers_sent(): void {
		$this->arrange_gate( true );
		Functions\when( 'headers_sent' )->justReturn( true );
		unset( $_COOKIE[ VisitorDataModule::LOGIN_GATE_COOKIE ] );

		( new VisitorDataModule() )->maintain_login_gate_cookie();

		$this->assertSame( array(), $this->cookie_writes, 'Cookies cannot be set after headers; skip silently.' );
	}

	protected function tearDown(): void {
		$_COOKIE = $this->cookie_backup;

		parent::tearDown();
	}
}
