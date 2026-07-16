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
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the cache-safe data layer module.
 */
final class AdminSchema implements AdminSchemaInterface {

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
				description: esc_html__( 'Enable this if your site uses full-page caching and you want to avoid leaking one visitor\'s data (IP, logged-in user email/username/role, WooCommerce customer and cart) to another visitor through the cached HTML. When on, values the browser can compute itself (referrer, search term) are pushed client-side as a gtm4wp.visitorData data layer event under the same variable names, so your Google Tag Manager tags keep working. Phase 1 tradeoff: the remaining server-only visitor fields (visitor IP, Cloudflare country, logged-in user data and the WooCommerce customer/cart blocks) are simply OMITTED while this is on — the safe default is "no data" rather than "leaked data". A later release will deliver those client-side, once per session and cookie-gated. Off by default.', 'duracelltomi-google-tag-manager' ),
				group: 'general',
				phase: Field::PHASE_EXPERIMENTAL
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
