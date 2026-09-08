<?php
/**
 * The server-side refund send lane.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a refund issued in the store into a Google Analytics refund event.
 *
 * This is the flagship of the Data Manager integration and the reason the
 * capture of phase 3 exists: a refund happens in the store admin, where no
 * browser and no Google tag is involved, so the browser-side tracking that
 * reports every purchase cannot report a single refund. Analytics keeps
 * counting revenue that was given back.
 *
 * The lane is deliberately unhurried. The platform hook only queues a job; the
 * job runs a minute later, decides once whether the send is allowed, sends,
 * writes down what happened, and either retries or stops. Nothing about it
 * happens inside the request that issued the refund.
 *
 * Every path that does not send says why, in the diagnostics ring: an absent
 * client id, a consent state that does not permit the send, a veto from the
 * site's own filter, no destination configured. That is the difference between
 * a feature that can be supported and one that can only be guessed at - and
 * none of those cases is ever papered over with an invented value, because a
 * refund event Analytics cannot join to its purchase is worse than an honest
 * gap.
 */
final class RefundSender {

	/**
	 * Skip reason: the commerce platform that issued the refund is not active.
	 */
	public const REASON_PLATFORM_INACTIVE = 'platform_inactive';

	/**
	 * Skip reason: the refund object could not be read back.
	 */
	public const REASON_REFUND_UNREADABLE = 'refund_unreadable';

	/**
	 * Skip reason: the refund returned no money.
	 */
	public const REASON_EMPTY_REFUND = 'empty_refund';

	/**
	 * Skip reason: no Google Analytics client id was captured for the order,
	 * so nothing could match the event to its purchase.
	 */
	public const REASON_NO_CLIENT_ID = 'no_client_id';

	/**
	 * Skip reason: no client id was captured BECAUSE the buyer refused
	 * analytics storage. The same missing identifier as above, told apart from
	 * it because the two are answered differently: one is a setup question
	 * (was capture on, did the Google tag fire), the other is the visitor's own
	 * decision, which no setting on this screen overrides.
	 *
	 * Reported instead of the bare missing-id reason whenever the order's own
	 * stored consent state says analytics storage was not granted. It is the
	 * cause; the missing id is the symptom.
	 */
	public const REASON_CONSENT_NO_CLIENT_ID = 'consent_no_client_id';

	/**
	 * Skip reason: no usable destination is configured.
	 */
	public const REASON_NO_DESTINATION = 'no_destination';

	/**
	 * Skip reason: the site's own filter cancelled the event.
	 */
	public const REASON_VETOED = 'vetoed';

	/**
	 * Constructor.
	 *
	 * @param Options                  $options The plugin options service.
	 * @param EventsIngest             $ingest  The API client.
	 * @param DestinationHealth        $health  Per-destination health records.
	 * @param SendLog                  $log     The diagnostics ring.
	 * @param array<int, RefundSource> $sources The per-platform adapters.
	 */
	public function __construct(
		private Options $options,
		private EventsIngest $ingest,
		private DestinationHealth $health,
		private SendLog $log,
		private array $sources
	) {
	}

	/**
	 * Registers the queue handler and the refund hook of every active platform.
	 *
	 * The caller decides whether the feature is on; run() checks the option
	 * again for the job that was queued before it was turned off.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( SendQueue::HOOK_SEND, array( $this, 'run' ) );

		foreach ( $this->sources as $source ) {
			if ( ! $source->is_active() ) {
				continue;
			}

			$platform = $source->platform();

			$source->register_hooks(
				function ( int $order_id, int $refund_id ) use ( $platform ): void {
					$this->enqueue( $platform, $order_id, $refund_id );
				}
			);
		}
	}

	/**
	 * Queues one refund for sending.
	 *
	 * Deliberately does no checking beyond the ids: every decision belongs in
	 * run(), so that each refund produces exactly one entry in the diagnostics
	 * ring saying what became of it. A refund silently dropped here would be
	 * indistinguishable from one that was never noticed.
	 *
	 * @param string $platform  Platform id.
	 * @param int    $order_id  The parent order.
	 * @param int    $refund_id The refund.
	 * @return void
	 */
	public function enqueue( string $platform, int $order_id, int $refund_id ): void {
		if ( ( $order_id <= 0 ) || ( $refund_id <= 0 ) ) {
			return;
		}

		SendQueue::schedule(
			SendQueue::HOOK_SEND,
			array(
				'platform'  => $platform,
				'order_id'  => $order_id,
				'refund_id' => $refund_id,
				'attempt'   => 1,
			),
			SendQueue::INITIAL_DELAY
		);
	}

