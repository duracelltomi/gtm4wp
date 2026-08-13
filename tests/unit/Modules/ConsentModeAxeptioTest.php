<?php
/**
 * Unit tests for the Axeptio consent tool handler of the consent module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\ConsentDefaults;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Modules\ConsentMode\Axeptio;
use GTM4WP\Modules\ConsentMode\ConsentModeModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Frontend\FrontendTestCase;

/**
 * Covers the Axeptio head block (SDK loader, consent default, data layer
 * bridge) and the hook gating of the consent module's Axeptio handler.
 *
 * The security-relevant assertion is the RI-2 hex-flag regression: the
 * window.axeptioSettings object is written into an inline script that the head
 * block prints WITHOUT the ampersand-restore, so every break-out character in
 * the (admin-set) project ID / cookies version must be hex-encoded. These tests
 * fail if the flags on that wp_json_encode() are dropped. Break-out characters
 * are written with \xNN escapes and the expected fragments are computed with
 * json_encode() using the same flags the source uses (TC-2).
 */
final class ConsentModeAxeptioTest extends FrontendTestCase {

	private const HEX_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;

	/**
	 * Builds an Axeptio handler with the given stored options, merged over the
	 * consent module defaults (which own the Axeptio option keys).
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return Axeptio
	 */
	private function make_axeptio( array $stored ): Axeptio {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new Axeptio( new Options( ( new ConsentModeModule() )->defaults() ) );
	}

	/**
	 * Returns the JSON-encoded form of a scalar without the surrounding quotes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function encoded_fragment( string $value ): string {
		return trim( (string) json_encode( $value, self::HEX_FLAGS ), '"' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_head_js_hex_encodes_breakout_characters_in_settings(): void {
		// \x3C < , \x3E > , \x22 " , \x26 &  (no literal break-out char in source).
		$project_id = "GTM\x3C/script\x3E\x22\x26x";

		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => $project_id,
			)
		);

		$js = $axeptio->add_head_js( '', 'dataLayer' );

		// Present: the hex-encoded form as the source's wp_json_encode() emits it.
		$this->assertStringContainsString( $this->encoded_fragment( $project_id ), $js );

		// Absent: the raw break-out payload that would appear if a flag were dropped.
		$this->assertStringNotContainsString( $project_id, $js );
		$this->assertStringNotContainsString( '</script>', $js );
	}

	public function test_head_js_includes_sdk_loader_and_datalayer_bridge(): void {
		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);

		$js = $axeptio->add_head_js( '', 'customDL' );

		$this->assertStringContainsString( 'window.axeptioSettings = ', $js );
		$this->assertStringContainsString( '"clientId":"my-project"', $js );
		$this->assertStringContainsString( 'https://static.axept.io/sdk.js', $js );
		$this->assertStringContainsString( 'axeptio.on("cookies:complete"', $js );
		$this->assertStringContainsString( '"event": "gtm4wp.axeptioConsentUpdate"', $js );
		// The data layer bridge honors the configured data layer variable name.
		$this->assertStringContainsString( 'window.customDL = window.customDL || [];', $js );
	}

	public function test_head_js_preserves_accumulated_inline_js(): void {
		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);

		$js = $axeptio->add_head_js( '/* earlier */', 'dataLayer' );

