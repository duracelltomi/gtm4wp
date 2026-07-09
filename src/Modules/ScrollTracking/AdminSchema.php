<?php
/**
 * Scroll tracking module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ScrollTracking;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the scroll tracking module, ported from the 1.x
 * Scroll tracking tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Scroll tracking', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Fire tags based on how the visitor scrolls through your page.', 'duracelltomi-google-tag-manager' ) . '<br />' . sprintf(
			/* translators: 1: opening anchor tag that points to the corresponding Analytics Talks blog post. 2: closing anchor tag. */
			esc_html__(
				'Based on the script originaly posted to %1$sAnalytics Talk%2$s',
				'duracelltomi-google-tag-manager'
			),
			'<a href="http://cutroni.com/blog/2012/02/21/advanced-content-tracking-with-google-analytics-part-1/" target="_blank" rel="noopener">',
			'</a>'
		);
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'scroller' => __( 'Scroll tracking', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_SCROLLER_ENABLED,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Enabled', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable scroll tracker script on your website.', 'duracelltomi-google-tag-manager' ),
				group: 'scroller'
			),
			new Field(
				key: GTM4WP_OPTION_SCROLLER_DEBUGMODE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Debug mode', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Fire console.log() commands instead of dataLayer events.', 'duracelltomi-google-tag-manager' ),
				group: 'scroller'
			),
			new Field(
				key: GTM4WP_OPTION_SCROLLER_CALLBACKTIME,
				type: Field::TYPE_INTEGER,
				default_value: 100,
				label: __( 'Time delay before location check', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter the number of milliseconds after the script checks the current location. It prevents too many events being fired while scrolling.', 'duracelltomi-google-tag-manager' ),
				group: 'scroller'
			),
			new Field(
				key: GTM4WP_OPTION_SCROLLER_DISTANCE,
				type: Field::TYPE_INTEGER,
				default_value: 150,
				label: __( 'Minimum distance', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'The minimum amount of pixels that a visitor has to scroll before we treat the move as scrolling.', 'duracelltomi-google-tag-manager' ),
				group: 'scroller'
			),
			new Field(
				key: GTM4WP_OPTION_SCROLLER_CONTENTID,
				type: Field::TYPE_TEXT,
				default_value: 'content',
				label: __( 'Content ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter the DOM ID of the content element in your template. Leave it empty for default(content). Do not include the # sign.', 'duracelltomi-google-tag-manager' ),
				group: 'scroller',
				sanitizer: static function ( $value ) {
					return trim( str_replace( '#', '', (string) $value ) );
				}
			),
			new Field(
				key: GTM4WP_OPTION_SCROLLER_READERTIME,
				type: Field::TYPE_INTEGER,
				default_value: 60,
				label: __( 'Scroller time', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter the number of seconds after the the scroller user is being treated as a reader, someone who really reads the content, not just scrolls through it.', 'duracelltomi-google-tag-manager' ),
				group: 'scroller'
			),
		);
	}

	/**
	 * The scroll tracking module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
