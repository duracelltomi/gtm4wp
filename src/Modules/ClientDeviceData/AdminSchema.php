<?php
/**
 * Client device data module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ClientDeviceData;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the client device data module.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * Documentation hub of this module on gtm4wp.com. Each of the three options
	 * has a page of its own below it.
	 */
	private const DOC_PAGE = 'use-special-3rd-party-data-in-google-tag-manager';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_PAGE;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Browser, OS & device data', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Data is collected in the browser using User-Agent Client Hints and pushed into the data layer as a gtm4wp.deviceData event shortly after page load. Browsers without Client Hints support (Safari, Firefox) provide less detail.', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'device' => __( 'Collected data', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_INCLUDE_BROWSERDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Browser data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the name and version of the browser the visitor uses.', 'duracelltomi-google-tag-manager' ),
				group: 'device',
				doc: self::DOC_PAGE . '/browser-attributes-in-the-data-layer'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_OSDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'OS data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the name and version of the operating system the visitor uses.', 'duracelltomi-google-tag-manager' ),
				group: 'device',
				doc: self::DOC_PAGE . '/operating-system-attributes-in-the-data-layer'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_DEVICEDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Device data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the type of device the user is currently using (desktop or mobile) including model data where available.', 'duracelltomi-google-tag-manager' ),
				group: 'device',
				doc: self::DOC_PAGE . '/device-attributes-in-the-data-layer'
			),
		);
	}

	/**
	 * The client device data module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
