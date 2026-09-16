<?php
/**
 * Unit tests for the DM8 send diagnostics ring.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\SendLog;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The ring is what makes an invisible lane supportable, and it is also read in
 * public: the settings screen shows it and people paste it into forum threads.
 * So two properties matter equally and both are asserted as values, not as
 * "something was written":
 *
 * 1. It records enough to answer "what happened to that refund" - the reason,
 *    the attempt, the destination, and later what Google did with it.
 * 2. It records nothing it should not. The entry shape is an allow-list, so a
 *    caller cannot widen what the log carries by passing an extra key.
 */
final class GoogleDataManagerSendLogTest extends TestCase {

	use OptionStoreTrait;

	private const NOW = 1_800_000_000;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );

		$this->stub_option_store();
	}

	/**
	 * A ring with a pinned clock.
	 *
	 * @param int $now Time every entry records.
	 * @return SendLog
	 */
	private function log( int $now = self::NOW ): SendLog {
		return new SendLog( static fn () => $now );
	}

	/**
	 * One accepted send entry.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private static function entry( array $overrides = array() ): array {
		return array_merge(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-ABC123',
				'outcome'     => SendLog::OUTCOME_ACCEPTED,
				'attempt'     => 1,
				'status'      => 200,
				'request_id'  => 'req-42',
			),
			$overrides
		);
	}

	// ---- Recording ---------------------------------------------------------

	public function test_a_new_site_has_an_empty_ring(): void {
		$this->assertSame( array(), $this->log()->all() );
	}

	public function test_an_entry_is_stored_with_every_member_present(): void {
		$this->log()->record( self::entry() );

		$this->assertSame(
			array(
				array(
					'time'        => self::NOW,
					'feature'     => 'refund',
					'reference'   => 'woocommerce:12:34',
					'destination' => 'G-ABC123',
					'outcome'     => 'accepted',
					'attempt'     => 1,
					'status'      => 200,
					'request_id'  => 'req-42',
					'reason'      => '',
					'result'      => '',
					'errors'      => 0,
					'warnings'    => 0,
				),
			),
			$this->log()->all()
		);
	}

	public function test_a_skipped_send_records_the_rule_that_stopped_it(): void {
		$this->log()->record(
			self::entry(
				array(
					'outcome'     => SendLog::OUTCOME_SKIPPED,
					'reason'      => 'consent_denied',
					'destination' => '',
					'status'      => 0,
					'request_id'  => '',
				)
			)
		);

		$entry = $this->log()->all()[0];

		$this->assertSame( 'skipped', $entry['outcome'] );
		$this->assertSame( 'consent_denied', $entry['reason'] );
	}

	public function test_entries_are_kept_oldest_first(): void {
		$log = $this->log();
		$log->record( self::entry( array( 'reference' => 'first' ) ) );
		$log->record( self::entry( array( 'reference' => 'second' ) ) );

		$this->assertSame( array( 'first', 'second' ), array_column( $log->all(), 'reference' ) );
	}

	public function test_the_ring_drops_the_oldest_entry_when_it_is_full(): void {
		$log = $this->log();

		for ( $i = 1; $i <= SendLog::MAX_ENTRIES + 5; $i++ ) {
			$log->record( self::entry( array( 'reference' => 'ref-' . $i ) ) );
		}

		$entries = $log->all();

		$this->assertCount( SendLog::MAX_ENTRIES, $entries );
		$this->assertSame( 'ref-6', $entries[0]['reference'], 'The five oldest entries are gone, not the newest ones.' );
		$this->assertSame( 'ref-' . ( SendLog::MAX_ENTRIES + 5 ), $entries[ SendLog::MAX_ENTRIES - 1 ]['reference'] );
	}

	public function test_the_ring_row_is_never_autoloaded(): void {
		$this->log()->record( self::entry() );
		$this->log()->record( self::entry() );

		$this->assertSame(
			array(
				array(
					'fn'       => 'add_option',
					'key'      => SendLog::OPTION_NAME,
					'autoload' => false,
				),
				array(
					'fn'       => 'update_option',
					'key'      => SendLog::OPTION_NAME,
					'autoload' => false,
				),
			),
			$this->option_writes,
			'The log is written by background jobs and read on one admin screen; autoloading it would cost every pageview.'
		);
	}

	public function test_clearing_empties_the_ring(): void {
		$log = $this->log();
		$log->record( self::entry() );
		$log->clear();

		$this->assertSame( array(), $log->all() );
	}

	// ---- What may not get in -----------------------------------------------

	public function test_a_caller_cannot_add_a_field_of_its_own(): void {
		$this->log()->record(
			self::entry(
				array(
					'access_token' => 'ya29.secret',
					'request_body' => array( 'events' => array() ),
				)
			)
		);

		$entry = $this->log()->all()[0];

		$this->assertArrayNotHasKey( 'access_token', $entry, 'The stored shape is an allow-list: a field nobody designed cannot appear because a caller passed it.' );
		$this->assertArrayNotHasKey( 'request_body', $entry );
		$this->assertStringNotContainsString( 'ya29.secret', self::flatten( $entry ) );
	}

	public function test_markup_in_a_reason_is_stripped(): void {
		$this->log()->record( self::entry( array( 'reason' => '<script>alert(1)</script>PERMISSION_DENIED' ) ) );

		$reason = $this->log()->all()[0]['reason'];

		$this->assertStringNotContainsString( '<script>', $reason );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $reason );
	}

	public function test_an_oversized_reason_is_capped_and_says_so(): void {
		$this->log()->record( self::entry( array( 'reason' => str_repeat( 'x', 5000 ) ) ) );

		$reason = $this->log()->all()[0]['reason'];

		$this->assertSame( SendLog::REASON_MAX_LENGTH, mb_strlen( $reason ), 'The ellipsis is inside the budget, not on top of it.' );
		$this->assertStringEndsWith( '…', $reason, 'A cut sentence must not read as a complete one.' );
	}

	public function test_a_reason_within_the_cap_is_stored_whole_with_no_ellipsis(): void {
		$reason = str_repeat( 'y', SendLog::REASON_MAX_LENGTH );

		$this->log()->record( self::entry( array( 'reason' => $reason ) ) );

		$this->assertSame( $reason, $this->log()->all()[0]['reason'] );
	}

	public function test_the_plugins_own_not_found_explanation_fits_the_cap(): void {
		// The sentence the cap used to cut, verbatim from EventsIngest; if it
		// grows past the budget again this is the test that says so.
		$explanation = 'Google Analytics could not find this destination. Please double-check both the GA4 property ID and the measurement ID - and check that the service account was added to the property, because a property the account is not allowed to see is reported as not found too (NOT_FOUND: Requested entity was not found.)';

		$this->log()->record( self::entry( array( 'reason' => $explanation ) ) );

		$this->assertSame( $explanation, $this->log()->all()[0]['reason'] );
	}

	public function test_an_oversized_reference_is_capped(): void {
		$this->log()->record( self::entry( array( 'reference' => str_repeat( 'y', 500 ) ) ) );

		$this->assertSame( 60, mb_strlen( $this->log()->all()[0]['reference'] ) );
	}

	public function test_a_non_scalar_field_becomes_an_empty_string(): void {
		$this->log()->record( self::entry( array( 'reason' => array( 'nested' => 'value' ) ) ) );

		$this->assertSame( '', $this->log()->all()[0]['reason'] );
	}

	public function test_negative_counts_are_never_stored(): void {
		$this->log()->record(
			self::entry(
				array(
					'attempt' => -3,
					'status'  => -1,
				)
			)
		);

		$entry = $this->log()->all()[0];

		$this->assertSame( 0, $entry['attempt'] );
		$this->assertSame( 0, $entry['status'] );
	}

	public function test_a_damaged_option_row_reads_as_an_empty_ring(): void {
		$this->options[ SendLog::OPTION_NAME ] = 'not-an-array';

		$this->assertSame( array(), $this->log()->all() );
	}

	public function test_a_damaged_entry_is_skipped_rather_than_returned(): void {
		$this->options[ SendLog::OPTION_NAME ] = array( 'garbage', array( 'reference' => 'good' ) );

		$entries = $this->log()->all();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'good', $entries[0]['reference'] );
	}

	// ---- Writing the asynchronous result back ------------------------------

	public function test_a_status_result_lands_on_the_entry_of_that_request_and_destination(): void {
		$log = $this->log();
		$log->record(
			self::entry(
				array(
					'destination' => 'G-AAA',
					'request_id'  => 'req-1',
				)
			)
		);
		$log->record(
			self::entry(
				array(
					'destination' => 'G-BBB',
					'request_id'  => 'req-1',
				)
			)
		);
		$log->record(
			self::entry(
				array(
					'destination' => 'G-AAA',
					'request_id'  => 'req-2',
				)
			)
		);

		$log->record_status(
			'req-1',
			array(
				array(
					'measurement' => 'G-AAA',
					'status'      => 'SUCCESS',
					'errors'      => 0,
					'warnings'    => 0,
				),
				array(
					'measurement' => 'G-BBB',
					'status'      => 'PARTIAL_SUCCESS',
					'errors'      => 2,
					'warnings'    => 1,
				),
			)
		);

		$entries = $log->all();

		$this->assertSame( 'SUCCESS', $entries[0]['result'] );
		$this->assertSame( 'PARTIAL_SUCCESS', $entries[1]['result'] );
		$this->assertSame( 2, $entries[1]['errors'] );
		$this->assertSame( 1, $entries[1]['warnings'] );
		$this->assertSame( '', $entries[2]['result'], 'A different request id is a different send and is left alone.' );
	}

	public function test_a_poll_that_learns_nothing_new_does_not_rewrite_the_row(): void {
		$log = $this->log();
		$log->record( self::entry( array( 'request_id' => 'req-1' ) ) );

		$statuses = array(
			array(
				'measurement' => 'G-ABC123',
				'status'      => 'PROCESSING',
				'errors'      => 0,
				'warnings'    => 0,
			),
		);

		$log->record_status( 'req-1', $statuses );
		$writes_after_first = count( $this->option_writes );

		$log->record_status( 'req-1', $statuses );

		$this->assertCount(
			$writes_after_first,
			$this->option_writes,
			'A request is polled again and again while it processes; each identical answer must not cost an option write.'
		);
		$this->assertSame( 'PROCESSING', $log->all()[0]['result'] );
	}

	public function test_a_status_without_a_request_id_writes_nothing(): void {
		$log = $this->log();
		$log->record( self::entry( array( 'request_id' => '' ) ) );
		$writes = count( $this->option_writes );

		$log->record_status(
			'',
			array(
				array(
					'measurement' => 'G-ABC123',
					'status'      => 'SUCCESS',
				),
			)
		);

		$this->assertCount( $writes, $this->option_writes );
		$this->assertSame( '', $log->all()[0]['result'] );
	}

	public function test_a_status_for_an_unknown_destination_leaves_the_entry_alone(): void {
		$log = $this->log();
		$log->record(
			self::entry(
				array(
					'destination' => 'G-AAA',
					'request_id'  => 'req-1',
				)
			)
		);

		$log->record_status(
			'req-1',
			array(
				array(
					'measurement' => 'G-ZZZ',
					'status'      => 'SUCCESS',
				),
			)
		);

		$this->assertSame( '', $log->all()[0]['result'] );
	}

	/**
	 * Encodes an entry for a "this string is nowhere in here" assertion, without
	 * depending on wp_json_encode() being stubbed in this suite.
	 *
	 * @param mixed $value Value to flatten.
	 * @return string
	 */
	private static function flatten( $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test helper, not plugin output.
	}

	// ---- The tone the settings screen colours by ---------------------------

	public function test_a_send_google_applied_is_the_only_quiet_tone(): void {
		$this->assertSame(
			SendLog::TONE_OK,
			SendLog::tone(
				array(
					'outcome'  => SendLog::OUTCOME_ACCEPTED,
					'result'   => 'SUCCESS',
					'errors'   => 0,
					'warnings' => 0,
				)
			)
		);
	}

	public function test_an_accepted_send_google_has_not_answered_about_is_neither_good_nor_bad(): void {
		$this->assertSame(
			SendLog::TONE_PENDING,
			SendLog::tone(
				array(
					'outcome' => SendLog::OUTCOME_ACCEPTED,
					'result'  => '',
				)
			),
			'A fresh row spends its first half hour here; drawing it as a problem would make every send look broken.'
		);

		$this->assertSame(
			SendLog::TONE_PENDING,
			SendLog::tone(
				array(
					'outcome' => SendLog::OUTCOME_ACCEPTED,
					'result'  => 'PROCESSING',
				)
			)
		);
	}

	public function test_a_failed_send_is_an_error(): void {
		$this->assertSame(
			SendLog::TONE_ERROR,
			SendLog::tone(
				array(
					'outcome' => SendLog::OUTCOME_FAILED,
					'result'  => '',
				)
			)
		);
	}

	public function test_a_send_google_took_but_could_not_process_is_an_error(): void {
		$this->assertSame(
			SendLog::TONE_ERROR,
			SendLog::tone(
				array(
					'outcome'  => SendLog::OUTCOME_ACCEPTED,
					'result'   => 'SUCCESS',
					'errors'   => 2,
					'warnings' => 0,
				)
			),
			'Records that failed inside an otherwise accepted request are lost data, whatever the request-level word says.'
		);
	}

	public function test_a_skipped_or_retrying_send_asks_for_a_look_without_claiming_data_was_lost(): void {
		$this->assertSame(
			SendLog::TONE_WARN,
			SendLog::tone(
				array(
					'outcome' => SendLog::OUTCOME_SKIPPED,
					'reason'  => 'consent_denied',
					'result'  => '',
				)
			)
		);

		$this->assertSame(
			SendLog::TONE_WARN,
			SendLog::tone(
				array(
					'outcome' => SendLog::OUTCOME_RETRYING,
					'result'  => '',
				)
			)
		);
	}

	public function test_warnings_and_a_partial_success_both_warn(): void {
		$this->assertSame(
			SendLog::TONE_WARN,
			SendLog::tone(
				array(
					'outcome'  => SendLog::OUTCOME_ACCEPTED,
					'result'   => 'SUCCESS',
					'errors'   => 0,
					'warnings' => 1,
				)
			)
		);

		$this->assertSame(
			SendLog::TONE_WARN,
			SendLog::tone(
				array(
					'outcome'  => SendLog::OUTCOME_ACCEPTED,
					'result'   => 'PARTIAL_SUCCESS',
					'errors'   => 0,
					'warnings' => 0,
				)
			)
		);
	}

	public function test_a_status_name_google_adds_later_is_drawn_as_something_to_look_at(): void {
		// Both spellings of the failure status Google's own two pages disagree
		// on (U136), and a name neither page has today.
		foreach ( array( 'FAILED', 'FAILURE', 'REJECTED_TOMORROW' ) as $status ) {
			$this->assertNotSame(
				SendLog::TONE_OK,
				SendLog::tone(
					array(
						'outcome'  => SendLog::OUTCOME_ACCEPTED,
						'result'   => $status,
						'errors'   => 0,
						'warnings' => 0,
					)
				),
				'Only SUCCESS is a success; anything else Google finished with needs a human.'
			);
		}
	}

	public function test_an_entry_missing_its_counts_is_read_without_warnings(): void {
		$this->assertSame(
			SendLog::TONE_OK,
			SendLog::tone(
				array(
					'outcome' => SendLog::OUTCOME_ACCEPTED,
					'result'  => 'SUCCESS',
				)
			)
		);
	}

	// ---- Which entries survive a full ring ---------------------------------

	public function test_a_flood_of_successful_sends_does_not_push_a_failure_out_of_the_ring(): void {
		$log = $this->log();

		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:1:2',
					'outcome'   => SendLog::OUTCOME_FAILED,
					'reason'    => 'quota exhausted',
				)
			)
		);

		// Twice the ring's capacity of routine sends on top of it.
		for ( $i = 0; $i < ( SendLog::MAX_ENTRIES * 2 ); $i++ ) {
			$log->record(
				self::entry(
					array(
						'reference' => 'woocommerce:9:' . $i,
						'outcome'   => SendLog::OUTCOME_ACCEPTED,
						'result'    => 'SUCCESS',
					)
				)
			);
		}

		$entries    = $log->all();
		$references = array_column( $entries, 'reference' );

		$this->assertCount( SendLog::MAX_ENTRIES, $entries );
		$this->assertContains(
			'woocommerce:1:2',
			$references,
			'The failure is the row that explains the number in Analytics; a batch of refunds must not evict it.'
		);
	}

	public function test_the_oldest_routine_send_is_the_one_that_makes_room(): void {
		$log = $this->log();

		for ( $i = 0; $i < SendLog::MAX_ENTRIES; $i++ ) {
			$log->record(
				self::entry(
					array(
						'reference' => 'woocommerce:9:' . $i,
						'outcome'   => SendLog::OUTCOME_ACCEPTED,
						'result'    => 'SUCCESS',
					)
				)
			);
		}

		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:9:new',
					'outcome'   => SendLog::OUTCOME_ACCEPTED,
					'result'    => 'SUCCESS',
				)
			)
		);

		$references = array_column( $log->all(), 'reference' );

		$this->assertNotContains( 'woocommerce:9:0', $references );
		$this->assertContains( 'woocommerce:9:1', $references );
		$this->assertContains( 'woocommerce:9:new', $references );
		$this->assertSame( 'woocommerce:9:new', end( $references ), 'Trimming removes entries; it never reorders them.' );
	}

	public function test_a_ring_of_nothing_but_problems_still_records_what_happens_next(): void {
		$log = $this->log();

		for ( $i = 0; $i < SendLog::MAX_ENTRIES; $i++ ) {
			$log->record(
				self::entry(
					array(
						'reference' => 'woocommerce:8:' . $i,
						'outcome'   => SendLog::OUTCOME_FAILED,
						'reason'    => 'refused',
					)
				)
			);
		}

		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:8:new',
					'outcome'   => SendLog::OUTCOME_FAILED,
					'reason'    => 'refused',
				)
			)
		);

		$entries    = $log->all();
		$references = array_column( $entries, 'reference' );

		$this->assertCount( SendLog::MAX_ENTRIES, $entries );
		$this->assertContains(
			'woocommerce:8:new',
			$references,
			'A ring full of failures must not freeze and stop recording; the oldest problem gives way to the newest one.'
		);
		$this->assertNotContains( 'woocommerce:8:0', $references );
	}

	public function test_a_skipped_send_counts_as_a_problem_worth_keeping(): void {
		$log = $this->log();

		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:7:1',
					'outcome'   => SendLog::OUTCOME_SKIPPED,
					'reason'    => 'no_client_id',
				)
			)
		);

		for ( $i = 0; $i < ( SendLog::MAX_ENTRIES + 5 ); $i++ ) {
			$log->record(
				self::entry(
					array(
						'reference' => 'woocommerce:9:' . $i,
						'outcome'   => SendLog::OUTCOME_ACCEPTED,
						'result'    => 'SUCCESS',
					)
				)
			);
		}

		$this->assertContains( 'woocommerce:7:1', array_column( $log->all(), 'reference' ) );
	}
}
