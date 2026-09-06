<?php
/**
 * Diagnostics ring of the Google Data Manager send lanes.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the last few sends: what was sent, where to, whether Google took
 * it, and - once the asynchronous processing finishes - what Google did with
 * it.
 *
 * This exists because the send lane is otherwise entirely invisible. A refund
 * is issued in the store admin, a job runs minutes later somewhere in cron, and
 * the result appears in Google Analytics up to a day after that. Without a
 * record, the only question anyone can answer afterwards is "the number looks
 * wrong", which is not a question this plugin can be debugged from.
 *
 * What is deliberately NOT stored: no access token, no key material, no request
 * body, no raw response body. An error body from Google can echo fragments of
 * what we sent, and this log is read on an admin screen and pasted into public
 * support threads. Only a short sanitized summary, status names and counts go
 * in.
 *
 * The ring is a single option row rewritten on each entry, like the health
 * records next to it. Two jobs writing in the same instant can cost one entry;
 * that is acceptable for a diagnostics ring in a way it would not be for the
 * idempotency meta, which is why that lives on the refund object instead.
 */
final class SendLog {

	/**
	 * Option row holding the ring. Non-autoloaded: written by queue jobs, read
	 * on the settings screen, never on a frontend pageview. Deleted by
	 * uninstall.php.
	 */
	public const OPTION_NAME = 'gtm4wp_gdm_send_log';

	/**
	 * How many entries the ring keeps. Enough to cover a store's recent
	 * refunds and every retry of them, small enough that the option row stays
	 * a few kilobytes.
	 */
	public const MAX_ENTRIES = 50;

	/**
	 * Feature name of a server-side refund event.
	 */
	public const FEATURE_REFUND = 'refund';

	/**
	 * Outcome: Google accepted the request (HTTP 200). Whether it applied the
	 * event is a separate question, answered later by the status poll.
	 */
	public const OUTCOME_ACCEPTED = 'accepted';

	/**
	 * Outcome: the request failed and will be retried.
	 */
	public const OUTCOME_RETRYING = 'retrying';

	/**
	 * Outcome: the request failed and will not be retried - either the error
	 * says the request itself is wrong, or the retries ran out.
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * Outcome: nothing was sent, on purpose. The reason says which rule
	 * stopped it (a consent reason code, a missing client id, a veto).
	 */
	public const OUTCOME_SKIPPED = 'skipped';

	/**
	 * Longest stored reason / error summary.
	 */
	private const REASON_MAX_LENGTH = 200;

	/**
	 * Longest stored order or refund reference. These are the store's own
	 * internal ids, but the cap keeps a crafted order number from stretching
	 * the row.
	 */
	private const REFERENCE_MAX_LENGTH = 60;

	/**
	 * Clock, injectable so tests can pin stored timestamps.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the current Unix time; null uses time().
	 */
	public function __construct( $clock = null ) {
		$this->clock = $clock;
	}

	/**
	 * Appends one entry, dropping the oldest when the ring is full.
	 *
	 * @param array<string, mixed> $entry Entry fields; unknown keys are dropped by clean_entry().
	 * @return void
	 */
	public function record( array $entry ): void {
		$entries   = $this->read();
		$entries[] = $this->clean_entry( $entry );

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		$this->write( $entries );
	}

