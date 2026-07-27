<?php
/**
 * Unit tests for the AMP module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\Frontend;
use GTM4WP\Modules\Amp\AmpModule;
use GTM4WP\Options\Options;
use GTM4WP\Plugin;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the amp-wp 2.x integration: the module hands one GTM amp-analytics
 * entry per container ID to the plugin's cross-mode `amp_analytics_entries`
 * filter and lets amp-wp render + encode it.
 *
 * The security-relevant assertion is the raw-passthrough contract (TS-11 /
 * RI-4): the compiled data layer carries request-sourced values (e.g.
 * siteSearchTerm on an AMP search page); the module must hand them to amp-wp
 * RAW so amp-wp escapes them once, and must never pre-escape with
 * esc_js()/esc_attr(). A re-introduced pre-escape makes the assertS() fail.
 * Break-out characters are written with \xNN escapes so no literal break-out
 * char appears in this file (TC-2).
 */
final class AmpModuleTest extends TestCase {

	protected function tearDown(): void {
		// Release the singleton this test injected so it never leaks (TS-7).
		$this->set_static( Plugin::class, 'instance', null );
		// compile() mirrors into the backward-compatible global; reset it (TS-7).
		unset( $GLOBALS['gtm4wp_datalayer_data'] );
		parent::tearDown();
	}

	/**
	 * Sets a private/protected instance property via reflection.
	 *
	 * @param object $target   Target object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	private function set_private( object $target, string $property, $value ): void {
		( new \ReflectionProperty( $target, $property ) )->setValue( $target, $value );
	}

	/**
	 * Sets a static property via reflection.
	 *
	 * @param class-string $class_name Target class.
	 * @param string       $property   Property name.
	 * @param mixed        $value      Value to set.
	 */
	private function set_static( string $class_name, string $property, $value ): void {
		( new \ReflectionProperty( $class_name, $property ) )->setValue( null, $value );
	}

