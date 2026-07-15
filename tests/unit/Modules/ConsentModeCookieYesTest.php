<?php
/**
 * Unit tests for the CookieYes consent bridge of the consent module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Modules\ConsentMode\ConsentModeModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the CookieYes bridge: it must register the head-block callback only
 * when the option is on, forward CookieYes' documented consent events to a
 * cookie_consent_update data layer push, and route the data layer variable name
 * (the only PHP-interpolated value) through esc_js.
 *
 * There is no PHP-side JSON sink here (unlike Axeptio's settings object): the
 * consent payload is read from the browser event (e.detail), so the RI-2
 * hex-flag regression does not apply — the security-relevant guard is that the
 * data layer name is escaped, mirroring the WebToffee/Axeptio bridges.
 */
final class ConsentModeCookieYesTest extends TestCase {

	/**
	 * Boots the consent module with the given stored options merged over its
	 * defaults.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ConsentModeModule
	 */
	private function boot( array $stored = array() ): ConsentModeModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module = new ConsentModeModule();
		$module->frontend( new Options( $module->defaults() ) );

		return $module;
	}

	public function test_registers_cookieyes_bridge_when_enabled(): void {
		$enabled = $this->boot( array( GTM4WP_OPTION_INTEGRATE_COOKIEYES => true ) );

		$this->assertNotFalse(
			has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $enabled, 'add_cookieyes_header_js' ) )
		);
	}

	public function test_inactive_when_disabled(): void {
		$disabled = $this->boot();

		$this->assertFalse(
			has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $disabled, 'add_cookieyes_header_js' ) )
		);
	}

	public function test_header_js_bridges_cookieyes_events_to_cookie_consent_update(): void {
		Functions\stubEscapeFunctions();

		$module = $this->boot( array( GTM4WP_OPTION_INTEGRATE_COOKIEYES => true ) );

		$js = $module->add_cookieyes_header_js( '', 'customDL' );

		// Both documented CookieYes events are bridged to one container signal.
		$this->assertStringContainsString( 'cookieyes_consent_update', $js );
		$this->assertStringContainsString( 'cookieyes_banner_load', $js );
		$this->assertStringContainsString( '"event": "cookie_consent_update"', $js );
		// The bridge honors the configured data layer variable name.
		$this->assertStringContainsString( 'window.customDL = window.customDL || [];', $js );
	}

	public function test_header_js_preserves_accumulated_inline_js(): void {
		Functions\stubEscapeFunctions();

		$module = $this->boot( array( GTM4WP_OPTION_INTEGRATE_COOKIEYES => true ) );

		$this->assertStringStartsWith( '/* earlier */', $module->add_cookieyes_header_js( '/* earlier */', 'dataLayer' ) );
	}

	public function test_header_js_escapes_the_datalayer_name(): void {
		// Model esc_js on its quote-escaping behavior so dropping the esc_js call
		// leaves the raw quote in the output and fails this test.
		Functions\when( 'esc_js' )->alias( static fn ( $t ) => addslashes( (string) $t ) );

		$module = $this->boot( array( GTM4WP_OPTION_INTEGRATE_COOKIEYES => true ) );

		$js = $module->add_cookieyes_header_js( '', 'dl"x' );

		// Present: the escaped form (esc_js was applied to the name).
		$this->assertStringContainsString( 'window.dl\\"x = window.dl\\"x || [];', $js );
		// Absent: the raw, unescaped name that would appear if esc_js were dropped.
		$this->assertStringNotContainsString( 'window.dl"x ', $js );
	}
}
