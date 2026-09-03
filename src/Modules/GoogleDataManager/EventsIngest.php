<?php
/**
 * Data Manager API events:ingest client.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Google\TokenService;
use GTM4WP\Google\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the plugin talks to the Data Manager API's events:ingest
 * method. The endpoint URL, the Destination shape for Google Analytics and
 * the request-level limits are external contracts, registered as U123-U125 in
 * .upstream/upstream-review-checklist.md; the contract tests pin the request
 * shape from our side.
 *
 * This phase only sends validateOnly probes (the settings screen's per-row
 * "Test" button); the real send paths of later phases go through the same
 * class so the wire format keeps its single definition.
 */
final class EventsIngest {

	/**
	 * The events:ingest endpoint. A fixed constant, never user input: the
	 * transport's host allow-list (WpTransport::ALLOWED_HOSTS) is the SSRF
	 * guard and already knows this host.
	 */
	public const ENDPOINT = 'https://datamanager.googleapis.com/v1/events:ingest';

	/**
	 * The operatingAccount.accountType of a Google Analytics property (U124).
	 */
	public const ACCOUNT_TYPE_GA4 = 'GOOGLE_ANALYTICS_PROPERTY';

	/**
	 * The eventSource of website-originated events.
	 */
	public const EVENT_SOURCE_WEB = 'WEB';

	/**
	 * Request-level limits (U125). Not enforced anywhere yet - refund volume
	 * is orders of magnitude below them - but the send paths of later phases
	 * chunk against these rather than re-reading the docs.
	 */
	public const MAX_EVENTS_PER_REQUEST       = 2000;
	public const MAX_DESTINATIONS_PER_REQUEST = 10;

	/**
	 * Constructor.
	 *
	 * @param TokenService  $tokens    The token minter.
	 * @param Transport     $transport The HTTP seam.
	 * @param callable|null $clock     Returns the current Unix time; null uses time(). Injected by tests.
	 */
	public function __construct( private TokenService $tokens, private Transport $transport, private $clock = null ) {
	}

	/**
	 * The API's Destination object for one of our destination rows (U124):
	 * the operating account is the GA4 property, the product destination id
	 * the measurement id of the web data stream. loginAccount is deliberately
	 * absent - for Google Analytics it is either omitted or a copy of the
	 * operating account, and omitting is the smaller claim.
	 *
	 * @param array<string, string> $row Validated destination row.
	 * @return array<string, mixed>
	 */
	public static function destination( array $row ): array {
		return array(
			'operatingAccount'     => array(
				'accountType' => self::ACCOUNT_TYPE_GA4,
				'accountId'   => (string) ( $row[ DestinationRows::COLUMN_PROPERTY ] ?? '' ),
			),
			'productDestinationId' => (string) ( $row[ DestinationRows::COLUMN_MEASUREMENT ] ?? '' ),
		);
	}

	/**
	 * Probes one destination with a validateOnly ingest request: nothing is
	 * applied server-side, only errors return - so a missing property grant, a
	 * wrong property id or a key Google refuses surfaces synchronously.
	 *
	 * The synthetic event mirrors the refund shape the send paths will use,
	 * so the probe validates the lane the feature actually needs rather than
	 * an arbitrary payload.
	 *
	 * @param array<string, string> $row Validated destination row.
	 * @return true|\WP_Error True when Google accepted the request.
	 */
	public function validate_destination( array $row ) {
		$token = $this->tokens->access_token(
			(string) ( $row[ DestinationRows::COLUMN_ACCOUNT ] ?? '' ),
			TokenService::SCOPE_DATA_MANAGER
		);

		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		$now = is_callable( $this->clock ) ? (int) call_user_func( $this->clock ) : time();

		$response = $this->transport->post_json(
			self::ENDPOINT,
			array(
				'destinations' => array( self::destination( $row ) ),
				'events'       => array(
					array(
						'eventName'      => 'refund',
						'eventTimestamp' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
						'transactionId'  => 'GTM4WP-VALIDATE',
						'clientId'       => '1000000000.1000000000',
						'eventSource'    => self::EVENT_SOURCE_WEB,
					),
				),
				'validateOnly' => true,
			),
			array( 'Authorization' => 'Bearer ' . $token )
		);

		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		if ( 200 === $response['status'] ) {
			return true;
		}

		return new \WP_Error( 'gtm4wp_gdm_destination_refused', self::error_summary( $response ) );
	}

	/**
	 * A short, storable description of a refused ingest request.
	 *
	 * Uses the standard Google error envelope (`error.status` and
	 * `error.message`) when present. Both are Google's own text about our
	 * request; the summary is sanitized and capped so a raw body fragment
	 * cannot ride along into a notice or a health record.
	 *
	 * @param array{status: int, body: array|null} $response The transport response.
	 * @return string
	 */
	private static function error_summary( array $response ): string {
		$error   = is_array( $response['body']['error'] ?? null ) ? $response['body']['error'] : array();
		$status  = is_string( $error['status'] ?? null ) ? $error['status'] : '';
		$message = is_string( $error['message'] ?? null ) ? $error['message'] : '';

		if ( ( '' === $status ) && ( '' === $message ) ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Google refused the request (HTTP %d).', 'duracelltomi-google-tag-manager' ),
				(int) $response['status']
			);
		}

		$summary = ( '' === $status ) ? $message : trim( $status . ': ' . $message );

		return mb_substr( sanitize_text_field( $summary ), 0, 200 );
	}
}
