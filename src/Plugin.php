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

		// Personal-data requests run from wp-admin, from cron and from WP-CLI,
		// so this cannot sit in either the admin or the frontend branch below.
		// It is registered whatever the capture setting says: a request has to
		// find data captured while the feature was on, including after it has
		// been turned off again.
		( new Modules\GoogleDataManager\PrivacyData() )->register_hooks();

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

				// The service-accounts routes are registered here for the same
				// reason as the settings routes: they must exist on REST requests,
				// where no admin code path is taken. Registering them is cheap (no
				// option read until a route actually runs), so there is no gate.
				$vault     = new Google\KeyVault();
				$transport = new Google\WpTransport();
				$tokens    = new Google\TokenService( $vault, $transport );
				( new Modules\GoogleAuth\RestController( $vault, $tokens ) )->register_routes();

				// The destinations test route rides the same registration; its
				// probe reuses the token service and transport above, so the
				// endpoint and scope keep their single definitions.
				( new Modules\GoogleDataManager\RestController(
					$vault,
					new Modules\GoogleDataManager\EventsIngest( $tokens, $transport )
				) )->register_routes();

				// The attribution backfill is guest-facing, so it registers only
				// while capture is on: an endpoint nobody needs should not
				// exist. It is gated on the option alone, not on a commerce
				// platform - the callback resolves the order through whichever
				// platform the request names and refuses when that platform is
				// absent.
				if ( $this->options->get( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION ) ) {
					( new Modules\GoogleDataManager\BackfillEndpoint() )->register_routes();
				}

				// A service account a destination row still references must not
				// be deletable: the delete route runs over REST, so the veto is
				// wired here, next to the routes it protects.
				add_filter(
					GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE,
					fn ( $in_use, $account_id ) => ( true === $in_use )
						|| Modules\GoogleDataManager\DestinationRows::references_account( $this->options, (string) $account_id ),
					10,
					2
				);
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
