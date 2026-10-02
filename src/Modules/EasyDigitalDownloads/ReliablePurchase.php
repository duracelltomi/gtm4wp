<?php
/**
 * Easy Digital Downloads reliable purchase tracking for the cache-safe data layer.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\EasyDigitalDownloads;

use GTM4WP\Ecommerce\Helpers as EcommerceHelpers;
use GTM4WP\Modules\VisitorData\VisitorDataEndpoint;
use GTM4WP\Modules\VisitorData\VisitorDataModule;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Options\Options;
use GTM4WP\RequestOrigin;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a purchase whose confirmation page was never reached through the
 * session endpoint (issue #398), the WooCommerce reliable-purchase one-shot
 * for EDD: EVENT_COOKIE is set when EDD builds an order, the read-only GET
 * resolves the buyer's order from their own purchase session, and the POST
 * beacon flags it tracked once the client has pushed it.
 */
final class ReliablePurchase {

	/**
	 * JS-readable one-shot event cookie; the runtime learns the name from the config.
	 */
	public const EVENT_COOKIE = 'gtm4wp_edd_event';

	/**
	 * Endpoint payload key; distinct from WooCommerce's pendingPurchase because
	 * the endpoint merges every resolver's value by key.
	 */
	public const FIELD_KEY = 'eddPendingPurchase';

	/**
	 * Confirm-delivery POST route, under VisitorDataEndpoint::REST_NAMESPACE.
	 */
	public const REST_ROUTE_CONFIRM = '/confirm-edd-purchase-tracked';

	/**
	 * Constructor.
	 *
	 * @param Options      $options       The plugin options service.
	 * @param DownloadData $download_data The download data builder.
	 */
	public function __construct(
		private Options $options,
		private DownloadData $download_data
	) {
	}

	/**
	 * Whether the fallback rides the session endpoint: cache-safe mode, EDD
	 * tracking and Reliable purchase tracking on, and the tracked flag in use
	 * (without it the event would repeat, as on the page path).
	 *
	 * @param Options $options The plugin options service.
	 * @return bool
	 */
	public static function is_enabled( Options $options ): bool {
		return VisitorDataModule::is_enabled( $options )
			&& true === $options->get( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE )
			&& (bool) $options->get( GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE )
			&& ! (bool) $options->get( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG );
	}

	/**
	 * Sets EVENT_COOKIE whenever EDD builds an order (U132). Called from
	 * Plugin::boot(): EDD's gateways build orders on admin-ajax and REST
	 * requests, where the frontend path does not boot.
	 *
	 * @param Options $options The plugin options service.
	 * @return void
	 */
	public static function register_flag_hooks( Options $options ): void {
		// The platform check sits here: the module path is gated by Registry::frontend().
		if ( ! self::is_enabled( $options ) || ! ( new EasyDigitalDownloadsModule() )->is_available() ) {
			return;
		}

		add_action( 'edd_built_order', array( new self( $options, new DownloadData( $options ) ), 'flag_event' ), 20, 0 );
	}

	/**
	 * Flags the one-shot for this browser. A request with no buyer behind it
	 * (a webhook, CLI) costs nothing: no browser keeps the cookie.
	 * Hooked to edd_built_order.
	 *
	 * @return void
	 */
	public function flag_event(): void {
		EcommerceHelpers::flag_oneshot_event( self::EVENT_COOKIE, true );
	}

	/**
	 * Declares the Tier 3 one-shot field. Declared whatever the cookie state,
	 * since the delivering fetch happens on a later page than the order.
	 * Hooked to GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS.
	 *
	 * @param array<int, VisitorField> $fields Visitor-scoped fields declared so far.
	 * @return array<int, VisitorField>
	 */
	public function declare_visitor_scoped_fields( array $fields ): array {
		if ( ! self::is_enabled( $this->options ) ) {
			return $fields;
		}

		$fields[] = new VisitorField(
			self::FIELD_KEY,
			VisitorField::TIER_ACTION,
			'',
			array( $this, 'resolve_pending_purchase' ),
			self::EVENT_COOKIE,
			true,
			rest_url( VisitorDataEndpoint::REST_NAMESPACE . self::REST_ROUTE_CONFIRM )
		);

		return $fields;
	}

