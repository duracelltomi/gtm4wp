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
use GTM4WP\Options\Options;
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
	 * Route that queues failed or fixable-skipped refunds again.
	 */
	public const REPLAY_ROUTE = '/google/send-log/replay';

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
		private KeyVault $vault,
		private EventsIngest $ingest,
		private SendLog $log,
		private ?Options $options = null,
		private ?DestinationHealth $health = null
	) {
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
	 * POST handler: queues every replayable refund again, or only the named ones.
	 *
	 * Nothing is sent from inside this request. Each refund goes back onto the
	 * queue as a fresh first attempt, aimed only at the destinations still
	 * missing it, and the sender applies every gate again when it runs - so a
	 * refund that was skipped for want of a destination is skipped once more,
	 * with the same reason, if the admin has not actually added one.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function replay( \WP_REST_Request $request ) {
		if ( null !== $this->options && ! $this->options->get( GTM4WP_OPTION_GDM_SEND_REFUNDS ) ) {
			return new \WP_Error(
				'gtm4wp_gdm_sending_off',
				__( 'Turn on "Send refunds to Google Analytics" and save before sending anything again.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 409 )
			);
		}

		$references = array();

		foreach ( (array) $request->get_param( 'references' ) as $reference ) {
			if ( is_string( $reference ) && null !== SendLog::parse_reference( $reference ) ) {
				$references[] = $reference;
			}
		}

		$queued = array();

		foreach ( $this->log->replay_plan( $references ) as $reference => $job ) {
			$payload = array(
				'platform'  => $job['platform'],
				'order_id'  => $job['order_id'],
				'refund_id' => $job['refund_id'],
				'attempt'   => 1,
			);

			if ( array() !== $job['only'] ) {
				$payload['only'] = $job['only'];
			}

			if ( SendQueue::schedule( SendQueue::HOOK_SEND, $payload ) ) {
				$queued[] = $reference;
			}
		}

		return new \WP_REST_Response(
			array(
				'queued'     => count( $queued ),
				'references' => $queued,
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
	 * Two added fields, both derived rather than stored: `tone`, how much
	 * attention the row deserves, and `replayable`, whether the row is one the
	 * replay route would act on. The second is answered from the replay plan
	 * itself, not from the row alone, so a failure that a later success has
	 * overtaken does not keep offering a replay that would queue nothing.
	 *
	 * @return \WP_REST_Response
	 */
	public function send_log(): \WP_REST_Response {
		$entries = array();

		$replayable = $this->log->replayable_entries();

		foreach ( array_reverse( $this->log->all(), true ) as $index => $entry ) {
			$entry['tone']       = SendLog::tone( $entry );
			$entry['replayable'] = isset( $replayable[ $index ] );
			$entries[]           = $entry;
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

		// A probe that passes after the admin corrected the destination is the
		// moment the failure streak - and the site-wide notice it raises - has
		// served its purpose. Without this the notice stayed until the next
		// customer happened to ask for a refund.
		if ( $ok ) {
			( $this->health ?? new DestinationHealth() )->clear_failures( $row[ DestinationRows::COLUMN_MEASUREMENT ] );
		}

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
