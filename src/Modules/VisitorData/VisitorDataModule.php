<?php
/**
 * Cache-safe data layer module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\VisitorData;

use GTM4WP\Module\AbstractModule;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Master switch for the cache-safe data layer (issue #398).
 *
 * On full-page-cached sites the HTML built for one visitor is served to every
 * visitor, so any visitor/session specific value baked into the data layer HTML
 * leaks (a logged-in editor's page cached with their email, then served to
 * anonymous visitors). When this module's option is on, other modules stop
 * rendering those values into the cacheable HTML and instead the browser pushes
 * what it can compute itself as a gtm4wp.visitorData data layer event — no
 * network request, no leak (Phase 1). Server-only visitor fields (IP, country,
 * user, cart) are omitted in Phase 1 and delivered client-side in Phase 2 via a
 * once-per-session / cookie-gated endpoint (see docs/dev/cache-safe-data-layer.md).
 *
 * Mirrors the ClientDeviceData module pattern: a small client script pushes the
 * values shortly after page load with the same data layer variable names 1.x /
 * the server path used, so existing Google Tag Manager setups keep working.
 */
final class VisitorDataModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'visitor-data';
	}

	/**
	 * Whether the cache-safe data layer is enabled for the given options.
	 *
	 * Shared read used by the modules that must omit their server-rendered
	 * visitor/session fields when the mode is on (PageVariables, WooCommerce).
	 *
	 * @param Options $options The plugin options service.
	 * @return bool
	 */
	public static function is_enabled( Options $options ): bool {
		return (bool) $options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );
	}

	/**
	 * Option defaults. Off by default: the mode is experimental and changes
	 * (omits) visitor data, so it must never turn on without the admin opting in.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false,
		);
	}

	/**
	 * Registers the frontend hooks. Nothing loads unless the mode is on.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( ! self::is_enabled( $this->options ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}

	/**
	 * Loads the client-side visitor-data runtime with its per-request field
	 * config, but only when at least one Tier 1 field is active on this request.
	 * The config carries only cache-safe (content/URL-derived, not visitor)
	 * information — which data layer keys to compute and from which browser
	 * source — so it is safe to bake into cached HTML.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$client_fields = $this->collect_client_fields();

		if ( array() === $client_fields ) {
			return;
		}

		$this->enqueue_script( 'gtm4wp-visitor-data', 'gtm4wp-visitor-data.js' );

		$config = array(
			'event'  => 'gtm4wp.visitorData',
			'fields' => $client_fields,
		);

		wp_add_inline_script(
			'gtm4wp-visitor-data',
			'var gtm4wp_visitordata_config = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
			'before'
		);
	}

	/**
	 * Builds the data-layer-key => client-source map for the Tier 1 fields that
	 * every module declares (through GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS) as
	 * active on the current request.
	 *
	 * @return array<string, string>
	 */
	private function collect_client_fields(): array {
		/**
		 * Collects the visitor-scoped fields to deliver outside the cacheable
		 * page HTML. Callbacks append VisitorField objects.
		 *
		 * @since 2.0
		 *
		 * @param VisitorField[] $fields Visitor-scoped fields declared so far.
		 *
		 * @return VisitorField[] Visitor-scoped fields for this request.
		 */
		$fields = apply_filters( GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS, array() );

		$client_fields = array();

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if (
					$field instanceof VisitorField
					&& VisitorField::TIER_CLIENT === $field->tier
					&& '' !== $field->client_source
				) {
					$client_fields[ $field->key ] = $field->client_source;
				}
			}
		}

		return $client_fields;
	}
}
