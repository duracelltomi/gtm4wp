<?php
/**
 * PHPUnit bootstrap file for GTM4WP XSS fix testing
 * Provides minimal WordPress function mocks for encoding behavior tests
 */

// Mock WordPress functions for testing
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Mock wp_json_encode - mirrors WordPress implementation
	 *
	 * @param mixed $data    Data to encode
	 * @param int   $options JSON encoding options
	 * @param int   $depth   Maximum depth
	 * @return string|false JSON string or false on failure
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'get_search_query' ) ) {
	/**
	 * Mock get_search_query - simulates WordPress behavior
	 *
	 * @param bool $escaped Whether to escape the result
	 * @return string Search query string
	 */
	function get_search_query( $escaped = true ) {
		global $test_search_query;
		$query = $test_search_query ?? '';

		if ( $escaped ) {
			// Simulate esc_attr() behavior
			$query = htmlspecialchars( $query, ENT_QUOTES, 'UTF-8' );
		}

		return $query;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Mock esc_attr - WordPress escaping for HTML attributes
	 *
	 * @param string $text Text to escape
	 * @return string Escaped text
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Mock wp_strip_all_tags - removes HTML tags
	 *
	 * @param string $text Text with HTML tags
	 * @return string Text without HTML tags
	 */
	function wp_strip_all_tags( $text ) {
		return strip_tags( $text );
	}
}
