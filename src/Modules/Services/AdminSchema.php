<?php
/**
 * Services for agencies and freelancers module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Services;

use GTM4WP\Admin\Docs;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A section with no fields: the intro repeats the first paragraph of the
 * services page and links to it.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * The services page on gtm4wp.com, resolved through Docs like every help link (U112).
	 */
	public const PAGE = 'gtm4wp-services';

	/**
	 * Services page, also linked from the panel header.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::PAGE;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Services for agencies and freelancers', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * The first paragraph of the services page and a link to it.
	 *
	 * @return string
	 */
	public function intro(): string {
		return sprintf(
			'%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s<span class="screen-reader-text"> %4$s</span></a>',
			esc_html__( 'Many agencies and freelancers use GTM4WP to build tracking for their clients. When a project needs more than the documentation can offer, you can bring in the people who develop the plugin. We work alongside you, not in place of you: you keep the client relationship, and we help you deliver.', 'duracelltomi-google-tag-manager' ),
			esc_url( Docs::url( self::PAGE ) ),
			esc_html__( 'Discover the services we offer to agencies and freelancers.', 'duracelltomi-google-tag-manager' ),
			esc_html__( '(opens in a new tab)', 'duracelltomi-google-tag-manager' )
		);
	}

	/**
	 * No accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array();
	}

	/**
	 * No fields.
	 *
	 * @return \GTM4WP\Options\Field[]
	 */
	public function fields(): array {
		return array();
	}

	/**
	 * Always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
