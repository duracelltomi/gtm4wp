<?php
/**
 * AMP module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Amp;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the AMP module, ported from the 1.x Integration tab.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * Documentation page of this module on gtm4wp.com.
	 */
	private const DOC_PAGE = 'setup-gtm4wp-features/amp-support';

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
		return __( 'AMP', 'duracelltomi-google-tag-manager' );
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
			'amp' => __( 'AMP', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_INTEGRATE_AMPID,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( "Google Tag Manager 'AMP' Container ID", 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: opening anchor tag for a link pointing to the official GTM help center article about the AMP container snippet 2: Closing anchor tag. */
					esc_html__(
						'Enter a comma separated list of Google Tag Manager container IDs that you would like to use on your site. This plugin will add the %1$sAMP GTM snippet%2$s to your AMP pages.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://support.google.com/tagmanager/answer/6103696?hl=en" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'amp',
				sanitizer: static function ( $value ) {
					// Field::to_string() keeps the cast warning-free on non-scalar
					// import values (a custom sanitizer replaces the type-defensive
					// default in Field::sanitize(), it does not run in front of it).
					$value = trim( Field::to_string( $value ) );

					if ( '' === $value ) {
						return '';
					}

					foreach ( explode( ',', $value ) as $one_amp_id ) {
						if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_amp_id ) ) {
							return new \WP_Error(
								'gtm4wp_invalid_amp_id',
								__( 'Invalid AMP Google Tag Manager Container ID. Valid ID format: GTM-XXXXX. Use comma without additional space (,) to enter more than one ID.', 'duracelltomi-google-tag-manager' )
							);
						}
					}

					return $value;
				},
				doc: self::DOC_PAGE
			),
		);
	}

	/**
	 * The AMP module is always available; it only acts on AMP pages.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
