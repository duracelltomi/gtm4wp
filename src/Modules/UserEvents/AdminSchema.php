<?php
/**
 * User events module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\UserEvents;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the user events module, ported from the 1.x Events tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'User events', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Fire tags in Google Tag Manager on special events on your website', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'events' => __( 'Events', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_EVENTS_FORMMOVE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Form fill events (gtm4wp.formElementEnter & gtm4wp.formElementLeave)', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor moves between elements of a form (comment, contact, etc).', 'duracelltomi-google-tag-manager' ),
				group: 'events'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_NEWUSERREG,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'New user registration', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a new user registration has been completed on the frontend of your site (admin events not included)', 'duracelltomi-google-tag-manager' ),
				group: 'events'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_USERLOGIN,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'User logged in', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when an existing user has been logged in on the frontend of your site (admin events not included)', 'duracelltomi-google-tag-manager' ),
				group: 'events'
			),
		);
	}

	/**
	 * The user events module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
