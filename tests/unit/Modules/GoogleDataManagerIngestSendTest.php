<?php
/**
 * Contract tests for the events:ingest send path and the status poll.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Modules\GoogleDataManager\ConsentPolicy;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\EventsIngest;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The request body of a real send is an external contract, and a drifted field
 * name does not fail: Google answers 200 and drops the field. Nothing here is
 * therefore asserted loosely - the whole body is pinned, key by key, for each
 * of the shapes the refund lane produces (U134/U135).
 *
 * The chunking rules are pinned the same way. Both of them are real limits
 * rather than defensive arithmetic: one request carries one bearer token, so
 * destinations of different service accounts cannot share it, and the API
 * accepts at most ten destinations per request (U125).
 */
final class GoogleDataManagerIngestSendTest extends TestCase {

	use OptionStoreTrait;

	private const NOW    = 1_800_000_000;
	private const SECRET = 'unit-test-site-secret-do-not-reuse';

	private KeyVault $vault;

	private FakeTransport $transport;

	private string $account;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		// FakeTransport enforces the real allow-list via WpTransport::is_allowed_url().
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->stub_option_store();

		$this->vault     = new KeyVault( self::SECRET );
		$this->transport = new FakeTransport();

		$account = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $account );
		$this->account = $account;
	}

	/**
	 * The client over the shared vault and fake transport, with pinned time.
	 *
	 * @return EventsIngest
	 */
	private function ingest(): EventsIngest {
		$clock = static fn () => self::NOW;

		return new EventsIngest( new TokenService( $this->vault, $this->transport, $clock ), $this->transport, $clock );
	}

	/**
	 * The account id of the stored fixture key.
	 *
	 * @return string
	 */
	private function stored_account(): string {
		return $this->account;
	}

	/**
	 * Queues a successful token exchange.
	 *
	 * @return void
	 */
	private function queue_token(): void {
		$this->transport->will_respond_json(
			200,
			array(
				'access_token' => 'ya29.test-token',
				'expires_in'   => 3600,
			)
		);
	}

	/**
	 * One destination row.
	 *
	 * @param string $measurement Measurement id.
	 * @param string $account     Service account id.
	 * @param string $property    GA4 property id.
	 * @return array<string, string>
	 */
	private static function row( string $measurement, string $account, string $property = '123456789' ): array {
		return array(
			DestinationRows::COLUMN_LABEL       => 'Shop',
			DestinationRows::COLUMN_ACCOUNT     => $account,
			DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
			DestinationRows::COLUMN_PROPERTY    => $property,
			DestinationRows::COLUMN_MEASUREMENT => $measurement,
		);
	}

	/**
	 * The full-refund event: transaction id and nothing about money.
	 *
	 * @return array<string, mixed>
	 */
	private static function full_event(): array {
		return array(
			'eventName'      => 'refund',
			'eventTimestamp' => '2027-01-15T10:00:00Z',
			'transactionId'  => 'WC-1234',
			'eventSource'    => 'WEB',
			'clientId'       => '313930999.1788522497',
		);
	}

	// ---- The request body --------------------------------------------------

	public function test_a_full_refund_posts_the_exact_request_body(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-42' ) );

		$results = $ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), self::full_event() );

		$request = $this->transport->requests[1];

		$this->assertSame( 'POST_JSON', $request['method'] );
		$this->assertSame( EventsIngest::ENDPOINT, $request['url'] );
		$this->assertSame( 'Bearer ya29.test-token', $request['headers']['Authorization'] );

		$this->assertSame(
			array(
				'destinations' => array(
					array(
						'operatingAccount'     => array(
							'accountType' => 'GOOGLE_ANALYTICS_PROPERTY',
							'accountId'   => '123456789',
						),
						'productDestinationId' => 'G-ABC123',
					),
				),
				'events'       => array(
					array(
						'eventName'      => 'refund',
						'eventTimestamp' => '2027-01-15T10:00:00Z',
						'transactionId'  => 'WC-1234',
						'eventSource'    => 'WEB',
						'clientId'       => '313930999.1788522497',
					),
				),
			),
			$request['body'],
			'The full-refund body carries no consent field, no validateOnly and nothing about money.'
		);

		$this->assertTrue( $results[0]['ok'] );
		$this->assertSame( 'req-42', $results[0]['request_id'] );
		$this->assertSame( 200, $results[0]['status'] );
		$this->assertSame( array( 'G-ABC123' ), $results[0]['measurements'] );
	}

	public function test_a_partial_refund_carries_the_value_pair_and_the_items(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-43' ) );

		$event                    = self::full_event();
		$event['currency']        = 'EUR';
		$event['conversionValue'] = 19.9;
		$event['cartData']        = array(
			'items' => array(
				array(
					'itemId'    => 'SKU-1',
					'unitPrice' => 9.95,
					'quantity'  => 2,
				),
			),
		);

		$ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), $event );

		$this->assertSame(
			array(
				'eventName'       => 'refund',
				'eventTimestamp'  => '2027-01-15T10:00:00Z',
				'transactionId'   => 'WC-1234',
				'eventSource'     => 'WEB',
				'clientId'        => '313930999.1788522497',
				'currency'        => 'EUR',
				'conversionValue' => 19.9,
				'cartData'        => array(
					'items' => array(
						array(
							'itemId'    => 'SKU-1',
							'unitPrice' => 9.95,
							'quantity'  => 2,
						),
					),
				),
			),
			$this->transport->requests[1]['body']['events'][0]
		);
	}

	public function test_the_consent_field_rides_at_request_level(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-44' ) );

		$ingest->send(
			array( self::row( 'G-ABC123', $this->stored_account() ) ),
			self::full_event(),
			array(
				'adUserData'        => EventsIngest::CONSENT_GRANTED,
				'adPersonalization' => EventsIngest::CONSENT_DENIED,
			)
		);

		$body = $this->transport->requests[1]['body'];

		$this->assertSame(
			array(
				'adUserData'        => 'CONSENT_GRANTED',
				'adPersonalization' => 'CONSENT_DENIED',
			),
			$body['consent'],
			'The consent object is a sibling of destinations and events, not a member of the event.'
		);
		$this->assertArrayNotHasKey( 'consent', $body['events'][0] );
	}

	public function test_an_empty_consent_field_is_omitted_entirely(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-45' ) );

		$ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), self::full_event(), array() );

		$this->assertArrayNotHasKey( 'consent', $this->transport->requests[1]['body'] );
	}

	// ---- The consent enum (U138) -------------------------------------------

	public function test_consent_signals_map_onto_googles_enum_values(): void {
		$this->assertSame(
			array(
				'adUserData'        => 'CONSENT_GRANTED',
				'adPersonalization' => 'CONSENT_DENIED',
			),
			EventsIngest::consent_field(
				array(
					'ad_user_data'       => ConsentPolicy::GRANTED,
					'ad_personalization' => 'denied',
					'analytics_storage'  => ConsentPolicy::GRANTED,
				)
			),
			'Only the two ads signals map, and they map to the API enum values rather than the consent-mode words.'
		);
	}

	public function test_an_unobserved_signal_is_omitted_rather_than_sent_as_unspecified(): void {
		$this->assertSame(
			array( 'adUserData' => 'CONSENT_GRANTED' ),
			EventsIngest::consent_field( array( 'ad_user_data' => 'granted' ) )
		);

		$this->assertSame( array(), EventsIngest::consent_field( array() ) );
	}

	// ---- Chunking ----------------------------------------------------------

	public function test_destinations_of_different_accounts_go_in_separate_requests(): void {
		$rows = array(
			self::row( 'G-AAA', 'sa_111111111111' ),
			self::row( 'G-BBB', 'sa_222222222222' ),
			self::row( 'G-CCC', 'sa_111111111111' ),
		);

		$chunks = EventsIngest::chunk( $rows );

		$this->assertCount( 2, $chunks, 'One request per service account: a request carries one bearer token.' );
		$this->assertSame( array( 'G-AAA', 'G-CCC' ), array_column( $chunks[0], DestinationRows::COLUMN_MEASUREMENT ) );
		$this->assertSame( array( 'G-BBB' ), array_column( $chunks[1], DestinationRows::COLUMN_MEASUREMENT ) );
	}

	public function test_more_than_ten_destinations_of_one_account_are_split(): void {
		$rows = array();
		for ( $i = 0; $i < 23; $i++ ) {
			$rows[] = self::row( 'G-' . $i, 'sa_111111111111' );
		}

		$chunks = EventsIngest::chunk( $rows );

		$this->assertCount( 3, $chunks );
		$this->assertCount( EventsIngest::MAX_DESTINATIONS_PER_REQUEST, $chunks[0] );
		$this->assertCount( EventsIngest::MAX_DESTINATIONS_PER_REQUEST, $chunks[1] );
		$this->assertCount( 3, $chunks[2] );
		$this->assertSame( 10, EventsIngest::MAX_DESTINATIONS_PER_REQUEST, 'The cap is the documented one (U125).' );
	}

	public function test_a_row_without_a_service_account_is_never_sent(): void {
		$this->assertSame( array(), EventsIngest::chunk( array( self::row( 'G-AAA', '' ) ) ) );
	}

	public function test_each_chunk_reports_its_own_outcome(): void {
		$ingest  = $this->ingest();
		$account = $this->stored_account();

		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-ok' ) );

		$rows = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$rows[] = self::row( 'G-' . $i, $account );
		}

		// The second chunk mints its own token here because the transient stub
		// never caches; in production it would reuse the first one. Then it is
		// refused, while the first chunk was not - and the caller has to be able
		// to tell them apart to retry only what failed.
		$this->queue_token();
		$this->transport->will_respond_json( 503, array( 'error' => array( 'status' => 'UNAVAILABLE' ) ) );

		$results = $ingest->send( $rows, self::full_event() );

		$this->assertCount( 2, $results );
		$this->assertTrue( $results[0]['ok'] );
		$this->assertFalse( $results[1]['ok'] );
		$this->assertTrue( $results[1]['retryable'] );
		$this->assertSame( array( 'G-10' ), $results[1]['measurements'] );
	}

	// ---- Failure classification --------------------------------------------

	public function test_only_rate_limits_and_server_errors_are_retried(): void {
		$expected = array(
			429 => true,
			500 => true,
			504 => true,
			400 => false,
			401 => false,
			403 => false,
			404 => false,
			200 => false,
		);

		foreach ( $expected as $status => $retryable ) {
			$this->assertSame( $retryable, EventsIngest::is_retryable( $status ), "HTTP {$status}." );
		}
	}

	public function test_a_transport_failure_is_retryable_and_classed_as_such(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$results = $ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), self::full_event() );

		$this->assertFalse( $results[0]['ok'] );
		$this->assertTrue( $results[0]['retryable'] );
		$this->assertSame( 0, $results[0]['status'], 'No HTTP status, because no response came back.' );
		$this->assertSame( EventsIngest::REASON_TRANSPORT, $results[0]['reason'] );
	}

	public function test_a_key_google_refuses_is_not_retried(): void {
		$ingest = $this->ingest();
		$this->transport->will_respond_json( 400, array( 'error_description' => 'Invalid JWT' ) );

		$results = $ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), self::full_event() );

		$this->assertFalse( $results[0]['ok'] );
		$this->assertFalse( $results[0]['retryable'], 'A key the admin has to fix is not fixed by waiting.' );
		$this->assertSame( EventsIngest::REASON_ACCOUNT, $results[0]['reason'] );
		$this->assertCount( 1, $this->transport->requests, 'No ingest request is made when no token could be minted.' );
	}

	public function test_the_reason_class_is_googles_code_name_and_carries_no_message(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json(
			403,
			array(
				'error' => array(
					'status'  => 'PERMISSION_DENIED',
					'message' => 'The caller does not have permission for property 123456789.',
				),
			)
		);

		$results = $ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), self::full_event() );

		$this->assertSame( 'PERMISSION_DENIED', $results[0]['reason'] );
		$this->assertStringNotContainsString( '123456789', $results[0]['reason'], 'The class names the failure without quoting anything from the request.' );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $results[0]['error'], 'The full summary keeps Google\'s own sentence, for the settings screen.' );
	}

	public function test_a_refusal_without_an_error_envelope_is_classed_by_its_status(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json( 502, null );

		$results = $ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), self::full_event() );

		$this->assertSame( 'http_502', $results[0]['reason'] );
	}

	public function test_a_hostile_status_name_cannot_become_the_reason_class(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json(
			400,
			array( 'error' => array( 'status' => '<script>alert(1)</script>' ) )
		);

		$results = $ingest->send( array( self::row( 'G-ABC123', $this->stored_account() ) ), self::full_event() );

		$this->assertSame( 'http_400', $results[0]['reason'], 'Anything that is not a bare code name falls back to the status.' );
	}

	// ---- Status polling (U136) ---------------------------------------------

	public function test_the_status_request_is_a_get_with_the_request_id(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json(
			200,
			array(
				'requestStatusPerDestination' => array(
					array(
						'destination'   => array( 'productDestinationId' => 'G-ABC123' ),
						'requestStatus' => 'SUCCESS',
					),
				),
			)
		);

		$statuses = $ingest->request_status( $this->stored_account(), 'req 42/7' );

		$this->assertSame( 'GET', $this->transport->requests[1]['method'] );
		$this->assertSame(
			EventsIngest::STATUS_ENDPOINT . '?requestId=req%2042%2F7',
			$this->transport->requests[1]['url'],
			'The request id is a query parameter and is URL-encoded.'
		);
		$this->assertSame(
			array(
				array(
					'measurement' => 'G-ABC123',
					'status'      => 'SUCCESS',
					'errors'      => 0,
					'warnings'    => 0,
				),
			),
			$statuses
		);
	}

	public function test_error_and_warning_record_counts_are_summed_per_destination(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json(
			200,
			array(
				'requestStatusPerDestination' => array(
					array(
						'destination'   => array( 'productDestinationId' => 'G-ABC123' ),
						'requestStatus' => 'PARTIAL_SUCCESS',
						'errorInfo'     => array(
							'errorCounts' => array(
								array(
									'reason'      => 'INVALID_TRANSACTION_ID',
									'recordCount' => 2,
								),
								array(
									'reason'      => 'INTERNAL_ERROR',
									'recordCount' => 3,
								),
							),
						),
						'warningInfo'   => array(
							'warningCounts' => array(
								array(
									'reason'      => 'UNKNOWN_ITEM',
									'recordCount' => 1,
								),
							),
						),
					),
				),
			)
		);

		$statuses = $ingest->request_status( $this->stored_account(), 'req-1' );

		$this->assertSame( 5, $statuses[0]['errors'] );
		$this->assertSame( 1, $statuses[0]['warnings'] );
		$this->assertSame( 'PARTIAL_SUCCESS', $statuses[0]['status'] );
	}

	public function test_the_reasons_google_returns_are_not_kept(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json(
			200,
			array(
				'requestStatusPerDestination' => array(
					array(
						'destination'   => array( 'productDestinationId' => 'G-ABC123' ),
						'requestStatus' => 'FAILED',
						'errorInfo'     => array(
							'errorCounts' => array(
								array(
									'reason'      => 'INVALID_TRANSACTION_ID',
									'recordCount' => 1,
								),
							),
						),
					),
				),
			)
		);

		$statuses = $ingest->request_status( $this->stored_account(), 'req-1' );

		$this->assertSame(
			array( 'measurement', 'status', 'errors', 'warnings' ),
			array_keys( $statuses[0] ),
			'Only counts and the status name survive - the reason list can name what it came from, and the log is read in public.'
		);
	}

	public function test_a_refused_status_request_comes_back_as_an_error(): void {
		$ingest = $this->ingest();
		$this->queue_token();
		$this->transport->will_respond_json( 404, array( 'error' => array( 'status' => 'NOT_FOUND' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $ingest->request_status( $this->stored_account(), 'req-1' ) );
	}

	public function test_no_status_request_is_made_without_a_request_id(): void {
		$ingest = $this->ingest();

		$this->assertInstanceOf( \WP_Error::class, $ingest->request_status( $this->stored_account(), '' ) );
		$this->assertSame( array(), $this->transport->requests, 'Not even a token is minted for a request that cannot be made.' );
	}

	/**
	 * Google's REST reference and its diagnostics guide disagree on the failure
	 * state's name (FAILED vs FAILURE), so both have to end the polling - and so
	 * does a name neither of them lists yet. Only a request that is genuinely
	 * still running, or one Google will not name at all, keeps the poller going.
	 */
	public function test_only_a_still_running_request_keeps_the_poller_going(): void {
		$expected = array(
			'SUCCESS'                => true,
			'PARTIAL_SUCCESS'        => true,
			'FAILED'                 => true,
			'FAILURE'                => true,
			'REJECTED_BY_POLICY'     => true,
			'PROCESSING'             => false,
			'REQUEST_STATUS_UNKNOWN' => false,
			''                       => false,
		);

		foreach ( $expected as $status => $terminal ) {
			$this->assertSame( $terminal, EventsIngest::is_terminal_status( (string) $status ), "Status '{$status}'." );
		}
	}
}
