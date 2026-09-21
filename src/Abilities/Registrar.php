<?php
/**
 * Registers the plugin's abilities with the WordPress Abilities API.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Abilities;

use GTM4WP\Module\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the plugin's ability category and abilities into the Abilities API
 * (WordPress 6.9+): a standard way for AI assistants and other clients to
 * discover what the plugin can do, through the wp-abilities/v1 REST routes
 * or, with the WordPress MCP Adapter installed, as MCP tools.
 *
 * Wired from Plugin::boot() on every request, before the admin/frontend
 * split: an ability runs on REST, MCP and WP-CLI requests, where neither
 * branch is taken. Registering is two add_action() calls; the registry itself
 * is built lazily by core the first time something asks for abilities, and
 * only then do the providers run. Below WordPress 6.9 the two actions never
 * fire, so nothing here needs a version check (U155).
 *
 * Every ability is gated on the settings capability and registered under the
 * one `gtm4wp` category, which third-party modules may register into as well.
 * Two filters let a site opt out: GTM4WP_WPFILTER_ABILITIES_ENABLED switches
 * the whole surface off, GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE keeps the
 * read-only abilities and withholds the ones that change anything.
 */
final class Registrar {

	/**
	 * The ability category every plugin ability belongs to.
	 */
	public const CATEGORY = 'gtm4wp';

	/**
	 * The ability name prefix: the namespace half of `gtm4wp/<verb>-<noun>`.
	 */
	public const NAMESPACE_PREFIX = 'gtm4wp/';

	/**
	 * Core's action for registering ability categories.
	 */
	public const HOOK_CATEGORIES = 'wp_abilities_api_categories_init';

	/**
	 * Core's action for registering abilities.
	 */
	public const HOOK_ABILITIES = 'wp_abilities_api_init';

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 */
	public function __construct( private Registry $registry ) {
	}

	/**
	 * Hangs the category and ability registration on core's actions.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_CATEGORIES, array( $this, 'register_category' ) );
		add_action( self::HOOK_ABILITIES, array( $this, 'register_abilities' ) );
	}

	/**
	 * Whether the plugin registers any ability at all.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Filters whether the plugin registers its abilities with the
		 * WordPress Abilities API. Return false to hide the whole surface from
		 * every client, the MCP Adapter included.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( GTM4WP_WPFILTER_ABILITIES_ENABLED, true );
	}

	/**
	 * Whether the abilities that change settings or send data are offered.
	 *
	 * Checked at registration, so a client never sees a write it may not
	 * perform, and again by each write's permission callback, so a filter
	 * that changes its answer between the two still denies.
	 *
	 * @return bool
	 */
	public static function writes_allowed(): bool {
		/**
		 * Filters whether the plugin's abilities may change anything. Return
		 * false to keep the surface read-only: the abilities that read status,
		 * settings and logs stay, the ones that change settings or contact
		 * Google are not registered and refuse to run.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $allowed Default true.
		 */
		return (bool) apply_filters( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE, true );
	}

	/**
	 * Registers the plugin's ability category. Categories must exist before
	 * the abilities that name them, which is why core fires this action first.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! self::is_enabled() || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Google Tag Manager for WordPress', 'duracelltomi-google-tag-manager' ),
				'description' => __( 'Read the configuration and diagnostics of Google Tag Manager for WordPress, explain its settings, and manage its Google integrations.', 'duracelltomi-google-tag-manager' ),
			)
		);
	}

	/**
	 * Registers every provider's abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! self::is_enabled() || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->providers() as $provider ) {
			$provider->register();
		}
	}

	/**
	 * The providers, one per feature area.
	 *
	 * @return ProviderInterface[]
	 */
	private function providers(): array {
		return array(
			new StatusAbilities( $this->registry ),
			new SettingsAbilities( $this->registry ),
			new DataManagerAbilities(),
		);
	}
}