	/**
	 * Writes the outcome of a status poll back onto the entries of one request.
	 *
	 * Keyed by the API's own requestId, which is what ties an asynchronous
	 * result back to the send that produced it. One request can cover several
	 * destinations, so every entry carrying that id and that measurement id is
	 * updated.
	 *
	 * @param string                                                                             $request_id The requestId of the send.
	 * @param array<int, array{measurement: string, status: string, errors: int, warnings: int}> $statuses   Per-destination statuses.
	 * @return void
	 */
	public function record_status( string $request_id, array $statuses ): void {
		if ( '' === $request_id ) {
			return;
		}

		$by_measurement = array();
		foreach ( $statuses as $status ) {
			if ( is_array( $status ) && isset( $status['measurement'] ) ) {
				$by_measurement[ (string) $status['measurement'] ] = $status;
			}
		}

		$entries = $this->read();
		$changed = false;

		foreach ( $entries as $index => $entry ) {
			if ( ( $entry['request_id'] ?? '' ) !== $request_id ) {
				continue;
			}

			$status = $by_measurement[ (string) ( $entry['destination'] ?? '' ) ] ?? null;

			if ( null === $status ) {
				continue;
			}

			$result   = mb_substr( (string) ( $status['status'] ?? '' ), 0, self::REASON_MAX_LENGTH );
			$errors   = max( 0, (int) ( $status['errors'] ?? 0 ) );
			$warnings = max( 0, (int) ( $status['warnings'] ?? 0 ) );

			// A poll that learns nothing new writes nothing: a request still
			// processing is asked about repeatedly, and each of those answers
			// would otherwise rewrite the whole option row for no change.
			if ( ( ( $entry['result'] ?? '' ) === $result )
				&& ( (int) ( $entry['errors'] ?? 0 ) === $errors )
				&& ( (int) ( $entry['warnings'] ?? 0 ) === $warnings )
			) {
				continue;
			}

			$entries[ $index ]['result']   = $result;
			$entries[ $index ]['errors']   = $errors;
			$entries[ $index ]['warnings'] = $warnings;
			$changed                       = true;
		}

		if ( $changed ) {
			$this->write( $entries );
		}
	}

	/**
	 * Every stored entry, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return $this->read();
	}

	/**
	 * Empties the ring.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->write( array() );
	}

	/**
	 * Coerces an entry to the stored shape, capping and sanitizing every text
	 * field. Built as an allow-list of members so a caller cannot widen what
	 * the log carries by passing extra keys.
	 *
	 * @param array<string, mixed> $entry Raw entry.
	 * @return array<string, mixed>
	 */
	private function clean_entry( array $entry ): array {
		return array(
			'time'        => isset( $entry['time'] ) ? (int) $entry['time'] : $this->now(),
			'feature'     => $this->text( $entry['feature'] ?? '', self::REFERENCE_MAX_LENGTH ),
			'reference'   => $this->text( $entry['reference'] ?? '', self::REFERENCE_MAX_LENGTH ),
			'destination' => $this->text( $entry['destination'] ?? '', self::REFERENCE_MAX_LENGTH ),
			'outcome'     => $this->text( $entry['outcome'] ?? '', self::REFERENCE_MAX_LENGTH ),
			'attempt'     => max( 0, (int) ( $entry['attempt'] ?? 0 ) ),
			'status'      => max( 0, (int) ( $entry['status'] ?? 0 ) ),
			'request_id'  => $this->text( $entry['request_id'] ?? '', self::REFERENCE_MAX_LENGTH ),
			'reason'      => $this->text( $entry['reason'] ?? '', self::REASON_MAX_LENGTH ),
			'result'      => $this->text( $entry['result'] ?? '', self::REASON_MAX_LENGTH ),
			'errors'      => max( 0, (int) ( $entry['errors'] ?? 0 ) ),
			'warnings'    => max( 0, (int) ( $entry['warnings'] ?? 0 ) ),
		);
	}

	/**
	 * One sanitized, capped text field.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $max_size Longest kept length.
	 * @return string
	 */
	private function text( $value, int $max_size ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, $max_size );
	}

	/**
	 * The current Unix time through the injectable clock.
	 *
	 * @return int
	 */
	private function now(): int {
		return is_callable( $this->clock ) ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Reads the option row, dropping anything that is not an entry array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$entries = array();

		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Writes the option row, non-autoloaded (the autoload flag only applies
	 * when the row is created, so the first write goes through add_option()).
	 *
	 * @param array<int, array<string, mixed>> $entries The ring.
	 * @return void
	 */
	private function write( array $entries ): void {
		$entries = array_values( $entries );

		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $entries, '', false );
			return;
		}

		update_option( self::OPTION_NAME, $entries, false );
	}
}
