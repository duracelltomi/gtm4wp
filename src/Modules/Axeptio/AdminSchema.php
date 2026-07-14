<?php
/**
 * Axeptio integration module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Axeptio;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the Axeptio integration, ported from the 1.x
 * "Axeptio" sub-tab of the Integration tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Axeptio', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Load the Axeptio consent management platform directly through GTM4WP — no separate Axeptio plugin required.', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'axeptio' => __( 'Axeptio', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_INTEGRATE_AXEPTIO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Enable Axeptio', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Enable this to let GTM4WP load the Axeptio CMP SDK directly. No separate Axeptio plugin is required. Enter your Axeptio project ID and cookies version below.',
					'duracelltomi-google-tag-manager'
				),
				group: 'axeptio'
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
				group: 'axeptio'
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
				group: 'axeptio'
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
				group: 'axeptio'
			),
		);
	}

	/**
	 * The Axeptio integration is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
