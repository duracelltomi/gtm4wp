<?php
/**
 * Unit tests for the DM8 send diagnostics ring.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\ConsentPolicy;
use GTM4WP\Modules\GoogleDataManager\RefundSender;
use GTM4WP\Modules\GoogleDataManager\SendLog;
use GTM4WP\Modules\GoogleDataManager\SendQueue;
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

	/**
	 * The skip reasons the ring classifies are stored in it and matched by
	 * the settings screen's labels, so their values are a contract: a value
	 * change would misread every stored row (#398). Each is classified as the
	 * sender's constant, not as a copy of its string.
	 */
	public function test_the_classified_skip_reasons_keep_their_stored_values(): void {
		$replayable = array(
			'no_destination'    => RefundSender::REASON_NO_DESTINATION,
			'consent_unknown'   => ConsentPolicy::REASON_UNKNOWN,
			'consent_denied'    => ConsentPolicy::REASON_DENIED,
			'vetoed'            => RefundSender::REASON_VETOED,
			'refund_unreadable' => RefundSender::REASON_REFUND_UNREADABLE,
			'not_production'    => RefundSender::REASON_NOT_PRODUCTION,
		);

		// An inactive platform is only ever written as failed, which stays
		// replayable through the failed branch, not as a skip (#553).
		$this->assertSame( 'platform_inactive', RefundSender::REASON_PLATFORM_INACTIVE );
		$this->assertTrue(
			SendLog::is_replayable(
				self::entry(
					array(
						'outcome' => SendLog::OUTCOME_FAILED,
						'reason'  => RefundSender::REASON_PLATFORM_INACTIVE,
					)
				)
			)
		);

		$informational = array(
			'already_sent'        => RefundSender::REASON_ALREADY_SENT,
			'destination_removed' => RefundSender::REASON_DESTINATION_REMOVED,
		);

		foreach ( $replayable as $stored => $reason ) {
			$this->assertSame( $stored, $reason );
			$row = self::entry(
				array(
					'outcome' => SendLog::OUTCOME_SKIPPED,
					'reason'  => $reason,
				)
			);
			$this->assertTrue( SendLog::is_replayable( $row ), $stored . ' is offered again.' );
			$this->assertSame( SendLog::TONE_WARN, SendLog::tone( $row ), $stored );
		}

		foreach ( $informational as $stored => $reason ) {
			$this->assertSame( $stored, $reason );
			$row = self::entry(
				array(
					'outcome' => SendLog::OUTCOME_SKIPPED,
					'reason'  => $reason,
				)
			);
			$this->assertFalse( SendLog::is_replayable( $row ), $stored . ' is never offered again.' );
			$this->assertSame( SendLog::TONE_OK, SendLog::tone( $row ), $stored );
		}
	}

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
					'error_class' => '',
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

	/**
	 * #509: error_class is what the log ability answers with in place of
	 * Google's text, so only a code-shaped value is ever stored.
	 *
	 * @param string $stored   The class a caller passed.
	 * @param string $expected What the ring keeps.
	 */
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'PERMISSION_DENIED', 'PERMISSION_DENIED' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'http_403', 'http_403' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'Project 123456789012 denied', '' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( '"><img src=x>', '' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( "PERMISSION_DENIED\n", '' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'A_CLASS_NAME_THAT_IS_LONGER_THAN_FORTY_CHARS', '' ) )]
	public function test_only_a_code_shaped_error_class_is_stored( string $stored, string $expected ): void {
		$log = $this->log();
		$log->record( self::entry( array( 'error_class' => $stored ) ) );

		$this->assertSame( $expected, $log->all()[0]['error_class'] );
	}

	/**
	 * The input-schema pattern accepts exactly what parse_reference() accepts.
	 * The schema is published to REST and ability clients, so it is pinned by
	 * the strings it sorts, not by its own value (TS-21).
	 */
	public function test_the_reference_pattern_accepts_what_parse_reference_accepts(): void {
		$accept = array( 'woocommerce:12:34', 'edd:1:0' );
		$refuse = array( 'woocommerce:12', ':12:34', 'woocommerce:a:34', 'woocommerce:12:34x', 'a:1:2:3', 'woocommerce:12:' );

		foreach ( $accept as $reference ) {
			$this->assertSame( 1, preg_match( '/' . SendLog::REFERENCE_PATTERN . '/D', $reference ), $reference );
			$this->assertNotNull( SendLog::parse_reference( $reference ), $reference );
		}

		foreach ( $refuse as $reference ) {
			$this->assertSame( 0, preg_match( '/' . SendLog::REFERENCE_PATTERN . '/D', $reference ), $reference );
			$this->assertNull( SendLog::parse_reference( $reference ), $reference );
		}
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

	/**
	 * The status poll is the one writer that updates an entry in place rather
	 * than going through record(); it used to cap the value and skip the
	 * cleaner, so a status string that was not a bare enum name was stored
	 * verbatim (#244). The value comes from Google's own response, so this is
	 * the class keeping its own "only sanitized text goes in" promise rather
	 * than a guard against a visitor - but the promise is what the A4 REST
	 * route and the settings panel rely on when they show the ring as stored.
	 */
	public function test_a_status_result_is_cleaned_and_capped_like_every_other_text_member(): void {
		$log = $this->log();
		$log->record(
			self::entry(
				array(
					'request_id'  => 'req-1',
					'destination' => 'G-ABC123',
				)
			)
		);

		$log->record_status(
			'req-1',
			array(
				array(
					'measurement' => 'G-ABC123',
					'status'      => '  <b>SUCCESS</b> ' . str_repeat( 'X', SendLog::REASON_MAX_LENGTH ),
				),
			)
		);

		$stored = $log->all()[0]['result'];

		$this->assertStringStartsWith( 'SUCCESS', $stored, 'The markup is stripped and the whitespace trimmed, as text() does for record().' );
		$this->assertStringNotContainsString( '<', $stored );
		$this->assertSame( SendLog::REASON_MAX_LENGTH, mb_strlen( $stored ), 'Capped to the same budget as record(), the ellipsis included.' );
		$this->assertStringEndsWith( '…', $stored );
	}

	/**
	 * The shape of the status list is Google's, decoded from JSON: a row that
	 * is not an array is skipped rather than read, and a negative count is
	 * clamped the way record() clamps its own (T94d - record() had the
	 * negative-count case, record_status() did not).
	 */
	public function test_a_malformed_status_row_is_skipped_and_negative_counts_are_clamped(): void {
		$log = $this->log();
		$log->record(
			self::entry(
				array(
					'request_id'  => 'req-1',
					'destination' => 'G-ABC123',
				)
			)
		);

		$log->record_status(
			'req-1',
			array(
				'garbage',
				null,
				array( 'status' => 'FAILED' ),
				array(
					'measurement' => 'G-ABC123',
					'status'      => 'SUCCESS',
					'errors'      => -5,
					'warnings'    => '-2',
				),
			)
		);

		$entry = $log->all()[0];

		$this->assertSame( 'SUCCESS', $entry['result'], 'The one well-formed row is the one applied.' );
		$this->assertSame( 0, $entry['errors'] );
		$this->assertSame( 0, $entry['warnings'] );
	}

	/**
	 * #478: the requestId is the poll's match key; one longer than the old
	 * 60-character display cap must still match, and so must one over the
	 * match key's own cap (both sides are capped alike).
	 */
	public function test_a_long_request_id_still_receives_its_status(): void {
		foreach ( array( 70, 260 ) as $length ) {
			$this->options = array();
			$log           = $this->log();
			$request_id    = str_repeat( 'r', $length );
			$log->record( self::entry( array( 'request_id' => $request_id ) ) );

			$log->record_status(
				$request_id,
				array(
					array(
						'measurement' => 'G-ABC123',
						'status'      => 'SUCCESS',
						'errors'      => 0,
						'warnings'    => 0,
					),
				)
			);

			$this->assertSame( 'SUCCESS', $log->all()[0]['result'], (string) $length );
		}
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

	/**
	 * #395: in a ring of problems (expected skips after switching the feature
	 * on, an EEA store), a routine row being written is not the one dropped:
	 * an accepted send and every queued row of a replay must land.
	 */
	public function test_a_routine_row_lands_in_a_ring_of_problems(): void {
		$log = $this->log();

		for ( $i = 0; $i < SendLog::MAX_ENTRIES; $i++ ) {
			$log->record(
				self::entry(
					array(
						'reference' => 'woocommerce:6:' . $i,
						'outcome'   => SendLog::OUTCOME_SKIPPED,
						'reason'    => 'no_client_id',
					)
				)
			);
		}

		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:6:ok',
					'outcome'   => SendLog::OUTCOME_ACCEPTED,
					'result'    => 'SUCCESS',
				)
			)
		);
		$log->record_queued( 'woocommerce:6:q', array( 'G-A', 'G-B' ) );

		$entries = $log->all();
		$tail    = array_slice( $entries, -3 );

		$this->assertCount( SendLog::MAX_ENTRIES, $entries );
		$this->assertSame( array( 'woocommerce:6:ok', 'woocommerce:6:q', 'woocommerce:6:q' ), array_column( $tail, 'reference' ) );
		$this->assertSame( array( 'G-ABC123', 'G-A', 'G-B' ), array_column( $tail, 'destination' ) );
	}

	/**
	 * #473: a retry whose job never ran (the lane switched off, a lost action)
	 * becomes a replay target once it is overdue by QUEUED_STALE_AFTER; until
	 * then the scheduled retry is left to run.
	 */
	public function test_an_overdue_retry_becomes_replayable(): void {
		$this->log()->record(
			self::entry(
				array(
					'reference' => 'woocommerce:5:6',
					'outcome'   => SendLog::OUTCOME_RETRYING,
					'attempt'   => 2,
				)
			)
		);
		$due = self::NOW + SendQueue::retry_delay( 2 );

		$this->assertSame( array(), $this->log( $due + SendLog::QUEUED_STALE_AFTER - 1 )->replay_plan(), 'Still due to run.' );
		$this->assertSame( array( 'G-ABC123' ), $this->log( $due + SendLog::QUEUED_STALE_AFTER )->replay_plan()['woocommerce:5:6']['only'] ?? null );
	}

	/**
	 * #395 with problems no trim may drop (failures, a fixable skip): the rows a
	 * write appends still land instead of being the routine rows trimmed.
	 */
	public function test_appended_rows_survive_a_ring_of_problems_that_are_never_trimmed(): void {
		$log = $this->log();

		for ( $i = 0; $i < SendLog::MAX_ENTRIES; $i++ ) {
			$log->record(
				self::entry(
					array(
						'reference' => 'woocommerce:4:' . $i,
						'outcome'   => 0 === $i % 2 ? SendLog::OUTCOME_FAILED : SendLog::OUTCOME_SKIPPED,
						'reason'    => 0 === $i % 2 ? 'refused' : 'consent_unknown',
					)
				)
			);
		}

		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:4:ok',
					'outcome'   => SendLog::OUTCOME_ACCEPTED,
					'result'    => 'SUCCESS',
				)
			)
		);

		$after_accepted = $log->all();
		$this->assertSame( 'woocommerce:4:ok', end( $after_accepted )['reference'] );

		// The next write may trim that routine row, never its own two.
		$log->record_queued( 'woocommerce:4:q', array( 'G-A', 'G-B' ) );
		$entries = $log->all();

		$this->assertCount( SendLog::MAX_ENTRIES, $entries );
		$this->assertSame( array( 'G-A', 'G-B' ), array_column( array_slice( $entries, -2 ), 'destination' ) );
		$this->assertSame( array( 'woocommerce:4:q', 'woocommerce:4:q' ), array_column( array_slice( $entries, -2 ), 'reference' ) );
	}

	/**
	 * A skip a setting can put right is a problem kept ahead of routine
	 * sends. An expected, never-replayable skip (no client id, #395) ages out
	 * in order with them instead, or a ring of them evicts every success.
	 */
	public function test_a_fixable_skip_is_kept_and_an_expected_one_ages_out(): void {
		$log = $this->log();

		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:7:1',
					'outcome'   => SendLog::OUTCOME_SKIPPED,
					'reason'    => 'no_destination',
				)
			)
		);
		$log->record(
			self::entry(
				array(
					'reference' => 'woocommerce:7:2',
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

		$references = array_column( $log->all(), 'reference' );

		$this->assertContains( 'woocommerce:7:1', $references );
		$this->assertNotContains( 'woocommerce:7:2', $references );
	}

	/**
	 * The ring is read back without cleaning (only writes are), so a stored row
	 * missing a member - an older format, an out-of-band write - must still
	 * display without a PHP warning on the settings screen and the ability
	 * (R48 #391). Warnings are made fatal here so the assertion is the absence
	 * of one, not a log line.
	 */
	public function test_a_stored_row_without_an_outcome_displays_without_a_warning(): void {
		$this->options[ SendLog::OPTION_NAME ] = array(
			array(
				'time'      => self::NOW,
				'feature'   => SendLog::FEATURE_REFUND,
				'reference' => 'woocommerce:12:34',
			),
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only warning trap, restored below.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception reported by PHPUnit.
				throw new \ErrorException( $errstr, 0, $errno );
			}
		);

		try {
			$entries = $this->log()->entries_for_display();
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $entries );
		$this->assertSame( SendLog::TONE_PENDING, $entries[0]['tone'] );
		$this->assertFalse( $entries[0]['waiting'] );
	}
}
