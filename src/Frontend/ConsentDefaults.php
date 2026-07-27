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
	 * Returns the consent mode default script block. Byte-identical to the
	 * block output by gtm4wp_wp_header_begin() in 1.x.
	 *
	 * @param ScriptTag $script_tag The script tag helper.
	 * @return string
	 */
	public function script_block( ScriptTag $script_tag ): string {
		return '
' . $script_tag->opening_tag() . '
		if (typeof gtag == "undefined") {
			function gtag(){dataLayer.push(arguments);}
		}

		gtag("consent", "default", {
			"analytics_storage": "' . $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS ) . '",
			"ad_storage": "' . $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS ) . '",
			"ad_user_data": "' . $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA ) . '",
			"ad_personalization": "' . $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO ) . '",
			"functionality_storage": "' . $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC ) . '",
			"security_storage": "' . $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY ) . '",
			"personalization_storage": "' . $this->flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO ) . '",
		});
</script>';
	}
}
