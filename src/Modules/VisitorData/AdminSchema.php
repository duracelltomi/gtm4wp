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

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the cache-safe data layer module.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

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
				description: esc_html__( 'Enable this if your site uses full-page caching and you want to avoid leaking one visitor\'s data (IP, logged-in user email/username/role, WooCommerce customer and cart) to another visitor through the cached HTML. When on, these visitor/session values are kept out of the cached HTML and delivered in the browser instead, under the same data layer variable names, so your Google Tag Manager tags keep working: the referrer and search term are computed by the browser itself; the visitor IP, Cloudflare country and logged-in user data come from a first-party endpoint that returns only the current visitor\'s own data (the IP/country fetched once per session, the user data only when the login state changes, so anonymous visitors never fetch it); and the WooCommerce customer and cart ride WooCommerce\'s own cart-refresh request, adding no extra page request. Because the values now arrive after the page view, point the tags that read them at the matching Custom Event trigger: gtm4wp.visitorData for the visitor and logged-in user variables, gtm4wp.customerData for the WooCommerce customer variables and gtm4wp.cartData for the cart content. Each of the three fires only when its own data changed, and only when you have the option that produces it enabled. The two WooCommerce one-shot events (an add_to_cart after the cart "Undo" and the reliable-purchase fallback) are delivered client-side too, each fired exactly once. Off by default.', 'duracelltomi-google-tag-manager' ),
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
}
