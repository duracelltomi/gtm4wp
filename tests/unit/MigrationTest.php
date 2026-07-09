<?php
/**
 * Unit tests for the version-stamped migration.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Functions;
use GTM4WP\Migration;

/**
 * Covers the one-time cleanup of options removed in 2.0.
 */
final class MigrationTest extends TestCase {

	/**
	 * In-memory option storage for the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $option_store = array();

	protected function setUp(): void {
		parent::setUp();

		$this->option_store = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) {
				return $this->option_store[ $key ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				$this->option_store[ $key ] = $value;
				return true;
			}
		);
	}

	public function test_removes_dropped_option_keys_and_stale_blacklist_entries(): void {
		$this->option_store[ GTM4WP_OPTIONS ] = array(
			GTM4WP_OPTION_GTM_CODE              => 'GTM-KEEP1',
			GTM4WP_OPTION_INCLUDE_MISCGEO       => true,
			GTM4WP_OPTION_INCLUDE_MISCGEOAPI    => 'api-key',
			GTM4WP_OPTION_INCLUDE_WEATHER       => true,
			GTM4WP_OPTION_INCLUDE_WEATHERUNITS  => 1,
			GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI => 'owm-key',
			GTM4WP_OPTION_INTEGRATE_WPECOMMERCE => true,
			GTM4WP_OPTION_BLACKLIST_SANDBOXED   => true,
			GTM4WP_OPTION_BLACKLIST_STATUS      => 'html,ua,gaawe,mf',
		);

		Migration::maybe_run();

		$options = $this->option_store[ GTM4WP_OPTIONS ];

		$this->assertSame( 'GTM-KEEP1', $options[ GTM4WP_OPTION_GTM_CODE ], 'Kept options stay untouched.' );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_INCLUDE_MISCGEO, $options );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_INCLUDE_WEATHER, $options );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_INTEGRATE_WPECOMMERCE, $options );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_BLACKLIST_SANDBOXED, $options );
		$this->assertSame( 'html,gaawe', $options[ GTM4WP_OPTION_BLACKLIST_STATUS ], 'Stale ua/mf entries are stripped.' );

		$this->assertSame( GTM4WP_VERSION, $this->option_store['gtm4wp-plugin-version'] );
	}

	public function test_seeds_container_rows_from_legacy_options(): void {
		$this->option_store[ GTM4WP_OPTIONS ] = array(
			GTM4WP_OPTION_GTM_CODE      => 'GTM-AAA111,GTM-BBB222',
			GTM4WP_OPTION_GTMDOMAIN     => 'gtm.example.com',
			GTM4WP_OPTION_GTMCUSTOMPATH => 'custom/loader.js',
		);

		Migration::maybe_run();

		$options = $this->option_store[ GTM4WP_OPTIONS ];

		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-AAA111',
					'gtm_auth'    => '',
					'gtm_preview' => '',
					'domain'      => 'gtm.example.com',
					'path'        => 'custom/loader.js',
				),
				array(
					'id'          => 'GTM-BBB222',
					'gtm_auth'    => '',
					'gtm_preview' => '',
					'domain'      => 'gtm.example.com',
					'path'        => 'custom/loader.js',
				),
			),
			$options[ GTM4WP_OPTION_GTM_CONTAINERS ]
		);

		$this->assertSame( 'GTM-AAA111,GTM-BBB222', $options[ GTM4WP_OPTION_GTM_CODE ], 'Flat 1.x keys stay untouched for downgrades.' );
		$this->assertSame( 'gtm.example.com', $options[ GTM4WP_OPTION_GTMDOMAIN ] );
	}

	public function test_seeding_applies_environment_to_every_container(): void {
		$this->option_store[ GTM4WP_OPTIONS ] = array(
			GTM4WP_OPTION_GTM_CODE        => 'GTM-AAA111,GTM-BBB222',
			GTM4WP_OPTION_ENV_GTM_AUTH    => 'authtoken',
			GTM4WP_OPTION_ENV_GTM_PREVIEW => 'env-2',
		);

		Migration::maybe_run();

		$rows = $this->option_store[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_GTM_CONTAINERS ];

		$this->assertCount( 2, $rows, 'All containers are migrated even with environment parameters set.' );
		$this->assertSame( 'authtoken', $rows[0]['gtm_auth'] );
		$this->assertSame( 'authtoken', $rows[1]['gtm_auth'] );
		$this->assertSame( 'env-2', $rows[1]['gtm_preview'] );
	}

	public function test_does_not_overwrite_existing_container_rows(): void {
		$existing_rows = array(
			array(
				'id'          => 'GTM-KEEP1',
				'gtm_auth'    => '',
				'gtm_preview' => '',
				'domain'      => '',
				'path'        => '',
			),
		);

		$this->option_store[ GTM4WP_OPTIONS ] = array(
			GTM4WP_OPTION_GTM_CODE       => 'GTM-STALE1',
			GTM4WP_OPTION_GTM_CONTAINERS => $existing_rows,
		);

		Migration::maybe_run();

		$this->assertSame(
			$existing_rows,
			$this->option_store[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_GTM_CONTAINERS ],
			'An already seeded row option must never be rebuilt.'
		);
	}

	public function test_runs_only_once_per_version(): void {
		$this->option_store['gtm4wp-plugin-version'] = GTM4WP_VERSION;
		$this->option_store[ GTM4WP_OPTIONS ]        = array(
			GTM4WP_OPTION_INCLUDE_WEATHER => true,
		);

		Migration::maybe_run();

		$this->assertArrayHasKey(
			GTM4WP_OPTION_INCLUDE_WEATHER,
			$this->option_store[ GTM4WP_OPTIONS ],
			'No cleanup runs when the version stamp matches.'
		);
	}

	public function test_seeds_container_rows_even_when_version_stamp_matches(): void {
		// Two builds sharing the same version string (e.g. 2.0.0-dev
		// snapshots) must not skip the container row seeding.
		$this->option_store['gtm4wp-plugin-version'] = GTM4WP_VERSION;
		$this->option_store[ GTM4WP_OPTIONS ]        = array(
			GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111',
		);

		Migration::maybe_run();

		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-AAA111',
					'gtm_auth'    => '',
					'gtm_preview' => '',
					'domain'      => '',
					'path'        => '',
				),
			),
			$this->option_store[ GTM4WP_OPTIONS ][ GTM4WP_OPTION_GTM_CONTAINERS ]
		);
	}

	public function test_handles_missing_options_gracefully(): void {
		Migration::maybe_run();

		$this->assertSame( GTM4WP_VERSION, $this->option_store['gtm4wp-plugin-version'] );
		$this->assertArrayNotHasKey( GTM4WP_OPTIONS, $this->option_store );
	}
}
