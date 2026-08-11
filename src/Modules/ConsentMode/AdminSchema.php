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
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the consent module, ported from the 1.x Integration tab.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * Documentation hub of this module on gtm4wp.com and the per-tool setup
	 * guides below it - one page per accordion group, which is how the settings
	 * and the documentation happen to line up here.
	 */
	private const DOC_BASE      = 'setup-gtm4wp-features/consent-mode-and-consent-tools';
	private const DOC_CONSENT   = self::DOC_BASE . '/how-to-setup-google-consent-mode';
	private const DOC_COOKIEBOT = self::DOC_BASE . '/cookiebot-gtm4wp-how-to-setup';
	private const DOC_COOKIEYES = self::DOC_BASE . '/cookieyes-gtm4wp-how-to-setup';
	private const DOC_AXEPTIO   = self::DOC_BASE . '/axeptio-gtm4wp-how-to-setup';

	/**
	 * The WebToffee guide predates the consent hub and still lives beside it
	 * rather than under it.
	 */
	private const DOC_WEBTOFFEE = 'setup-gtm4wp-features/webtoffee-gdpr-cookie-consent-plugin-gtm4wp-how-to-setup';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_BASE;
	}

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
			'consent-mode' => __( 'Google Consent Mode', 'duracelltomi-google-tag-manager' ),
			'cookiebot'    => __( 'Cookiebot', 'duracelltomi-google-tag-manager' ),
			'webtoffee'    => __( 'WebToffee GDPR Cookie Consent', 'duracelltomi-google-tag-manager' ),
			'cookieyes'    => __( 'CookieYes', 'duracelltomi-google-tag-manager' ),
			'axeptio'      => __( 'Axeptio', 'duracelltomi-google-tag-manager' ),
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
				group: 'consent-mode',
				doc: self::DOC_CONSENT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Analytics Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the analytics_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode',
				doc: self::DOC_CONSENT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Ad Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the ad_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode',
				doc: self::DOC_CONSENT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Ad User Data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the ad_user_data flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode',
				doc: self::DOC_CONSENT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Ad Personalization', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the ad_personalization flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode',
				doc: self::DOC_CONSENT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Functionality Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the functionality_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode',
				doc: self::DOC_CONSENT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Personalization Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the personalization_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode',
				doc: self::DOC_CONSENT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Security Storage', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select this checkbox to make the security_storage flag "granted" by default.', 'duracelltomi-google-tag-manager' ),
				group: 'consent-mode',
				doc: self::DOC_CONSENT
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
				group: 'cookiebot',
				doc: self::DOC_COOKIEBOT
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'WebToffee GDPR Cookie Consent (v2.x)', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Enabling this feature will fire a GTM event (cookie_consent_update) when the consent banner has been closed with consents being set or during pageload when previously set consents have been found. Deprecated: this integration only targets the long-outdated WebToffee GDPR Cookie Consent v2.x product line. You do not need it with v3.x or above, which ships all the necessary code to integrate the consent banner with Google Tag Manager natively - please upgrade the WebToffee plugin instead of using this option.',
					'duracelltomi-google-tag-manager'
				),
				group: 'webtoffee',
				phase: Field::PHASE_DEPRECATED,
				doc: self::DOC_WEBTOFFEE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_COOKIEYES,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'CookieYes consent bridge', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: opening anchor tag linking to CookieYes' consent banner action API documentation. 2: Closing anchor tag. */
					esc_html__(
						'Enable this to push a GTM data layer event (cookie_consent_update) whenever CookieYes reports a consent choice. It listens for the %1$sCookieYes consent banner action API%2$s events (cookieyes_consent_update and cookieyes_banner_load) and forwards the accepted/rejected categories to the data layer, giving your container a defined consent signal to sequence tags on. It does not defer any events - rely on Google Consent Mode v2 to gate tags regardless of push order.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://www.cookieyes.com/documentation/consent-banner-action-api/" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'cookieyes',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_COOKIEYES
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_AXEPTIO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Enable Axeptio', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Enable this to let GTM4WP load the Axeptio CMP SDK directly. No separate Axeptio plugin is required. Enter your Axeptio project ID and cookies version below.',
					'duracelltomi-google-tag-manager'
				),
				group: 'axeptio',
				doc: self::DOC_AXEPTIO
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Axeptio Project ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Your Axeptio project identifier, passed to the SDK as the clientId. You can find it in your Axeptio dashboard.',
					'duracelltomi-google-tag-manager'
				),
				group: 'axeptio',
				doc: self::DOC_AXEPTIO
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION,
				type: Field::TYPE_AXEPTIO_VERSION,
				default_value: '',
				label: __( 'Cookies version', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'The cookies version (cookiesVersion) loaded by the SDK. The list is fetched automatically from your Axeptio project once a valid Project ID is set above.',
					'duracelltomi-google-tag-manager'
				),
				group: 'axeptio',
				doc: self::DOC_AXEPTIO
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Enable Google Consent Mode v2', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'When enabled, Axeptio drives Google Consent Mode v2: it fires both the "default" (everything denied) and the "update" commands using its certified vendor mapping. Use this instead of the plugin\'s own "Google Consent Mode" feature, and do not enable both. A dedicated dataLayer event (gtm4wp.axeptioConsentUpdate) is also pushed whenever the visitor updates their choices.',
					'duracelltomi-google-tag-manager'
				),
				group: 'axeptio',
				doc: self::DOC_AXEPTIO
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
