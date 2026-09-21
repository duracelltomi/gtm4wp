<?php
/**
 * Unit tests for the refund send lane.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Modules\GoogleDataManager\ConsentPolicy;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\EventsIngest;
use GTM4WP\Modules\GoogleDataManager\GoogleDataManagerModule;
use GTM4WP\Modules\GoogleDataManager\RefundData;
use GTM4WP\Modules\GoogleDataManager\RefundSender;
use GTM4WP\Modules\GoogleDataManager\RefundSource;
use GTM4WP\Modules\GoogleDataManager\SendLog;
use GTM4WP\Modules\GoogleDataManager\SendQueue;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The lane's job is to decide once, per refund, whether an event may be sent -
 * and to say why whenever it may not. Both halves are load-bearing:
 *
 * - The consent gate is the first production consumer of the policy phase 3
 *   built. Where the policy applies, a denied or simply unknown analytics
 *   signal refuses the send, and the reason code goes into the diagnostics
 *   ring. That is not only the legal reading: with analytics storage denied the
 *   stored client id can be an ephemeral one, and an event sent with it is
 *   permanently unmatchable.
 * - Nothing is ever guessed. An order with no captured client id is skipped
 *   with that reason, never sent with an invented identifier.
 *
 * The retry rules matter for a subtler reason: a request that succeeded for one
 * destination and failed for another must be repeated only for the one that
 * failed, because Google has no documented rule that would collapse two refunds
 * of one transaction back into one.
 */
final class GoogleDataManagerRefundSenderTest extends TestCase {

	use OptionStoreTrait;

	private const NOW    = 1_800_000_000;
	private const SECRET = 'unit-test-site-secret-do-not-reuse';

	private FakeTransport $transport;

	private KeyVault $vault;

	private string $account;

	private SendLog $log;

	private DestinationHealth $health;

	/**
	 * Every job queued during the test.
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
		$this->vault     = new KeyVault( self::SECRET );

		$account = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $account );
		$this->account = $account;

		$this->log       = new SendLog( static fn () => self::NOW );
		$this->health    = new DestinationHealth( static fn () => self::NOW );
		$this->scheduled = array();

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args = array(), $group = '' ) {
				$this->scheduled[] = array(
					'hook'    => $hook,
					'payload' => $args[0] ?? array(),
					// Two wall-clock reads (SendQueue's and this one), so a second
					// boundary between them reads one less; the assertions allow it.
					'delay'   => $timestamp - time(),
				);

				return 1;
			}
		);
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
	}

	/**
	 * Installs the plugin options over a stored array.
	 *
	 * @param array<string, mixed> $stored Stored option values, merged over the defaults.
	 * @return Options
	 */
	private function options( array $stored = array() ): Options {
		$defaults = ( new GoogleDataManagerModule() )->defaults();

		$this->options['gtm4wp-options'] = array_merge(
			array(
				GTM4WP_OPTION_GDM_SEND_REFUNDS   => true,
				GTM4WP_OPTION_GDM_CONSENT_POLICY => ConsentPolicy::POLICY_EEA_ONLY,
				GTM4WP_OPTION_GDM_DESTINATIONS   => array( $this->destination( 'G-ABC123' ) ),
			),
			$stored
		);

		return new Options( $defaults );
	}

	/**
	 * One destination row on the stored service account.
	 *
	 * @param string $measurement Measurement id.
	 * @param string $property    Property id.
	 * @return array<string, string>
	 */
	private function destination( string $measurement, string $property = '123456789' ): array {
		return array(
			DestinationRows::COLUMN_LABEL       => 'Shop',
			DestinationRows::COLUMN_ACCOUNT     => $this->account,
			DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
			DestinationRows::COLUMN_PROPERTY    => $property,
			DestinationRows::COLUMN_MEASUREMENT => $measurement,
		);
	}

