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
 * REST routes of the destinations panel under gtm4wp/v2/google:
 *
 * - POST destinations/test  probe one destination with a validateOnly ingest
 * - GET  send-log           read the diagnostics ring of the send lanes
 *
 * The test route takes the destination's values from the request rather than a
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
	 * Read-only view of the send diagnostics ring.
	 */
	public const LOG_ROUTE = '/google/send-log';

	/**
	 * Constructor.
	 *
	 * @param KeyVault     $vault  The key store, to name an unknown account before any request.
	 * @param EventsIngest $ingest The validateOnly probe.
	 * @param SendLog      $log    The diagnostics ring.
	 */
	public function __construct( private KeyVault $vault, private EventsIngest $ingest, private SendLog $log ) {
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

		register_rest_route(
			RestCors::REST_NAMESPACE,
			self::LOG_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'send_log' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * GET handler: the diagnostics ring, newest entry first.
	 *
	 * The ring is returned as stored. What may be in it is decided where it is
	 * written (SendLog): statuses, counts, reason codes and the store's own
	 * order and refund ids - never a token, key material or a response body
	 * from Google.
	 *
	 * The one added field is `tone`, derived from the entry rather than stored
	 * with it: how much attention the row deserves, decided next to Google's
	 * status vocabulary instead of in the admin bundle.
	 *
	 * @return \WP_REST_Response
	 */
	public function send_log(): \WP_REST_Response {
		$entries = array();

		foreach ( array_reverse( $this->log->all() ) as $entry ) {
			$entry['tone'] = SendLog::tone( $entry );
			$entries[]     = $entry;
		}

		return new \WP_REST_Response(
			array(
				'entries' => $entries,
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

		$problems = self::row_problems( $row );
		if ( array() !== $problems ) {
			return new \WP_Error(
				'gtm4wp_gdm_destination_invalid',
				implode( ' ', $problems ),
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

	/**
	 * Every reason a submitted destination cannot be probed, one sentence per
	 * failing field so the panel can say which cell to fix - the lump "not
	 * complete or not valid" wording answered every mistake identically.
	 *
	 * The rules are DestinationRows' own (the single is_valid_row() predicate
	 * uses the same patterns); only the wording lives here, because
	 * DestinationRows is loaded on frontend requests and must stay free of
	 * translated strings.
	 *
	 * @param array<string, string> $row Normalized row.
	 * @return string[] Problem sentences; empty when the row is valid.
	 */
	private static function row_problems( array $row ): array {
		$problems = array();

		if ( 1 !== preg_match( DestinationRows::ACCOUNT_PATTERN, $row[ DestinationRows::COLUMN_ACCOUNT ] ?? '' ) ) {
			$problems[] = __( 'Pick the service account the destination sends with.', 'duracelltomi-google-tag-manager' );
		}

		if ( ! in_array( $row[ DestinationRows::COLUMN_TYPE ] ?? '', DestinationRows::TYPES, true ) ) {
			$problems[] = __( 'Unknown destination type.', 'duracelltomi-google-tag-manager' );
		}

		if ( 1 !== preg_match( DestinationRows::PROPERTY_PATTERN, $row[ DestinationRows::COLUMN_PROPERTY ] ?? '' ) ) {
			$problems[] = __( 'The GA4 property ID must be the all-numeric ID shown in the Google Analytics admin - not the G-XXXXXXX measurement ID.', 'duracelltomi-google-tag-manager' );
		}

		if ( 1 !== preg_match( DestinationRows::MEASUREMENT_PATTERN, $row[ DestinationRows::COLUMN_MEASUREMENT ] ?? '' ) ) {
			$problems[] = __( 'The measurement ID must have the format G-XXXXXXX, as shown for the web data stream in the Google Analytics admin.', 'duracelltomi-google-tag-manager' );
		}

		return $problems;
	}
}
