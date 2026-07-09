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

	public function test_first_container_only_when_env_parameters_set(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CODE        => 'GTM-FIRST,GTM-SECOND',
				GTM4WP_OPTION_ENV_GTM_AUTH    => 'authtoken',
				GTM4WP_OPTION_ENV_GTM_PREVIEW => 'env-1',
			)
		);

		$options = new Options( self::DEFAULTS );

		$this->assertSame( 'GTM-FIRST', $options->get( GTM4WP_OPTION_GTM_CODE ) );
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
		define( 'GTM4WP_HARDCODED_GTM_ID', 'GTM-HARD1' );

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
		define( 'GTM4WP_HARDCODED_GTM_ID', 'not-a-gtm-id' );

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

	public function test_get_returns_fallback_for_unknown_key(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options( self::DEFAULTS );

		$this->assertNull( $options->get( 'unknown-key' ) );
		$this->assertSame( 'x', $options->get( 'unknown-key', 'x' ) );
	}
}
