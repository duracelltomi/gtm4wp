<?php
/**
 * Unit tests for the DataLayer service.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;

/**
 * Ports the behavioral contract of the data layer parts of 1.x:
 * data layer naming, compile filter and the additional push queue.
 */
final class DataLayerTest extends FrontendTestCase {

	public function test_name_defaults_to_datalayer(): void {
		$datalayer = new DataLayer( $this->make_options() );

		$this->assertSame( 'dataLayer', $datalayer->name() );
	}

	public function test_name_uses_configured_value(): void {
		$datalayer = new DataLayer(
			$this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => 'myDataLayer' ) )
		);

		$this->assertSame( 'myDataLayer', $datalayer->name() );
	}

	public function test_compile_applies_public_filter_and_mirrors_global(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->once()
			->with( array() )
			->andReturn( array( 'pagePostType' => 'post' ) );

		$datalayer = new DataLayer( $this->make_options() );
		$compiled  = $datalayer->compile();

		$this->assertSame( array( 'pagePostType' => 'post' ), $compiled );
		$this->assertSame( array( 'pagePostType' => 'post' ), $GLOBALS['gtm4wp_datalayer_data'] );
	}

	public function test_queue_push_rejects_invalid_parameters(): void {
		$datalayer = new DataLayer( $this->make_options() );

		$this->assertFalse( $datalayer->queue_push( 123 ) );
		$this->assertFalse( $datalayer->queue_push( 'event', 'not-an-array' ) );
	}

	public function test_queue_push_appends_to_compat_global(): void {
		$datalayer = new DataLayer( $this->make_options() );

		$this->assertTrue( $datalayer->queue_push( 'gtm4wp.test', array( 'foo' => 'bar' ), 'before();', 'after();' ) );

		$this->assertCount( 1, $GLOBALS['gtm4wp_additional_datalayer_pushes'] );
		$this->assertSame(
			array(
				'datalayer_object' => array(
					'foo'   => 'bar',
					'event' => 'gtm4wp.test',
				),
				'js_before'        => 'before();',
				'js_after'         => 'after();',
			),
			$GLOBALS['gtm4wp_additional_datalayer_pushes'][0]
		);
	}

	public function test_flush_pushes_adds_inline_script_and_resets_queue(): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = array( $handle, $code, $position );
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'gtm4wp.test', array( 'value' => 42 ), '// before', '// after' );

		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'gtm4wp-additional-datalayer-pushes', $captured[0][0] );
		$this->assertSame( 'after', $captured[0][2] );
		$this->assertStringContainsString( '// before', $captured[0][1] );
		$this->assertStringContainsString( 'dataLayer.push({"value":42,"event":"gtm4wp.test"});', $captured[0][1] );
		$this->assertStringContainsString( '// after', $captured[0][1] );

		$this->assertSame( array(), $GLOBALS['gtm4wp_additional_datalayer_pushes'] );

		// A second flush must not duplicate output.
		$datalayer->flush_pushes();
		$this->assertCount( 1, $captured );
	}

	public function test_flush_pushes_reads_third_party_entries_from_global(): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		// Third party code appending directly to the compat global.
		$GLOBALS['gtm4wp_additional_datalayer_pushes'][] = array(
			'datalayer_object' => array( 'event' => 'thirdparty.event' ),
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$this->assertStringContainsString( 'thirdparty.event', $captured[0] );
	}

	public function test_enqueue_push_handle_registers_empty_handle(): void {
		Functions\expect( 'wp_register_script' )
			->once()
			->with( 'gtm4wp-additional-datalayer-pushes', '', array(), GTM4WP_VERSION, true );
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'gtm4wp-additional-datalayer-pushes' );

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->enqueue_push_handle();
	}
}
