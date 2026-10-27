<?php
/**
 * Unit tests for reading ability input across the transports.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use Brain\Monkey\Functions;
use GTM4WP\Abilities\Input;
use GTM4WP\Tests\unit\TestCase;

/**
 * Before WordPress 7.1 the GET and DELETE REST transports hand a callback
 * every value as a query string (#502): "false" must read as false and a
 * single name as a one-item list, as 7.1's own coercion reads them.
 */
final class InputTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Core's wp_parse_list() (TS-16): a string splits on commas and whitespace.
		Functions\when( 'wp_parse_list' )->alias(
			static fn ( $input_list ) => is_array( $input_list ) ? $input_list : preg_split( '/[\s,]+/', (string) $input_list, -1, PREG_SPLIT_NO_EMPTY )
		);
	}

	public function test_a_query_string_false_is_false(): void {
		foreach ( array( 'false', 'FALSE', '0', '', false, 0, null ) as $value ) {
			$this->assertFalse( Input::flag( $value ), var_export( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- assertion message.
		}

		foreach ( array( 'true', '1', 'yes', true, 1 ) as $value ) {
			$this->assertTrue( Input::flag( $value ), var_export( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- assertion message.
		}
	}

	public function test_a_comma_separated_string_is_a_list(): void {
		$this->assertSame( array( 'container' ), Input::strings( 'container' ) );
		$this->assertSame( array( 'container', 'woocommerce' ), Input::strings( 'container, woocommerce,,container' ) );
		$this->assertSame( array( 'a', 'b' ), Input::strings( array( 'a', 'b', 'a', '', 42 ) ) );
		$this->assertSame( array(), Input::strings( 42 ) );
	}

	/**
	 * #568: WordPress 7.1 splits a list on whitespace too (wp_parse_list()),
	 * so the 6.9/7.0 GET transport must read "a b" as two names, not one.
	 */
	public function test_a_whitespace_separated_string_is_a_list(): void {
		$this->assertSame( array( 'container', 'woocommerce' ), Input::strings( 'container woocommerce' ) );
		$this->assertSame( array( 'container', 'woocommerce' ), Input::strings( "container\n\twoocommerce" ) );
		$this->assertSame( array(), Input::strings( ' ' ) );
	}
}
