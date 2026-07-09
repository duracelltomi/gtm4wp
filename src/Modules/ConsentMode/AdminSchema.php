<?php
/**
 * Consent mode module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ConsentMode;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the consent module, ported from the 1.x Integration tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Consent mode & consent tools', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Configure Google consent mode default values and integrations with consent management tools.', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'consent-mode'  => __( 'Google Consent Mode', 'duracelltomi-google-tag-manager' ),
			'consent-tools' => __( 'Consent management tools', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		return array(
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Google Consent Mode', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: opening anchor tag linking to Google's documentation about the consent mode command. 2: Closing anchor tag. */
					esc_html__(
						'Enable this checkbox if you wish to execute the "default" command of %1$sGoogle Consent Mode%2$s before the container loads. The "update" command needs to be executed from your consent management tool. DO NOT enable this feature if your consent manager tool supports firing both the "default" and the "update" command.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://developers.google.com/tag-platform/gtagjs/reference#consent" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Analytics Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the analytics_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Ad Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the ad_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Ad User Data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the ad_user_data flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Ad Personalization', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the ad_personalization flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Functionality Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the functionality_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Personalization Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the personalization_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Security Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the security_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_COOKIEBOT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Cookiebot auto blocking', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: opening anchor tag linking to Cookiebot's documentation about the automatic cookie blocking feature. 2: Closing anchor tag. */
					esc_html__(
						'Enable this checkbox if you wish to use the %1$sautomatic cookie blocking mode of Cookiebot with Google Tag Manager%2$s.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://support.cookiebot.com/hc/en-us/articles/360009192739-Google-Tag-Manager-and-Automatic-cookie-blocking" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'consent-tools'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'WebToffee GDPR Cookie Consent (v2.x)', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Enabling this feature will fire a GTM event (cookie_consent_update) when the consent banner has been closed with consents being set or during pageload when previously set consents have been found. You do not need to use this integration with v3.x or above since it includes all the necessary codes to integrate the consent banner with Google Tag Manager.',
					'duracelltomi-google-tag-manager'
				),
				group: 'consent-tools'
			),
		);
	}

	/**
	 * The consent module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
