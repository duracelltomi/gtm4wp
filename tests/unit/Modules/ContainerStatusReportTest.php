<?php
/**
 * Unit tests for the Container module's status report.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\Container\HardcodedContainers;
use GTM4WP\Modules\Container\StatusReport;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Abilities\AbilitiesTestCase;

/**
 * The Container module's slice of gtm4wp/get-status, pinned field by field
 * where the module builds it. The ability test only checks that the slice
 * arrives; what is in it, and what is kept out of it, is decided here.
 *
 * Extends the abilities fixture for its in-memory option row and the stubs
 * the Options service reaches; the class under test is the module's.
 */
final class ContainerStatusReportTest extends AbilitiesTestCase {

	/**
	 * A report over the stored settings.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return array<string, mixed>
	 */
	private function report( array $stored ): array {
		$this->store_settings( $stored );

		return ( new StatusReport( new Options( $this->registry()->defaults() ) ) )->report();
	}

	public function test_the_containers_are_reported_as_loaded_without_the_environment_tokens(): void {
		$report = $this->report(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID      => 'GTM-ABC123',
						ContainerRows::COLUMN_AUTH    => 'auth-token',
						ContainerRows::COLUMN_PREVIEW => 'env-3',
						ContainerRows::COLUMN_DOMAIN  => 'gtm.example.com',
						ContainerRows::COLUMN_PATH    => 'loader.js',
						ContainerRows::COLUMN_NO_ID   => '1',
					),
					array( ContainerRows::COLUMN_ID => 'GTM-DEF456' ),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-ABC123',
					'environment' => true,
					'domain'      => 'gtm.example.com',
					'path'        => 'loader.js',
					'omit_id'     => true,
				),
				array(
					'id'          => 'GTM-DEF456',
					'environment' => false,
					'domain'      => '',
					'path'        => '',
					'omit_id'     => false,
				),
			),
			$report['containers']
		);

		$text = (string) json_encode( $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for substring assertions.
		$this->assertStringNotContainsString( 'auth-token', $text, 'The environment parameters travel to the browser in the loader URL, but an assistant only needs to know they are set.' );
		$this->assertStringNotContainsString( 'env-3', $text );
	}

	/**
	 * The per-row booleans between the two extremes (T102): an environment
	 * needs BOTH tokens - a row with one is exactly what the configuration
	 * checks flag as incomplete, so the two readers must agree - and the
	 * legacy '0' no_id flag means off.
	 */
	public function test_a_row_with_one_environment_token_or_a_zero_no_id_flag_reports_neither(): void {
		$report = $this->report(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID   => 'GTM-AUTH01',
						ContainerRows::COLUMN_AUTH => 'auth-only',
					),
					array(
						ContainerRows::COLUMN_ID      => 'GTM-PREV01',
						ContainerRows::COLUMN_PREVIEW => 'env-only',
					),
					array(
						ContainerRows::COLUMN_ID    => 'GTM-ZERO01',
						ContainerRows::COLUMN_NO_ID => '0',
					),
				),
			)
		);

		$rows = array_column( $report['containers'], null, 'id' );

		$this->assertFalse( $rows['GTM-AUTH01']['environment'], 'gtm_auth alone is not an environment.' );
		$this->assertFalse( $rows['GTM-PREV01']['environment'], 'gtm_preview alone is not an environment.' );
		$this->assertFalse( $rows['GTM-ZERO01']['omit_id'], "A stored '0' is the 1.x spelling of off." );

		$text = (string) json_encode( $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for substring assertions.
		$this->assertStringNotContainsString( 'auth-only', $text );
		$this->assertStringNotContainsString( 'env-only', $text );
	}

	public function test_an_empty_container_table_reports_no_containers(): void {
		$report = $this->report( array( GTM4WP_OPTION_GTM_CONTAINERS => array() ) );

		$this->assertSame( array(), $report['containers'] );
		$this->assertTrue( $report['container_code_output'], 'Placement, not the table, decides whether container code is emitted.' );
	}

	public function test_a_placement_value_outside_the_known_ones_falls_back_to_the_footer(): void {
		$report = $this->report(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT  => 99,
			)
		);

		$this->assertSame( StatusReport::PLACEMENT_FOOTER, $report['placement'], 'The default leg of placement_name(): what ContainerCode does with an unknown value.' );
		$this->assertTrue( $report['container_code_output'] );
	}

	/**
	 * A constant defined with a malformed value is ignored by the plugin and
	 * named in `errors`, the way the admin notice names it; the ignored
	 * constant locks nothing.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_a_malformed_constant_is_reported_as_an_error_and_locks_nothing(): void {
		define( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', "env-42\n" );

		$report = $this->report( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$this->assertSame(
			array(
				'active'         => false,
				'locked_columns' => array(),
				'locked_rows'    => false,
				'errors'         => array( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ),
			),
			$report['hardcoded']
		);
		$this->assertFalse( $report['containers'][0]['environment'], 'The malformed value was not applied.' );
	}

	public function test_the_report_keeps_the_ability_key_order(): void {
		$report = $this->report( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$this->assertSame( array( 'containers', 'placement', 'container_code_output', 'datalayer_name', 'hardcoded' ), array_keys( $report ) );
	}

	/**
	 * One placement, one word.
	 *
	 * @param int    $stored   The stored placement.
	 * @param string $expected The word.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_placements' )]
	public function test_every_placement_has_a_word( int $stored, string $expected ): void {
		$report = $this->report(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT  => $stored,
			)
		);

		$this->assertSame( $expected, $report['placement'] );
		$this->assertSame( StatusReport::PLACEMENT_OFF !== $expected, $report['container_code_output'], 'Placement off is the data-layer-only setup: no container code is emitted.' );
		$this->assertContains( $expected, StatusReport::PLACEMENTS, 'Every word the report can answer is in the enum the output schema declares.' );
	}

	/**
	 * Every placement value with its word.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function provide_placements(): array {
		return array(
			'footer'         => array( GTM4WP_PLACEMENT_FOOTER, 'footer' ),
			'body open'      => array( GTM4WP_PLACEMENT_BODYOPEN, 'body_open_manual' ),
			'body open auto' => array( GTM4WP_PLACEMENT_BODYOPEN_AUTO, 'body_open_auto' ),
			'off'            => array( GTM4WP_PLACEMENT_OFF, 'off' ),
		);
	}

	public function test_the_enum_has_no_word_the_report_cannot_answer(): void {
		$this->assertCount( count( self::provide_placements() ), StatusReport::PLACEMENTS );
	}

	public function test_an_unusable_data_layer_name_is_reported_with_the_fallback(): void {
		$report = $this->report(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_DATALAYER_NAME => 'my-layer',
			)
		);

		$this->assertSame(
			array(
				'configured' => 'my-layer',
				'effective'  => 'dataLayer',
				'valid'      => false,
			),
			$report['datalayer_name']
		);
	}

	public function test_a_usable_data_layer_name_is_valid_and_empty_means_the_default(): void {
		$report = $this->report( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$this->assertSame(
			array(
				'configured' => '',
				'effective'  => 'dataLayer',
				'valid'      => true,
			),
			$report['datalayer_name']
		);

		$report = $this->report(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_DATALAYER_NAME => 'myLayer',
			)
		);

		$this->assertSame( 'myLayer', $report['datalayer_name']['effective'] );
		$this->assertTrue( $report['datalayer_name']['valid'] );
	}

	public function test_no_hardcoded_override_is_reported_when_no_constant_is_defined(): void {
		$report = $this->report( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$this->assertSame(
			array(
				'active'         => false,
				'locked_columns' => array(),
				'locked_rows'    => false,
				'errors'         => array(),
			),
			$report['hardcoded']
		);
	}

	/**
	 * The report's `active` is HardcodedContainers' own predicate, not a
	 * second copy of it (PA-2, #254): under a defined constant the two agree
	 * and the lock columns are the ones locks() names. Green by design before
	 * and after the shared predicate landed - it pins that the two cannot
	 * drift apart, not a defect.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_a_hardcoded_container_id_is_reported_as_active_by_the_shared_predicate(): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', 'GTM-BBB222' );

		$report = $this->report( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );
		$locks  = HardcodedContainers::locks();

		$this->assertTrue( HardcodedContainers::is_active() );
		$this->assertSame( HardcodedContainers::is_active(), $report['hardcoded']['active'] );
		$this->assertSame( HardcodedContainers::locks_any( $locks ), $report['hardcoded']['active'] );
		$this->assertSame( array_keys( $locks['columns'] ), $report['hardcoded']['locked_columns'] );
		$this->assertTrue( $report['hardcoded']['locked_rows'] );
		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-BBB222',
					'environment' => false,
					'domain'      => '',
					'path'        => '',
					'omit_id'     => false,
				),
			),
			$report['containers'],
			'The reported container is the one the constant loads.'
		);
	}
}
