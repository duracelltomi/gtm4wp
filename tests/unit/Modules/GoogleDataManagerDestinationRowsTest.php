<?php
/**
 * Unit tests for the Data Manager destination row helper.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * The row grammar (the U124 anchors), the runtime accessor with its public
 * filter, and the deletion veto's reference check. The runtime accessor is
 * the one every consumer reads destinations through, so a filtered-in row
 * that fails validation must be dropped here - phase 4's send path relies on
 * never seeing an invalid row.
 */
final class GoogleDataManagerDestinationRowsTest extends TestCase {

	private const ACCOUNT_ID = 'sa_0123456789ab';

	/**
	 * A complete valid row.
	 *
	 * @param array<string, string> $overrides Column overrides.
	 * @return array<string, string>
	 */
	private static function row( array $overrides = array() ): array {
		return array_merge(
			array(
				DestinationRows::COLUMN_LABEL       => 'Production',
				DestinationRows::COLUMN_ACCOUNT     => self::ACCOUNT_ID,
				DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
				DestinationRows::COLUMN_PROPERTY    => '123456789',
				DestinationRows::COLUMN_MEASUREMENT => 'G-ABC123',
			),
			$overrides
		);
	}

	/**
	 * An Options service over one stored destinations value.
	 *
	 * @param mixed $stored Stored option value.
	 * @return Options
	 */
	private function options_with( $stored ): Options {
		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_GDM_DESTINATIONS => $stored ) );

		return new Options( array( GTM4WP_OPTION_GDM_DESTINATIONS => array() ) );
	}

	// ---- Row grammar -------------------------------------------------------

	public function test_a_complete_row_is_valid(): void {
		$this->assertTrue( DestinationRows::is_valid_row( self::row() ) );
	}

	public function test_the_label_is_not_part_of_validity(): void {
		$this->assertTrue( DestinationRows::is_valid_row( self::row( array( DestinationRows::COLUMN_LABEL => '' ) ) ) );
	}

	/**
	 * Every failing aspect, one at a time: the negative direction of each
	 * anchor. A trailing newline is its own case - the D modifier is what
	 * rejects it, and dropping the modifier would pass every other case here.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function invalid_rows(): array {
		return array(
			'missing account'        => array( array( DestinationRows::COLUMN_ACCOUNT => '' ) ),
			'malformed account'      => array( array( DestinationRows::COLUMN_ACCOUNT => 'sa_NOTHEX00000' ) ),
			'foreign id shape'       => array( array( DestinationRows::COLUMN_ACCOUNT => 'other_id' ) ),
			'unknown type'           => array( array( DestinationRows::COLUMN_TYPE => 'google-ads' ) ),
			'empty type'             => array( array( DestinationRows::COLUMN_TYPE => '' ) ),
			'non-numeric property'   => array( array( DestinationRows::COLUMN_PROPERTY => 'UA-1234' ) ),
			'empty property'         => array( array( DestinationRows::COLUMN_PROPERTY => '' ) ),
			'oversized property'     => array( array( DestinationRows::COLUMN_PROPERTY => str_repeat( '9', 21 ) ) ),
			'measurement without G-' => array( array( DestinationRows::COLUMN_MEASUREMENT => 'ABC123' ) ),
			'empty measurement'      => array( array( DestinationRows::COLUMN_MEASUREMENT => '' ) ),
			'oversized measurement'  => array( array( DestinationRows::COLUMN_MEASUREMENT => 'G-' . str_repeat( 'A', 31 ) ) ),
			'injection-shaped cells' => array(
				array(
					DestinationRows::COLUMN_PROPERTY    => '123"; alert(1);//',
					DestinationRows::COLUMN_MEASUREMENT => 'G-"</script>',
				),
			),
		);
	}

	/**
	 * One failing aspect at a time.
	 *
	 * @param array<string, string> $overrides Column overrides.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_rows' )]
	public function test_an_invalid_row_is_rejected( array $overrides ): void {
		$this->assertFalse( DestinationRows::is_valid_row( DestinationRows::normalize_row( self::row( $overrides ) ) ) );
	}

	/**
	 * The D-modifier case, deliberately NOT normalized first: normalize_row()
	 * trims, so the only way a trailing newline reaches the pattern is a
	 * caller skipping normalization - and the pattern is the last line of
	 * defense there (the ContainerRows lesson).
	 */
	public function test_a_trailing_newline_fails_the_pattern_itself(): void {
		$this->assertFalse( DestinationRows::is_valid_row( self::row( array( DestinationRows::COLUMN_MEASUREMENT => "G-ABC123\n" ) ) ) );
		$this->assertFalse( DestinationRows::is_valid_row( self::row( array( DestinationRows::COLUMN_PROPERTY => "123456789\n" ) ) ) );
		$this->assertFalse( DestinationRows::is_valid_row( self::row( array( DestinationRows::COLUMN_ACCOUNT => self::ACCOUNT_ID . "\n" ) ) ) );
	}

	public function test_normalize_row_trims_uppercases_the_measurement_id_and_collapses_non_scalars(): void {
		$row = DestinationRows::normalize_row(
			array(
				DestinationRows::COLUMN_LABEL       => "  Production \n",
				DestinationRows::COLUMN_ACCOUNT     => array( 'nested' ),
				DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
				DestinationRows::COLUMN_PROPERTY    => ' 123456789 ',
				DestinationRows::COLUMN_MEASUREMENT => ' g-abc123 ',
			)
		);

		$this->assertSame( 'Production', $row[ DestinationRows::COLUMN_LABEL ] );
		$this->assertSame( '', $row[ DestinationRows::COLUMN_ACCOUNT ], 'A non-scalar cell collapses to the empty string, never warns.' );
		$this->assertSame( '123456789', $row[ DestinationRows::COLUMN_PROPERTY ] );
		$this->assertSame( 'G-ABC123', $row[ DestinationRows::COLUMN_MEASUREMENT ], 'A lowercase paste is repaired to the uppercase grammar.' );
	}

	// ---- Runtime accessor --------------------------------------------------

	public function test_rows_returns_the_stored_rows_through_the_filter(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_DESTINATIONS )
			->once()
			->with( array( self::row() ) )
			->andReturnFirstArg();

		$this->assertSame( array( self::row() ), DestinationRows::rows( $this->options_with( array( self::row() ) ) ) );
	}

	public function test_rows_drops_an_invalid_filtered_in_row_and_keeps_the_valid_ones(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_DESTINATIONS )
			->once()
			->andReturn(
				array(
					self::row(),
					self::row( array( DestinationRows::COLUMN_MEASUREMENT => 'not-a-measurement-id' ) ),
					'not even a row',
				)
			);

		$this->assertSame( array( self::row() ), DestinationRows::rows( $this->options_with( array() ) ) );
	}

	public function test_rows_survives_a_filter_returning_a_non_array(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_DESTINATIONS )->once()->andReturn( 'broken' );

		$this->assertSame( array(), DestinationRows::rows( $this->options_with( array( self::row() ) ) ) );
	}

	public function test_rows_tolerates_a_damaged_stored_value(): void {
		$this->assertSame( array(), DestinationRows::rows( $this->options_with( 'not-an-array' ) ) );
	}

	// ---- Deletion veto -----------------------------------------------------

	public function test_references_account_finds_a_stored_reference(): void {
		$options = $this->options_with( array( self::row() ) );

		$this->assertTrue( DestinationRows::references_account( $options, self::ACCOUNT_ID ) );
		$this->assertFalse( DestinationRows::references_account( $options, 'sa_ffffffffffff' ) );
	}

	public function test_references_account_is_false_for_an_empty_id_even_when_a_row_is_incomplete(): void {
		// A row whose account cell is empty must not make the EMPTY id "in
		// use" - that would block deleting any account forever.
		$options = $this->options_with( array( self::row( array( DestinationRows::COLUMN_ACCOUNT => '' ) ) ) );

		$this->assertFalse( DestinationRows::references_account( $options, '' ) );
	}

	public function test_references_account_tolerates_a_damaged_stored_value(): void {
		$this->assertFalse( DestinationRows::references_account( $this->options_with( 'broken' ), self::ACCOUNT_ID ) );
	}
}
