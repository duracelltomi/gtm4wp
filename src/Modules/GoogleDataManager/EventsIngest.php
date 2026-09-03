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
	 * cannot ride along into a notice or a health record. A status the
	 * settings screen can act on gets a plain-words explanation in front,
	 * with Google's own sentence kept in parentheses - "NOT_FOUND: Requested
	 * entity was not found." alone told the admin nothing to do.
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
	 * Actionable wording for the google.rpc.Code names this probe commonly
	 * comes back with (U126 in .upstream/upstream-review-checklist.md). An
	 * unmapped or renamed status degrades to the raw summary alone, never to
	 * silence.
	 *
	 * NOT_FOUND deliberately points at access as well as the IDs: like most
	 * Google APIs, an entity the caller is not allowed to see is reported as
	 * not found rather than confirmed to exist, so "check the IDs" alone
	 * would send an admin with a missing property grant down the wrong path.
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