	/**
	 * A refund adapter double.
	 *
	 * It answers exactly the RefundSource contract and no more: load() returns
	 * a RefundData or null, and the sent marker really is remembered, so a
	 * second run of the same job behaves as it would in production (UC-3).
	 *
	 * @param RefundData|null $refund What load() returns.
	 * @param bool            $active Whether the platform reports itself active.
	 * @return RefundSource
	 */
	private function source( ?RefundData $refund, bool $active = true ): RefundSource {
		return new class( $refund, $active ) implements RefundSource {
			/**
			 * Refund ids marked sent, to the request id recorded for each.
			 *
			 * @var array<int, string>
			 */
			public array $sent = array();

			/**
			 * How many times load() was called.
			 */
			public int $loads = 0;

			/**
			 * Builds the double.
			 *
			 * @param RefundData|null $refund The refund to answer with.
			 * @param bool            $active Platform availability.
			 */
			public function __construct( private ?RefundData $refund, private bool $active ) {
			}

			public function platform(): string {
				return self::PLATFORM_WOOCOMMERCE;
			}

			public function is_active(): bool {
				return $this->active;
			}

			public function register_hooks( callable $enqueue ): void {
			}

			public function load( int $order_id, int $refund_id ): ?RefundData {
				++$this->loads;

				return $this->refund;
			}

			public function objects( int $order_id, int $refund_id ): array {
				return array( null, null );
			}

			public function is_sent( int $refund_id ): bool {
				return isset( $this->sent[ $refund_id ] );
			}

			public function mark_sent( int $refund_id, string $request_id ): void {
				$this->sent[ $refund_id ] = $request_id;
			}
		};
	}

	/**
	 * A refund description.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 * @return RefundData
	 */
	private static function refund( array $overrides = array() ): RefundData {
		$values = array_merge(
			array(
				'amount'          => 40.0,
				'order_total'     => 100.0,
				'client_id'       => '313930999.1788522497',
				'consent_state'   => array(
					'signals'     => array( 'analytics_storage' => 'granted' ),
					'captured_at' => self::NOW,
				),
				'billing_country' => 'DE',
				'items'           => array(),
			),
			$overrides
		);

		return new RefundData(
			RefundSource::PLATFORM_WOOCOMMERCE,
			12,
			34,
			'WC-1001',
			'EUR',
			$values['amount'],
			$values['order_total'],
			self::NOW,
			$values['items'],
			$values['client_id'],
			$values['consent_state'],
			$values['billing_country']
		);
	}

	/**
	 * The sender over one adapter.
	 *
	 * @param RefundSource         $source The adapter.
	 * @param array<string, mixed> $stored Stored options.
	 * @return RefundSender
	 */
	private function sender( RefundSource $source, array $stored = array() ): RefundSender {
		$options = $this->options( $stored );
		$clock   = static fn () => self::NOW;

		return new RefundSender(
			$options,
			new EventsIngest( new TokenService( $this->vault, $this->transport, $clock ), $this->transport, $clock ),
			$this->health,
			$this->log,
			array( $source )
		);
	}

	/**
	 * A queued send job payload.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 * @return array<string, mixed>
	 */
	private static function job( array $overrides = array() ): array {
		return array_merge(
			array(
				'platform'  => RefundSource::PLATFORM_WOOCOMMERCE,
				'order_id'  => 12,
				'refund_id' => 34,
				'attempt'   => 1,
			),
			$overrides
		);
	}