	/**
	 * Runs one queued send job.
	 *
	 * @param mixed $payload The scheduled payload.
	 * @return void
	 */
	public function run( $payload ): void {
		if ( ! is_array( $payload ) || ! $this->options->get( GTM4WP_OPTION_GDM_SEND_REFUNDS ) ) {
			return;
		}

		$platform  = (string) ( $payload['platform'] ?? '' );
		$order_id  = (int) ( $payload['order_id'] ?? 0 );
		$refund_id = (int) ( $payload['refund_id'] ?? 0 );
		$attempt   = max( 1, (int) ( $payload['attempt'] ?? 1 ) );
		$only      = $this->only_list( $payload['only'] ?? null );

		if ( ( $order_id <= 0 ) || ( $refund_id <= 0 ) ) {
			return;
		}

		$source = $this->source( $platform );

		if ( null === $source ) {
			$this->retry_or_fail( $payload, $attempt, self::REASON_PLATFORM_INACTIVE );
			return;
		}

		// A refund that has already been sent is not sent again, whatever put
		// this job in the queue. This is the guarantee the per-refund meta
		// exists for: several partial refunds of one order each send once, and
		// a duplicate job for any of them sends nothing.
		if ( $source->is_sent( $refund_id ) ) {
			return;
		}

		$refund = $source->load( $order_id, $refund_id );

		if ( null === $refund ) {
			$this->skip( $platform . ':' . $order_id . ':' . $refund_id, $attempt, self::REASON_REFUND_UNREADABLE );
			return;
		}

		$reason = $this->refusal( $refund );

		if ( null !== $reason ) {
			$this->skip( $refund->reference(), $attempt, $reason );
			return;
		}

		$rows = $this->destinations( $only );

		if ( array() === $rows ) {
			$this->skip( $refund->reference(), $attempt, self::REASON_NO_DESTINATION );
			return;
		}

		list( $refund_object, $order_object ) = $source->objects( $order_id, $refund_id );

		$event = RefundEvent::filter( RefundEvent::build( $refund ), $refund, $refund_object, $order_object );

		if ( null === $event ) {
			$this->skip( $refund->reference(), $attempt, self::REASON_VETOED );
			return;
		}

		$this->deliver( $source, $refund, $rows, $event, $payload, $attempt );
	}

	/**
	 * Sends the event and records what came back.
	 *
	 * @param RefundSource                      $source  The platform adapter.
	 * @param RefundData                        $refund  The refund.
	 * @param array<int, array<string, string>> $rows    Destination rows to send to.
	 * @param array<string, mixed>              $event   The assembled event.
	 * @param array<string, mixed>              $payload The job payload.
	 * @param int                               $attempt This attempt's number.
	 * @return void
	 */
	private function deliver( RefundSource $source, RefundData $refund, array $rows, array $event, array $payload, int $attempt ): void {
		$signals = $refund->consent_signals();
		$results = $this->ingest->send( $rows, $event, EventsIngest::consent_field( $signals ?? array() ) );

		$retry      = array();
		$request_id = '';

		foreach ( $results as $result ) {
			$this->record_result( $refund, $attempt, $result );

			if ( $result['ok'] ) {
				if ( '' === $request_id ) {
					$request_id = $result['request_id'];
				}

				StatusPoller::start( $result['account'], $result['request_id'] );

				continue;
			}

			if ( $result['retryable'] ) {
				// Only the destinations that failed are retried. Repeating the
				// request for a destination that already took the event would
				// send it twice, and Analytics has no documented rule that
				// would collapse two refunds of one transaction back into one.
				$retry = array_merge( $retry, $result['measurements'] );
			}
		}

		$delay = SendQueue::retry_delay( $attempt );

		if ( array() !== $retry && null !== $delay ) {
			SendQueue::schedule(
				SendQueue::HOOK_SEND,
				array_merge(
					$payload,
					array(
						'attempt' => $attempt + 1,
						'only'    => array_values( array_unique( $retry ) ),
					)
				),
				$delay
			);

			return;
		}

		if ( '' !== $request_id ) {
			$source->mark_sent( $refund->refund_id, $request_id );
		}
	}

	/**
	 * Writes one send outcome into the health record and the diagnostics ring.
	 *
	 * @param RefundData                                                                                                                                $refund  The refund.
	 * @param int                                                                                                                                       $attempt This attempt's number.
	 * @param array{account: string, measurements: string[], ok: bool, status: int, request_id: string, error: string, reason: string, retryable: bool} $result One request's outcome.
	 * @return void
	 */
	private function record_result( RefundData $refund, int $attempt, array $result ): void {
		$last_step = ( null === SendQueue::retry_delay( $attempt ) );

		foreach ( $result['measurements'] as $measurement ) {
			if ( $result['ok'] ) {
				$this->health->record_success( $measurement );
			} else {
				$this->health->record_failure( $measurement, $result['error'], $result['reason'] );
			}

			$this->log->record(
				array(
					'feature'     => SendLog::FEATURE_REFUND,
					'reference'   => $refund->reference(),
					'destination' => $measurement,
					'attempt'     => $attempt,
					'status'      => $result['status'],
					'request_id'  => $result['request_id'],
					'outcome'     => $this->outcome( $result, $last_step ),
					'reason'      => $result['error'],
				)
			);
		}
	}

	/**
	 * The diagnostics outcome of one request.
	 *
	 * @param array{ok: bool, retryable: bool} $result    The request outcome.
	 * @param bool                             $last_step Whether the retries are used up.
	 * @return string
	 */
	private function outcome( array $result, bool $last_step ): string {
		if ( $result['ok'] ) {
			return SendLog::OUTCOME_ACCEPTED;
		}

		return ( $result['retryable'] && ! $last_step ) ? SendLog::OUTCOME_RETRYING : SendLog::OUTCOME_FAILED;
	}

