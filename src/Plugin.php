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

		// Personal-data requests run from wp-admin, cron and WP-CLI, so this sits
		// outside both branches below, and it is registered whatever the capture
		// setting says: a request must find data captured while the feature was on.
		( new Modules\GoogleDataManager\PrivacyData() )->register_hooks();

		// The refund send lane runs from Action Scheduler / WP-Cron, so it sits
		// outside both branches too; gated so an unused feature costs nothing.
		if ( $this->options->get( GTM4WP_OPTION_GDM_SEND_REFUNDS ) ) {
			$this->boot_refund_lane();
		}

		// REST routes must exist on REST requests, where is_admin() is false.
		// RestCors is registered here unconditionally on purpose: it withdraws
		// core's reflected cross-origin grant for the WHOLE gtm4wp/v2 namespace,
		// and registering it from a module tied that to a feature flag (#97).
		add_action(
			'rest_api_init',
			function () {
				RestCors::register();

				( new Admin\RestController( $this->registry ) )->register_routes();

				// Cheap to register (no option read until a route runs), so no gate.
				$vault     = new Google\KeyVault();
				$transport = new Google\WpTransport();
				$tokens    = new Google\TokenService( $vault, $transport );
				( new Modules\GoogleAuth\RestController( $vault, $tokens ) )->register_routes();

				// The destinations test route reuses the token service and
				// transport so the endpoint and scope keep single definitions.
				( new Modules\GoogleDataManager\RestController(
					$vault,
					new Modules\GoogleDataManager\EventsIngest( $tokens, $transport ),
					new Modules\GoogleDataManager\SendLog(),
					$this->options,
					new Modules\GoogleDataManager\DestinationHealth()
				) )->register_routes();

				// Guest-facing, so it exists only while capture is on. Gated on the
				// option alone: the callback resolves the platform per request.
				if ( $this->options->get( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION ) ) {
					( new Modules\GoogleDataManager\BackfillEndpoint() )->register_routes();
				}

				// A service account a destination row references must not be
				// deletable; the veto is wired next to the routes it protects.
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
	 * Returns the module registry, or null before boot() has run (a caller
	 * earlier than plugins_loaded gets a testable value, not a TypeError).
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
	 * Registers the server-side refund send lane and its status polling. Both
	 * commerce adapters are wired unconditionally and decide for themselves
	 * whether their platform is active (the WC/EDD parity rule).
	 *
	 * @return void
	 */
	private function boot_refund_lane(): void {
		if ( null === $this->options ) {
			return;
		}

		$ingest = new Modules\GoogleDataManager\EventsIngest(
			new Google\TokenService( new Google\KeyVault(), new Google\WpTransport() ),
			new Google\WpTransport()
		);
		$log    = new Modules\GoogleDataManager\SendLog();

		( new Modules\GoogleDataManager\RefundSender(
			$this->options,
			$ingest,
			new Modules\GoogleDataManager\DestinationHealth(),
			$log,
			array(
				new Modules\GoogleDataManager\WooCommerceRefunds( $this->options ),
				new Modules\GoogleDataManager\EddRefunds( $this->options ),
			)
		) )->register_hooks();

		( new Modules\GoogleDataManager\StatusPoller( $ingest, $log ) )->register_hooks();
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
