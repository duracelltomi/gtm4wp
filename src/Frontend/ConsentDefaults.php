<?php
/**
 * Google consent mode default state.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Frontend;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the consent mode default block before the GTM container code.
 *
 * Port of gtm4wp_get_consent_mode_flag() and the consent block of
 * gtm4wp_wp_header_begin() from 1.x (public/frontend.php).
 */
final class ConsentDefaults {

	/**
	 * Internal filter that lets a CMP integration suppress the GTM4WP consent
	 * mode default block when the CMP fires the "default" command itself (so
	 * it is not sent twice). The Axeptio module returns false here when it
	 * drives Google Consent Mode v2.
	 *
	 * @since 2.0.0
	 */
	public const FILTER_DEFAULT_ENABLED = 'gtm4wp_consent_mode_default_enabled';

	/**
	 * Region preset values of the GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS
	 * option. An empty string means no region key: the defaults apply worldwide,
	 * which is the 1.x-compatible behavior.
	 */
	public const REGIONS_MODE_EEA    = 'eea';
	public const REGIONS_MODE_CUSTOM = 'custom';

	/**
	 * The region codes the EEA preset scopes the consent defaults to:
	 * the EU-27 plus Iceland, Liechtenstein and Norway (= the EEA), plus the
	 * United Kingdom and Switzerland. 32 codes.
	 *
	 * Upstream mirror (UD-1): Google documents that `region` takes ISO 3166-2
	 * codes but publishes no official EEA list, so this membership is
	 * corroborated against several independent implementations rather than a
	 * single page. Registered in .upstream/upstream-review-checklist.md, which
	 * points at the local research notes for the corroborating sources; the
	 * exact membership is pinned by a unit test so a silent edit here fails
	 * loudly.
	 */
	public const REGIONS_EEA_UK_CH = array(
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
	);

