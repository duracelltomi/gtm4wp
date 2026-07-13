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

	public function test_name_falls_back_when_configured_value_is_not_a_string(): void {
		// A corrupted/non-string stored value must not reach the script output;
		// name() falls back to the safe default.
		$datalayer = new DataLayer(
			$this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => 12345 ) )
		);

		$this->assertSame( 'dataLayer', $datalayer->name() );
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

	public function test_compiled_is_empty_before_compile_and_cached_after(): void {
		// compiled() is the read-only accessor the AMP module uses; it must not
		// re-run the compile filter (asserted via ->once()).
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->once()
			->andReturn( array( 'pagePostType' => 'post' ) );

		$datalayer = new DataLayer( $this->make_options() );

		$this->assertSame( array(), $datalayer->compiled(), 'compiled() is empty before compile().' );

		$datalayer->compile();

		$this->assertSame( array( 'pagePostType' => 'post' ), $datalayer->compiled() );
		$this->assertSame( array( 'pagePostType' => 'post' ), $datalayer->compiled(), 'Repeated reads must not re-run the compile filter.' );
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

	public function test_enqueue_push_handle_registers_empty_handle_and_flushes_queue(): void {
		Functions\expect( 'wp_register_script' )
			->once()
			->with( 'gtm4wp-additional-datalayer-pushes', '', array(), GTM4WP_VERSION, true );
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'gtm4wp-additional-datalayer-pushes' );

		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'gtm4wp.test', array( 'foo' => 'bar' ) );

		$datalayer->enqueue_push_handle();

		// Registering the handle also flushes everything queued so far.
		$this->assertCount( 1, $captured, 'enqueue_push_handle() must flush the queued pushes.' );
		$this->assertStringContainsString( 'gtm4wp.test', $captured[0] );
		$this->assertSame( array(), $GLOBALS['gtm4wp_additional_datalayer_pushes'], 'The queue is reset after the flush.' );
	}

	public function test_flush_pushes_hex_encodes_script_breakout_characters(): void {
		// The additional-push path prints via wp_add_inline_script (no
		// htmlspecialchars_decode), so JSON_HEX_TAG is the load-bearing guard
		// against a </script> break-out; the amp/quot flags are applied for
		// uniformity (see .security patterns FP-2 / RI-2).
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'gtm4wp.test', array( 'evil' => '</script><svg onload=alert(1)> & "quote"' ) );

		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$code = $captured[0];

		// The exact hex-encoded JSON the source emits, built the same way, so
		// the assertion tracks the real output instead of a hand-typed literal.
		$expected_json = wp_json_encode(
			array(
				'evil'  => '</script><svg onload=alert(1)> & "quote"',
				'event' => 'gtm4wp.test',
			),
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		$this->assertStringContainsString( $expected_json, $code );
		$this->assertStringNotContainsString( '</script>', $code, 'A </script> in an additional push must never survive unencoded.' );
		$this->assertStringNotContainsString( '<svg', $code, 'A raw < must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( '"quote"', $code, 'A raw double quote must be hex-encoded (JSON_HEX_QUOT).' );
	}
}