		$this->assertStringStartsWith( '/* earlier */', $js );
	}

	public function test_cookies_version_included_only_when_set(): void {
		$with_version = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION => 'my-project-v3',
			)
		);
		$this->assertStringContainsString( '"cookiesVersion":"my-project-v3"', $with_version->add_head_js( '', 'dataLayer' ) );

		$without_version = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);
		$this->assertStringNotContainsString( 'cookiesVersion', $without_version->add_head_js( '', 'dataLayer' ) );
	}

	public function test_consent_mode_default_present_only_when_enabled(): void {
		$enabled = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => true,
			)
		);

		$js = $enabled->add_head_js( '', 'dataLayer' );
		$this->assertStringContainsString( '"googleConsentMode"', $js );
		$this->assertStringContainsString( '"analytics_storage":"denied"', $js );
		$this->assertStringContainsString( '"ad_storage":"denied"', $js );
		$this->assertStringContainsString( '"ad_user_data":"denied"', $js );
		$this->assertStringContainsString( '"ad_personalization":"denied"', $js );
		$this->assertStringContainsString( '"wait_for_update":500', $js );

		$disabled = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);
		$this->assertStringNotContainsString( 'googleConsentMode', $disabled->add_head_js( '', 'dataLayer' ) );
	}

	public function test_consent_mode_default_can_be_filtered(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_AXEPTIO_CONSENT_MODE_DEFAULT )
			->once()
			->andReturn(
				array(
					'analytics_storage' => 'granted',
					'wait_for_update'   => 1000,
				)
			);

		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => true,
			)
		);

		$js = $axeptio->add_head_js( '', 'dataLayer' );
		$this->assertStringContainsString( '"analytics_storage":"granted"', $js );
		$this->assertStringContainsString( '"wait_for_update":1000', $js );
	}

	/**
	 * T40 (#141 at the call site): the encode-failure guard is pinned at
	 * ScriptTag::json_literal() itself, but reverting THIS call to a bare
	 * wp_json_encode() left the suite green - and the settings pass through the
	 * public consent-mode filter, so a third party can hand back a value the
	 * encoder refuses (NAN is the faithful trigger; bad UTF-8 is repaired). The
	 * guard must cost the settings object only: `window.axeptioSettings = null;`
	 * with the SDK loader and the data layer bridge after it intact - never
	 * `= ;`, a SyntaxError that takes the whole head block down.
	 */
	public function test_head_js_falls_back_to_null_when_filtered_settings_cannot_be_encoded(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_AXEPTIO_CONSENT_MODE_DEFAULT )
			->once()
			->andReturn( array( 'wait_for_update' => NAN ) );

		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => true,
			)
		);

		$js = $axeptio->add_head_js( '', 'dataLayer' );

		$this->assertStringContainsString( 'window.axeptioSettings = null;', $js, 'The guard costs the settings object, not the block.' );
		$this->assertStringNotContainsString( 'axeptioSettings = ;', $js, 'An empty literal is a SyntaxError.' );
		$this->assertStringContainsString( 'https://static.axept.io/sdk.js', $js, 'The SDK loader below the assignment must survive.' );
		$this->assertStringContainsString( 'axeptio.on("cookies:complete"', $js, 'And so must the data layer bridge.' );
	}

	public function test_registers_head_js_when_enabled_with_project_id(): void {
		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);
		$axeptio->register_hooks();

		$this->assertNotFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $axeptio, 'add_head_js' ) ) );
	}

	public function test_inactive_when_disabled(): void {
		$axeptio = $this->make_axeptio( array( GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project' ) );
		$axeptio->register_hooks();

		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $axeptio, 'add_head_js' ) ) );
	}

	public function test_inactive_when_enabled_without_project_id(): void {
		$axeptio = $this->make_axeptio( array( GTM4WP_OPTION_INTEGRATE_AXEPTIO => true ) );
		$axeptio->register_hooks();

		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $axeptio, 'add_head_js' ) ) );
	}

	public function test_suppresses_consent_default_when_consent_mode_enabled(): void {
		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => true,
			)
		);
		$axeptio->register_hooks();

		$this->assertNotFalse( has_filter( ConsentDefaults::FILTER_DEFAULT_ENABLED, array( $axeptio, 'suppress_consent_default' ) ) );
		$this->assertFalse( $axeptio->suppress_consent_default() );
	}

	public function test_does_not_suppress_consent_default_when_consent_mode_disabled(): void {
		$axeptio = $this->make_axeptio(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);
		$axeptio->register_hooks();

		$this->assertFalse( has_filter( ConsentDefaults::FILTER_DEFAULT_ENABLED, array( $axeptio, 'suppress_consent_default' ) ) );
	}
}