	/**
	 * Grammar of one region code: an ISO 3166-1 alpha-2 country code with an
	 * optional ISO 3166-2 subdivision suffix (DK, DE-BY, US-CA) - the format
	 * Google documents for the consent default region parameter.
	 *
	 * The single definition shared by the save-side sanitizer
	 * (sanitize_region_codes()) and the output sink (regions()), per PA-2: the
	 * value is re-validated where it is emitted, never trusted just because it
	 * was stored. The /D modifier keeps `$` from matching before a trailing
	 * newline.
	 */
	private const REGION_CODE_PATTERN = '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/D';

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Whether consent mode default output is enabled.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		$enabled = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE );

		/**
		 * Filters whether GTM4WP outputs its own Google Consent Mode "default"
		 * command. A CMP integration that fires the consent default itself
		 * (e.g. Axeptio driving Consent Mode v2) returns false here so the
		 * default is not sent twice.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $enabled Whether the consent default block is output.
		 */
		return (bool) apply_filters( self::FILTER_DEFAULT_ENABLED, $enabled );
	}

	/**
	 * Returns the value of a consent mode flag.
	 *
	 * @param string $flag The flag to be read, one of the GTM4WP_OPTION_INTEGRATE_CONSENTMODE_* option keys.
	 * @return string The value of the flag (granted or denied).
	 */
	public function flag( string $flag ): string {
		$flag_value = false;

		if ( in_array(
			$flag,
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY,
			),
			true
		) ) {
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE ) ) {
				$flag_value = (bool) $this->options->get( $flag );

				/**
				 * Filter to overwrite the value of the consent mode flag.
				 * Should use boolean true or false. Returned value will be converted to
				 * string "granted" or "denied" afterwards.
				 *
				 * @since 1.22
				 *
				 * @param boolean $flag_value The value of the flag (boolean true or false).
				 * @param string $flag The flag to be set.
				 *
				 * @return boolean The updated value of the flag (boolean true or false).
				 */
				$flag_value = apply_filters( GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG, $flag_value, $flag );
			}
		}

		return ( $flag_value ? 'granted' : 'denied' );
	}

	/**
	 * Validates one region code against the shared grammar.
	 *
	 * @param string $code A candidate region code (already uppercased/trimmed).
	 * @return bool
	 */
	public static function is_valid_region_code( string $code ): bool {
		return 1 === preg_match( self::REGION_CODE_PATTERN, $code );
	}

	/**
	 * Save-time sanitizer of the custom region code list: accepts a comma (or
	 * whitespace) separated string, uppercases each entry, drops everything
	 * that is not a valid region code, dedupes, and stores the survivors as a
	 * normalized comma separated string.
	 *
	 * Type-defensive on purpose: a custom Field sanitizer runs before the
	 * shared type guard, so it can receive a non-scalar from the settings
	 * import route.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Normalized comma separated region codes.
	 */
	public static function sanitize_region_codes( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$codes = preg_split( '/[\s,]+/', strtoupper( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $codes ) {
			return '';
		}

		$codes = array_values( array_unique( array_filter( $codes, array( self::class, 'is_valid_region_code' ) ) ) );

		return implode( ',', $codes );
	}

	/**
	 * Resolves the region list the consent defaults are scoped to, or an empty
	 * array for the worldwide (1.x-compatible) default.
	 *
	 * The EEA preset resolves from the code constant, never from a stored
	 * copy, so it cannot go stale in the database. Custom codes are
	 * re-validated here against the same grammar the save side uses (PA-2) -
	 * only codes matching the pattern can reach the script sink.
	 *
	 * @return string[]
	 */
	public function regions(): array {
		$mode = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS );

		if ( self::REGIONS_MODE_EEA === $mode ) {
			return self::REGIONS_EEA_UK_CH;
		}

		if ( self::REGIONS_MODE_CUSTOM === $mode ) {
			$stored = $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS_CUSTOM );

			if ( ! is_scalar( $stored ) ) {
				return array();
			}

			$codes = preg_split( '/[\s,]+/', strtoupper( (string) $stored ), -1, PREG_SPLIT_NO_EMPTY );

			if ( false === $codes ) {
				return array();
			}

			return array_values( array_unique( array_filter( $codes, array( self::class, 'is_valid_region_code' ) ) ) );
		}

		return array();
	}

	/**
	 * Builds the payload of the consent "default" command: the seven consent
	 * signals plus the optional region and wait_for_update keys, passed
	 * through the public whole-payload filter.
	 *
	 * The region and wait_for_update keys are added conditionally so an unset
	 * option emits nothing rather than an empty key.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		$payload = array(
			'analytics_storage'       => $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS ),
			'ad_storage'              => $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS ),
			'ad_user_data'            => $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA ),
			'ad_personalization'      => $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO ),
			'functionality_storage'   => $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC ),
			'security_storage'        => $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY ),
			'personalization_storage' => $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO ),
		);

		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE ) ) {
			$regions = $this->regions();

			if ( array() !== $regions ) {
				$payload['region'] = $regions;
			}

			$wait_for_update = $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_WAITFORUPDATE );
			$wait_for_update = is_scalar( $wait_for_update ) ? (int) $wait_for_update : 0;

			if ( $wait_for_update > 0 ) {
				$payload['wait_for_update'] = $wait_for_update;
			}
		}

		/**
		 * Filters the whole Google Consent Mode "default" command payload right
		 * before it is written into the consent default script block. Runs
		 * after the per-flag GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG filter, so an
		 * integration can e.g. set its own region list without a stored option.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $payload The consent default payload.
		 */
		return (array) apply_filters( GTM4WP_WPFILTER_CONSENT_DEFAULT_PAYLOAD, $payload );
	}

	/**
	 * Returns the consent mode default script block. With none of the region /
	 * wait_for_update / gtag("set", ...) options configured the emitted block
	 * is byte-identical to the block output by gtm4wp_wp_header_begin() in 1.x.
	 *
	 * @param ScriptTag $script_tag The script tag helper.
	 * @return string
	 */
	public function script_block( ScriptTag $script_tag ): string {
		$payload_lines = '';

		foreach ( $this->payload() as $key => $value ) {
			// Every key and value is JSON-encoded with the full hex flag set
			// (RI-2); an unencodable entry is omitted rather than concatenated
			// as '' into a SyntaxError (RI-21).
			$encoded_key   = wp_json_encode( (string) $key, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );
			$encoded_value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

			if ( false === $encoded_key || false === $encoded_value ) {
				continue;
			}

			$payload_lines .= '
			' . $encoded_key . ': ' . $encoded_value . ',';
		}

		// The two "set" commands are emitted after the "consent default"
		// command, and only when their option is on, so the default output
		// stays byte-identical to 1.x.
		//
		// Placing them after is OUR choice, not a documented requirement:
		// Google states no ordering between these and the consent default
		// (verified 2026-08-16). The one placement rule it does give is that
		// url_passthrough must precede any `config` commands, which this
		// plugin never emits - it loads a GTM container, not gtag configs.
		// Implementations in the wild are split - some emit these before the
		// default command, some after - so do not "correct" this toward
		// whichever one you read last. Keeping the default block first keeps
		// the one command that MUST precede tag loading at the top.
		//
		// ads_data_redaction is a static `true` rather than something that
		// tracks consent updates because Google scopes the redaction itself:
		// "To further redact your ads data when ad_storage is denied, set
		// ads_data_redaction to true." It is therefore inert once ad_storage
		// is granted, and re-emitting it per update (as WebToffee does) buys
		// nothing here.
		$set_commands = '';

		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE ) ) {
			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADSREDACTION ) ) {
				$set_commands .= '
		gtag("set", "ads_data_redaction", true);';
			}

			if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_URLPASSTHROUGH ) ) {
				$set_commands .= '
		gtag("set", "url_passthrough", true);';
			}
		}

		return '
' . $script_tag->opening_tag() . '
		if (typeof gtag == "undefined") {
			function gtag(){dataLayer.push(arguments);}
		}

		gtag("consent", "default", {' . $payload_lines . '
		});' . $set_commands . '
</script>';
	}
}
