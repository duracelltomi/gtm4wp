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

	public function test_enabled_can_be_suppressed_by_filter(): void {
		// A CMP integration (e.g. Axeptio driving Consent Mode v2) fires the
		// consent default itself and vetoes the GTM4WP one via this filter, so
		// it is never sent twice.
		Filters\expectApplied( ConsentDefaults::FILTER_DEFAULT_ENABLED )
			->once()
			->with( true )
			->andReturn( false );

		$consent = new ConsentDefaults(
			$this->make_options( array( GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true ) )
		);

		$this->assertFalse( $consent->enabled() );
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

	public function test_flag_filter_can_grant_a_disabled_option(): void {
		// The overwrite filter works in both directions: it can also turn a
		// disabled option into "granted".
		Filters\expectApplied( GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG )
			->once()
			->with( false, GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS )
			->andReturn( true );

		$consent = new ConsentDefaults(
			$this->make_options(
				array(
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS => false,
				)
			)
		);

		$this->assertSame( 'granted', $consent->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS ) );
	}

	/**
	 * The JSON flag set every payload entry is encoded with - must stay the
	 * same expression the source uses (TC-2).
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;

	/**
	 * UD-2 pin of the U115 upstream mirror: the EEA preset is exactly 32 codes -
	 * EU-27 + Iceland/Liechtenstein/Norway + United Kingdom + Switzerland.
	 * A silent edit to the constant must fail here.
	 */
	public function test_eea_region_preset_is_pinned_to_the_32_google_shipped_codes(): void {
		$this->assertSame(
			array(
				'AT',
				'BE',
				'BG',
				'CH',
				'CY',
				'CZ',
				'DE',
				'DK',
				'EE',
				'ES',
				'FI',
				'FR',
				'GB',
				'GR',
				'HR',
				'HU',
				'IE',
				'IS',
				'IT',
				'LI',
				'LT',
				'LU',
				'LV',
				'MT',
				'NL',
				'NO',
				'PL',
				'PT',
				'RO',
				'SE',
				'SI',
				'SK',
			),
			ConsentDefaults::REGIONS_EEA_UK_CH
		);
	}

	public function test_script_block_is_byte_identical_to_1x_without_the_new_options(): void {
		// BE-1: with region scope, wait_for_update and the two set commands
		// all unset, the emitted block must stay byte-for-byte what 1.x and
		// every 2.0 build so far produced.
		$options = $this->make_options(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => true,
			)
		);

		$consent = new ConsentDefaults( $options );

		$this->assertSame(
			'
<script data-cfasync="false" data-pagespeed-no-defer>
		if (typeof gtag == "undefined") {
			function gtag(){dataLayer.push(arguments);}
		}

		gtag("consent", "default", {
			"analytics_storage": "granted",
			"ad_storage": "denied",
			"ad_user_data": "denied",
			"ad_personalization": "denied",
			"functionality_storage": "denied",
			"security_storage": "denied",
			"personalization_storage": "denied",
		});
</script>',
			$consent->script_block( new ScriptTag( $options ) )
		);
	}

	public function test_script_block_emits_the_eea_region_list_and_wait_for_update(): void {
		$options = $this->make_options(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS => ConsentDefaults::REGIONS_MODE_EEA,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_WAITFORUPDATE => 500,
			)
		);

		$consent = new ConsentDefaults( $options );
		$block   = $consent->script_block( new ScriptTag( $options ) );

		// Expected fragments built with the same encoder + flags the source
		// uses (TC-2), never hand-typed.
		$this->assertStringContainsString(
			'"region": ' . json_encode( ConsentDefaults::REGIONS_EEA_UK_CH, self::JSON_FLAGS ) . ',', // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- TC-2: the test harness aliases wp_json_encode to json_encode.
			$block
		);
		$this->assertStringContainsString( '"wait_for_update": 500,', $block );
	}

	public function test_payload_carries_no_region_when_consent_mode_is_off(): void {
		// flag() reports denied while the master switch is off; the region and
		// wait keys follow the same convention so a filter force-enabling the
		// block never emits region scoping the admin never configured.
		$consent = new ConsentDefaults(
			$this->make_options(
				array(
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE => false,
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS => ConsentDefaults::REGIONS_MODE_EEA,
					GTM4WP_OPTION_INTEGRATE_CONSENTMODE_WAITFORUPDATE => 500,
				)
			)
		);

		$payload = $consent->payload();

		$this->assertArrayNotHasKey( 'region', $payload );
		$this->assertArrayNotHasKey( 'wait_for_update', $payload );
	}

	public function test_script_block_emits_only_valid_custom_region_codes(): void {
		// PA-2: the sink re-validates the stored value against the shared
		// grammar, so a hostile string that never went through the save-side
		// sanitizer (direct DB edit, import quirk) cannot reach the script.
		$options = $this->make_options(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS => ConsentDefaults::REGIONS_MODE_CUSTOM,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS_CUSTOM => 'dk, de-by, "</script><script>alert(1)</script>, us-ca, x, GBR, 12',
			)
		);

		$consent = new ConsentDefaults( $options );
		$block   = $consent->script_block( new ScriptTag( $options ) );

		// Both directions (TS-2): the valid codes are emitted...
		$this->assertStringContainsString(
			'"region": ' . json_encode( array( 'DK', 'DE-BY', 'US-CA' ), self::JSON_FLAGS ) . ',', // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- TC-2.
			$block
		);
		// ...and no fragment of the hostile entries survives.
		$this->assertStringNotContainsString( 'alert', $block );
		$this->assertStringNotContainsString( '<script>', $block );
		$this->assertStringNotContainsString( 'GBR', $block );
	}

	public function test_sanitize_region_codes_normalizes_and_drops_invalid_entries(): void {
		$this->assertSame(
			'DK,DE-BY,US-CA',
			ConsentDefaults::sanitize_region_codes( 'dk de-by,us-ca, dk, alert(1), G, GBR' ),
			'Valid codes are uppercased and deduped; everything else is dropped.'
		);
		$this->assertSame( '', ConsentDefaults::sanitize_region_codes( '"</script>' ) );
		$this->assertSame( '', ConsentDefaults::sanitize_region_codes( array( 'DK' ) ), 'A non-scalar (settings import can produce one) sanitizes to the empty string instead of warning.' );
		$this->assertSame( '', ConsentDefaults::sanitize_region_codes( null ) );
	}

	public function test_region_code_grammar_is_anchored(): void {
		$this->assertTrue( ConsentDefaults::is_valid_region_code( 'DK' ) );
		$this->assertTrue( ConsentDefaults::is_valid_region_code( 'DE-BY' ) );
		$this->assertTrue( ConsentDefaults::is_valid_region_code( 'US-CA' ) );
		// The /D modifier: PCRE's $ would otherwise match before a trailing
		// newline and let a code smuggle one into the script block.
		$this->assertFalse( ConsentDefaults::is_valid_region_code( "DK\n" ) );
		$this->assertFalse( ConsentDefaults::is_valid_region_code( 'dk' ) );
		$this->assertFalse( ConsentDefaults::is_valid_region_code( 'GBR' ) );
		$this->assertFalse( ConsentDefaults::is_valid_region_code( 'D' ) );
		$this->assertFalse( ConsentDefaults::is_valid_region_code( 'DE-BAYERN' ) );
		$this->assertFalse( ConsentDefaults::is_valid_region_code( '' ) );
	}

	public function test_wait_for_update_is_omitted_when_zero_or_negative(): void {
		foreach ( array( 0, -100 ) as $wait ) {
			$consent = new ConsentDefaults(
				$this->make_options(
					array(
						GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
						GTM4WP_OPTION_INTEGRATE_CONSENTMODE_WAITFORUPDATE => $wait,
					)
				)
			);

			$this->assertArrayNotHasKey( 'wait_for_update', $consent->payload() );
		}
	}

	public function test_set_commands_are_emitted_before_the_default_block(): void {
		$options = $this->make_options(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADSREDACTION => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_URLPASSTHROUGH => true,
			)
		);

		$consent = new ConsentDefaults( $options );
		$block   = $consent->script_block( new ScriptTag( $options ) );

		$this->assertStringContainsString( 'gtag("set", "ads_data_redaction", true);', $block );
		$this->assertStringContainsString( 'gtag("set", "url_passthrough", true);', $block );

		// Pins OUR chosen order, not a Google requirement - the docs impose no
		// ordering between the set commands and the consent default (see the
		// reasoning in ConsentDefaults::script_block()). Asserted so the order
		// cannot drift silently, not because the other order would be invalid.
		$default_pos = strpos( $block, 'gtag("consent", "default"' );
		$this->assertNotFalse( $default_pos );
		$this->assertLessThan( $default_pos, strpos( $block, 'gtag("set", "ads_data_redaction"' ) );
		$this->assertLessThan( $default_pos, strpos( $block, 'gtag("set", "url_passthrough"' ) );
	}

	public function test_set_commands_are_absent_by_default(): void {
		$options = $this->make_options(
			array( GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true )
		);

		$consent = new ConsentDefaults( $options );

		$this->assertStringNotContainsString( 'gtag("set"', $consent->script_block( new ScriptTag( $options ) ) );
	}

	public function test_payload_filter_can_extend_the_payload_and_stays_hex_encoded(): void {
		// An integration can set its own region (or any key) without a stored
		// option - and whatever it adds still goes through the hex-flag JSON
		// encoding (RI-2), so a hostile value cannot break out of the block.
		Filters\expectApplied( GTM4WP_WPFILTER_CONSENT_DEFAULT_PAYLOAD )
			->once()
			->andReturnUsing(
				static function ( array $payload ): array {
					$payload['region'] = array( 'DK' );
					$payload['x']      = '</script>';
					return $payload;
				}
			);

		$options = $this->make_options(
			array( GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true )
		);

		$consent = new ConsentDefaults( $options );
		$block   = $consent->script_block( new ScriptTag( $options ) );

		$this->assertStringContainsString( '"region": ["DK"],', $block );
		// The hostile value appears only in its hex-encoded form (built with
		// the same encoder + flags, TC-2)...
		$this->assertStringContainsString(
			'"x": ' . json_encode( '</script>', self::JSON_FLAGS ) . ',', // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- TC-2.
			$block
		);
		// ...and the raw break-out never (the single legitimate </script> is
		// the block's own closing tag, TS-2).
		$this->assertSame( 1, substr_count( $block, '</script>' ) );
	}

	public function test_payload_filter_entry_that_cannot_be_encoded_is_omitted(): void {
		// RI-21: an unencodable value must drop its entry, never concatenate
		// a false-as-empty-string into a SyntaxError.
		Filters\expectApplied( GTM4WP_WPFILTER_CONSENT_DEFAULT_PAYLOAD )
			->once()
			->andReturnUsing(
				static function ( array $payload ): array {
					$payload['broken'] = NAN;
					return $payload;
				}
			);

		$options = $this->make_options(
			array( GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true )
		);

		$consent = new ConsentDefaults( $options );
		$block   = $consent->script_block( new ScriptTag( $options ) );

		$this->assertStringNotContainsString( 'broken', $block );
		$this->assertStringNotContainsString( ': ,', $block );
		$this->assertStringContainsString( '"analytics_storage": "denied",', $block, 'The encodable siblings must survive.' );
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
