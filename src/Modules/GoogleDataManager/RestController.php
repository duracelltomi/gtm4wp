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

use GTM4WP\Capability;
use GTM4WP\Google\KeyVault;
use GTM4WP\Options\Options;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes of the destinations panel under gtm4wp/v2/google:
 *
 * - POST destinations/test  probe one destination with a validateOnly ingest
 * - GET  send-log           read the diagnostics ring of the send lanes
 * - POST send-log/replay    queue failed or fixable refunds again
 *
 * A thin adapter: the probe lives in DestinationProbe, the replay in
 * RefundReplay and the log shaping in SendLog, each shared with the
 * module's abilities so the panel and an assistant do the same thing. The
 * test route takes the destination's values from the request so an edit
 * can be tested before saving; every value passes the DestinationRows rules
 * first, and the only URL contacted is the fixed ingest endpoint. Requires the
 * settings capability and the REST nonce; the response never carries a token.
 */
final class RestController {

	public const REST_ROUTE = '/google/destinations/test';

	/**
	 * Read-only view of the send diagnostics ring.
	 */
	public const LOG_ROUTE = '/google/send-log';

	/**
	 * Route that queues failed or fixable-skipped refunds again.
	 */
	public const REPLAY_ROUTE = '/google/send-log/replay';

	/**
	 * The probe behind the test route.
	 *
	 * @var DestinationProbe
	 */
	private DestinationProbe $probe;

	/**
	 * The replay behind the replay route.
	 *
	 * @var RefundReplay
	 */
	private RefundReplay $replay;

	/**
	 * Constructor.
	 *
	 * @param KeyVault               $vault   The key store, to name an unknown account before any request.
	 * @param EventsIngest           $ingest  The validateOnly probe.
	 * @param SendLog                $log     The diagnostics ring.
	 * @param Options|null           $options Plugin options; null skips the sending-on check of the replay route (tests).
	 * @param DestinationHealth|null $health  Per-destination health, cleared by a passing probe; null builds one on demand.
	 */
	public function __construct(
		KeyVault $vault,
		EventsIngest $ingest,
		private SendLog $log,
		?Options $options = null,
		?DestinationHealth $health = null
	) {
		$this->probe  = new DestinationProbe( $vault, $ingest, $health );
		$this->replay = new RefundReplay( $log, $options );
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

		register_rest_route(
			RestCors::REST_NAMESPACE,
			self::REPLAY_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'replay' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'references' => array(
						'type'     => 'array',
						'required' => false,
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * POST handler: queues every replayable refund again, or only the named
	 * ones, as fresh first attempts aimed at the destinations still missing
	 * them. Nothing is sent from this request; the sender applies every gate
	 * again. The work is RefundReplay's, shared with the replay ability.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function replay( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->replay->replay( RefundReplay::references( $request->get_param( 'references' ) ) );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * GET handler: the diagnostics ring as the settings screen shows it -
	 * newest first, with `tone` and `replayable` derived per row. The shaping
	 * lives in SendLog, shared with the gtm4wp/get-google-data-manager-log
	 * ability.
	 *
	 * @return \WP_REST_Response
	 */
	public function send_log(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'entries' => $this->log->entries_for_display(),
			)
		);
	}

	/**
	 * Permission check, using the same capability filter as the settings page.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return Capability::can_manage_settings();
	}

	/**
	 * POST handler: validates the submitted destination and hands it to the
	 * probe shared with the test ability.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_destination( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
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

		$result = $this->probe->probe( $row );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Every reason a submitted destination cannot be probed, one sentence per
	 * failing field. The rules are DestinationRows' own; only the wording lives
	 * here, since that class is loaded on frontend requests.
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
