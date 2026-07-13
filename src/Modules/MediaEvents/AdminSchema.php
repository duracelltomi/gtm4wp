<?php
/**
 * Media events module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\MediaEvents;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the media events module, ported from the 1.x Events tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Media events', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Fire tags in Google Tag Manager when visitors interact with embedded media players on your website.', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'players' => __( 'Media players', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_EVENTS_YOUTUBE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'YouTube video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a YouTube video embeded on your site.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_VIMEO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Vimeo video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Vimeo video embeded on your site.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_SOUNDCLOUD,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Soundcloud events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Soundcloud media embeded on your site.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_HTML5MEDIA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'HTML5 video and audio events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a native HTML5 <video> or <audio> player embeded on your site.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL
			),
		);
	}

	/**
	 * The media events module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
