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

use GTM4WP\Capability;
use GTM4WP\Module\AbilitiesInterface;
use GTM4WP\Module\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the plugin's ability category and abilities into the Abilities API
 * (WordPress 6.9+): discovery for AI assistants and other clients through
 * wp-abilities/v1, or as MCP tools with the MCP Adapter. Wired from
 * Plugin::boot() before the admin/frontend split, since an ability runs on
 * REST, MCP and WP-CLI requests where neither branch is taken; below 6.9 the
 * two actions never fire, so no version check (U155).
 *
 * Providers register under the one `gtm4wp` category: the plugin-wide ones
 * named here, plus one per module whose admin schema opts in through
 * Module\AbilitiesInterface (the Admin\SiteHealthInfo collector shape, so a
 * third-party module lands under the same category and switches). Every
 * ability is gated on the settings capability;
 * GTM4WP_WPFILTER_ABILITIES_ENABLED switches the surface off,
 * GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE keeps it read-only. That switch is
 * enforced at both ends - a write is registered only while writes_allowed(),
 * and re-checks it at run time through write_disabled_error() - which
 * ContractTest and RegistrarTest pin.
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
	 * The permission callback of every write: the write switch (a filter that
	 * flipped since registration still denies), then the settings capability.
	 *
	 * Returns a bool, never a WP_Error: core discards a WP_Error from a
	 * permission callback (generic denial plus _doing_it_wrong(), U155), so the
	 * named 403 comes from the write's execute callback instead. No parameter
	 * declared, like Capability::can_manage_settings().
	 *
	 * @return bool
	 */
	public static function can_write(): bool {
		return self::writes_allowed() && Capability::can_manage_settings();
	}

	/**
	 * The refusal a write ability returns when it runs while writes are
	 * switched off: one definition, so every write refuses with the same
	 * stable code and status.
	 *
	 * @return \WP_Error
	 */
	public static function write_disabled_error(): \WP_Error {
		return new \WP_Error(
			'gtm4wp_abilities_write_disabled',
			__( 'Changing settings through the Abilities API is switched off on this site (gtm4wp_abilities_allow_write).', 'duracelltomi-google-tag-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * The refusal of a `confirm` ability called without `confirm: true`: the
	 * site-wide operations want the user's yes in the call itself, so an
	 * assistant that skipped the protocol in the description cannot run them by
	 * accident. One definition, so every such ability refuses alike.
	 *
	 * @return \WP_Error
	 */
	public static function confirmation_required_error(): \WP_Error {
		return new \WP_Error(
			'gtm4wp_confirmation_required',
			__( 'This call needs confirm: true. Show the user what it will do, ask for an explicit yes, and repeat the call with confirm set to true.', 'duracelltomi-google-tag-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The input schema of an ability that takes no input (#253). The
	 * empty-object default is what core applies when a client sends nothing, so
	 * a bare call validates instead of failing on null input (U155).
	 *
	 * No `properties` key on purpose: an empty PHP array serialises as `[]`, and
	 * core's REST route leaves it alone, publishing an invalid schema fragment.
	 * The absent key validates like an empty one and `additionalProperties`
	 * still refuses every input key (U155). ContractTest pins the shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function no_input_schema(): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'additionalProperties' => false,
		);
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
	 * Registers every provider's abilities: the plugin-wide ones first, then
	 * each module's, in registry order.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! self::is_enabled() || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		( new StatusAbilities( $this->registry ) )->register();
		( new SettingsAbilities( $this->registry ) )->register();

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();

			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$schema = new $schema_class();

			// instanceof, not method_exists(): a third party schema predating
			// the interface stays valid and simply contributes nothing.
			if ( ! $schema instanceof AbilitiesInterface ) {
				continue;
			}

			$schema->abilities()->register();
		}
	}
}
