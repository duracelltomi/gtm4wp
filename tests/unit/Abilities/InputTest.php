<?php
/**
 * Unit tests for reading ability input across the transports.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Abilities\Input;
use GTM4WP\Tests\unit\TestCase;

/**
 * Before WordPress 7.1 the GET and DELETE REST transports hand a callback
 * every value as a query string (#502): "false" must read as false and a
 * single name as a one-item list, as 7.1's own coercion reads them.
 */
final class InputTest extends TestCase {

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
}
