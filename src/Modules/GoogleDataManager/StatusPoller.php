<?php
/**
 * Asynchronous send-status polling.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Asks Google what became of a request it already accepted.
 *
 * The ingest call answers within a second, and its answer means only that the
 * request was well-formed and authorised. Whether the events were applied is
 * decided asynchronously, somewhere between half an hour and a day later. A
 * 200 is therefore not evidence that anything reached Google Analytics, and a
 * plugin that treated it as such would report success for events that were
 * silently dropped.
 *
 * The schedule is Google's own published guidance (U136): first ask after 30
 * minutes, then multiply the wait by 1.3 each time, never wait longer than an
 * hour between asks, and stop after 24 hours.
 */
final class StatusPoller {

	/**
	 * How long to wait before the first status request, in seconds.
	 */
	public const FIRST_DELAY = 1800;

	/**
	 * Multiplier applied to the wait after each inconclusive answer. Stored as
	 * a permille integer so the arithmetic stays exact and the tests can pin
	 * the resulting series.
	 */
	public const BACKOFF_PERMILLE = 1300;

	/**
	 * Longest wait between two status requests, in seconds.
	 */
	public const MAX_INTERVAL = 3600;

	/**
	 * How long to keep asking, in seconds, counted from the send.
	 */
	public const MAX_ELAPSED = 86400;

	/**
	 * Constructor.
	 *
	 * @param EventsIngest $ingest The API client.
	 * @param SendLog      $log    The diagnostics ring.
	 */
	public function __construct( private EventsIngest $ingest, private SendLog $log ) {
	}

	/**
	 * Registers the queue handler.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( SendQueue::HOOK_STATUS, array( $this, 'poll' ) );
	}

	/**
	 * Queues the first status request for an accepted send.
	 *
	 * @param string $account_id The service account the send used.
	 * @param string $request_id The requestId the ingest response returned.
	 * @return void
	 */
	public static function start( string $account_id, string $request_id ): void {
		if ( ( '' === $account_id ) || ( '' === $request_id ) ) {
			return;
		}

		SendQueue::schedule(
			SendQueue::HOOK_STATUS,
			array(
				'account'    => $account_id,
				'request_id' => $request_id,
				'interval'   => self::FIRST_DELAY,
				'elapsed'    => self::FIRST_DELAY,
			),
			self::FIRST_DELAY
		);
	}

	/**
	 * The wait before the next status request after a given one.
	 *
	 * @param int $interval The wait that just elapsed, in seconds.
	 * @return int
	 */
	public static function next_interval( int $interval ): int {
		$next = (int) round( ( max( 1, $interval ) * self::BACKOFF_PERMILLE ) / 1000 );

		return min( self::MAX_INTERVAL, $next );
	}

	/**
	 * Runs one queued status request.
	 *
	 * @param mixed $payload The scheduled payload.
	 * @return void
	 */
	public function poll( $payload ): void {
		if ( ! is_array( $payload ) ) {
			return;
		}

		$account    = (string) ( $payload['account'] ?? '' );
		$request_id = (string) ( $payload['request_id'] ?? '' );
		$interval   = max( 1, (int) ( $payload['interval'] ?? self::FIRST_DELAY ) );
		$elapsed    = max( 0, (int) ( $payload['elapsed'] ?? $interval ) );

		if ( ( '' === $account ) || ( '' === $request_id ) ) {
			return;
		}

		$statuses = $this->ingest->request_status( $account, $request_id );

		if ( $statuses instanceof \WP_Error ) {
			// A refused or unreachable status request says nothing about the
			// send itself, so it is not written into the log as a result. It is
			// simply asked again, within the same overall window.
			$this->reschedule( $payload, $interval, $elapsed );

			return;
		}

		// Everything Google answered is written down, including "still
		// processing": the log's job is to show what is known right now, and
		// "we asked and it is not finished" is different from "we never heard
		// back".
		$this->log->record_status( $request_id, $statuses );

		if ( self::is_finished( $statuses ) ) {
			return;
		}

		$this->reschedule( $payload, $interval, $elapsed );
	}

	/**
	 * Whether every destination of a request has reached a final state.
	 *
	 * An answer with no destinations in it is not treated as finished: that is
	 * a shape we did not expect, and the polling window bounds how long the
	 * uncertainty can last anyway.
	 *
	 * @param array<int, array{measurement: string, status: string, errors: int, warnings: int}> $statuses Per-destination statuses.
	 * @return bool
	 */
	public static function is_finished( array $statuses ): bool {
		if ( array() === $statuses ) {
			return false;
		}

		foreach ( $statuses as $status ) {
			if ( ! EventsIngest::is_terminal_status( (string) ( $status['status'] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Queues the next status request, unless the 24 hour window is used up.
	 *
	 * @param array<string, mixed> $payload  The job payload.
	 * @param int                  $interval The wait that just elapsed.
	 * @param int                  $elapsed  Total time spent asking so far.
	 * @return void
	 */
	private function reschedule( array $payload, int $interval, int $elapsed ): void {
		$next = self::next_interval( $interval );

		if ( ( $elapsed + $next ) > self::MAX_ELAPSED ) {
			return;
		}

		SendQueue::schedule(
			SendQueue::HOOK_STATUS,
			array_merge(
				$payload,
				array(
					'interval' => $next,
					'elapsed'  => $elapsed + $next,
				)
			),
			$next
		);
	}
}