	/**
	 * Boots an AMP module and installs a Plugin singleton whose frontend data
	 * layer reports the given compiled data (TC-8: reflection-injected singleton
	 * chain, no Registry needed).
	 *
	 * @param array<string, mixed> $compiled Compiled data layer to expose (empty forces compile()).
	 * @param string               $ampid    The configured AMP container id(s).
	 * @return AmpModule
	 */
	private function make_module_with_datalayer( array $compiled, string $ampid = 'GTM-AMP1' ): AmpModule {
		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_INTEGRATE_AMPID => $ampid ) );

		$options = new Options( ( new AmpModule() )->defaults() );

		// Real DataLayer with a seeded compiled() result (no compile filter run
		// unless the seed is empty).
		$datalayer = new DataLayer( $options );
		$this->set_private( $datalayer, 'compiled', $compiled );

		// Frontend built without its constructor so no Registry is needed; only
		// datalayer() is exercised by the analytics-entry builder.
		$frontend = ( new \ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $frontend, 'datalayer', $datalayer );

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $plugin, 'frontend', $frontend );
		$this->set_static( Plugin::class, 'instance', $plugin );

		$module = new AmpModule();
		$module->frontend( $options );

		return $module;
	}

	public function test_add_amp_analytics_entries_builds_one_gtm_entry(): void {
		$module  = $this->make_module_with_datalayer( array( 'event' => 'gtm.dom' ), 'GTM-AMP1' );
		$entries = $module->add_amp_analytics_entries( array() );

		$this->assertArrayHasKey( 'gtm4wp-GTM-AMP1', $entries );

		$entry = $entries['gtm4wp-GTM-AMP1'];

		// Remote GTM config URL carries the container id and the SOURCE_URL macro.
		$this->assertStringContainsString( 'googletagmanager.com/amp.json?id=GTM-AMP1', $entry['attributes']['config'] );
		$this->assertStringContainsString( 'gtm.url=SOURCE_URL', $entry['attributes']['config'] );
		$this->assertSame( 'include', $entry['attributes']['data-credentials'] );

		// The compiled data layer is handed over as the amp-analytics vars.
		$this->assertSame( array( 'event' => 'gtm.dom' ), $entry['config_data']['vars'] );
	}

	public function test_add_amp_analytics_entries_preserves_existing_entries(): void {
		$module   = $this->make_module_with_datalayer( array( 'event' => 'x' ) );
		$existing = array(
			'other-analytics' => array(
				'attributes'  => array(),
				'config_data' => array(),
			),
		);

		$entries = $module->add_amp_analytics_entries( $existing );

		$this->assertArrayHasKey( 'other-analytics', $entries, 'Entries from other integrations are kept.' );
		$this->assertArrayHasKey( 'gtm4wp-GTM-AMP1', $entries );
	}

	public function test_add_amp_analytics_entries_adds_one_entry_per_container_and_skips_blanks(): void {
		// Comma-separated list with an empty item and surrounding whitespace.
		$module  = $this->make_module_with_datalayer( array( 'event' => 'x' ), 'GTM-AMP1,, GTM-AMP2 ' );
		$entries = $module->add_amp_analytics_entries( array() );

		$this->assertArrayHasKey( 'gtm4wp-GTM-AMP1', $entries );
		$this->assertArrayHasKey( 'gtm4wp-GTM-AMP2', $entries, 'Whitespace around an id is trimmed.' );
		$this->assertCount( 2, $entries, 'The empty list item produced no entry.' );
	}

	public function test_add_amp_analytics_entries_returns_entries_unchanged_for_empty_data_layer(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )->andReturn( array() );

		// Seed empty so compiled() is empty and compile() also yields empty.
		$module   = $this->make_module_with_datalayer( array() );
		$existing = array( 'other-analytics' => array() );

		$entries = $module->add_amp_analytics_entries( $existing );

		$this->assertSame( $existing, $entries, 'No GTM entry is added when the data layer is empty.' );
	}

	public function test_add_amp_analytics_entries_compiles_data_layer_on_demand(): void {
		// Legacy Reader mode: wp_head never ran, so compiled() is empty and the
		// callback must run compile() (the public filter) itself.
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->once()
			->andReturn(
				array(
					'event'        => 'gtm.js',
					'pagePostType' => 'post',
				)
			);

		$module  = $this->make_module_with_datalayer( array() );
		$entries = $module->add_amp_analytics_entries( array() );

		$this->assertSame(
			array(
				'event'        => 'gtm.js',
				'pagePostType' => 'post',
			),
			$entries['gtm4wp-GTM-AMP1']['config_data']['vars']
		);
	}

	public function test_add_amp_analytics_entries_hands_hostile_data_layer_values_raw(): void {
		// \x3C < , \x3E > , \x22 " , \x26 &  (no literal break-out char in source).
		$hostile = array(
			'siteSearchTerm' => "\x3C/script\x3E",
			'quoteAmp'       => "x\x22y\x26z",
		);

		$module  = $this->make_module_with_datalayer( $hostile );
		$entries = $module->add_amp_analytics_entries( array() );

		$vars = $entries['gtm4wp-GTM-AMP1']['config_data']['vars'];

		// Raw passthrough: amp-wp encodes downstream, so the module must NOT
		// pre-escape. A re-added esc_js()/esc_attr() would change these and fail.
		$this->assertSame( $hostile, $vars );

		// Explicitly assert no entity-encoding crept in (TS-2 / TS-11 direction 2).
		$this->assertStringNotContainsString( '&amp;', $vars['quoteAmp'] );
		$this->assertStringNotContainsString( '&lt;', $vars['siteSearchTerm'] );
	}

	public function test_is_amp_request_prefers_amp_is_request_then_falls_back(): void {
		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_INTEGRATE_AMPID => 'GTM-AMP1' ) );
		$module = new AmpModule();
		$module->frontend( new Options( $module->defaults() ) );

		// Modern amp-wp (>= 2.0): amp_is_request() decides.
		Functions\when( 'amp_is_request' )->justReturn( true );
		$this->assertTrue( $module->is_amp_request( false ) );

		// Pre-2.0 fallback: amp_is_request() absent/false, is_amp_endpoint() true.
		Functions\when( 'amp_is_request' )->justReturn( false );
		Functions\when( 'is_amp_endpoint' )->justReturn( true );
		$this->assertTrue( $module->is_amp_request( false ) );

		// Neither says AMP: fall back to the incoming filter value.
		Functions\when( 'is_amp_endpoint' )->justReturn( false );
		$this->assertTrue( $module->is_amp_request( true ), 'Falls back to the incoming filter value.' );
		$this->assertFalse( $module->is_amp_request( false ) );
	}
}