	/**
	 * Queues a token exchange and one ingest answer.
	 *
	 * @param int        $status HTTP status of the ingest call.
	 * @param array|null $body   Its decoded body.
	 * @return void
	 */
	private function queue_send( int $status, ?array $body ): void {
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
	 * The single entry the ring holds.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(): array {
		$entries = $this->log->all();
		$this->assertCount( 1, $entries );

		return $entries[0];
	}

	// ---- The happy path ----------------------------------------------------

	public function test_an_allowed_refund_is_sent_marked_and_logged(): void {
		$source = $this->source( self::refund() );
		$this->queue_send( 200, array( 'requestId' => 'req-42' ) );

		$this->sender( $source )->run( self::job() );

		$this->assertSame( array( 34 => 'req-42' ), $source->sent, 'The refund is marked sent with the request the send returned.' );

		$entry = $this->entry();
		$this->assertSame( SendLog::OUTCOME_ACCEPTED, $entry['outcome'] );
		$this->assertSame( 'woocommerce:12:34', $entry['reference'] );
		$this->assertSame( 'G-ABC123', $entry['destination'] );
		$this->assertSame( 'req-42', $entry['request_id'] );
		$this->assertSame( 200, $entry['status'] );

		$this->assertSame( self::NOW, $this->health->get( 'G-ABC123' )['last_success'] );
	}

	public function test_an_accepted_send_queues_the_status_poll(): void {
		$this->queue_send( 200, array( 'requestId' => 'req-42' ) );

		$this->sender( $this->source( self::refund() ) )->run( self::job() );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( SendQueue::HOOK_STATUS, $this->scheduled[0]['hook'] );
		$this->assertSame( 'req-42', $this->scheduled[0]['payload']['request_id'] );
		$this->assertSame( $this->account, $this->scheduled[0]['payload']['account'] );
	}

	public function test_the_consent_signals_ride_along_as_the_request_level_field(): void {
		$this->queue_send( 200, array( 'requestId' => 'req-42' ) );

		$this->sender(
			$this->source(
				self::refund(
					array(
						'consent_state' => array(
							'signals' => array(
								'analytics_storage'  => 'granted',
								'ad_user_data'       => 'granted',
								'ad_personalization' => 'denied',
							),
						),
					)
				)
			)
		)->run( self::job() );

		$this->assertSame(
			array(
				'adUserData'        => 'CONSENT_GRANTED',
				'adPersonalization' => 'CONSENT_DENIED',
			),
			$this->transport->requests[1]['body']['consent']
		);
	}

	// ---- The consent gate --------------------------------------------------

	public function test_a_denied_analytics_signal_refuses_the_send_in_the_eea(): void {
		$source = $this->source(
			self::refund( array( 'consent_state' => array( 'signals' => array( 'analytics_storage' => 'denied' ) ) ) )
		);

		$this->sender( $source )->run( self::job() );

		$this->assertSame( array(), $this->transport->requests, 'Nothing leaves the site, not even a token exchange.' );
		$this->assertSame( ConsentPolicy::REASON_DENIED, $this->entry()['reason'] );
		$this->assertSame( SendLog::OUTCOME_SKIPPED, $this->entry()['outcome'] );
		$this->assertSame( array(), $source->sent );
	}

	public function test_an_unknown_consent_state_also_refuses_in_the_eea(): void {
		$this->sender( $this->source( self::refund( array( 'consent_state' => null ) ) ) )->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame(
			ConsentPolicy::REASON_UNKNOWN,
			$this->entry()['reason'],
			'Unknown is not denied, but where the policy applies it is not a permission either - and the stored client id could be an ephemeral one.'
		);
	}

	public function test_a_buyer_outside_the_region_is_sent_without_a_consent_state(): void {
		$this->queue_send( 200, array( 'requestId' => 'req-42' ) );

		$this->sender(
			$this->source(
				self::refund(
					array(
						'billing_country' => 'US',
						'consent_state'   => null,
					)
				)
			)
		)->run( self::job() );

		$this->assertSame( SendLog::OUTCOME_ACCEPTED, $this->entry()['outcome'] );
	}

	public function test_the_always_policy_refuses_an_unknown_state_outside_the_region_too(): void {
		$this->sender(
			$this->source(
				self::refund(
					array(
						'billing_country' => 'US',
						'consent_state'   => null,
					)
				)
			),
			array( GTM4WP_OPTION_GDM_CONSENT_POLICY => ConsentPolicy::POLICY_ALWAYS )
		)->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame( ConsentPolicy::REASON_UNKNOWN, $this->entry()['reason'] );
	}

	public function test_the_never_policy_sends_even_on_a_denial(): void {
		$this->queue_send( 200, array( 'requestId' => 'req-42' ) );

		$this->sender(
			$this->source(
				self::refund( array( 'consent_state' => array( 'signals' => array( 'analytics_storage' => 'denied' ) ) ) )
			),
			array( GTM4WP_OPTION_GDM_CONSENT_POLICY => ConsentPolicy::POLICY_NEVER )
		)->run( self::job() );

		$this->assertSame(
			SendLog::OUTCOME_ACCEPTED,
			$this->entry()['outcome'],
			'The site owner asserted their own lawful basis; the field description is where that decision is stated.'
		);
	}

	// ---- Nothing is guessed ------------------------------------------------

	public function test_an_order_with_no_client_id_is_skipped_rather_than_sent(): void {
		$this->sender( $this->source( self::refund( array( 'client_id' => '' ) ) ) )->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame(
			RefundSender::REASON_NO_CLIENT_ID,
			$this->entry()['reason'],
			'An event Analytics cannot join to its purchase is worse than an honest gap.'
		);
	}

	public function test_a_missing_client_id_names_the_buyers_refusal_when_that_is_what_caused_it(): void {
		$this->sender(
			$this->source(
				self::refund(
					array(
						'client_id'     => '',
						'consent_state' => array( 'signals' => array( 'analytics_storage' => 'denied' ) ),
					)
				)
			)
		)->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame(
			RefundSender::REASON_CONSENT_NO_CLIENT_ID,
			$this->entry()['reason'],
			'The refusal is the cause and the missing id only the symptom; a store owner told the latter would go looking for a setup fault that is not there.'
		);
	}

	public function test_a_missing_client_id_stays_a_setup_question_when_the_buyer_allowed_analytics(): void {
		$this->sender(
			$this->source(
				self::refund(
					array(
						'client_id'     => '',
						'consent_state' => array( 'signals' => array( 'analytics_storage' => 'granted' ) ),
					)
				)
			)
		)->run( self::job() );

		$this->assertSame( RefundSender::REASON_NO_CLIENT_ID, $this->entry()['reason'] );
	}

	public function test_a_missing_client_id_with_no_stored_answer_stays_a_setup_question(): void {
		$this->sender(
			$this->source(
				self::refund(
					array(
						'client_id'     => '',
						'consent_state' => null,
					)
				)
			)
		)->run( self::job() );

		$this->assertSame(
			RefundSender::REASON_NO_CLIENT_ID,
			$this->entry()['reason'],
			'An order predating the feature has no answer stored, and blaming consent for it would be an invention.'
		);
	}

	public function test_the_never_policy_cannot_send_an_order_whose_buyer_refused_analytics_storage(): void {
		$this->sender(
			$this->source(
				self::refund(
					array(
						'client_id'     => '',
						'consent_state' => array( 'signals' => array( 'analytics_storage' => 'denied' ) ),
					)
				)
			),
			array( GTM4WP_OPTION_GDM_CONSENT_POLICY => ConsentPolicy::POLICY_NEVER )
		)->run( self::job() );

		// "Never" waives the transfer rule for data the site holds. It cannot
		// invent an identifier the browser was never allowed to keep - and one
		// captured under a denial would be a fresh per-pageview value that
		// matches no purchase anyway.
		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame( RefundSender::REASON_CONSENT_NO_CLIENT_ID, $this->entry()['reason'] );
	}

	public function test_a_refund_of_nothing_is_skipped(): void {
		$this->sender( $this->source( self::refund( array( 'amount' => 0.0 ) ) ) )->run( self::job() );

		$this->assertSame( RefundSender::REASON_EMPTY_REFUND, $this->entry()['reason'] );
	}

	public function test_a_refund_that_cannot_be_read_back_is_skipped_with_its_reference(): void {
		$this->sender( $this->source( null ) )->run( self::job() );

		$this->assertSame( RefundSender::REASON_REFUND_UNREADABLE, $this->entry()['reason'] );
		$this->assertSame( 'woocommerce:12:34', $this->entry()['reference'] );
	}

	public function test_the_missing_destination_is_reported_ahead_of_a_per_order_gap(): void {
		$this->sender(
			$this->source(
				self::refund(
					array(
						'client_id'     => '',
						'consent_state' => array( 'signals' => array( 'analytics_storage' => 'denied' ) ),
					)
				)
			),
			array( GTM4WP_OPTION_GDM_DESTINATIONS => array() )
		)->run( self::job() );

		// Both are true of this refund. The one worth reporting is the one that
		// stops every refund on the site and is fixed once, on this screen -
		// not the one that would send the reader auditing orders.
		$this->assertSame( RefundSender::REASON_NO_DESTINATION, $this->entry()['reason'] );
	}

	public function test_no_configured_destination_is_a_reason_not_a_silence(): void {
		$this->sender( $this->source( self::refund() ), array( GTM4WP_OPTION_GDM_DESTINATIONS => array() ) )->run( self::job() );

		$this->assertSame( RefundSender::REASON_NO_DESTINATION, $this->entry()['reason'] );
	}

	public function test_a_vetoed_event_is_recorded_as_such(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_REFUND_EVENT )->once()->andReturn( array() );

		$this->sender( $this->source( self::refund() ) )->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame( RefundSender::REASON_VETOED, $this->entry()['reason'] );
	}

