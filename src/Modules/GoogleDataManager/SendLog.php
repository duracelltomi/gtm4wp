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
	 * Tone: nothing needs looking at - Google applied the event.
	 */
	public const TONE_OK = 'ok';

	/**
	 * Tone: sent, and nothing is known about it yet. Every accepted request
	 * spends its first half hour here.
	 */
	public const TONE_PENDING = 'pending';

	/**
	 * Tone: no data was lost yet, but somebody should look - a refund the lane
	 * deliberately skipped, a send being retried, a partial result.
	 */
	public const TONE_WARN = 'warn';

	/**
	 * Tone: this event did not arrive.
	 */
	public const TONE_ERROR = 'error';

	/**
	 * Skip reasons a later configuration change can put right, so the refund
	 * is worth queueing again once the admin has acted. The two missing-id
	 * reasons and the empty refund are deliberately absent: nothing on the
	 * settings screen can produce an identifier that was never captured, and
	 * a refund of nothing has nothing to send.
	 *
	 * @var string[]
	 */
	private const REPLAYABLE_SKIPS = array(
		'no_destination',
		'consent_unknown',
		'consent_denied',
		'vetoed',
		'platform_inactive',
		'refund_unreadable',
	);

	/**
	 * Longest stored reason / error summary, in characters.
	 *
	 * It was 200, which cut the plugin's own plain-words explanation of a
	 * NOT_FOUND answer mid-sentence - the one sentence the row exists to
	 * carry. Fifty rows of 400 is still a few kilobytes, and anything cut is
	 * now marked as cut rather than left reading as a complete thought.
	 */
	public const REASON_MAX_LENGTH = 400;

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
	 * Appends one entry, making room for it when the ring is full.
	 *
	 * @param array<string, mixed> $entry Entry fields; unknown keys are dropped by clean_entry().
	 * @return void
	 */
	public function record( array $entry ): void {
		$entries   = $this->read();
		$entries[] = $this->clean_entry( $entry );

		$this->write( self::trim( $entries ) );
	}

	/**
	 * The ring cut back to MAX_ENTRIES, dropping routine sends before problems.
	 *
	 * A plain "drop the oldest" is the wrong rule for what this ring is for.
	 * The rows worth reading are the rare ones - a failure, a deliberate skip -
	 * and the rows that crowd them out are the successes, which arrive in bulk.
	 * A store refunding a batch of orders would push this morning's failure out
	 * of the ring before anybody looked at it, and the failure is the only row
	 * that could have explained the number in Analytics.
	 *
	 * So the oldest routine entry goes first, and a problem is dropped only
	 * when the ring holds nothing else. Order is preserved either way: this
	 * removes entries, it never reorders them.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries, oldest first.
	 * @return array<int, array<string, mixed>>
	 */
	private static function trim( array $entries ): array {
		$excess = count( $entries ) - self::MAX_ENTRIES;

		if ( $excess <= 0 ) {
			return $entries;
		}

		// Oldest first, and only the routine ones: the newest entry is never a
		// candidate, because it is the one just recorded.
		foreach ( array_keys( $entries ) as $index ) {
			if ( $excess <= 0 ) {
				break;
			}

			$tone = self::tone( $entries[ $index ] );

			if ( self::TONE_WARN === $tone || self::TONE_ERROR === $tone ) {
				continue;
			}

			unset( $entries[ $index ] );
			--$excess;
		}

		$entries = array_values( $entries );

		// A ring holding nothing but problems still has to give way to the new
		// entry, or it would freeze at the first fifty failures and stop
		// recording what happened next.
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		return $entries;
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

			// Through the same cleaner every other text member takes, not a
			// bare cap: this is the one writer that updates an entry in place,
			// and it was the one member the class doc block's "sanitized"
			// promise did not cover (#244). Google's status names are plain
			// upper-case words, so a genuine value passes through unchanged.
			$result   = $this->text( $status['status'] ?? '', self::REASON_MAX_LENGTH );
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
	 * How much attention one entry deserves, in four steps the settings screen
	 * renders as colour.
	 *
	 * Decided here rather than in the admin bundle on purpose: the judgement
	 * rests on Google's requestStatus vocabulary (U136), which already lives in
	 * EventsIngest and disagrees with itself across Google's own two pages
	 * (FAILED vs FAILURE). A second copy of that vocabulary in JavaScript would
	 * be a second thing to keep in step with a moving external contract - and
	 * the one that silently stops matching, because a colour has no test on the
	 * screen.
	 *
	 * Written as "terminal but not a success deserves a look" rather than as a
	 * list of failure names, so a status Google adds later is drawn as
	 * something to look at instead of as a success (UC-5).
	 *
	 * @param array<string, mixed> $entry One stored entry.
	 * @return string One of the TONE_* values.
	 */
	public static function tone( array $entry ): string {
		$outcome = (string) ( $entry['outcome'] ?? '' );
		$result  = (string) ( $entry['result'] ?? '' );

		if ( self::OUTCOME_FAILED === $outcome ) {
			return self::TONE_ERROR;
		}

		if ( self::OUTCOME_SKIPPED === $outcome || self::OUTCOME_RETRYING === $outcome ) {
			return self::TONE_WARN;
		}

		if ( (int) ( $entry['errors'] ?? 0 ) > 0 ) {
			return self::TONE_ERROR;
		}

		// Accepted, and Google has not finished with it: the normal state of a
		// fresh row, and not something to colour as either good or bad.
		if ( ! EventsIngest::is_terminal_status( $result ) ) {
			return self::TONE_PENDING;
		}

		if ( EventsIngest::STATUS_SUCCESS !== $result || (int) ( $entry['warnings'] ?? 0 ) > 0 ) {
			return self::TONE_WARN;
		}

		return self::TONE_OK;
	}

	/**
	 * Whether an entry describes something worth queueing again.
	 *
	 * A failed send always is: the retry ladder is finite, and once it is used
	 * up nothing else ever asks about that refund. A skip is, when its reason
	 * is one a configuration change can fix.
	 *
	 * @param array<string, mixed> $entry One stored entry.
	 * @return bool
	 */
	public static function is_replayable( array $entry ): bool {
		$outcome = (string) ( $entry['outcome'] ?? '' );

		if ( self::OUTCOME_FAILED === $outcome ) {
			return true;
		}

		return ( self::OUTCOME_SKIPPED === $outcome )
			&& in_array( (string) ( $entry['reason'] ?? '' ), self::REPLAYABLE_SKIPS, true );
	}

	/**
	 * What a replay would queue, one job per refund.
	 *
	 * The ring holds a row per destination per attempt, so thirty failed rows
	 * are rarely thirty refunds. This reduces them: for each refund only the
	 * NEWEST row per destination counts (a destination that failed on the
	 * first attempt and was accepted on the second is fine), and the job that
	 * comes out names exactly the destinations still failing in `only`, so a
	 * destination that already took the event is never sent it twice. A
	 * refund whose newest word is a replayable skip is queued whole - it was
	 * never sent anywhere.
	 *
	 * @param string[] $references Limit to these references; empty means every replayable refund.
	 * @return array<string, array{platform: string, order_id: int, refund_id: int, only: string[]}> Keyed by reference.
	 */
	public function replay_plan( array $references = array() ): array {
		$latest = array();
		$order  = array();

		foreach ( $this->all() as $index => $entry ) {
			$reference = (string) ( $entry['reference'] ?? '' );

			if ( '' === $reference || ( array() !== $references && ! in_array( $reference, $references, true ) ) ) {
				continue;
			}

			$destination = (string) ( $entry['destination'] ?? '' );

			// Oldest first, so the last write for a key is the newest row.
			$latest[ $reference ][ $destination ] = array( 'index' => $index ) + $entry;
			$order[ $reference ]                  = $index;
		}

		$plan = array();

		foreach ( $latest as $reference => $by_destination ) {
			$parsed = self::parse_reference( $reference );

			if ( null === $parsed ) {
				continue;
			}

			$only = array();

			foreach ( $by_destination as $destination => $entry ) {
				if ( '' !== $destination && self::OUTCOME_FAILED === (string) ( $entry['outcome'] ?? '' ) ) {
					$only[] = $destination;
				}
			}

			// The refund-level row (a skip carries no destination). It wins
			// only when it is the newest word about the refund: an older skip
			// followed by a real send attempt is history.
			$whole = $by_destination[''] ?? null;

			if ( null !== $whole
				&& (int) $whole['index'] === (int) $order[ $reference ]
				&& self::is_replayable( $whole )
			) {
				$plan[ $reference ] = $parsed + array( 'only' => array() );

				continue;
			}

			if ( array() !== $only ) {
				$plan[ $reference ] = $parsed + array( 'only' => $only );
			}
		}

		return $plan;
	}

	/**
	 * Which stored entries a replay would act on, by their index in all().
	 *
	 * The per-row answer to the question replay_plan() answers per refund,
	 * and derived from the plan rather than judged row by row: a failed row
	 * that a later accepted row for the same destination has overtaken is
	 * history, and must not advertise a replay the plan would not perform.
	 * Only the newest row per destination among the plan's targets qualifies;
	 * a refund the plan queues whole (its newest word is a fixable skip) marks
	 * that skip row alone.
	 *
	 * @return array<int, true> Indices of the replayable entries.
	 */
	public function replayable_entries(): array {
		$plan   = $this->replay_plan();
		$newest = array();

		foreach ( $this->all() as $index => $entry ) {
			$reference = (string) ( $entry['reference'] ?? '' );

			if ( ! isset( $plan[ $reference ] ) ) {
				continue;
			}

			$destination = (string) ( $entry['destination'] ?? '' );
			$targets     = $plan[ $reference ]['only'];

			$wanted = ( array() === $targets )
				? ( '' === $destination )
				: in_array( $destination, $targets, true );

			if ( $wanted ) {
				// Oldest first: the last write wins, which is the newest row.
				// A pipe as the separator: a reference is platform:ids and a destination
				// a G- id, so neither can contain one.
				$newest[ $reference . '|' . $destination ] = $index;
			}
		}

		return array_fill_keys( array_values( $newest ), true );
	}

	/**
	 * Whether the newest row about one refund and destination says Google
	 * took it. The sender's guard against sending a destination the same
	 * event twice when a replay is queued for one that already recovered.
	 *
	 * @param string $reference   The refund reference.
	 * @param string $measurement The destination.
	 * @return bool
	 */
	public function latest_is_accepted( string $reference, string $measurement ): bool {
		$newest = null;

		foreach ( $this->all() as $entry ) {
			if ( (string) ( $entry['reference'] ?? '' ) === $reference
				&& (string) ( $entry['destination'] ?? '' ) === $measurement
			) {
				$newest = $entry;
			}
		}

		return null !== $newest && self::OUTCOME_ACCEPTED === (string) ( $newest['outcome'] ?? '' );
	}

	/**
	 * The three ids inside a stored reference, or null when it is not one.
	 *
	 * The reference is written by RefundData::reference() as
	 * platform:order_id:refund_id, and the platform ids carry no colon.
	 *
	 * @param string $reference A stored reference.
	 * @return array{platform: string, order_id: int, refund_id: int}|null
	 */
	public static function parse_reference( string $reference ): ?array {
		$parts = explode( ':', $reference );

		if ( 3 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) || ! ctype_digit( $parts[2] ) ) {
			return null;
		}

		return array(
			'platform'  => $parts[0],
			'order_id'  => (int) $parts[1],
			'refund_id' => (int) $parts[2],
		);
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

		$clean = sanitize_text_field( (string) $value );

		if ( mb_strlen( $clean ) <= $max_size ) {
			return $clean;
		}

		// The ellipsis is part of the budget, so a capped value never exceeds
		// the cap by the character that says it was capped.
		return mb_substr( $clean, 0, $max_size - 1 ) . '…';
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