	/**
	 * Endpoint resolver: the purchase payload for the caller's own order, with
	 * the order NUMBER for the shared gtm4wp_orderid_tracked guard (RI-14);
	 * `{ pending: true }` while the order may still become trackable, so the
	 * client keeps the cookie; null otherwise. No request parameter is read
	 * (PA-10) and orderData stays on the confirmation page. The session is
	 * touched only while EVENT_COOKIE is present, so an unrelated endpoint
	 * request does not pay for it.
	 *
	 * READ-ONLY: a public GET, so no flag write here; the POST beacon does it.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve_pending_purchase(): ?array {
		if ( ! self::is_enabled( $this->options ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the order comes from the server-side session.
		if ( ! isset( $_COOKIE[ self::EVENT_COOKIE ] ) ) {
			return null;
		}

		$order = $this->session_order();
		if ( null === $order ) {
			return null;
		}

		if ( ! $this->download_data->is_order_trackable( $order ) ) {
			return $this->download_data->may_become_trackable( $order ) ? array( 'pending' => true ) : null;
		}

		return array(
			'push'        => array_merge(
				$this->download_data->get_purchase_datalayer( $order ),
				$this->download_data->customer_signals( $order )
			),
			'orderNumber' => (string) $order->get_number(),
			'flag'        => true,
		);
	}

	/**
	 * Registers the confirm-delivery POST route. Hooked to rest_api_init.
	 *
	 * @return void
	 */
	public function register_confirm_route(): void {
		if ( ! self::is_enabled( $this->options ) ) {
			return;
		}

		register_rest_route(
			VisitorDataEndpoint::REST_NAMESPACE,
			self::REST_ROUTE_CONFIRM,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm_purchase_tracked' ),
				'permission_callback' => array( $this, 'check_confirm_permission' ),
			)
		);
	}

	/**
	 * Permission callback, the EDD confirm beacon's gate (FP-5): the
	 * wp_rest nonce is only a malformed-request filter, the same-origin check is
	 * the CSRF gate. Not a capability check: guests buy too.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public function check_confirm_permission( \WP_REST_Request $request ): bool {
		return RequestOrigin::is_nonced_same_origin_request( $request );
	}

	/**
	 * POST callback: flags the caller's session order tracked, only when its age
	 * and status would let the GET deliver it, so a stray POST cannot suppress a
	 * purchase nothing has reported. Reads nothing from the request (PA-10).
	 * Not gated on EVENT_COOKIE (the client clears it as the beacon leaves) nor
	 * on the gtm4wp_orderid_tracked cookie (the client may have just written it).
	 *
	 * @return \WP_REST_Response A 204 No Content response.
	 */
	public function confirm_purchase_tracked(): \WP_REST_Response {
		$order = $this->session_order();

		if (
			null !== $order
			&& ! $this->download_data->is_order_older_than_max_age( $order )
			&& $this->download_data->is_order_status_trackable( $order )
		) {
			$this->download_data->flag_order_tracked( $order );
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * The order of the buyer's own EDD purchase session (U116), or null.
	 *
	 * @return \EDD\Orders\Order|null
	 */
	private function session_order(): ?\EDD\Orders\Order {
		if ( ! function_exists( 'edd_get_purchase_session' ) || ! function_exists( 'edd_get_order_by' ) ) {
			return null;
		}

		$session = edd_get_purchase_session();
		if ( ! is_array( $session ) || empty( $session['purchase_key'] ) ) {
			return null;
		}

		$order = edd_get_order_by( 'payment_key', (string) $session['purchase_key'] );

		return $order instanceof \EDD\Orders\Order ? $order : null;
	}
}
