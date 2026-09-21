<?php
/**
 * Unit tests for the Container module's status report.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\Container\ContainerRows;
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
}
