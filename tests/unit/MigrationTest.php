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

	public function test_handles_missing_options_gracefully(): void {
		Migration::maybe_run();

		$this->assertSame( GTM4WP_VERSION, $this->option_store['gtm4wp-plugin-version'] );
		$this->assertArrayNotHasKey( GTM4WP_OPTIONS, $this->option_store );
	}
}
