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
 * Two kinds of provider register under the one `gtm4wp` category: the
 * plugin-wide ones named here (status and settings, which belong to no
 * module, like Admin\RestController), and one per module whose admin schema
 * opts in through Module\AbilitiesInterface - the collector-plus-opt-in
 * shape of Admin\SiteHealthInfo, so a third-party module registered through
 * 'gtm4wp_register_modules' contributes under the same category and the
 * same switches. Every ability is gated on the settings capability. Two
 * filters let a site opt out: GTM4WP_WPFILTER_ABILITIES_ENABLED switches the
 * whole surface off, GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE keeps the
 * read-only abilities and withholds the ones that change anything. The
 * write switch is enforced at both ends: a provider registers a write only
 * while writes_allowed() says so, and every write names can_write() as its
 * permission callback and refuses with write_disabled_error() when run
 * after the switch flipped. ContractTest pins both for every write in the
 * catalogue.
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
	 * The permission callback of every ability that changes something: the
	 * write switch first (a filter that flipped since registration still
	 * denies), then the same settings capability as the reads.
	 *
	 * Returns a bool and never a WP_Error on purpose: core discards a WP_Error
	 * from a permission callback (it becomes a generic permission denial plus
	 * a _doing_it_wrong() notice, U155), so the named refusal a client can act
	 * on - gtm4wp_abilities_write_disabled, 403 - is issued by the write's
	 * execute callback, which re-checks writes_allowed() itself. No parameter
	 * declared, like Capability::can_manage_settings(): core hands the input
	 * over and the decision never depends on it.
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
	 * The refusal an ability with a `confirm` input returns when the call
	 * does not carry `confirm: true`: the site-wide operations (a settings
	 * import, a refund replay) ask for the user's explicit yes in the call
	 * itself, so an assistant that skipped the confirmation protocol in the
	 * description still cannot run them by accident. One definition, so the
	 * code and the wording are the same for every such ability.
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
	 * The input schema of an ability that takes no input, shared by every
	 * provider (#253). The empty-object default is what core applies when a
	 * client sends nothing at all, so a bare call validates instead of failing
	 * on a null input (U155).
	 *
	 * No `properties` key on purpose: an empty PHP array serialises as `[]`,
	 * and while core's REST route turns the empty `default` into `{}` it leaves
	 * `properties` alone, so the published schema would carry an invalid
	 * fragment (JSON Schema wants an object there). Core validates the absent
	 * key the same way it validates an empty one, and `additionalProperties`
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
