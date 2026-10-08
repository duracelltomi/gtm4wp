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
 * Turns a refund issued in the store admin, where no browser and no Google
 * tag is involved, into a Google Analytics refund event. The platform hook
 * only queues a job; the job runs a minute later, decides once whether the
 * send is allowed, sends, records what happened, and retries or stops. Every
 * path that does not send says why in the diagnostics ring, and no case is
 * papered over with an invented value: a refund Analytics cannot join to its
 * purchase is worse than an honest gap.
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
	 * Skip reason: no client id BECAUSE the buyer refused analytics storage.
	 * Told apart from the bare missing id since one is a setup question and
	 * the other the visitor's decision, which no setting overrides.
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
	 * Skip reason: a destination (or, refund-level, every destination) already
	 * accepted this refund, so a replay or retry naming it sends nothing.
	 */
	public const REASON_ALREADY_SENT = 'already_sent';

	/**
	 * Skip reason: a destination a retry or replay names was not among the
	 * configured Google Analytics destinations when the job ran.
	 */
	public const REASON_DESTINATION_REMOVED = 'destination_removed';

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
	 * Registers the queue handler and the refund hook of every active platform;
	 * run() re-checks the option for a job queued before it was turned off.
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
	 * Queues one refund for sending. No checking beyond the ids: every decision
	 * belongs in run(), so each refund gets exactly one diagnostics entry.
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
		$only      = DestinationRows::measurement_ids( $payload['only'] ?? null );
		$is_replay = ! empty( $payload['replay'] );
		$reference = $platform . ':' . $order_id . ':' . $refund_id;

		if ( ( $order_id <= 0 ) || ( $refund_id <= 0 ) ) {
			return;
		}

		$source = $this->source( $platform );

		if ( null === $source ) {
			$this->retry_or_fail( $payload, $attempt, self::REASON_PLATFORM_INACTIVE );
			return;
		}

		// No destination is ever sent a refund it already accepted (#394). What
		// "accepted" means is the refund's own marker, which survives the ring,
		// plus the ring's newest row (refunds accepted before the marker existed).
		if ( array() === $only ) {
			// An already-sent refund is never sent again (the per-refund marker).
			// A duplicate platform job is not an event and leaves no row; a
			// replay does, so its queued row does not outlive it (#388).
			if ( $source->is_sent( $refund_id ) ) {
				if ( $is_replay ) {
					$this->skip( $reference, $attempt, self::REASON_ALREADY_SENT );
				}

				return;
			}

			// A partial accept leaves no per-refund marker: the whole job goes
			// only to the destinations still missing the event.
			$configured = array_column( $this->destinations( array() ), DestinationRows::COLUMN_MEASUREMENT );
			$accepted   = $this->accepted( $source, $reference, $refund_id, $configured );

			if ( array() !== $accepted ) {
				$only = array_values( array_diff( $configured, $accepted ) );

				if ( array() === $only ) {
					if ( $is_replay ) {
						$this->skip( $reference, $attempt, self::REASON_ALREADY_SENT );
					}

					return;
				}
			}
		} else {
			// A retry or replay names the destinations still missing the event.
			// One that took it meanwhile is dropped with a row of its own, which
			// supersedes its failed or queued one, so two replays queued before
			// the first ran send once and neither stays offered (#388).
			$accepted = $this->accepted( $source, $reference, $refund_id, $only );

			foreach ( $accepted as $measurement ) {
				$this->skip( $reference, $attempt, self::REASON_ALREADY_SENT, $measurement );
			}

			$only = array_values( array_diff( $only, $accepted ) );

			if ( array() === $only ) {
				return;
			}
		}

		$refund = $source->load( $order_id, $refund_id );

		if ( null === $refund ) {
			$this->skip( $reference, $attempt, self::REASON_REFUND_UNREADABLE );
			return;
		}

		// Ahead of the per-order refusals: a site-wide fault (no destination)
		// outranks a per-order one, or the reader audits orders while the whole
		// lane points at nowhere.
		$rows = $this->destinations( $only );

		// A named destination that is not configured any more (deleted, or
		// filtered out by gtm4wp_gdm_destinations) gets a row of its own, so
		// the replay that named it is not left looking unprocessed (#388).
		if ( array() !== $only ) {
			$kept = array_column( $rows, DestinationRows::COLUMN_MEASUREMENT );

			foreach ( array_diff( $only, $kept ) as $measurement ) {
				$this->skip( $refund->reference(), $attempt, self::REASON_DESTINATION_REMOVED, $measurement );
			}
		}

		if ( array() === $rows ) {
			// Refund-level only when nothing at all is configured: a refund-level
			// row for named destinations would turn the next replay into a whole
			// job for destinations that may already have it.
			if ( array() === $only || array() === $this->destinations( array() ) ) {
				$this->skip( $refund->reference(), $attempt, self::REASON_NO_DESTINATION );
			}

			return;
		}

		$reason = $this->refusal( $refund );

		if ( null !== $reason ) {
			$this->skip( $refund->reference(), $attempt, $reason );
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
		$accepted   = array();

		foreach ( $results as $result ) {
			$this->record_result( $refund, $attempt, $result );

			if ( $result['ok'] ) {
				if ( '' === $request_id ) {
					$request_id = $result['request_id'];
				}

				$accepted = array_merge( $accepted, $result['measurements'] );

				StatusPoller::start( $result['account'], $result['request_id'] );

				continue;
			}

			if ( $result['retryable'] ) {
				// Only the failed destinations: Analytics has no documented rule
				// collapsing two refunds of one transaction into one.
				$retry = array_merge( $retry, $result['measurements'] );
			}
		}

		$delay    = SendQueue::retry_delay( $attempt );
		$retrying = ( array() !== $retry && null !== $delay );

		if ( $retrying ) {
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
		}

		// Who took it, always (a retry may never reach the others, #394); and,
		// once no retry is coming, that the refund is done - in one write.
		if ( array() !== $accepted ) {
			$source->mark_accepted( $refund->refund_id, $accepted, $retrying ? null : $request_id );
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
	 * Why this refund may not be sent, or null when it may. Where the site's
	 * consent policy applies, analytics storage must have been granted at
	 * order time; with it denied the client id is ephemeral and the event
	 * permanently unmatchable anyway.
	 *
	 * @param RefundData $refund The refund.
	 * @return string|null A reason code, or null to proceed.
	 */
	private function refusal( RefundData $refund ): ?string {
		if ( $refund->amount <= 0 ) {
			return self::REASON_EMPTY_REFUND;
		}

		if ( '' === $refund->client_id ) {
			// Ahead of the policy check and unaffected by it: a "Never" policy
			// cannot conjure an identifier that was never captured.
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
	 * Records a refund (or one destination of it) that will not be sent, with
	 * the rule that stopped it.
	 *
	 * @param string $reference   The refund reference.
	 * @param int    $attempt     This attempt's number.
	 * @param string $reason      A reason code.
	 * @param string $destination The destination it is about; '' for the whole refund.
	 * @return void
	 */
	private function skip( string $reference, int $attempt, string $reason, string $destination = '' ): void {
		$this->log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => $reference,
				'destination' => $destination,
				'attempt'     => $attempt,
				'outcome'     => SendLog::OUTCOME_SKIPPED,
				'reason'      => $reason,
			)
		);
	}

	/**
	 * Which of the given destinations already accepted a refund: its stored
	 * marker, or the ring's newest row for that destination.
	 *
	 * @param RefundSource $source     The platform adapter.
	 * @param string       $reference  The refund reference.
	 * @param int          $refund_id  The refund.
	 * @param string[]     $candidates Measurement ids to check.
	 * @return string[] The accepted ones, in the candidates' order.
	 */
	private function accepted( RefundSource $source, string $reference, int $refund_id, array $candidates ): array {
		if ( array() === $candidates ) {
			return array();
		}

		$marker = $source->accepted_destinations( $refund_id );

		return array_values(
			array_filter(
				$candidates,
				fn ( string $measurement ): bool => in_array( $measurement, $marker, true )
					|| $this->log->latest_is_accepted( $reference, $measurement )
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
