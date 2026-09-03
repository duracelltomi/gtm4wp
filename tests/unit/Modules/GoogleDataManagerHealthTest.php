<?php
/**
 * Unit tests for the per-destination health records.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The health store feeds the failing-destination notice and, later, the Site
 * Health rows - so the streak arithmetic, the threshold and the never-store-
 * raw-bodies cap are each asserted as values, not as "it ran" (the Infection
 * discipline).
 */
final class GoogleDataManagerHealthTest extends TestCase {

	use OptionStoreTrait;

	private const MEASUREMENT = 'G-ABC123';

	private const NOW = 1_800_000_000;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );

		$this->stub_option_store();
	}

	/**
	 * A store with a pinned clock.
	 *
	 * @param int $now The time every write records.
	 * @return DestinationHealth
	 */
	private function health( int $now = self::NOW ): DestinationHealth {
		return new DestinationHealth( static fn () => $now );
	}

	public function test_a_new_destination_has_no_record(): void {
		$this->assertNull( $this->health()->get( self::MEASUREMENT ) );
		$this->assertFalse( $this->health()->is_failing( self::MEASUREMENT ) );
		$this->assertSame( array(), $this->health()->all() );
	}

	public function test_a_success_records_the_time_and_clears_the_error(): void {
		$this->health()->record_success( self::MEASUREMENT );

		$this->assertSame(
			array(
				'last_success'         => self::NOW,
				'last_failure'         => 0,
				'consecutive_failures' => 0,
				'last_error'           => '',
			),
			$this->health()->get( self::MEASUREMENT )
		);
	}

	public function test_failures_accumulate_and_cross_the_threshold(): void {
		$health = $this->health();

		for ( $i = 1; $i < DestinationHealth::FAILURE_THRESHOLD; $i++ ) {
			$health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED: no access' );
			$this->assertFalse( $health->is_failing( self::MEASUREMENT ), "Not failing after {$i} failure(s)." );
		}

		$health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED: no access' );

		$record = $health->get( self::MEASUREMENT );
		$this->assertSame( DestinationHealth::FAILURE_THRESHOLD, $record['consecutive_failures'] );
		$this->assertSame( self::NOW, $record['last_failure'] );
		$this->assertSame( 'PERMISSION_DENIED: no access', $record['last_error'] );
		$this->assertTrue( $health->is_failing( self::MEASUREMENT ) );
	}

	public function test_a_success_resets_the_streak_but_keeps_the_last_failure_time(): void {
		$health = $this->health();

		$health->record_failure( self::MEASUREMENT, 'boom' );
		$health->record_failure( self::MEASUREMENT, 'boom' );
		$health->record_failure( self::MEASUREMENT, 'boom' );
		$this->assertTrue( $health->is_failing( self::MEASUREMENT ) );

		$health->record_success( self::MEASUREMENT );

		$record = $health->get( self::MEASUREMENT );
		$this->assertSame( 0, $record['consecutive_failures'] );
		$this->assertSame( '', $record['last_error'] );
		$this->assertSame( self::NOW, $record['last_failure'], 'The failure history survives as a timestamp; only the streak resets.' );
		$this->assertFalse( $health->is_failing( self::MEASUREMENT ) );
	}

	public function test_records_are_kept_per_destination(): void {
		$health = $this->health();

		$health->record_failure( self::MEASUREMENT, 'boom' );
		$health->record_success( 'G-OTHER1' );

		$this->assertSame( 1, $health->get( self::MEASUREMENT )['consecutive_failures'] );
		$this->assertSame( 0, $health->get( 'G-OTHER1' )['consecutive_failures'] );
		$this->assertSame( array( self::MEASUREMENT, 'G-OTHER1' ), array_keys( $health->all() ) );
	}

	/**
	 * The stored summary is what the notice and the settings screen print, so
	 * a verbose or markup-carrying upstream error is capped and stripped at
	 * write time (the KeyVault last_error discipline).
	 */
	public function test_the_stored_error_is_sanitized_and_capped(): void {
		$this->health()->record_failure( self::MEASUREMENT, '<script>alert(1)</script>' . str_repeat( 'x', 500 ) );

		$error = $this->health()->get( self::MEASUREMENT )['last_error'];
		$this->assertStringNotContainsString( '<script>', $error );
		$this->assertLessThanOrEqual( 200, mb_strlen( $error ) );
	}

	public function test_an_empty_measurement_id_records_nothing(): void {
		$this->health()->record_success( '' );
		$this->health()->record_failure( '', 'boom' );

		$this->assertSame( array(), $this->health()->all() );
	}

	public function test_the_option_row_is_never_autoloaded(): void {
		$this->health()->record_failure( self::MEASUREMENT, 'boom' );
		$this->health()->record_success( self::MEASUREMENT );

		$this->assertNotEmpty( $this->option_writes );
		foreach ( $this->option_writes as $write ) {
			$this->assertSame( DestinationHealth::OPTION_NAME, $write['key'] );
			$this->assertFalse( $write['autoload'], 'Every write keeps the row out of autoload.' );
		}
	}

	public function test_a_damaged_stored_row_is_coerced_not_fatal(): void {
		$this->stub_option_store(
			array(
				DestinationHealth::OPTION_NAME => array(
					self::MEASUREMENT => 'not-an-array',
					'G-OTHER1'        => array(
						'consecutive_failures' => '5',
						'last_error'           => 42,
					),
				),
			)
		);

		$this->assertSame( 0, $this->health()->get( self::MEASUREMENT )['consecutive_failures'] );

		$other = $this->health()->get( 'G-OTHER1' );
		$this->assertSame( 5, $other['consecutive_failures'] );
		$this->assertSame( '', $other['last_error'], 'A non-string error collapses to empty rather than leaking a type.' );
		$this->assertTrue( $this->health()->is_failing( 'G-OTHER1' ) );
	}
}
