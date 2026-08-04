<?php
/**
 * Plugin core.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP;

use GTM4WP\Compat\Globals;
use GTM4WP\Frontend\Frontend;
use GTM4WP\Module\Registry;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Boot orchestration: builds the module registry and the options service,
 * then routes between the admin and frontend code paths so that no admin
 * code is ever loaded on frontend requests and vice versa.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The module registry.
	 *
	 * @var Registry|null
	 */
	private ?Registry $registry = null;

	/**
	 * The plugin options service.
	 *
	 * @var Options|null
	 */
	private ?Options $options = null;

	/**
	 * The frontend orchestrator, only set on frontend requests.
	 *
	 * @var Frontend|null
	 */
	private ?Frontend $frontend = null;

	/**
	 * Returns the singleton plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boots the plugin on the plugins_loaded hook.
	 *
	 * Mirrors the 1.x split: admin code only loads for users who can see
	 * the settings page, frontend code only loads outside wp-admin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->registry = Registry::with_default_modules();
		$this->options  = new Options( $this->registry->defaults() );

		Globals::populate( $this->options );

		// The settings REST endpoint must be reachable on REST requests where
		// is_admin() is false; the controller class only loads when a REST
		// request actually initializes.
		//
		// RestCors is registered in the same place and with no condition on
		// purpose: it withdraws core's reflected cross-origin grant for the WHOLE
		// gtm4wp/v2 namespace, and the namespace exists on every install because
		// of the settings routes right above. Registering it from a module (as it
		// was) tied a namespace-wide control to that module's feature flag (#97).
		add_action(
			'rest_api_init',
			function () {
				RestCors::register();

				( new Admin\RestController( $this->registry ) )->register_routes();
			}
		);

		if ( is_admin() ) {
			/**
			 * Filters the capability needed to see and manage the GTM4WP settings page.
			 *
			 * @since 1.20
			 *
			 * @param string $capability The required capability. Default 'manage_options'.
			 */
			if ( current_user_can( apply_filters( 'gtm4wp_admin_page_capability', 'manage_options' ) ) ) {
				$this->boot_admin();
			}

			return;
		}

		$this->boot_frontend();
	}

	/**
	 * Returns the module registry, or null before boot() has run.
	 *
	 * Nullable like frontend() below: both are built in boot(), so a caller that
	 * runs earlier than plugins_loaded would otherwise get a TypeError from the
	 * return type instead of a value it can test.
	 *
	 * @return Registry|null
	 */
	public function registry(): ?Registry {
		return $this->registry;
	}

	/**
	 * Returns the options service, or null before boot() has run.
	 *
	 * @return Options|null
	 */
	public function options(): ?Options {
		return $this->options;
	}

	/**
	 * Boots the admin code path.
	 *
	 * @return void
	 */
	private function boot_admin(): void {
		Migration::maybe_run();

		( new Admin\Admin( $this->registry, $this->options ) )->boot();
	}

	/**
	 * Returns the frontend orchestrator. Only available on frontend requests.
	 *
	 * @return Frontend|null
	 */
	public function frontend(): ?Frontend {
		return $this->frontend;
	}

	/**
	 * Boots the frontend code path.
	 *
	 * @return void
	 */
	private function boot_frontend(): void {
		$this->frontend = new Frontend( $this->options, $this->registry );
		$this->frontend->boot();
	}
}
