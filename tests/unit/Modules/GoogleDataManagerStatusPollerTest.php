<?php
/**
 * Unit tests for the asynchronous send-status polling.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Modules\GoogleDataManager\EventsIngest;
use GTM4WP\Modules\GoogleDataManager\SendLog;
use GTM4WP\Modules\GoogleDataManager\SendQueue;
use GTM4WP\Modules\GoogleDataManager\StatusPoller;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * A 200 on the ingest call means "accepted", not "applied": Google decides
 * asynchronously, between half an hour and a day later. Everything here is
 * about that gap - the schedule Google publishes for asking (U136), when to
 * stop asking, and the fact that a status request failing says nothing about
 * the send it is asking about.
 */
final class GoogleDataManagerStatusPollerTest extends TestCase {

	use OptionStoreTrait;

	private const NOW    = 1_800_000_000;
	private const SECRET = 'unit-test-site-secret-do-not-reuse';

	private FakeTransport $transport;

	private string $account;

	private KeyVault $vault;

	/**
	 * Every job the poller queued.
	 *
	 * @var array<int, array{hook: string, payload: array<string, mixed>, delay: int}>
	 */
	private array $scheduled = array();

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

		$this->transport = new FakeTransport();

		$vault   = new KeyVault( self::SECRET );
		$account = $vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $account );
		$this->account = $account;
		$this->vault   = $vault;

		$this->scheduled = array();

		// Both backends are stubbed so the poller's own scheduling is what is
		// under test, not which queue carried it.
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args = array(), $group = '' ) {
				$this->scheduled[] = array(
					'hook'    => $hook,
					'payload' => $args[0] ?? array(),
					'delay'   => $timestamp - time(),
				);

				return 1;
			}
		);
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
	}

	/**
	 * The real API client over the fake transport.
	 *
	 * No double stands in for EventsIngest: the transport is the seam the
	 * design already provides, and a stand-in would decide for itself what a
	 * status answer looks like - which is the one thing these tests are meant
	 * to pin against the real parser (UC-3).
	 *
	 * @return EventsIngest
	 */
	private function ingest(): EventsIngest {
		$clock = static fn () => self::NOW;

		return new EventsIngest( new TokenService( $this->vault, $this->transport, $clock ), $this->transport, $clock );
	}

	/**
	 * Queues a token exchange followed by one status answer.
	 *
	 * @param int        $status HTTP status of the status request.
	 * @param array|null $body   Its decoded body.
	 * @return void
	 */
	private function queue_status( int $status, ?array $body ): void {
		$this->transport->will_respond_json(
			200,
			array(
				'access_token' => 'ya29.test-token',
				'expires_in'   => 3600,
			)
		);
		$this->transport->will_respond_json( $status, $body );
	}

	/**
	 * A requestStatus:retrieve body for one destination.
	 *
	 * @param string $status Status name.
	 * @return array<string, mixed>
	 */
	private static function status_body( string $status ): array {
		return array(
			'requestStatusPerDestination' => array(
				array(
					'destination'   => array( 'productDestinationId' => 'G-ABC123' ),
					'requestStatus' => $status,
				),
			),
		);
	}

	/**
	 * One per-destination status row.
	 *
	 * @param string $status Status name.
	 * @return array<int, array{measurement: string, status: string, errors: int, warnings: int}>
	 */
	private static function statuses( string $status ): array {
		return array(
			array(
				'measurement' => 'G-ABC123',
				'status'      => $status,
				'errors'      => 0,
				'warnings'    => 0,
			),
		);
	}

	/**
	 * A poll payload.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 * @return array<string, mixed>
	 */
	private static function payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'account'    => 'sa_aaaaaaaaaaaa',
				'request_id' => 'req-42',
				'interval'   => StatusPoller::FIRST_DELAY,
				'elapsed'    => StatusPoller::FIRST_DELAY,
			),
			$overrides
		);
	}

	// ---- Google's published schedule (U136) --------------------------------

	public function test_the_first_check_waits_half_an_hour(): void {
		StatusPoller::start( 'sa_aaaaaaaaaaaa', 'req-42' );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( SendQueue::HOOK_STATUS, $this->scheduled[0]['hook'] );
		$this->assertSame( 1800, $this->scheduled[0]['delay'], 'Google says to wait 30 minutes before the first diagnostics request.' );
		$this->assertSame(
			array(
				'account'    => 'sa_aaaaaaaaaaaa',
				'request_id' => 'req-42',
				'interval'   => 1800,
				'elapsed'    => 1800,
			),
			$this->scheduled[0]['payload']
		);
	}

	public function test_the_wait_grows_by_a_third_each_time_and_stops_at_an_hour(): void {
		$this->assertSame( 2340, StatusPoller::next_interval( 1800 ) );
		$this->assertSame( 3042, StatusPoller::next_interval( 2340 ) );
		$this->assertSame( 3600, StatusPoller::next_interval( 3042 ), 'The 1.3 multiplier would give 3955; the published cap is 60 minutes.' );
		$this->assertSame( 3600, StatusPoller::next_interval( 3600 ) );
	}

	public function test_nothing_is_queued_without_an_account_or_a_request_id(): void {
		StatusPoller::start( '', 'req-42' );
		StatusPoller::start( 'sa_aaaaaaaaaaaa', '' );

		$this->assertSame( array(), $this->scheduled, 'A poll that could not be made is not queued at all.' );
	}

	// ---- Polling -----------------------------------------------------------

	public function test_a_finished_request_records_its_result_and_stops_the_polling(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-ABC123',
				'outcome'     => SendLog::OUTCOME_ACCEPTED,
				'request_id'  => 'req-42',
			)
		);

		$this->queue_status( 200, self::status_body( 'SUCCESS' ) );

		( new StatusPoller( $this->ingest(), $log ) )->poll( self::payload( array( 'account' => $this->account ) ) );

		$this->assertStringContainsString( 'requestId=req-42', $this->transport->requests[1]['url'] );
		$this->assertSame( 'SUCCESS', $log->all()[0]['result'] );
		$this->assertSame( array(), $this->scheduled, 'A finished request is not asked about again.' );
	}

	public function test_a_still_processing_request_is_recorded_and_asked_about_again(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			array(
				'destination' => 'G-ABC123',
				'request_id'  => 'req-42',
			)
		);

		$this->queue_status( 200, self::status_body( 'PROCESSING' ) );

		( new StatusPoller( $this->ingest(), $log ) )->poll( self::payload( array( 'account' => $this->account ) ) );

		$this->assertSame( 'PROCESSING', $log->all()[0]['result'], 'What is known right now is written down: "still processing" is not the same as never having heard back.' );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 2340, $this->scheduled[0]['delay'] );
		$this->assertSame( 2340, $this->scheduled[0]['payload']['interval'] );
		$this->assertSame( 1800 + 2340, $this->scheduled[0]['payload']['elapsed'] );
	}

	public function test_a_request_with_one_destination_still_running_keeps_polling(): void {
		$this->assertFalse(
			StatusPoller::is_finished(
				array(
					array(
						'measurement' => 'G-AAA',
						'status'      => 'SUCCESS',
					),
					array(
						'measurement' => 'G-BBB',
						'status'      => 'PROCESSING',
					),
				)
			),
			'One destination still running is enough to keep asking - the other one is already recorded.'
		);

		$this->assertTrue(
			StatusPoller::is_finished(
				array(
					array(
						'measurement' => 'G-AAA',
						'status'      => 'SUCCESS',
					),
					array(
						'measurement' => 'G-BBB',
						'status'      => 'FAILED',
					),
				)
			),
			'A destination that failed is finished too; only "still running" keeps the poller going.'
		);
	}

	public function test_an_answer_with_no_destinations_is_not_treated_as_finished(): void {
		$this->assertFalse(
			StatusPoller::is_finished( array() ),
			'A shape we did not expect is not evidence of anything; the 24 hour window bounds the uncertainty instead.'
		);
	}

	public function test_a_refused_status_request_is_retried_without_being_recorded_as_a_result(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			array(
				'destination' => 'G-ABC123',
				'request_id'  => 'req-42',
			)
		);

		$this->queue_status( 503, array( 'error' => array( 'status' => 'UNAVAILABLE' ) ) );

		( new StatusPoller( $this->ingest(), $log ) )->poll( self::payload( array( 'account' => $this->account ) ) );

		$this->assertSame(
			'',
			$log->all()[0]['result'],
			'Failing to ask says nothing about the send; writing it down as a result would be a claim we cannot make.'
		);
		$this->assertCount( 1, $this->scheduled, 'It is simply asked again.' );
	}

	public function test_the_polling_stops_after_twenty_four_hours(): void {
		$log = new SendLog( static fn () => self::NOW );
		$this->queue_status( 200, self::status_body( 'PROCESSING' ) );

		( new StatusPoller( $this->ingest(), $log ) )->poll(
			self::payload(
				array(
					'account'  => $this->account,
					'interval' => 3600,
					'elapsed'  => StatusPoller::MAX_ELAPSED - 100,
				)
			)
		);

		$this->assertSame( array(), $this->scheduled, 'The next check would fall outside the 24 hour window Google gives itself.' );
	}

	public function test_polling_continues_while_the_next_check_still_fits_in_the_window(): void {
		$log = new SendLog( static fn () => self::NOW );
		$this->queue_status( 200, self::status_body( 'PROCESSING' ) );

		( new StatusPoller( $this->ingest(), $log ) )->poll(
			self::payload(
				array(
					'account'  => $this->account,
					'interval' => 3600,
					'elapsed'  => StatusPoller::MAX_ELAPSED - 3600,
				)
			)
		);

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( StatusPoller::MAX_ELAPSED, $this->scheduled[0]['payload']['elapsed'] );
	}

	public function test_a_payload_that_is_not_a_job_of_ours_does_nothing(): void {
		$poller = new StatusPoller( $this->ingest(), new SendLog( static fn () => self::NOW ) );

		$poller->poll( 'not-an-array' );
		$poller->poll( self::payload( array( 'request_id' => '' ) ) );
		$poller->poll( self::payload( array( 'account' => '' ) ) );

		$this->assertSame( array(), $this->transport->requests, 'Nothing is asked of Google for a job that cannot name what to ask about - not even a token is minted.' );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_the_queue_handler_is_registered_on_the_status_hook(): void {
		$poller = new StatusPoller( $this->ingest(), new SendLog() );
		$poller->register_hooks();

		$this->assertNotFalse( has_action( SendQueue::HOOK_STATUS, array( $poller, 'poll' ) ) );
	}

	public function test_the_published_schedule_constants_are_the_documented_ones(): void {
		$this->assertSame( 1800, StatusPoller::FIRST_DELAY, '30 minutes before the first request.' );
		$this->assertSame( 1300, StatusPoller::BACKOFF_PERMILLE, 'A backoff multiplier of 1.3.' );
		$this->assertSame( 3600, StatusPoller::MAX_INTERVAL, 'A maximum backoff of 60 minutes.' );
		$this->assertSame( 86400, StatusPoller::MAX_ELAPSED, 'A maximum total time of 24 hours.' );
	}

	public function test_the_terminal_states_come_from_the_ingest_contract(): void {
		$this->assertTrue( EventsIngest::is_terminal_status( EventsIngest::STATUS_SUCCESS ) );
		$this->assertFalse( EventsIngest::is_terminal_status( EventsIngest::STATUS_PROCESSING ) );
	}
}
