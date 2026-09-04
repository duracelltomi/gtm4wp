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
use GTM4WP\Modules\GoogleDataManager\ConsentPolicy;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
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
		return $this->field_by_key( GTM4WP_OPTION_GDM_DESTINATIONS );
	}

	/**
	 * One Field of the schema, by option key.
	 *
	 * @param string $key Option key.
	 * @return Field
	 */
	private function field_by_key( string $key ): Field {
		foreach ( ( new AdminSchema() )->fields() as $field ) {
			if ( $key === $field->key ) {
				return $field;
			}
		}

		$this->fail( 'The schema has no field for option ' . $key . '.' );
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
	 * Capture is off by default and, per the capture design, needs a
	 * destination to read a measurement ID from - so the checkbox declares the
	 * dependency to the admin UI. That declaration only greys the control; the
	 * module guards the value itself at hook-registration time.
	 */
	public function test_the_capture_field_is_an_experimental_checkbox_depending_on_the_destinations(): void {
		$field = $this->field_by_key( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION );

		$this->assertSame( Field::TYPE_CHECKBOX, $field->type );
		$this->assertFalse( $field->default_value, 'Capture starts off: it is part of the first data-leaves-the-site feature.' );
		$this->assertSame( Field::PHASE_EXPERIMENTAL, $field->phase );
		$this->assertSame( GTM4WP_OPTION_GDM_DESTINATIONS, $field->depends_on );
	}

	/**
	 * The consent policy is the field description's promise in code: three
	 * choices, defaulting to the region gate.
	 */
	public function test_the_consent_policy_field_offers_exactly_the_three_policies(): void {
		$field = $this->field_by_key( GTM4WP_OPTION_GDM_CONSENT_POLICY );

		$this->assertSame( Field::TYPE_SELECT, $field->type );
		$this->assertSame( ConsentPolicy::POLICY_EEA_ONLY, $field->default_value );
		$this->assertSame( Field::PHASE_EXPERIMENTAL, $field->phase );
		$this->assertSame( ConsentPolicy::policies(), array_keys( $field->choices ) );
	}

	/**
	 * A value outside the choice list falls back to the default rather than
	 * being stored: an unknown policy string reaching the send gate would be
	 * read as the eea-only default anyway, and storing it would leave the
	 * settings screen showing something the gate does not honour.
	 */
	public function test_the_consent_policy_refuses_a_value_outside_its_choices(): void {
		$field = $this->field_by_key( GTM4WP_OPTION_GDM_CONSENT_POLICY );

		$this->assertSame( ConsentPolicy::POLICY_ALWAYS, $field->sanitize( ConsentPolicy::POLICY_ALWAYS ) );
		$this->assertSame( ConsentPolicy::POLICY_NEVER, $field->sanitize( ConsentPolicy::POLICY_NEVER ) );
		$this->assertSame( ConsentPolicy::POLICY_EEA_ONLY, $field->sanitize( 'send-everything' ) );
		$this->assertSame( ConsentPolicy::POLICY_EEA_ONLY, $field->sanitize( array( 'never' ) ) );
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

	/**
	 * The inline cell validation of the two ID columns is the save-time rule:
	 * each column's `pattern` is '^<body>$' built from the same DestinationRows
	 * constant the sanitizer's pattern is built from, so the table marks while
	 * typing exactly what a save would refuse - one definition, not a JS copy
	 * that can drift.
	 */
	public function test_the_id_columns_carry_the_sanitizers_rule_as_their_inline_pattern(): void {
		$columns = array();
		foreach ( ( new AdminSchema() )->fields()[0]->columns as $column ) {
			$columns[ $column['key'] ] = $column;
		}

		$this->assertSame( '^' . DestinationRows::PROPERTY_PATTERN_BODY . '$', $columns[ DestinationRows::COLUMN_PROPERTY ]['pattern'] );
		$this->assertSame( '^' . DestinationRows::MEASUREMENT_PATTERN_BODY . '$', $columns[ DestinationRows::COLUMN_MEASUREMENT ]['pattern'] );

		// And the message an invalid cell shows is written, not the generic
		// client-side fallback.
		$this->assertNotSame( '', $columns[ DestinationRows::COLUMN_PROPERTY ]['invalid_message'] );
		$this->assertNotSame( '', $columns[ DestinationRows::COLUMN_MEASUREMENT ]['invalid_message'] );
	}

	/**
	 * The literal, not the constant, on purpose: the React panel registry in
	 * js/admin/components/panels/index.js keys on this exact string with its
	 * own literal, so a renamed PANEL constant would leave both suites green
	 * while the settings panel silently disappears (T78, sibling pin at
	 * GoogleAuthCustodyTest::test_the_module_descriptor_names_its_panel_and_boot_data).
	 */
	public function test_the_panel_names_the_react_component_the_registry_keys_on(): void {
		$this->assertSame( 'gdm-destinations', ( new AdminSchema() )->panel() );
		$this->assertSame( 'gdm-destinations', AdminSchema::PANEL );
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

	/**
	 * The health payload is the stored records, cleaned, not a hardcoded
	 * array (T78): a seeded record must come back through panel_data() with
	 * the threshold the React warning compares it against.
	 */
	public function test_panel_data_carries_the_stored_health_records_and_the_threshold(): void {
		$this->stub_option_store(
			array(
				KeyVault::OPTION_NAME          => array(
					self::ACCOUNT_ID => array( 'label' => 'Production SA' ),
				),
				DestinationHealth::OPTION_NAME => array(
					'G-ABC123' => array(
						'last_success'         => 1_800_000_000,
						'last_failure'         => 1_800_000_100,
						'consecutive_failures' => 4,
						'last_error'           => 'PERMISSION_DENIED: no access.',
					),
				),
			)
		);

		$data = ( new AdminSchema() )->panel_data();

		$this->assertSame(
			array(
				'G-ABC123' => array(
					'last_success'         => 1_800_000_000,
					'last_failure'         => 1_800_000_100,
					'consecutive_failures' => 4,
					'last_error'           => 'PERMISSION_DENIED: no access.',
				),
			),
			$data['health']
		);
		$this->assertSame( DestinationHealth::FAILURE_THRESHOLD, $data['threshold'] );
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

	/**
	 * The table UI seeds the type select's default into a fresh row, so the
	 * untouched "Add row" state is NOT all-empty: it carries type=ga4 with
	 * every user-filled cell blank. It must be dropped silently all the same
	 * (RI-28) - the emptiness test judges the cells the user fills, not the
	 * seeded default.
	 */
	public function test_an_untouched_seeded_row_is_dropped_silently(): void {
		$this->assertSame(
			array( self::row() ),
			$this->field()->sanitize(
				array(
					array(
						DestinationRows::COLUMN_LABEL    => '',
						DestinationRows::COLUMN_ACCOUNT  => '',
						DestinationRows::COLUMN_TYPE     => DestinationRows::TYPE_GA4,
						DestinationRows::COLUMN_PROPERTY => '',
						DestinationRows::COLUMN_MEASUREMENT => '',
					),
					self::row(),
				)
			)
		);
	}

	/**
	 * Error messages number rows as the screen shows them: a dropped (empty
	 * or untouched) row above still counts, so "row 2" in the error is row 2
	 * in the table.
	 */
	public function test_error_messages_number_rows_as_the_screen_shows_them(): void {
		$result = $this->field()->sanitize(
			array(
				array(),
				self::row(
					array(
						DestinationRows::COLUMN_LABEL    => '',
						DestinationRows::COLUMN_PROPERTY => 'not-numeric',
					)
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'destination row 2', $result->get_error_message() );
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
