<?php
/**
 * Unit tests for the ConsentDefaults service.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Filters;
use GTM4WP\Frontend\ConsentDefaults;
use GTM4WP\Frontend\ScriptTag;

/**
 * Ports the behavioral contract of gtm4wp_get_consent_mode_flag() and the
 * consent default block of gtm4wp_wp_header_begin() from 1.x.
 */
final class ConsentDefaultsTest extends FrontendTestCase {

	public function test_flag_denied_when_consent_mode_disabled(): void {
		$consent = new ConsentDefaults(
			$this->make_options(
				array(
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE => false,
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => true,
				)
			)
		);

		$this->assertFalse( $consent->enabled() );
		$this->assertSame( 'denied', $consent->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS ) );
	}

	public function test_flag_granted_when_enabled_and_option_on(): void {
		$consent = new ConsentDefaults(
			$this->make_options(
				array(
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => true,
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS => false,
				)
			)
		);

		$this->assertTrue( $consent->enabled() );
		$this->assertSame( 'granted', $consent->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS ) );
		$this->assertSame( 'denied', $consent->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS ) );
	}

	public function test_unknown_flag_is_always_denied(): void {
		$consent = new ConsentDefaults(
			$this->make_options( array( GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true ) )
		);

		$this->assertSame( 'denied', $consent->flag( 'not-a-consent-flag' ) );
	}

	public function test_flag_can_be_overwritten_by_filter(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG )
			->once()
			->with( true, GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS )
			->andReturn( false );

		$consent = new ConsentDefaults(
			$this->make_options(
				array(
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => true,
				)
			)
		);

		$this->assertSame( 'denied', $consent->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS ) );
	}

	public function test_script_block_contains_all_seven_signals(): void {
		$options = $this->make_options(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE      => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC => true,
			)
		);

		$consent = new ConsentDefaults( $options );
		$block   = $consent->script_block( new ScriptTag( $options ) );

		$this->assertStringContainsString( 'gtag("consent", "default", {', $block );
		$this->assertStringContainsString( '"analytics_storage": "granted"', $block );
		$this->assertStringContainsString( '"ad_storage": "denied"', $block );
		$this->assertStringContainsString( '"ad_user_data": "denied"', $block );
		$this->assertStringContainsString( '"ad_personalization": "denied"', $block );
		$this->assertStringContainsString( '"functionality_storage": "granted"', $block );
		$this->assertStringContainsString( '"security_storage": "denied"', $block );
		$this->assertStringContainsString( '"personalization_storage": "denied"', $block );
		$this->assertStringContainsString( 'function gtag(){dataLayer.push(arguments);}', $block );
	}
}