	// ---- Idempotency -------------------------------------------------------

	public function test_a_refund_already_sent_is_never_sent_again(): void {
		$source = $this->source( self::refund() );
		$source->mark_sent( 34, 'req-earlier' );

		$this->sender( $source )->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame( 0, $source->loads, 'The refund is not even read: the marker settles it.' );
		$this->assertSame( array(), $this->log->all(), 'A duplicate job is not an event and does not fill the ring.' );
	}

	public function test_the_feature_being_off_stops_a_job_queued_while_it_was_on(): void {
		$source = $this->source( self::refund() );

		$this->sender( $source, array( GTM4WP_OPTION_GDM_SEND_REFUNDS => false ) )->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame( array(), $this->log->all() );
	}

	// ---- Failures and retries ----------------------------------------------

	public function test_a_server_error_is_retried_on_the_next_rung_of_the_ladder(): void {
		$source = $this->source( self::refund() );
		$this->queue_send( 503, array( 'error' => array( 'status' => 'UNAVAILABLE' ) ) );

		$this->sender( $source )->run( self::job() );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( SendQueue::HOOK_SEND, $this->scheduled[0]['hook'] );
		$this->assertSame( 2, $this->scheduled[0]['payload']['attempt'] );
		$this->assertEqualsWithDelta( 60, $this->scheduled[0]['delay'], 1 );
		$this->assertSame( array( 'G-ABC123' ), $this->scheduled[0]['payload']['only'] );
		$this->assertSame( array(), $source->sent, 'Nothing is marked sent while a retry is still coming.' );
		$this->assertSame( SendLog::OUTCOME_RETRYING, $this->entry()['outcome'] );
	}

