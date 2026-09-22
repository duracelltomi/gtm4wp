<?php
/**
 * Unit tests for the Google Data Manager abilities.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Module\AbilitiesInterface;
use GTM4WP\Modules\GoogleDataManager\Abilities;
use GTM4WP\Modules\GoogleDataManager\AdminSchema;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationProbe;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\EventsIngest;
use GTM4WP\Modules\GoogleDataManager\SendLog;
use GTM4WP\Tests\unit\Abilities\AbilitiesTestCase;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;

/**
 * The gtm4wp/get-google-data-manager-log ability hands over the same rows the settings
 * screen lists, through the one shaping SendLog owns. Pinned: the order, the
 * two derived fields, the problems filter, the cap, the queue summary, and -
 * the property that matters most for a transcript - that a row carries the
 * stored allow-list of members and nothing else. Plus the hand-over: the
 * module's admin schema is how the Registrar finds this provider.
 *
 * The two actions share their body with the REST routes (DestinationProbe,
 * RefundReplay), whose tests pin the wire format and the replay plan. Pinned
 * here are the ability's own guards: the measurement ID resolved against the
 * stored destinations before anything leaves the site, the confirmation the
 * replay demands, and the write switch. The transport is the recording fake,
 * which throws on a request nobody queued a response for, so "no request"
 * assertions are real.
 */
final class GoogleDataManagerAbilitiesTest extends AbilitiesTestCase {

	private const NOW         = 1_800_000_000;
	private const SECRET      = 'unit-test-site-secret-do-not-reuse';
	private const TOKEN       = 'ya29.unit-test-access-token';
	private const MEASUREMENT = 'G-ABC123';
	private const PROPERTY    = '123456789';

	private SendLog $log;

	private KeyVault $vault;

	private FakeTransport $transport;

	private DestinationHealth $health;

