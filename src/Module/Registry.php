<?php
/**
 * Module registry.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Module;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Holds all feature modules of the plugin.
 *
 * Third party plugins can register their own modules through the
 * 'gtm4wp_register_modules' action which receives this registry instance;
 * registered modules automatically appear in the admin navigation and
 * take part in option default merging.
 */
final class Registry {

	/**
	 * Registered modules, keyed by module id, in registration order.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Class names of the modules shipped with the plugin, in admin
	 * navigation order.
	 *
	 * @var string[]
	 */
	private const BUILTIN_MODULES = array(
		\GTM4WP\Modules\Container\ContainerModule::class,
		\GTM4WP\Modules\PageVariables\PageVariablesModule::class,
		\GTM4WP\Modules\ClientDeviceData\ClientDeviceDataModule::class,
		\GTM4WP\Modules\UserEvents\UserEventsModule::class,
		\GTM4WP\Modules\MediaEvents\MediaEventsModule::class,
		\GTM4WP\Modules\ScrollTracking\ScrollTrackingModule::class,
		\GTM4WP\Modules\ConsentMode\ConsentModeModule::class,
		\GTM4WP\Modules\ContactForm7\ContactForm7Module::class,
		\GTM4WP\Modules\WooCommerce\WooCommerceModule::class,
		\GTM4WP\Modules\Amp\AmpModule::class,
		\GTM4WP\Modules\Blacklist\BlacklistModule::class,
	);

	/**
	 * Creates a registry with all built-in modules and applies the
	 * 'gtm4wp_register_modules' extension point.
	 *
	 * @return self
	 */
	public static function with_default_modules(): self {
		$registry = new self();

		foreach ( self::BUILTIN_MODULES as $module_class ) {
			$registry->add( new $module_class() );
		}

		/**
		 * Fires after the built-in modules have been registered.
		 *
		 * Third party plugins can add their own modules here:
		 * add_action( 'gtm4wp_register_modules', fn( $registry ) => $registry->add( new My_Module() ) );
		 *
		 * @since 2.0.0
		 *
		 * @param Registry $registry The module registry.
		 */
		do_action( 'gtm4wp_register_modules', $registry );

		return $registry;
	}

	/**
	 * Registers a module. A module registered with an already known id
	 * replaces the earlier one.
	 *
	 * @param ModuleInterface $module The module to register.
	 * @return void
	 */
	public function add( ModuleInterface $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/**
	 * Returns all registered modules keyed by id.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Returns a single module by id.
	 *
	 * @param string $id Module id.
	 * @return ModuleInterface|null
	 */
	public function get( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Merges the option defaults of all registered modules.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		$defaults = array();

		foreach ( $this->modules as $module ) {
			$defaults = array_merge( $defaults, $module->defaults() );
		}

		return $defaults;
	}

	/**
	 * Boots the frontend part of every available module.
	 *
	 * @param Options $options The plugin options service.
	 * @return void
	 */
	public function frontend( Options $options ): void {
		foreach ( $this->modules as $module ) {
			if ( $module->is_available() ) {
				$module->frontend( $options );
			}
		}
	}
}
