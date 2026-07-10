<?php
/**
 * Unit tests for the container row list helper.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the row normalization, the 1.x legacy conversion in both
 * directions and the wp-config hard coded ID handling.
 */
final class ContainerRowsTest extends TestCase {

	public function test_normalize_drops_invalid_entries_and_fills_missing_columns(): void {
		$rows = ContainerRows::normalize(
			array(
				array(
					'id'       => ' GTM-AAA111 ',
					'gtm_auth' => 'authtoken',
				),
				array( 'gtm_auth' => 'no-id-row' ),
				'not-an-array',
				array( 'id' => array( 'not' => 'scalar' ) ),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-AAA111',
					'gtm_auth'    => 'authtoken',
					'gtm_preview' => '',
					'domain'      => '',
					'path'        => '',
					'no_id'       => '',
				),
			),
			$rows
		);
	}

	public function test_normalize_retains_the_no_id_flag(): void {
		$rows = ContainerRows::normalize(
			array(
				array(
					'id'    => 'GTM-AAA111',
					'path'  => 'custom/loader.js',
					'no_id' => '1',
				),
			)
		);

		$this->assertSame( '1', $rows[0]['no_id'] );
		$this->assertSame( 'custom/loader.js', $rows[0]['path'] );
	}

	public function test_normalize_returns_empty_list_for_non_array_values(): void {
		$this->assertSame( array(), ContainerRows::normalize( 'GTM-AAA111' ) );
		$this->assertSame( array(), ContainerRows::normalize( null ) );
	}

	public function test_from_legacy_gives_every_container_the_shared_settings(): void {
		$rows = ContainerRows::from_legacy(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-AAA111,GTM-BBB222',
				GTM4WP_OPTION_GTMDOMAIN     => 'gtm.example.com',
				GTM4WP_OPTION_GTMCUSTOMPATH => 'custom/loader.js',
			)
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'GTM-AAA111', $rows[0]['id'] );
		$this->assertSame( 'GTM-BBB222', $rows[1]['id'] );
		$this->assertSame( 'gtm.example.com', $rows[0]['domain'] );
		$this->assertSame( 'gtm.example.com', $rows[1]['domain'], 'Every row inherits the previously shared domain.' );
		$this->assertSame( 'custom/loader.js', $rows[1]['path'] );
	}

	public function test_from_legacy_keeps_every_container_when_environment_is_set(): void {
		// 1.x only loaded the first container when both env parameters were
		// configured; with per-row environments every ID is kept and
		// inherits the previously shared values.
		$rows = ContainerRows::from_legacy(
			array(
				GTM4WP_OPTION_GTM_CODE        => 'GTM-AAA111,GTM-BBB222',
				GTM4WP_OPTION_ENV_GTM_AUTH    => 'authtoken',
				GTM4WP_OPTION_ENV_GTM_PREVIEW => 'env-2',
			)
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'GTM-AAA111', $rows[0]['id'] );
		$this->assertSame( 'GTM-BBB222', $rows[1]['id'] );
		$this->assertSame( 'authtoken', $rows[0]['gtm_auth'] );
		$this->assertSame( 'authtoken', $rows[1]['gtm_auth'], 'Every row inherits the previously shared environment.' );
		$this->assertSame( 'env-2', $rows[1]['gtm_preview'] );
	}

	public function test_from_legacy_with_empty_gtm_code_returns_no_rows(): void {
		$this->assertSame( array(), ContainerRows::from_legacy( array( GTM4WP_OPTION_GTM_CODE => '' ) ) );
		$this->assertSame( array(), ContainerRows::from_legacy( array() ) );
	}

	public function test_legacy_values_mirror_ids_and_first_row_settings(): void {
		$mirrors = ContainerRows::legacy_values(
			array(
				array(
					'id'          => 'GTM-AAA111',
					'gtm_auth'    => 'authtoken',
					'gtm_preview' => 'env-2',
					'domain'      => 'gtm.example.com',
					'path'        => 'custom/loader.js',
				),
				array(
					'id'          => 'GTM-BBB222',
					'gtm_auth'    => 'other',
					'gtm_preview' => 'env-3',
					'domain'      => '',
					'path'        => '',
				),
			)
		);

		$this->assertSame( 'GTM-AAA111,GTM-BBB222', $mirrors[ GTM4WP_OPTION_GTM_CODE ] );
		$this->assertSame( 'authtoken', $mirrors[ GTM4WP_OPTION_ENV_GTM_AUTH ] );
		$this->assertSame( 'env-2', $mirrors[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] );
		$this->assertSame( 'gtm.example.com', $mirrors[ GTM4WP_OPTION_GTMDOMAIN ] );
		$this->assertSame( 'custom/loader.js', $mirrors[ GTM4WP_OPTION_GTMCUSTOMPATH ] );
	}

	public function test_legacy_values_of_empty_row_list_are_empty_strings(): void {
		$mirrors = ContainerRows::legacy_values( array() );

		$this->assertSame( '', $mirrors[ GTM4WP_OPTION_GTM_CODE ] );
		$this->assertSame( '', $mirrors[ GTM4WP_OPTION_ENV_GTM_AUTH ] );
	}

	public function test_for_hardcoded_ids_matches_rows_by_id_and_inherits_first_row(): void {
		$stored_rows = array(
			array(
				'id'          => 'GTM-AAA111',
				'gtm_auth'    => 'authtoken',
				'gtm_preview' => 'env-2',
				'domain'      => 'gtm.example.com',
				'path'        => '',
			),
			array(
				'id'          => 'GTM-BBB222',
				'gtm_auth'    => '',
				'gtm_preview' => '',
				'domain'      => 'other.example.com',
				'path'        => '',
			),
		);

		$rows = ContainerRows::for_hardcoded_ids( array( 'GTM-BBB222', 'GTM-NEW333' ), $stored_rows );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'other.example.com', $rows[0]['domain'], 'A matching stored row keeps its own settings.' );
		$this->assertSame( 'GTM-NEW333', $rows[1]['id'] );
		$this->assertSame( 'gtm.example.com', $rows[1]['domain'], 'Unknown IDs inherit the settings of the first stored row.' );
		$this->assertSame( 'authtoken', $rows[1]['gtm_auth'] );
	}

	public function test_for_hardcoded_ids_without_stored_rows_creates_blank_rows(): void {
		$rows = ContainerRows::for_hardcoded_ids( array( 'GTM-NEW333' ), array() );

		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-NEW333',
					'gtm_auth'    => '',
					'gtm_preview' => '',
					'domain'      => '',
					'path'        => '',
					'no_id'       => '',
				),
			),
			$rows
		);
	}
}