	public function test_an_application_error_is_not_retried(): void {
		$this->queue_send( 400, array( 'error' => array( 'status' => 'INVALID_ARGUMENT' ) ) );

		$this->sender( $this->source( self::refund() ) )->run( self::job() );

		$this->assertSame( array(), $this->scheduled, 'Repeating a request Google called wrong would only spend quota on the same rejection.' );
		$this->assertSame( SendLog::OUTCOME_FAILED, $this->entry()['outcome'] );
		$this->assertSame( 1, $this->health->get( 'G-ABC123' )['consecutive_failures'] );
	}

	public function test_the_last_attempt_gives_up_instead_of_queueing_another(): void {
		$this->queue_send( 503, array( 'error' => array( 'status' => 'UNAVAILABLE' ) ) );

		$this->sender( $this->source( self::refund() ) )->run( self::job( array( 'attempt' => 6 ) ) );

		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( SendLog::OUTCOME_FAILED, $this->entry()['outcome'] );
	}

	public function test_two_destinations_on_one_account_share_one_accepted_request(): void {
		$source = $this->source( self::refund() );

		// Two destinations on the same account fit in one request, and one
		// accepted request answers for both.
		$this->queue_send( 200, array( 'requestId' => 'req-ok' ) );

		$sender = $this->sender(
			$source,
			array(
				GTM4WP_OPTION_GDM_DESTINATIONS => array(
					$this->destination( 'G-AAA' ),
					$this->destination( 'G-BBB', '987654321' ),
				),
			)
		);

		$sender->run( self::job() );

		// Both destinations shared the accepted request, so there is nothing to
		// retry and both carry a success record.
		$this->assertSame( array(), $this->scheduled_sends() );
		$this->assertSame( self::NOW, $this->health->get( 'G-AAA' )['last_success'] );
		$this->assertSame( self::NOW, $this->health->get( 'G-BBB' )['last_success'] );
		$this->assertCount( 2, $this->log->all(), 'One entry per destination: they can genuinely differ.' );
	}

