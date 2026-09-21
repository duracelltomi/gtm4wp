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
 * Both the settings screen's validateOnly probe and the real refund sends go
 * through this one class, so the wire format keeps its single definition: the
 * probe validates the same lane the sends use.
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
	 * Request-level limits (U125); send() chunks destinations against the second.
	 */
	public const MAX_EVENTS_PER_REQUEST       = 2000;
	public const MAX_DESTINATIONS_PER_REQUEST = 10;

	/**
	 * The requestStatus:retrieve endpoint (U136). A fixed constant on the same
	 * allow-listed host as the ingest endpoint.
	 */
	public const STATUS_ENDPOINT = 'https://datamanager.googleapis.com/v1/requestStatus:retrieve';

	/**
	 * ConsentStatus enum values of the request-level `consent` field (U138).
	 * CONSENT_STATUS_UNSPECIFIED is deliberately absent: an unobserved signal
	 * is omitted instead, the smaller claim.
	 */
	public const CONSENT_GRANTED = 'CONSENT_GRANTED';
	public const CONSENT_DENIED  = 'CONSENT_DENIED';

	/**
	 * The consent-mode signals that map into the request-level `consent` field,
	 * as consent-mode name => Data Manager field name (U138).
	 *
	 * @var array<string, string>
	 */
	public const CONSENT_SIGNALS = array(
		'ad_user_data'       => 'adUserData',
		'ad_personalization' => 'adPersonalization',
	);

	/**
	 * RequestStatus values (U136). Google's reference says FAILED, its
	 * diagnostics guide FAILURE, so both are accepted; anything neither
	 * PROCESSING nor the unknown placeholder is terminal, so a status Google
	 * adds later ends the polling instead of looping to the 24 hour cap.
	 */
	public const STATUS_SUCCESS         = 'SUCCESS';
	public const STATUS_PARTIAL_SUCCESS = 'PARTIAL_SUCCESS';
	public const STATUS_FAILED          = 'FAILED';
	public const STATUS_FAILURE         = 'FAILURE';
	public const STATUS_PROCESSING      = 'PROCESSING';
	public const STATUS_UNKNOWN         = 'REQUEST_STATUS_UNKNOWN';

	/**
	 * Reason class of a failure that happened before any request was made -
	 * the service account could not be opened or Google refused to sign it in.
	 */
	public const REASON_ACCOUNT = 'account_error';

	/**
	 * Reason class of a failure at the transport level: DNS, TLS, a timeout.
	 */
	public const REASON_TRANSPORT = 'transport_error';

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
	 * Probes one destination with a validateOnly ingest request, so a missing
	 * property grant, a wrong property id or a refused key surfaces
	 * synchronously. The synthetic event mirrors the refund shape the sends use.
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
	 * Sends one assembled event to a set of destination rows, split per service
	 * account (one bearer token per request) and per
	 * MAX_DESTINATIONS_PER_REQUEST (U125). Every request reports its own
	 * outcome: one property can accept an event while another refuses it.
	 *
	 * @param array<int, array<string, string>> $rows    Validated destination rows.
	 * @param array<string, mixed>              $event   One assembled event, already in API shape.
	 * @param array<string, string>             $consent Request-level consent field; omitted when empty.
	 * @return array<int, array{account: string, measurements: string[], ok: bool, status: int, request_id: string, error: string, reason: string, retryable: bool}>
	 */
	public function send( array $rows, array $event, array $consent = array() ): array {
		$results = array();

		foreach ( self::chunk( $rows ) as $chunk ) {
			$results[] = $this->send_chunk( $chunk, $event, $consent );
		}

		return $results;
	}

	/**
	 * Splits destination rows into the request groups send() makes: one group
	 * per service account, each within the per-request cap, order preserved.
	 *
	 * @param array<int, array<string, string>> $rows Validated destination rows.
	 * @return array<int, array<int, array<string, string>>>
	 */
	public static function chunk( array $rows ): array {
		$by_account = array();

		foreach ( $rows as $row ) {
			$account = (string) ( $row[ DestinationRows::COLUMN_ACCOUNT ] ?? '' );

			if ( '' === $account ) {
				continue;
			}

			$by_account[ $account ][] = $row;
		}

		$chunks = array();

		foreach ( $by_account as $account_rows ) {
			foreach ( array_chunk( $account_rows, self::MAX_DESTINATIONS_PER_REQUEST ) as $chunk ) {
				$chunks[] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * Sends one request: the destinations of a single service account, at most
	 * a capped number of them.
	 *
	 * @param array<int, array<string, string>> $rows    Destination rows sharing one service account.
	 * @param array<string, mixed>              $event   The assembled event.
	 * @param array<string, string>             $consent Request-level consent field.
	 * @return array{account: string, measurements: string[], ok: bool, status: int, request_id: string, error: string, reason: string, retryable: bool}
	 */
	private function send_chunk( array $rows, array $event, array $consent ): array {
		$account      = (string) ( $rows[0][ DestinationRows::COLUMN_ACCOUNT ] ?? '' );
		$measurements = array();

		foreach ( $rows as $row ) {
			$measurements[] = (string) ( $row[ DestinationRows::COLUMN_MEASUREMENT ] ?? '' );
		}

		$result = array(
			'account'      => $account,
			'measurements' => $measurements,
			'ok'           => false,
			'status'       => 0,
			'request_id'   => '',
			'error'        => '',
			'reason'       => '',
			'retryable'    => false,
		);

		$token = $this->tokens->access_token( $account, TokenService::SCOPE_DATA_MANAGER );

		if ( $token instanceof \WP_Error ) {
			// Needs the admin, not a retry; status stays 0, no request was made.
			$result['error']  = $token->get_error_message();
			$result['reason'] = self::REASON_ACCOUNT;

			return $result;
		}

		$body = array(
			'destinations' => array_map( array( self::class, 'destination' ), $rows ),
			'events'       => array( $event ),
		);

		if ( array() !== $consent ) {
			$body['consent'] = $consent;
		}

		$response = $this->transport->post_json(
			self::ENDPOINT,
			$body,
			array( 'Authorization' => 'Bearer ' . $token )
		);

		if ( $response instanceof \WP_Error ) {
			// DNS, TLS or a timeout: the request may never have reached Google.
			$result['error']     = $response->get_error_message();
			$result['reason']    = self::REASON_TRANSPORT;
			$result['retryable'] = true;

			return $result;
		}

		$result['status'] = (int) $response['status'];

		if ( 200 === $result['status'] ) {
			$request_id = $response['body']['requestId'] ?? null;

			$result['ok']         = true;
			$result['request_id'] = is_string( $request_id ) ? $request_id : '';

			return $result;
		}

		$result['error']     = self::error_summary( $response );
		$result['reason']    = self::reason_class( $response );
		$result['retryable'] = self::is_retryable( $result['status'] );

		return $result;
	}

	/**
	 * The reason class of a refused request: Google's google.rpc.Code name when
	 * present, otherwise the HTTP status. Names the failure where the error
	 * text must not go (Site Health is pasted into public threads, and an error
	 * message can quote the request).
	 *
	 * @param array{status: int, body: array|null} $response The transport response.
	 * @return string
	 */
	private static function reason_class( array $response ): string {
		$status = $response['body']['error']['status'] ?? null;

		if ( is_string( $status ) && ( 1 === preg_match( '/^[A-Z_]{1,40}$/D', $status ) ) ) {
			return $status;
		}

		return 'http_' . (int) $response['status'];
	}

	/**
	 * Whether an HTTP status is worth repeating the request for: rate limiting
	 * and server-side failures are transient; any other 4xx says the request is wrong.
	 *
	 * @param int $status HTTP status code.
	 * @return bool
	 */
	public static function is_retryable( int $status ): bool {
		return ( 429 === $status ) || ( $status >= 500 );
	}

	/**
	 * The request-level `consent` field for a stored consent-mode signal map,
	 * empty when it carries neither ads signal. An absent signal is omitted
	 * rather than sent as the unspecified value.
	 *
	 * @param array<string, mixed> $signals Consent-mode signal map (name => granted|denied).
	 * @return array<string, string>
	 */
	public static function consent_field( array $signals ): array {
		$consent = array();

		foreach ( self::CONSENT_SIGNALS as $signal => $field ) {
			if ( ! isset( $signals[ $signal ] ) || ! is_string( $signals[ $signal ] ) ) {
				continue;
			}

			$consent[ $field ] = ( ConsentPolicy::GRANTED === $signals[ $signal ] )
				? self::CONSENT_GRANTED
				: self::CONSENT_DENIED;
		}

		return $consent;
	}

	/**
	 * Asks Google how an accepted ingest request was processed (U136):
	 * ingestion is asynchronous (30 minutes to 24 hours), so a 200 on the
	 * ingest call means "accepted", not "applied".
	 *
	 * @param string $account_id Service account the request was sent with.
	 * @param string $request_id The requestId the ingest response returned.
	 * @return array<int, array{measurement: string, status: string, errors: int, warnings: int}>|\WP_Error
	 */
	public function request_status( string $account_id, string $request_id ) {
		if ( '' === $request_id ) {
			return new \WP_Error(
				'gtm4wp_gdm_status_no_request',
				__( 'No request ID to ask Google about.', 'duracelltomi-google-tag-manager' )
			);
		}

		$token = $this->tokens->access_token( $account_id, TokenService::SCOPE_DATA_MANAGER );

		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		$response = $this->transport->get(
			self::STATUS_ENDPOINT . '?requestId=' . rawurlencode( $request_id ),
			array( 'Authorization' => 'Bearer ' . $token )
		);

		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		if ( 200 !== (int) $response['status'] ) {
			return new \WP_Error( 'gtm4wp_gdm_status_refused', self::error_summary( $response ) );
		}

		return self::read_status_body( is_array( $response['body'] ) ? $response['body'] : array() );
	}

	/**
	 * Reduces a requestStatus:retrieve body to the per-destination rows the
	 * diagnostics ring stores. Only counts and status names are kept; Google's
	 * reason lists can name the records they came from and are left behind.
	 *
	 * @param array<string, mixed> $body Decoded response body.
	 * @return array<int, array{measurement: string, status: string, errors: int, warnings: int}>
	 */
	private static function read_status_body( array $body ): array {
		$rows     = array();
		$per_dest = $body['requestStatusPerDestination'] ?? null;

		if ( ! is_array( $per_dest ) ) {
			return $rows;
		}

		foreach ( $per_dest as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$measurement = $entry['destination']['productDestinationId'] ?? '';
			$status      = $entry['requestStatus'] ?? '';

			$rows[] = array(
				'measurement' => is_string( $measurement ) ? $measurement : '',
				'status'      => is_string( $status ) ? $status : '',
				'errors'      => self::count_records( $entry['errorInfo']['errorCounts'] ?? null ),
				'warnings'    => self::count_records( $entry['warningInfo']['warningCounts'] ?? null ),
			);
		}

		return $rows;
	}

	/**
	 * Sums the recordCount members of an errorCounts / warningCounts list.
	 *
	 * @param mixed $counts The list as decoded from the response.
	 * @return int
	 */
	private static function count_records( $counts ): int {
		if ( ! is_array( $counts ) ) {
			return 0;
		}

		$total = 0;

		foreach ( $counts as $count ) {
			if ( is_array( $count ) && isset( $count['recordCount'] ) && is_numeric( $count['recordCount'] ) ) {
				$total += (int) $count['recordCount'];
			}
		}

		return $total;
	}

	/**
	 * Whether a per-destination status means Google has finished: "anything
	 * not still running", not a list of finished states (UC-5).
	 *
	 * @param string $status A requestStatus value.
	 * @return bool
	 */
	public static function is_terminal_status( string $status ): bool {
		return ( '' !== $status )
			&& ( self::STATUS_PROCESSING !== $status )
			&& ( self::STATUS_UNKNOWN !== $status );
	}

	/**
	 * A short, storable description of a refused request from the standard
	 * Google error envelope, sanitized and capped so a raw body fragment cannot
	 * reach a notice. An actionable status gets plain-words wording in front
	 * ("NOT_FOUND: Requested entity was not found." alone told the admin nothing).
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
		$summary = mb_substr( sanitize_text_field( $summary ), 0, 200 );

		$hint = self::status_hint( $status );

		return ( '' === $hint ) ? $summary : $hint . ' (' . $summary . ')';
	}

	/**
	 * Actionable wording for the common google.rpc.Code names (U126); an
	 * unmapped status degrades to the raw summary, never to silence. NOT_FOUND
	 * points at access as well as the IDs: Google reports an entity the caller
	 * may not see as not found.
	 *
	 * @param string $status The `error.status` name.
	 * @return string Explanation, or '' for a status with no mapped wording.
	 */
	private static function status_hint( string $status ): string {
		switch ( $status ) {
			case 'NOT_FOUND':
				return __( 'Google Analytics could not find this destination. Please double-check both the GA4 property ID and the measurement ID - and check that the service account was added to the property, because a property the account is not allowed to see is also reported as not found.', 'duracelltomi-google-tag-manager' );
			case 'PERMISSION_DENIED':
				return __( 'The service account is not allowed to send to this destination. Add it to the GA4 property with the Editor role, and make sure the Data Manager API is enabled in the Google Cloud project the account belongs to.', 'duracelltomi-google-tag-manager' );
			case 'UNAUTHENTICATED':
				return __( 'Google did not accept the sign-in of the service account. Use the Test button in the Google service accounts section to check it; if it fails there too, upload its key file again.', 'duracelltomi-google-tag-manager' );
			case 'INVALID_ARGUMENT':
				return __( 'Google refused the request as invalid. Please double-check the GA4 property ID and the measurement ID.', 'duracelltomi-google-tag-manager' );
			default:
				return '';
		}
	}
}
