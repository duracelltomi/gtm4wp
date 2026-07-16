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

	protected function setUp(): void {
		parent::setUp();

		$this->enqueued       = array();
		$this->inline_scripts = array();
		$this->scoped_fields  = array();

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

	public function test_excludes_tier2_and_tier3_fields_from_the_client_config(): void {
		// Phase 1 delivers ONLY Tier 1 (browser-known) fields client-side. A Tier 2
		// (session) or Tier 3 (action) field must never be baked into the client
		// config — Phase 2 delivers those through the cookie-gated session endpoint.
		$this->scoped_fields = array(
			new VisitorField( 'visitorIP', VisitorField::TIER_SESSION ),
			new VisitorField( 'visitorEmail', VisitorField::TIER_ACTION ),
		);

		$module = $this->make_module( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-visitor-data', $this->enqueued, 'Tier 2/3 fields must not trigger the Phase 1 client push.' );
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
}
