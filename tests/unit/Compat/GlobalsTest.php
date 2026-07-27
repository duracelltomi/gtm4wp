<?php
/**
 * Unit tests for the backward-compatible global mirror population.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Compat;

use Brain\Monkey\Functions;
use GTM4WP\Compat\Globals;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers Globals::populate() — the read-only 1.x global surface third-party
 * code reads: the options mirror, the datalayer-name fallback, and the
 * "do not clobber an already-set global" branches.
 */
final class GlobalsTest extends TestCase {

	/**
	 * The gtm4wp_* globals this class touches, snapshotted for isolation (TS-7).
	 *
	 * @var array<string, mixed>
	 */
	private array $globals_backup = array();

	protected function setUp(): void {
		parent::setUp();

		foreach ( $this->managed_globals() as $key ) {
			$this->globals_backup[ $key ] = $GLOBALS[ $key ] ?? null;
			unset( $GLOBALS[ $key ] );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->globals_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
		parent::tearDown();
	}

	/**
	 * The gtm4wp_* global keys this test snapshots and restores.
	 *
	 * @return string[]
	 */
	private function managed_globals(): array {
		return array(
			'gtm4wp_options',
			'gtm4wp_datalayer_name',
			'gtm4wp_datalayer_data',
			'gtm4wp_additional_datalayer_pushes',
			'gtm4wp_container_code_written',
		);
	}

	/**
	 * Builds an Options service backed by the given stored values.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 */
	private function make_options( array $stored = array() ): Options {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new Options( array( GTM4WP_OPTION_DATALAYER_NAME => 'dataLayer' ) );
	}

	public function test_populate_mirrors_options_and_defaults_globals(): void {
		Globals::populate( $this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => 'dataLayer' ) ) );

		$this->assertIsArray( $GLOBALS['gtm4wp_options'] );
		$this->assertSame( 'dataLayer', $GLOBALS['gtm4wp_datalayer_name'] );
		$this->assertSame( array(), $GLOBALS['gtm4wp_datalayer_data'] );
		$this->assertSame( array(), $GLOBALS['gtm4wp_additional_datalayer_pushes'] );
		$this->assertFalse( $GLOBALS['gtm4wp_container_code_written'] );
	}

	public function test_custom_datalayer_name_is_used(): void {
		Globals::populate( $this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => 'myCustomLayer' ) ) );

		$this->assertSame( 'myCustomLayer', $GLOBALS['gtm4wp_datalayer_name'] );
	}

	public function test_empty_datalayer_name_falls_back_to_default(): void {
		Globals::populate( $this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => '' ) ) );

		$this->assertSame( 'dataLayer', $GLOBALS['gtm4wp_datalayer_name'] );
	}

	public function test_non_string_datalayer_name_falls_back_to_default(): void {
		Globals::populate( $this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => array( 'unexpected' ) ) ) );

		$this->assertSame( 'dataLayer', $GLOBALS['gtm4wp_datalayer_name'] );
	}

	public function test_populate_does_not_clobber_already_set_globals(): void {
		$GLOBALS['gtm4wp_datalayer_data']              = array( 'preexisting' => 1 );
		$GLOBALS['gtm4wp_additional_datalayer_pushes'] = array( 'queued' );
		$GLOBALS['gtm4wp_container_code_written']      = true;

		Globals::populate( $this->make_options() );

		$this->assertSame( array( 'preexisting' => 1 ), $GLOBALS['gtm4wp_datalayer_data'], 'Existing data layer content must be preserved.' );
		$this->assertSame( array( 'queued' ), $GLOBALS['gtm4wp_additional_datalayer_pushes'] );
		$this->assertTrue( $GLOBALS['gtm4wp_container_code_written'], 'The container-written flag must not be reset.' );
	}
}
