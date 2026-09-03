<?php
/**
 * Unit tests for the Google Data Manager admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Modules\GoogleDataManager\AdminSchema;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The destinations table's save-time sanitizer: the container-table
 * discipline applied to a new table. Every rejection is a WP_Error naming
 * the row (never a silently repaired or dropped broken row), every
 * acceptance stores the normalized form, and - the reason this schema is
 * special - fields() itself never touches the database: the vault read
 * lives in panel_data(), because the settings REST controller walks
 * fields() on every REST request site-wide.
 */
final class GoogleDataManagerAdminSchemaTest extends TestCase {

	use OptionStoreTrait;

	private const ACCOUNT_ID = 'sa_0123456789ab';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );

		// The vault only needs to answer has(); a metadata-shaped row is enough.
		$this->stub_option_store(
			array(
				KeyVault::OPTION_NAME => array(
					self::ACCOUNT_ID => array( 'label' => 'Production SA' ),
				),
			)
		);
	}

	/**
	 * The destinations Field of the schema.
	 *
	 * @return Field
	 */
	private function field(): Field {
		$fields = ( new AdminSchema() )->fields();
		$this->assertCount( 1, $fields );

		return $fields[0];
	}

	/**
	 * A complete valid submitted row.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return array<string, mixed>
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

	// ---- Field shape -------------------------------------------------------

	public function test_the_field_is_an_experimental_table_defaulting_to_empty(): void {
		$field = $this->field();

		$this->assertSame( GTM4WP_OPTION_GDM_DESTINATIONS, $field->key );
		$this->assertSame( Field::TYPE_TABLE, $field->type );
		$this->assertSame( array(), $field->default_value );
		$this->assertSame( Field::PHASE_EXPERIMENTAL, $field->phase, 'The first data-leaves-the-site feature ships experimental.' );
	}

	/**
	 * The cost rule the class doc block states, pinned: building the schema
	 * must not read the database. get_option() is deliberately re-stubbed to
	 * explode here, so a vault read sneaking back into fields() (or the
	 * column choices) fails this test instead of taxing every REST request.
	 */
	public function test_building_the_fields_reads_no_options(): void {
		Functions\when( 'get_option' )->alias(
			static function (): void {
				throw new \LogicException( 'fields() must not read the database.' );
			}
		);

		$field = ( new AdminSchema() )->fields()[0];

		$account_column = null;
		foreach ( $field->columns as $column ) {
			if ( DestinationRows::COLUMN_ACCOUNT === $column['key'] ) {
				$account_column = $column;
			}
		}

		$this->assertSame( array(), $account_column['choices'], 'The service-account choices arrive through panel_data(), not fields().' );
	}

	public function test_panel_data_carries_the_account_choices_for_the_select_column(): void {
		$data = ( new AdminSchema() )->panel_data();

		$this->assertSame(
			array( self::ACCOUNT_ID => 'Production SA' ),
			$data['columnChoices'][ GTM4WP_OPTION_GDM_DESTINATIONS ][ DestinationRows::COLUMN_ACCOUNT ]
		);
		$this->assertSame( 'gtm4wp/v2/google/destinations/test', $data['testPath'] );
		$this->assertSame( GTM4WP_OPTION_GDM_DESTINATIONS, $data['optionKey'] );
		$this->assertArrayHasKey( 'health', $data );
		$this->assertArrayHasKey( 'threshold', $data );
	}

	// ---- Sanitizer: acceptance --------------------------------------------

	public function test_a_valid_row_is_stored_normalized(): void {
		$stored = $this->field()->sanitize(
			array(
				self::row(
					array(
						DestinationRows::COLUMN_MEASUREMENT => ' g-abc123 ',
						DestinationRows::COLUMN_PROPERTY => ' 123456789 ',
					)
				),
			)
		);

		$this->assertSame( array( self::row() ), $stored );
	}

	public function test_all_empty_rows_are_dropped_silently(): void {
		$this->assertSame(
			array( self::row() ),
			$this->field()->sanitize(
				array(
					array(),
					self::row(),
					array(
						DestinationRows::COLUMN_LABEL   => '',
						DestinationRows::COLUMN_ACCOUNT => '',
					),
				)
			)
		);
	}

	public function test_a_non_array_value_stores_the_empty_table(): void {
		$this->assertSame( array(), $this->field()->sanitize( 'broken' ) );
		$this->assertSame( array(), $this->field()->sanitize( null ) );
	}

	public function test_a_hostile_label_is_stripped_and_capped(): void {
		$stored = $this->field()->sanitize(
			array( self::row( array( DestinationRows::COLUMN_LABEL => '<script>x</script>' . str_repeat( 'a', 200 ) ) ) )
		);

		$this->assertIsArray( $stored );
		$label = $stored[0][ DestinationRows::COLUMN_LABEL ];
		$this->assertStringNotContainsString( '<script>', $label );
		$this->assertSame( DestinationRows::LABEL_MAX_LENGTH, mb_strlen( $label ) );
	}

	// ---- Sanitizer: rejection ---------------------------------------------

	/**
	 * Each invalid aspect is refused with its own error code so the settings
	 * screen can say what is wrong with which row.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function rejected_rows(): array {
		return array(
			'unknown type'          => array( array( DestinationRows::COLUMN_TYPE => 'google-ads' ), 'gtm4wp_gdm_invalid_type' ),
			'missing account'       => array( array( DestinationRows::COLUMN_ACCOUNT => '' ), 'gtm4wp_gdm_missing_account' ),
			'malformed account'     => array( array( DestinationRows::COLUMN_ACCOUNT => '../../etc/passwd' ), 'gtm4wp_gdm_missing_account' ),
			'account not in vault'  => array( array( DestinationRows::COLUMN_ACCOUNT => 'sa_ffffffffffff' ), 'gtm4wp_gdm_unknown_account' ),
			'non-numeric property'  => array( array( DestinationRows::COLUMN_PROPERTY => 'UA-1234-5' ), 'gtm4wp_gdm_invalid_property' ),
			'hostile property'      => array( array( DestinationRows::COLUMN_PROPERTY => '123"; alert(1)' ), 'gtm4wp_gdm_invalid_property' ),
			'malformed measurement' => array( array( DestinationRows::COLUMN_MEASUREMENT => 'GTM-ABC123' ), 'gtm4wp_gdm_invalid_measurement' ),
			'hostile measurement'   => array( array( DestinationRows::COLUMN_MEASUREMENT => 'G-"</script>' ), 'gtm4wp_gdm_invalid_measurement' ),
		);
	}

	/**
	 * One rejected aspect at a time.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @param string               $code      Expected error code.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'rejected_rows' )]
	public function test_an_invalid_row_is_rejected_with_its_own_error( array $overrides, string $code ): void {
		$result = $this->field()->sanitize( array( self::row( $overrides ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_a_duplicate_measurement_id_is_rejected(): void {
		$result = $this->field()->sanitize(
			array(
				self::row(),
				self::row(
					array(
						DestinationRows::COLUMN_LABEL    => 'Second',
						DestinationRows::COLUMN_PROPERTY => '987654321',
					)
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_gdm_duplicate_measurement', $result->get_error_code() );
	}

	/**
	 * The duplicate check runs on the NORMALIZED id, so the same stream typed
	 * once lowercase and once uppercase cannot slip in as two rows.
	 */
	public function test_a_duplicate_differing_only_in_case_is_still_rejected(): void {
		$result = $this->field()->sanitize(
			array(
				self::row(),
				self::row( array( DestinationRows::COLUMN_MEASUREMENT => 'g-abc123' ) ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_gdm_duplicate_measurement', $result->get_error_code() );
	}

	/**
	 * The settings-import route reaches sanitize() with raw decoded JSON, so
	 * nested arrays can sit where scalars belong (the ModuleConsistencyTest
	 * sweep covers the warning; this pins the outcome).
	 */
	public function test_nested_arrays_in_cells_are_handled_without_warning(): void {
		$result = $this->field()->sanitize(
			array(
				array(
					DestinationRows::COLUMN_LABEL   => array( 'nested' ),
					DestinationRows::COLUMN_ACCOUNT => array( array( 'deeper' ) ),
				),
			)
		);

		$this->assertSame( array(), $result, 'A row of nothing but non-scalars normalizes to all-empty and is dropped.' );
	}
}
