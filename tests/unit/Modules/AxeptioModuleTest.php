<?php
/**
 * Unit tests for the Axeptio integration module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\ConsentDefaults;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Modules\Axeptio\AxeptioModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Frontend\FrontendTestCase;

/**
 * Covers the Axeptio head block (SDK loader, consent default, data layer
 * bridge) and the hook gating.
 *
 * The security-relevant assertion is the RI-2 hex-flag regression: the
 * window.axeptioSettings object is written into an inline script that the head
 * block prints WITHOUT the ampersand-restore, so every break-out character in
 * the (admin-set) project ID / cookies version must be hex-encoded. These tests
 * fail if the flags on that wp_json_encode() are dropped. Break-out characters
 * are written with \xNN escapes and the expected fragments are computed with
 * json_encode() using the same flags the source uses (TC-2).
 */
final class AxeptioModuleTest extends FrontendTestCase {

	private const HEX_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;

	/**
	 * Boots an Axeptio module with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return AxeptioModule
	 */
	private function boot( array $stored ): AxeptioModule {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( $stored );

		$module = new AxeptioModule();
		$module->frontend( new Options( $module->defaults() ) );

		return $module;
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

		$module = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => $project_id,
			)
		);

		$js = $module->add_axeptio_head_js( '', 'dataLayer' );

		// Present: the hex-encoded form as the source's wp_json_encode() emits it.
		$this->assertStringContainsString( $this->encoded_fragment( $project_id ), $js );

		// Absent: the raw break-out payload that would appear if a flag were dropped.
		$this->assertStringNotContainsString( $project_id, $js );
		$this->assertStringNotContainsString( '</script>', $js );
	}

	public function test_head_js_includes_sdk_loader_and_datalayer_bridge(): void {
		$module = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);

		$js = $module->add_axeptio_head_js( '', 'customDL' );

		$this->assertStringContainsString( 'window.axeptioSettings = ', $js );
		$this->assertStringContainsString( '"clientId":"my-project"', $js );
		$this->assertStringContainsString( 'https://static.axept.io/sdk.js', $js );
		$this->assertStringContainsString( 'axeptio.on("cookies:complete"', $js );
		$this->assertStringContainsString( '"event": "gtm4wp.axeptioConsentUpdate"', $js );
		// The data layer bridge honors the configured data layer variable name.
		$this->assertStringContainsString( 'window.customDL = window.customDL || [];', $js );
	}

	public function test_head_js_preserves_accumulated_inline_js(): void {
		$module = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);

		$js = $module->add_axeptio_head_js( '/* earlier */', 'dataLayer' );

		$this->assertStringStartsWith( '/* earlier */', $js );
	}

	public function test_cookies_version_included_only_when_set(): void {
		$with_version = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION => 'my-project-v3',
			)
		);
		$this->assertStringContainsString( '"cookiesVersion":"my-project-v3"', $with_version->add_axeptio_head_js( '', 'dataLayer' ) );

		$without_version = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);
		$this->assertStringNotContainsString( 'cookiesVersion', $without_version->add_axeptio_head_js( '', 'dataLayer' ) );
	}

	public function test_consent_mode_default_present_only_when_enabled(): void {
		$enabled = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => true,
			)
		);

		$js = $enabled->add_axeptio_head_js( '', 'dataLayer' );
		$this->assertStringContainsString( '"googleConsentMode"', $js );
		$this->assertStringContainsString( '"analytics_storage":"denied"', $js );
		$this->assertStringContainsString( '"ad_storage":"denied"', $js );
		$this->assertStringContainsString( '"ad_user_data":"denied"', $js );
		$this->assertStringContainsString( '"ad_personalization":"denied"', $js );
		$this->assertStringContainsString( '"wait_for_update":500', $js );

		$disabled = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);
		$this->assertStringNotContainsString( 'googleConsentMode', $disabled->add_axeptio_head_js( '', 'dataLayer' ) );
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

		$module = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => true,
			)
		);

		$js = $module->add_axeptio_head_js( '', 'dataLayer' );
		$this->assertStringContainsString( '"analytics_storage":"granted"', $js );
		$this->assertStringContainsString( '"wait_for_update":1000', $js );
	}

	public function test_registers_head_js_when_enabled_with_project_id(): void {
		$module = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);

		$this->assertNotFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $module, 'add_axeptio_head_js' ) ) );
	}

	public function test_inactive_when_disabled(): void {
		$module = $this->boot( array( GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project' ) );

		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $module, 'add_axeptio_head_js' ) ) );
	}

	public function test_inactive_when_enabled_without_project_id(): void {
		$module = $this->boot( array( GTM4WP_OPTION_INTEGRATE_AXEPTIO => true ) );

		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $module, 'add_axeptio_head_js' ) ) );
	}

	public function test_suppresses_consent_default_when_consent_mode_enabled(): void {
		$module = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => true,
			)
		);

		$this->assertNotFalse( has_filter( ConsentDefaults::FILTER_DEFAULT_ENABLED, array( $module, 'suppress_consent_default' ) ) );
		$this->assertFalse( $module->suppress_consent_default() );
	}

	public function test_does_not_suppress_consent_default_when_consent_mode_disabled(): void {
		$module = $this->boot(
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);

		$this->assertFalse( has_filter( ConsentDefaults::FILTER_DEFAULT_ENABLED, array( $module, 'suppress_consent_default' ) ) );
	}
}
