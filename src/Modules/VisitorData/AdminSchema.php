<?php
/**
 * Cache-safe data layer module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\VisitorData;

use GTM4WP\Admin\SiteHealthRows;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Options\Field;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the cache-safe data layer module.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface, SiteHealthInfoInterface {

	/**
	 * Documentation page of this module on gtm4wp.com. The cache-safe mode is
	 * documented through the triggers it makes necessary, which is what a reader
	 * arriving from this option needs next.
	 */
	private const DOC_PAGE = 'setup-gtm4wp-features/how-to-setup-triggers-for-visitor-device-customer-and-cart-data';

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
		return __( 'Cache-safe data layer', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'On sites with full-page caching (LiteSpeed, WP Rocket, Varnish, Cloudflare APO), the HTML built for one visitor is served to everyone. Any visitor-specific value baked into the data layer would then leak to other visitors. This mode keeps those values out of the cached HTML.', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'general' => __( 'Cache-safe data layer', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_CACHE_SAFE_DATALAYER,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Enable cache-safe data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Keeps visitor-specific values (IP, logged-in user, store customer and cart) out of cached HTML and delivers them in the browser under the same variable names. Fire the tags that read them on the gtm4wp.visitorData, gtm4wp.customerData and gtm4wp.cartData events.', 'duracelltomi-google-tag-manager' ),
				group: 'general',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_PAGE
			),
		);
	}

	/**
	 * The cache-safe data layer module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Options $options The plugin options service.
	 * @return array<string, array<string, mixed>>
	 */
	public function site_health_info( Options $options ): array {
		return array(
			'cache_safe_datalayer' => SiteHealthRows::on_off(
				__( 'Cache-safe data layer', 'duracelltomi-google-tag-manager' ),
				(bool) $options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER )
			),
		);
	}
}
