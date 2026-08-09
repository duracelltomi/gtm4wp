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
				description: esc_html__( 'Check this to fire dataLayer events for Contact Form 7 submissions (supported events: before submit, invalid input, unaccepted terms, spam detected, submission aborted, form submitted, mail sent, mail send failed). Each event carries the form ID, unit tag, container post ID, locale and submission status.', 'duracelltomi-google-tag-manager' ),
				group: 'cf7'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WPCF7_INPUTS,
				type: Field::TYPE_SELECT,
				default_value: 'full',
				label: __( 'Submitted field values in the data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Controls how submitted Contact Form 7 field values are exposed in the data layer. Submitted values may contain personal data; whether it is forwarded to analytics or advertising tools is the responsibility of the tag/container manager. Choose "Field names only" or "None" to keep personal data out of the data layer.', 'duracelltomi-google-tag-manager' ),
				group: 'cf7',
				choices: array(
					'full'  => __( 'Full: field names and submitted values (default)', 'duracelltomi-google-tag-manager' ),
					'names' => __( 'Field names only (no values)', 'duracelltomi-google-tag-manager' ),
					'none'  => __( 'None: do not include submitted fields', 'duracelltomi-google-tag-manager' ),
				)
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WPCF7_GA4EVENTS,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Also push GA4 recommended events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'In addition to the gtm4wp.contactForm7* events, push Google Analytics 4 recommended form events: form_start (on first field interaction), form_submit (on submit) and generate_lead (on a successful send). Each carries form_id, form_name and form_destination. Like GA4 Enhanced Measurement, form_submit fires on every submission attempt, including one Contact Form 7 rejects, so form_submit and generate_lead also carry form_status with the Contact Form 7 status (mail_sent, validation_failed, spam and so on) to tell the two apart. Useful because GA4 Enhanced Measurement does not reliably capture Contact Form 7 AJAX submissions on its own.', 'duracelltomi-google-tag-manager' ),
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
