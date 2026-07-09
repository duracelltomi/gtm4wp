<?php
/**
 * Contact Form 7 module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ContactForm7;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the Contact Form 7 module, ported from the 1.x
 * Integration tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Contact Form 7', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return '';
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'cf7' => __( 'Contact Form 7', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_INTEGRATE_WPCF7,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Contact Form 7', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this to fire dataLayer events after Contact Form 7 submissions (supported events: invalid input, spam detected, form submitted, form submitted and mail sent, form submitted and mail send failed).', 'duracelltomi-google-tag-manager' ),
				group: 'cf7'
			),
		);
	}

	/**
	 * The Contact Form 7 module is always available; the tracker script is
	 * simply inactive without the CF7 plugin.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