	/**
	 * Why this refund may not be sent, or null when it may.
	 *
	 * The consent decision is the gate phase 3 built and this is its first
	 * consumer: where the site's policy applies, analytics storage has to have
	 * been granted at order time. Beyond the legal reading that also protects
	 * the data - with analytics storage denied, the captured client id can be
	 * an ephemeral one, and an event sent with it is permanently unmatchable.
	 *
	 * @param RefundData $refund The refund.
	 * @return string|null A reason code, or null to proceed.
	 */
	private function refusal( RefundData $refund ): ?string {
		if ( $refund->amount <= 0 ) {
			return self::REASON_EMPTY_REFUND;
		}

		if ( '' === $refund->client_id ) {
			// Deliberately ahead of the policy check below, and unaffected by
			// it: a "Never" policy waives the transfer rule for data the site
			// holds, and cannot conjure an identifier that was never captured.
			// It could not usefully do so either - with analytics storage
			// denied, Google's tag runs cookieless and hands out a fresh client
			// id on every page view, so a value captured then would match no
			// purchase in Analytics.
			$signals = $refund->consent_signals();

			if ( is_array( $signals )
				&& array_key_exists( ConsentPolicy::SIGNAL_ANALYTICS, $signals )
				&& ConsentPolicy::GRANTED !== $signals[ ConsentPolicy::SIGNAL_ANALYTICS ]
			) {
				return self::REASON_CONSENT_NO_CLIENT_ID;
			}

			return self::REASON_NO_CLIENT_ID;
		}

		$decision = ConsentPolicy::decide(
			(string) $this->options->get( GTM4WP_OPTION_GDM_CONSENT_POLICY ),
			$refund->billing_country,
			$refund->consent_signals()
		);

		return $decision['allowed'] ? null : $decision['reason'];
	}

	/**
	 * The Google Analytics destinations to send to, optionally narrowed to the
	 * measurement ids a retry is for.
	 *
	 * @param string[] $only Measurement ids to keep; empty means all of them.
	 * @return array<int, array<string, string>>
	 */
	private function destinations( array $only ): array {
		$rows = array();

		foreach ( DestinationRows::rows( $this->options ) as $row ) {
			if ( DestinationRows::TYPE_GA4 !== $row[ DestinationRows::COLUMN_TYPE ] ) {
				continue;
			}

			if ( array() !== $only && ! in_array( $row[ DestinationRows::COLUMN_MEASUREMENT ], $only, true ) ) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The measurement ids of a retry payload, as strings.
	 *
	 * @param mixed $only The payload member.
	 * @return string[]
	 */
	private function only_list( $only ): array {
		if ( ! is_array( $only ) ) {
			return array();
		}

		$list = array();

		foreach ( $only as $measurement ) {
			if ( is_string( $measurement ) && ( '' !== $measurement ) ) {
				$list[] = $measurement;
			}
		}

		return $list;
	}

	/**
	 * The adapter of a platform, when that platform is active.
	 *
	 * @param string $platform Platform id.
	 * @return RefundSource|null
	 */
	private function source( string $platform ): ?RefundSource {
		foreach ( $this->sources as $source ) {
			if ( $source->platform() === $platform ) {
				return $source->is_active() ? $source : null;
			}
		}

		return null;
	}

	/**
	 * Records a refund that will not be sent, with the rule that stopped it.
	 *
	 * @param string $reference The refund reference.
	 * @param int    $attempt   This attempt's number.
	 * @param string $reason    A reason code.
	 * @return void
	 */
	private function skip( string $reference, int $attempt, string $reason ): void {
		$this->log->record(
			array(
				'feature'   => SendLog::FEATURE_REFUND,
				'reference' => $reference,
				'attempt'   => $attempt,
				'outcome'   => SendLog::OUTCOME_SKIPPED,
				'reason'    => $reason,
			)
		);
	}

	/**
	 * Queues another attempt of a job that could not even start, or records
	 * the failure when the retries are used up.
	 *
	 * @param array<string, mixed> $payload The job payload.
	 * @param int                  $attempt This attempt's number.
	 * @param string               $reason  A reason code.
	 * @return void
	 */
	private function retry_or_fail( array $payload, int $attempt, string $reason ): void {
		$delay = SendQueue::retry_delay( $attempt );

		if ( null === $delay ) {
			$this->log->record(
				array(
					'feature'   => SendLog::FEATURE_REFUND,
					'reference' => (string) ( $payload['platform'] ?? '' ) . ':' . (int) ( $payload['order_id'] ?? 0 ) . ':' . (int) ( $payload['refund_id'] ?? 0 ),
					'attempt'   => $attempt,
					'outcome'   => SendLog::OUTCOME_FAILED,
					'reason'    => $reason,
				)
			);

			return;
		}

		SendQueue::schedule(
			SendQueue::HOOK_SEND,
			array_merge( $payload, array( 'attempt' => $attempt + 1 ) ),
			$delay
		);
	}
}