	/**
	 * The rule the class doc block is built on, with the fixture that can
	 * actually exercise it: two accounts mean two requests, so one can be
	 * accepted while the other is refused. Only the refused destination may
	 * be retried, the accepted one is polled, and the refund is NOT marked
	 * sent while any part of it is still owed (T89 - every earlier case used
	 * one account, one chunk, so "retry everything on any failure" was green).
	 */
	public function test_a_partly_accepted_send_retries_only_the_refused_destination(): void {
		$second = $this->vault->add( KeyFileFixture::parse(), 'Second' );
		$this->assertIsString( $second );

		$row_b                                    = $this->destination( 'G-BBB', '987654321' );
		$row_b[ DestinationRows::COLUMN_ACCOUNT ] = $second;

		$source = $this->source( self::refund() );

		// Chunk 1 (first account): token + accepted. Chunk 2 (second account):
		// token + a retryable refusal.
		$this->queue_send( 200, array( 'requestId' => 'req-ok' ) );
		$this->queue_send( 503, array( 'error' => array( 'status' => 'UNAVAILABLE' ) ) );

		$this->sender(
			$source,
			array( GTM4WP_OPTION_GDM_DESTINATIONS => array( $this->destination( 'G-AAA' ), $row_b ) )
		)->run( self::job() );

		$sends = $this->scheduled_sends();
		$this->assertCount( 1, $sends, 'Exactly one retry is queued.' );
		$this->assertSame( array( 'G-BBB' ), $sends[0]['payload']['only'], 'Only the refused destination is retried; the accepted one would otherwise take the refund twice.' );
		$this->assertSame( 2, $sends[0]['payload']['attempt'] );

		$this->assertSame( array(), $source->sent, 'The refund is not marked sent while one destination still owes it.' );

		$polls = array_values( array_filter( $this->scheduled, static fn ( $job ) => SendQueue::HOOK_STATUS === $job['hook'] ) );
		$this->assertCount( 1, $polls, 'The accepted request is polled for its processing status.' );
		$this->assertSame( 'req-ok', $polls[0]['payload']['request_id'] );

		$this->assertSame( self::NOW, $this->health->get( 'G-AAA' )['last_success'] );
		$this->assertSame( 1, $this->health->get( 'G-BBB' )['consecutive_failures'] );

		$outcomes = array_column( $this->log->all(), 'outcome', 'destination' );
		$this->assertSame( SendLog::OUTCOME_ACCEPTED, $outcomes['G-AAA'] );
		$this->assertSame( SendLog::OUTCOME_RETRYING, $outcomes['G-BBB'] );
	}

	public function test_a_partly_accepted_send_that_exhausts_its_retries_is_still_never_marked_sent(): void {
		$second = $this->vault->add( KeyFileFixture::parse(), 'Second' );
		$this->assertIsString( $second );

		$row_b                                    = $this->destination( 'G-BBB', '987654321' );
		$row_b[ DestinationRows::COLUMN_ACCOUNT ] = $second;

		$source = $this->source( self::refund() );

		$this->queue_send( 200, array( 'requestId' => 'req-ok' ) );
		$this->queue_send( 503, array( 'error' => array( 'status' => 'UNAVAILABLE' ) ) );

		$this->sender(
			$source,
			array( GTM4WP_OPTION_GDM_DESTINATIONS => array( $this->destination( 'G-AAA' ), $row_b ) )
		)->run( self::job( array( 'attempt' => 6 ) ) );

		$this->assertSame( array(), $this->scheduled_sends(), 'The last attempt queues nothing more.' );
		$this->assertSame( array( 34 => 'req-ok' ), $source->sent, 'With nothing left to retry the accepted half is what the refund is marked sent with.' );

		$outcomes = array_column( $this->log->all(), 'outcome', 'destination' );
		$this->assertSame( SendLog::OUTCOME_ACCEPTED, $outcomes['G-AAA'] );
		$this->assertSame( SendLog::OUTCOME_FAILED, $outcomes['G-BBB'] );
	}