	/**
	 * Jobs the replay tests saw queued.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();

		// FakeTransport enforces the real allow-list via WpTransport::is_allowed_url().
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->scheduled = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $when, $hook, $args, $group ) {
				$this->scheduled[] = array(
					'hook'    => $hook,
					'payload' => $args[0],
				);
				return count( $this->scheduled );
			}
		);

		$clock = static fn () => self::NOW;

		$this->log       = new SendLog( $clock );
		$this->vault     = new KeyVault( self::SECRET, $clock );
		$this->transport = new FakeTransport();
		$this->health    = new DestinationHealth( $clock );

		$tokens = new TokenService( $this->vault, $this->transport, $clock );
		$probe  = new DestinationProbe( $this->vault, new EventsIngest( $tokens, $this->transport, $clock ), $this->health );

		( new Abilities( $this->log, null, $probe ) )->register();
	}

	/**
	 * Records one row.
	 *
	 * @param string $reference The refund reference.
	 * @param string $outcome   One of the OUTCOME_* values.
	 * @param string $reason    Reason code, for a skip.
	 * @return void
	 */
	private function record( string $reference, string $outcome, string $reason = '' ): void {
		$this->log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => $reference,
				'destination' => SendLog::OUTCOME_SKIPPED === $outcome ? '' : self::MEASUREMENT,
				'outcome'     => $outcome,
				'attempt'     => 1,
				'status'      => SendLog::OUTCOME_ACCEPTED === $outcome ? 200 : 0,
				'request_id'  => SendLog::OUTCOME_ACCEPTED === $outcome ? 'req-' . $reference : '',
				'reason'      => $reason,
			)
		);
	}

	/**
	 * Stores the fixture key and returns its account id.
	 *
	 * @return string
	 */
	private function store_account(): string {
		$id = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $id );

		return $id;
	}

	/**
	 * Seeds the settings row with one destination over the given account and
	 * the refund lane on.
	 *
	 * @param string $account_id  Service-account id of the destination.
	 * @param bool   $sending     Whether the refund lane is on.
	 * @return void
	 */
	private function store_destination( string $account_id, bool $sending = true ): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GDM_DESTINATIONS => array(
					array(
						DestinationRows::COLUMN_LABEL    => 'Shop GA4',
						DestinationRows::COLUMN_ACCOUNT  => $account_id,
						DestinationRows::COLUMN_TYPE     => DestinationRows::TYPE_GA4,
						DestinationRows::COLUMN_PROPERTY => self::PROPERTY,
						DestinationRows::COLUMN_MEASUREMENT => self::MEASUREMENT,
					),
				),
				GTM4WP_OPTION_GDM_SEND_REFUNDS => $sending,
			)
		);
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
				'access_token' => self::TOKEN,
				'expires_in'   => 3600,
			)
		);
	}

	// ---- Hand-over ---------------------------------------------------------

	public function test_the_module_schema_hands_the_provider_to_the_registrar(): void {
		$this->registered = array();

		$schema = new AdminSchema();
		$this->assertInstanceOf( AbilitiesInterface::class, $schema, 'Opting in on the admin schema is how a module reaches the abilities surface.' );

		$provider = $schema->abilities();
		$this->assertInstanceOf( Abilities::class, $provider );

		$provider->register();

		$this->assertSame( array( Abilities::GET_LOG, Abilities::TEST_DESTINATION, Abilities::REPLAY_REFUNDS ), array_keys( $this->registered ), 'The provider the schema builds registers the module abilities, nothing else.' );
	}

	// ---- get-google-data-manager-log ---------------------------------------

	public function test_the_log_is_returned_newest_first_with_the_derived_fields(): void {
		$this->record( 'woocommerce:10:11', SendLog::OUTCOME_ACCEPTED );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_FAILED );

		$result = $this->execute( Abilities::GET_LOG );

		$this->assertCount( 2, $result['entries'] );
		$this->assertSame( 'woocommerce:12:13', $result['entries'][0]['reference'], 'Newest first.' );
		$this->assertSame( SendLog::TONE_ERROR, $result['entries'][0]['tone'] );
		$this->assertTrue( $result['entries'][0]['replayable'] );
		$this->assertSame( SendLog::TONE_PENDING, $result['entries'][1]['tone'] );
		$this->assertFalse( $result['entries'][1]['replayable'] );
	}

	public function test_a_row_carries_the_stored_members_and_the_two_derived_ones_only(): void {
		$this->record( 'woocommerce:10:11', SendLog::OUTCOME_ACCEPTED );

		$entry = $this->execute( Abilities::GET_LOG )['entries'][0];

		$this->assertSame(
			array( 'time', 'feature', 'reference', 'destination', 'outcome', 'attempt', 'status', 'request_id', 'reason', 'result', 'errors', 'warnings', 'tone', 'replayable' ),
			array_keys( $entry ),
			'No token, no key material, no response body: the allow-list SendLog writes is the allow-list the ability reads.'
		);
	}

	public function test_problems_only_keeps_the_rows_that_need_attention(): void {
		$this->record( 'woocommerce:10:11', SendLog::OUTCOME_ACCEPTED );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_SKIPPED, 'no_destination' );
		$this->record( 'woocommerce:14:15', SendLog::OUTCOME_FAILED );

		$entries = $this->execute( Abilities::GET_LOG, array( 'problems_only' => true ) )['entries'];

		$this->assertSame( array( 'woocommerce:14:15', 'woocommerce:12:13' ), array_column( $entries, 'reference' ) );
		$this->assertSame( array( SendLog::TONE_ERROR, SendLog::TONE_WARN ), array_column( $entries, 'tone' ) );
	}

	public function test_the_limit_cuts_the_newest_rows(): void {
		$this->record( 'woocommerce:10:11', SendLog::OUTCOME_ACCEPTED );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_ACCEPTED );
		$this->record( 'woocommerce:14:15', SendLog::OUTCOME_ACCEPTED );

		$entries = $this->execute( Abilities::GET_LOG, array( 'limit' => 2 ) )['entries'];

		$this->assertSame( array( 'woocommerce:14:15', 'woocommerce:12:13' ), array_column( $entries, 'reference' ) );
	}

	public function test_a_limit_outside_the_schema_is_clamped_rather_than_trusted(): void {
		$this->record( 'woocommerce:10:11', SendLog::OUTCOME_ACCEPTED );

		$this->assertCount( 1, $this->execute( Abilities::GET_LOG, array( 'limit' => 0 ) )['entries'] );
		$this->assertCount( 1, $this->execute( Abilities::GET_LOG, array( 'limit' => 10_000 ) )['entries'] );
		$this->assertSame( SendLog::MAX_ENTRIES, Abilities::MAX_LIMIT, 'The cap is the ring size; a larger limit could never return more.' );
	}

	public function test_an_empty_log_answers_with_an_empty_list_and_the_queue_summary(): void {
		$result = $this->execute( Abilities::GET_LOG );

		$this->assertSame( array(), $result['entries'] );
		$this->assertSame(
			array(
				'to_send'       => 0,
				'status_checks' => 0,
				'backend'       => 'action-scheduler',
			),
			$result['queue'],
			'The as_* stubs of the base fixture make Action Scheduler the detected backend.'
		);
	}

	// ---- test-google-data-manager-destination ------------------------------

	public function test_the_probe_of_a_stored_destination_sends_the_validate_only_request_and_reports_success(): void {
		$this->store_destination( $this->store_account() );
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$result = $this->execute( Abilities::TEST_DESTINATION, array( 'measurement_id' => self::MEASUREMENT ) );

		$this->assertSame( array( 'ok', 'message' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
		$this->assertStringNotContainsString( self::TOKEN, serialize( $result ), 'No access token.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening to search.

		$this->assertCount( 2, $this->transport->requests, 'One token exchange, one ingest probe - the REST route\'s test pins the bytes.' );
		$probe = $this->transport->requests[1];
		$this->assertSame( EventsIngest::ENDPOINT, $probe['url'] );
		$this->assertTrue( $probe['body']['validateOnly'] );
		$this->assertSame( self::PROPERTY, $probe['body']['destinations'][0]['operatingAccount']['accountId'], 'The property id comes from the stored row, never from the call.' );
		$this->assertSame( self::MEASUREMENT, $probe['body']['destinations'][0]['productDestinationId'] );
	}

	public function test_the_measurement_id_is_matched_after_normalisation(): void {
		$this->store_destination( $this->store_account() );
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$result = $this->execute( Abilities::TEST_DESTINATION, array( 'measurement_id' => ' g-abc123 ' ) );

		$this->assertTrue( $result['ok'], 'A lowercase paste names the same stored row, as on the settings screen.' );
	}

	/**
	 * Measurement IDs that name no stored destination. Each is refused with
	 * 404 before anything leaves the site: the fake transport would throw on
	 * a request nobody queued a response for.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unknown_destinations(): array {
		return array(
			'well-formed but not stored' => array( array( 'measurement_id' => 'G-NOTSTORED' ) ),
			'hostile'                    => array( array( 'measurement_id' => 'G-"</script>' ) ),
			'not a string'               => array( array( 'measurement_id' => array( self::MEASUREMENT ) ) ),
			'empty'                      => array( array( 'measurement_id' => '' ) ),
			'missing'                    => array( array() ),
		);
	}

	/**
	 * An unknown measurement id is 404 and sends nothing.
	 *
	 * @param array<string, mixed> $input The input.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unknown_destinations' )]
	public function test_an_unknown_measurement_id_is_refused_with_404_before_any_request( array $input ): void {
		$this->store_destination( $this->store_account() );

		$result = $this->execute( Abilities::TEST_DESTINATION, $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_gdm_destination_unknown', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	public function test_a_stored_destination_whose_account_is_gone_is_refused_with_404_before_any_request(): void {
		// A well-formed id the vault does not hold: the account was deleted
		// after the destination was saved.
		$this->store_destination( 'sa_ffffffffffff' );

		$result = $this->execute( Abilities::TEST_DESTINATION, array( 'measurement_id' => self::MEASUREMENT ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	public function test_a_google_refusal_is_a_normal_answer_with_the_reason(): void {
		$this->store_destination( $this->store_account() );
		$this->queue_token();
		$this->transport->will_respond_json(
			403,
			array(
				'error' => array(
					'code'    => 403,
					'status'  => 'PERMISSION_DENIED',
					'message' => 'The caller does not have permission on the property.',
				),
			)
		);

		$result = $this->execute( Abilities::TEST_DESTINATION, array( 'measurement_id' => self::MEASUREMENT ) );

		$this->assertIsArray( $result, 'A refused probe is a result the assistant explains, not an error.' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $result['message'] );
	}

	public function test_a_passing_probe_clears_the_destinations_failure_streak(): void {
		$this->store_destination( $this->store_account() );
		for ( $i = 0; $i < DestinationHealth::FAILURE_THRESHOLD; $i++ ) {
			$this->health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED', 'PERMISSION_DENIED' );
		}
		$this->assertTrue( $this->health->is_failing( self::MEASUREMENT ) );

		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );
		$this->execute( Abilities::TEST_DESTINATION, array( 'measurement_id' => self::MEASUREMENT ) );

		$this->assertFalse( $this->health->is_failing( self::MEASUREMENT ), 'The same side effect as the panel\'s Test button: the notice ends now, not at the next refund.' );
	}

	// ---- replay-google-data-manager-refunds --------------------------------

	/**
	 * Calls that do not carry the confirmation.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unconfirmed_calls(): array {
		return array(
			'confirm missing' => array( array() ),
			'confirm false'   => array( array( 'confirm' => false ) ),
			'confirm null'    => array( array( 'confirm' => null ) ),
			'confirm string'  => array( array( 'confirm' => 'true' ) ),
			'confirm 1'       => array( array( 'confirm' => 1 ) ),
		);
	}

	/**
	 * Without `confirm: true` nothing runs: no plan, no job, no request.
	 *
	 * @param array<string, mixed> $input The input.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unconfirmed_calls' )]
	public function test_a_replay_without_confirm_true_is_refused_before_anything_runs( array $input ): void {
		$this->store_destination( $this->store_account() );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_FAILED );

		$result = $this->execute( Abilities::REPLAY_REFUNDS, $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_confirmation_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->scheduled, 'Nothing was queued.' );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	public function test_a_confirmed_replay_queues_each_replayable_refund_once(): void {
		$this->store_destination( $this->store_account() );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_FAILED );
		$this->record( 'woocommerce:14:15', SendLog::OUTCOME_ACCEPTED );

		$result = $this->execute( Abilities::REPLAY_REFUNDS, array( 'confirm' => true ) );

		$this->assertSame(
			array(
				'queued'     => 1,
				'references' => array( 'woocommerce:12:13' ),
			),
			$result
		);
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame(
			array(
				'platform'  => 'woocommerce',
				'order_id'  => 12,
				'refund_id' => 13,
				'attempt'   => 1,
				'only'      => array( self::MEASUREMENT ),
			),
			$this->scheduled[0]['payload'],
			'A fresh first attempt aimed at the destination still failing - the REST route\'s plan, through the shared RefundReplay.'
		);
		$this->assertSame( array(), $this->transport->requests, 'Nothing is sent by the call itself; the job runs later.' );
	}

	public function test_a_replay_can_be_limited_to_named_references(): void {
		$this->store_destination( $this->store_account() );
		$this->record( 'woocommerce:1:2', SendLog::OUTCOME_FAILED );
		$this->record( 'woocommerce:3:4', SendLog::OUTCOME_FAILED );

		$result = $this->execute(
			Abilities::REPLAY_REFUNDS,
			array(
				'confirm'    => true,
				'references' => array( 'woocommerce:3:4', 'not a reference', 42 ),
			)
		);

		$this->assertSame( array( 'woocommerce:3:4' ), $result['references'] );
		$this->assertCount( 1, $this->scheduled );
	}

	public function test_a_replay_is_refused_while_sending_is_off(): void {
		$this->store_destination( $this->store_account(), false );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_FAILED );

		$result = $this->execute( Abilities::REPLAY_REFUNDS, array( 'confirm' => true ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_gdm_sending_off', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->scheduled, 'A job queued now would be dropped by the sender with no row to say so.' );
	}

	public function test_a_replay_reads_the_lane_switch_fresh_not_from_a_request_scoped_copy(): void {
		// The provider was built in setUp with no Options injected; the row
		// is written after that, and the ability must still see it.
		$this->store_destination( $this->store_account() );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_FAILED );

		$this->assertSame( 1, $this->execute( Abilities::REPLAY_REFUNDS, array( 'confirm' => true ) )['queued'] );

		$this->store_destination( $this->store_account(), false );

		$this->assertInstanceOf( \WP_Error::class, $this->execute( Abilities::REPLAY_REFUNDS, array( 'confirm' => true ) ), 'Turned off in between: seen.' );
	}

	// ---- The write switch (TS-12: both halves) -----------------------------

	public function test_a_read_only_site_lists_the_log_but_neither_action(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );

		$this->registered = array();
		( new Abilities( $this->log ) )->register();

		$this->assertSame( array( Abilities::GET_LOG ), array_keys( $this->registered ) );
	}

	public function test_an_action_run_after_the_switch_flipped_refuses_with_403_and_does_nothing(): void {
		$this->store_destination( $this->store_account() );
		$this->record( 'woocommerce:12:13', SendLog::OUTCOME_FAILED );
		$this->assertArrayHasKey( Abilities::TEST_DESTINATION, $this->registered, 'Registered while writes were allowed.' );

		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );

		foreach ( array(
			Abilities::TEST_DESTINATION => array( 'measurement_id' => self::MEASUREMENT ),
			Abilities::REPLAY_REFUNDS   => array( 'confirm' => true ),
		) as $name => $input ) {
			$result = $this->execute( $name, $input );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'gtm4wp_abilities_write_disabled', $result->get_error_code(), $name );
			$this->assertSame( 403, $result->get_error_data()['status'], $name );
		}

		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
		$this->assertSame( array(), $this->scheduled, 'Nothing was queued.' );
	}
}
