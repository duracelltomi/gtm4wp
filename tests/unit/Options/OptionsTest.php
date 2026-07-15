<?php
/**
 * Unit tests for the Options service.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Options;

use Brain\Monkey\Functions;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Ports the behavioral contract of gtm4wp_reload_options() from 1.x.
 */
final class OptionsTest extends TestCase {

	/**
	 * Default option values used across the tests.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		GTM4WP_OPTION_GTM_CODE         => '',
		GTM4WP_OPTION_DATALAYER_NAME   => '',
		GTM4WP_OPTION_ENV_GTM_AUTH     => '',
		GTM4WP_OPTION_ENV_GTM_PREVIEW  => '',
		GTM4WP_OPTION_BLACKLIST_STATUS => '',
		GTM4WP_OPTION_INCLUDE_LOGGEDIN => false,
	);

	public function test_stored_options_override_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-ABC123',
				GTM4WP_OPTION_INCLUDE_LOGGEDIN => true,
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'GTM-ABC123', $options->get( GTM4WP_OPTION_GTM_CODE ) );
		$this->assertTrue( $options->get( GTM4WP_OPTION_INCLUDE_LOGGEDIN ) );
		$this->assertSame( '', $options->get( GTM4WP_OPTION_DATALAYER_NAME ), 'Untouched options keep their default.' );
	}

	public function test_unknown_stored_keys_are_preserved(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'some-third-party-key' => 'kept',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'kept', $options->get( 'some-third-party-key' ) );
	}

	public function test_non_array_stored_value_falls_back_to_defaults(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$options = new Options( self::DEFAULTS );

		$this->assertSame( '', $options->get( GTM4WP_OPTION_GTM_CODE ) );
	}

	public function test_blacklist_status_is_exploded_to_array(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html,img,awct',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( array( 'html', 'img', 'awct' ), $options->get( GTM4WP_OPTION_BLACKLIST_STATUS ) );
	}

	public function test_empty_blacklist_status_becomes_single_empty_entry(): void {
		// Parity with 1.x: explode( ',', '' ) === array( '' ).
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options( self::DEFAULTS );

		$this->assertSame( array( '' ), $options->get( GTM4WP_OPTION_BLACKLIST_STATUS ) );
	}

	public function test_all_containers_kept_when_env_parameters_set(): void {
		// Changed vs 1.x: environments are per-row now, so a stored
		// environment no longer limits output to the first container.
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CODE        => 'GTM-FIRST,GTM-SECOND',
				GTM4WP_OPTION_ENV_GTM_AUTH    => 'authtoken',
				GTM4WP_OPTION_ENV_GTM_PREVIEW => 'env-1',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'GTM-FIRST,GTM-SECOND', $options->get( GTM4WP_OPTION_GTM_CODE ) );

		$rows = $options->get( GTM4WP_OPTION_GTM_CONTAINERS );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'authtoken', $rows[1]['gtm_auth'], 'Every container inherits the previously shared environment.' );
	}

	public function test_all_containers_kept_without_env_parameters(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CODE => 'GTM-FIRST,GTM-SECOND',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'GTM-FIRST,GTM-SECOND', $options->get( GTM4WP_OPTION_GTM_CODE ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_valid_hardcoded_gtm_id_overrides_stored_value(): void {
		$this->define_hardcoded_gtm_id( 'GTM-HARD1' );

		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CODE => 'GTM-STORED',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'GTM-HARD1', $options->get( GTM4WP_OPTION_GTM_CODE ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_invalid_hardcoded_gtm_id_is_ignored(): void {
		$this->define_hardcoded_gtm_id( 'not-a-gtm-id' );

		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CODE => 'GTM-STORED',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'GTM-STORED', $options->get( GTM4WP_OPTION_GTM_CODE ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_hardcoded_env_parameters_override_stored_values(): void {
		define( 'GTM4WP_HARDCODED_GTM_ENV_AUTH', 'hard-auth' );
		define( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', 'hard-preview' );

		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_ENV_GTM_AUTH    => 'stored-auth',
				GTM4WP_OPTION_ENV_GTM_PREVIEW => 'stored-preview',
				GTM4WP_OPTION_GTM_CODE        => 'GTM-A,GTM-B',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'hard-auth', $options->get( GTM4WP_OPTION_ENV_GTM_AUTH ) );
		$this->assertSame( 'hard-preview', $options->get( GTM4WP_OPTION_ENV_GTM_PREVIEW ) );
		$this->assertSame( 'GTM-A', $options->get( GTM4WP_OPTION_GTM_CODE ), 'Env parameters limit output to the first container.' );
	}

	public function test_stored_container_rows_expose_legacy_mirrors(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-AAA111',
						'gtm_auth'    => 'authtoken',
						'gtm_preview' => 'env-2',
						'domain'      => 'gtm.example.com',
						'path'        => 'custom/loader.js',
					),
					array(
						'id' => 'GTM-BBB222',
					),
				),
			)
		);

		$options = new Options( self::DEFAULTS );

		$rows = $options->get( GTM4WP_OPTION_GTM_CONTAINERS );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'GTM-BBB222', $rows[1]['id'] );
		$this->assertSame( '', $rows[1]['gtm_auth'], 'Missing row keys are normalized to empty strings.' );

		$this->assertSame( 'GTM-AAA111,GTM-BBB222', $options->get( GTM4WP_OPTION_GTM_CODE ), 'gtm-code mirror lists every container ID.' );
		$this->assertSame( 'authtoken', $options->get( GTM4WP_OPTION_ENV_GTM_AUTH ), 'Environment mirror comes from the first row.' );
		$this->assertSame( 'gtm.example.com', $options->get( GTM4WP_OPTION_GTMDOMAIN ) );
		$this->assertSame( 'custom/loader.js', $options->get( GTM4WP_OPTION_GTMCUSTOMPATH ) );
	}

	public function test_saved_empty_container_rows_do_not_resurrect_legacy_options(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(),
				GTM4WP_OPTION_GTM_CODE       => 'GTM-STALE1',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( array(), $options->get( GTM4WP_OPTION_GTM_CONTAINERS ), 'An intentionally emptied table must stay empty.' );
		$this->assertSame( '', $options->get( GTM4WP_OPTION_GTM_CODE ), 'Stale legacy values must not resurrect containers.' );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_hardcoded_gtm_id_keeps_settings_of_matching_row(): void {
		$this->define_hardcoded_gtm_id( 'GTM-BBB222' );

		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'     => 'GTM-AAA111',
						'domain' => 'first.example.com',
					),
					array(
						'id'     => 'GTM-BBB222',
						'domain' => 'second.example.com',
					),
				),
			)
		);

		$options = new Options( self::DEFAULTS );

		$rows = $options->get( GTM4WP_OPTION_GTM_CONTAINERS );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'GTM-BBB222', $rows[0]['id'] );
		$this->assertSame( 'second.example.com', $rows[0]['domain'], 'The hard coded ID keeps the settings of its own row.' );
	}

	public function test_get_returns_fallback_for_unknown_key(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options( self::DEFAULTS );

		$this->assertNull( $options->get( 'unknown-key' ) );
		$this->assertSame( 'x', $options->get( 'unknown-key', 'x' ) );
	}

	public function test_fresh_install_uses_container_placement_default(): void {
		// Regression for #302: a brand-new install has no gtm4wp-options row,
		// so get_option() returns the empty-array default. Options must merge
		// module defaults over it, so the container placement comes back at its
		// default (container not silently off) instead of a missing key.
		Functions\when( 'get_option' )->justReturn( array() );

		$defaults = self::DEFAULTS + array(
			GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_FOOTER,
		);

		$options = new Options( $defaults );

		$this->assertSame(
			GTM4WP_PLACEMENT_FOOTER,
			$options->get( GTM4WP_OPTION_GTM_PLACEMENT ),
			'A missing options row must fall through to the placement default.'
		);
	}

	public function test_stored_off_placement_overrides_default(): void {
		// The override direction of #302: once a site explicitly turns the
		// container off, the stored placement must win over the default so the
		// merge never silently flips it back on.
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_OFF,
			)
		);

		$defaults = self::DEFAULTS + array(
			GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_FOOTER,
		);

		$options = new Options( $defaults );

		$this->assertSame(
			GTM4WP_PLACEMENT_OFF,
			$options->get( GTM4WP_OPTION_GTM_PLACEMENT ),
			'An explicit "Off" placement must be honored over the default.'
		);
	}

	/**
	 * Defines the GTM4WP_HARDCODED_GTM_ID wp-config override for the current test.
	 *
	 * Routed through a single helper so the constant is declared in exactly one
	 * place; each caller runs in its own process (RunInSeparateProcess), so the
	 * define happens at most once per process at runtime.
	 *
	 * @param string $value The container ID to hard code.
	 */
	private function define_hardcoded_gtm_id( string $value ): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', $value );
	}
}