	public function test_a_retry_narrows_the_send_to_the_named_destinations(): void {
		$this->queue_send( 200, array( 'requestId' => 'req-42' ) );

		$this->sender(
			$this->source( self::refund() ),
			array(
				GTM4WP_OPTION_GDM_DESTINATIONS => array(
					$this->destination( 'G-AAA' ),
					$this->destination( 'G-BBB', '987654321' ),
				),
			)
		)->run( self::job( array( 'only' => array( 'G-BBB' ) ) ) );

		$destinations = $this->transport->requests[1]['body']['destinations'];

		$this->assertCount( 1, $destinations, 'A retry must not resend to a destination that already took the event.' );
		$this->assertSame( 'G-BBB', $destinations[0]['productDestinationId'] );
	}

	// ---- Replaying a refund that one destination already took -------------

	public function test_a_targeted_job_runs_for_a_refund_already_marked_sent(): void {
		$source = $this->source( self::refund() );
		// One destination accepted the refund earlier, the other exhausted its
		// retries: the refund carries the sent marker, and G-BBB never got it.
		$source->mark_sent( 34, 'req-earlier' );
		$this->log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-AAA',
				'outcome'     => SendLog::OUTCOME_ACCEPTED,
				'request_id'  => 'req-earlier',
			)
		);
		$this->log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-BBB',
				'outcome'     => SendLog::OUTCOME_FAILED,
				'reason'      => 'UNAVAILABLE',
			)
		);

		$this->queue_send( 200, array( 'requestId' => 'req-replay' ) );

		$this->sender(
			$source,
			array(
				GTM4WP_OPTION_GDM_DESTINATIONS => array(
					$this->destination( 'G-AAA' ),
					$this->destination( 'G-BBB', '987654321' ),
				),
			)
		)->run( self::job( array( 'only' => array( 'G-BBB' ) ) ) );

		$destinations = $this->transport->requests[1]['body']['destinations'];

		$this->assertCount( 1, $destinations, 'The per-refund marker must not block a job aimed at the destination that never got the event.' );
		$this->assertSame( 'G-BBB', $destinations[0]['productDestinationId'] );
	}

	public function test_a_targeted_job_drops_a_destination_whose_newest_row_says_accepted(): void {
		$source = $this->source( self::refund() );
		$source->mark_sent( 34, 'req-earlier' );
		// Two replays were queued before the first ran; the first succeeded.
		$this->log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-BBB',
				'outcome'     => SendLog::OUTCOME_FAILED,
				'reason'      => 'UNAVAILABLE',
			)
		);
		$this->log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-BBB',
				'outcome'     => SendLog::OUTCOME_ACCEPTED,
				'request_id'  => 'req-first-replay',
			)
		);

		$this->sender(
			$source,
			array( GTM4WP_OPTION_GDM_DESTINATIONS => array( $this->destination( 'G-BBB' ) ) )
		)->run( self::job( array( 'only' => array( 'G-BBB' ) ) ) );

		$this->assertSame( array(), $this->transport->requests, 'The second replay finds the destination already served and sends nothing - one refund, one event, per destination.' );
	}

	public function test_an_untargeted_job_is_still_stopped_by_the_sent_marker(): void {
		$source = $this->source( self::refund() );
		$source->mark_sent( 34, 'req-earlier' );

		$this->sender( $source )->run( self::job() );

		$this->assertSame( array(), $this->transport->requests );
	}

	public function test_an_inactive_platform_is_retried_before_it_is_given_up_on(): void {
		$this->sender( $this->source( self::refund(), false ) )->run( self::job() );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 2, $this->scheduled[0]['payload']['attempt'] );
		$this->assertSame( array(), $this->log->all(), 'A plugin-update window is not worth a diagnostics entry yet.' );
	}

	public function test_an_inactive_platform_is_eventually_recorded_as_failed(): void {
		$this->sender( $this->source( self::refund(), false ) )->run( self::job( array( 'attempt' => 6 ) ) );

		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( RefundSender::REASON_PLATFORM_INACTIVE, $this->entry()['reason'] );
		$this->assertSame( SendLog::OUTCOME_FAILED, $this->entry()['outcome'] );
	}

	// ---- Enqueueing --------------------------------------------------------

	public function test_a_refund_is_queued_a_minute_out_with_its_ids(): void {
		$this->sender( $this->source( self::refund() ) )->enqueue( RefundSource::PLATFORM_WOOCOMMERCE, 12, 34 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( SendQueue::HOOK_SEND, $this->scheduled[0]['hook'] );
		$this->assertEqualsWithDelta( 60, $this->scheduled[0]['delay'], 1, 'The platform hooks fire while the refund is still being written.' );
		$this->assertSame(
			array(
				'platform'  => 'woocommerce',
				'order_id'  => 12,
				'refund_id' => 34,
				'attempt'   => 1,
			),
			$this->scheduled[0]['payload']
		);
	}

	public function test_an_impossible_id_pair_is_not_queued(): void {
		$sender = $this->sender( $this->source( self::refund() ) );

		$sender->enqueue( RefundSource::PLATFORM_WOOCOMMERCE, 0, 34 );
		$sender->enqueue( RefundSource::PLATFORM_WOOCOMMERCE, 12, 0 );

		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_job_naming_no_ids_does_nothing(): void {
		$sender = $this->sender( $this->source( self::refund() ) );

		$sender->run( 'not-an-array' );
		$sender->run( self::job( array( 'order_id' => 0 ) ) );

		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame( array(), $this->log->all() );
	}

	public function test_the_queue_handler_is_registered(): void {
		$sender = $this->sender( $this->source( self::refund() ) );
		$sender->register_hooks();

		$this->assertNotFalse( has_action( SendQueue::HOOK_SEND, array( $sender, 'run' ) ) );
	}

	/**
	 * A source double whose register_hooks() hands the enqueue callable back,
	 * so the test can fire it the way the platform hook would. The default
	 * double's register_hooks() is a no-op, which left the inactive-source
	 * skip and the closure's platform binding unexercised (T94c).
	 *
	 * @param string $platform What the source reports as its platform.
	 * @param bool   $active   Whether it reports itself active.
	 * @return RefundSource&object{enqueue: ?callable, registered: int}
	 */
	private function hooking_source( string $platform, bool $active ): RefundSource {
		return new class( $platform, $active ) implements RefundSource {
			/**
			 * The enqueue callable register_hooks() received.
			 *
			 * @var callable|null
			 */
			public $enqueue = null;

			/**
			 * How many times register_hooks() was called.
			 */
			public int $registered = 0;

			/**
			 * Builds the double.
			 *
			 * @param string $platform Platform name.
			 * @param bool   $active   Platform availability.
			 */
			public function __construct( private string $platform, private bool $active ) {
			}

			public function platform(): string {
				return $this->platform;
			}

			public function is_active(): bool {
				return $this->active;
			}

			public function register_hooks( callable $enqueue ): void {
				++$this->registered;
				$this->enqueue = $enqueue;
			}

			public function load( int $order_id, int $refund_id ): ?RefundData {
				return null;
			}

			public function objects( int $order_id, int $refund_id ): array {
				return array( null, null );
			}

			public function is_sent( int $refund_id ): bool {
				return false;
			}

			public function mark_sent( int $refund_id, string $request_id ): void {
			}
		};
	}

	public function test_only_active_sources_get_their_refund_hook_and_each_closure_names_its_own_platform(): void {
		$woo = $this->hooking_source( RefundSource::PLATFORM_WOOCOMMERCE, false );
		$edd = $this->hooking_source( RefundSource::PLATFORM_EDD, true );

		$clock = static fn () => self::NOW;
		( new RefundSender(
			$this->options(),
			new EventsIngest( new TokenService( $this->vault, $this->transport, $clock ), $this->transport, $clock ),
			$this->health,
			$this->log,
			array( $woo, $edd )
		) )->register_hooks();

		$this->assertSame( 0, $woo->registered, 'An inactive platform is not asked to hook anything.' );
		$this->assertSame( 1, $edd->registered );

		( $edd->enqueue )( 12, 34 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'edd', $this->scheduled[0]['payload']['platform'], 'The closure carries the platform of the source it was handed to, so run() loads the refund from the right adapter.' );
	}

	/**
	 * The queued send jobs only, so a status poll queued by a successful chunk
	 * does not read as a retry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function scheduled_sends(): array {
		return array_values(
			array_filter(
				$this->scheduled,
				static fn ( $job ) => SendQueue::HOOK_SEND === $job['hook']
			)
		);
	}
}
