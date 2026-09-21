<?php
/**
 * Unit tests for the Google Data Manager abilities.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Module\AbilitiesInterface;
use GTM4WP\Modules\GoogleDataManager\Abilities;
use GTM4WP\Modules\GoogleDataManager\AdminSchema;
use GTM4WP\Modules\GoogleDataManager\SendLog;
use GTM4WP\Tests\unit\Abilities\AbilitiesTestCase;

/**
 * The gtm4wp/get-google-data-manager-log ability hands over the same rows the settings
 * screen lists, through the one shaping SendLog owns. Pinned: the order, the
 * two derived fields, the problems filter, the cap, the queue summary, and -
 * the property that matters most for a transcript - that a row carries the
 * stored allow-list of members and nothing else. Plus the hand-over: the
 * module's admin schema is how the Registrar finds this provider.
 */
final class GoogleDataManagerAbilitiesTest extends AbilitiesTestCase {

	private const NOW = 1_800_000_000;

	private SendLog $log;

	protected function setUp(): void {
		parent::setUp();

		$this->log = new SendLog( static fn () => self::NOW );

		( new Abilities( $this->log ) )->register();
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
				'destination' => SendLog::OUTCOME_SKIPPED === $outcome ? '' : 'G-ABC123',
				'outcome'     => $outcome,
				'attempt'     => 1,
				'status'      => SendLog::OUTCOME_ACCEPTED === $outcome ? 200 : 0,
				'request_id'  => SendLog::OUTCOME_ACCEPTED === $outcome ? 'req-' . $reference : '',
				'reason'      => $reason,
			)
		);
	}

	public function test_the_module_schema_hands_the_provider_to_the_registrar(): void {
		$this->registered = array();

		$schema = new AdminSchema();
		$this->assertInstanceOf( AbilitiesInterface::class, $schema, 'Opting in on the admin schema is how a module reaches the abilities surface.' );

		$provider = $schema->abilities();
		$this->assertInstanceOf( Abilities::class, $provider );

		$provider->register();

		$this->assertSame( array( Abilities::GET_LOG ), array_keys( $this->registered ), 'The provider the schema builds registers the module ability, nothing else.' );
	}

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
}
