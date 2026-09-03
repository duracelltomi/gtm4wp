<?php
/**
 * Data Manager destinations REST controller.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Google\KeyVault;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * REST route of the destinations panel under gtm4wp/v2/google:
 *
 * - POST destinations/test  probe one destination with a validateOnly ingest
 *
 * The route takes the destination's values from the request rather than a
 * stored row index, so the settings screen can test an edit before saving
 * it. Every value is validated against the same DestinationRows rules the
 * save-time sanitizer enforces before anything leaves the site, and the only
 * URL ever contacted is the fixed ingest endpoint. Requires the settings
 * capability and the REST nonce; the response reports success or Google's
 * error summary, never a token.
 */
final class RestController {

	public const REST_ROUTE = '/google/destinations/test';

	/**
	 * Constructor.
	 *
	 * @param KeyVault     $vault  The key store, to name an unknown account before any request.
	 * @param EventsIngest $ingest The validateOnly probe.
	 */
	public function __construct( private KeyVault $vault, private EventsIngest $ingest ) {
	}

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			RestCors::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_destination' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					DestinationRows::COLUMN_ACCOUNT     => array(
						'type'     => 'string',
						'required' => true,
					),
					DestinationRows::COLUMN_TYPE        => array(
						'type'     => 'string',
						'required' => true,
					),
					DestinationRows::COLUMN_PROPERTY    => array(
						'type'     => 'string',
						'required' => true,
					),
					DestinationRows::COLUMN_MEASUREMENT => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission check, using the same capability filter as the settings page.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		/** This filter is documented in src/Plugin.php */
		return current_user_can( apply_filters( 'gtm4wp_admin_page_capability', 'manage_options' ) );
	}

	/**
	 * POST handler: validates the submitted destination and sends the
	 * validateOnly probe.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_destination( \WP_REST_Request $request ) {
		$row = DestinationRows::normalize_row(
			array(
				DestinationRows::COLUMN_ACCOUNT     => $request->get_param( DestinationRows::COLUMN_ACCOUNT ),
				DestinationRows::COLUMN_TYPE        => $request->get_param( DestinationRows::COLUMN_TYPE ),
				DestinationRows::COLUMN_PROPERTY    => $request->get_param( DestinationRows::COLUMN_PROPERTY ),
				DestinationRows::COLUMN_MEASUREMENT => $request->get_param( DestinationRows::COLUMN_MEASUREMENT ),
			)
		);

		if ( ! DestinationRows::is_valid_row( $row ) ) {
			return new \WP_Error(
				'gtm4wp_gdm_destination_invalid',
				__( 'This destination is not complete or not valid. Pick a service account, and enter the numeric GA4 property ID and the G-XXXXXXX measurement ID of its web data stream.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->vault->has( $row[ DestinationRows::COLUMN_ACCOUNT ] ) ) {
			return new \WP_Error(
				'gtm4wp_google_account_unknown',
				__( 'This service account no longer exists.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->ingest->validate_destination( $row );
		$ok     = ! ( $result instanceof \WP_Error );

		return new \WP_REST_Response(
			array(
				'ok'      => $ok,
				'message' => $ok
					? __( 'Google accepted a test request for this destination.', 'duracelltomi-google-tag-manager' )
					: $result->get_error_message(),
			)
		);
	}
}
