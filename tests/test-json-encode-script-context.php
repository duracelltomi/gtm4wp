<?php
/**
 * JSON Pipeline Integration Tests
 * Tests the complete encoding pipeline: wp_json_encode() → htmlspecialchars_decode()
 *
 * @package GTM4WP
 * @subpackage Tests
 */

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * JSON Encoding Pipeline Tests
 *
 * Validates that:
 * - wp_json_encode() produces backslash-escaped JSON
 * - htmlspecialchars_decode() does NOT undo JSON escaping
 * - The complete pipeline maintains JSON integrity
 */
class Test_JSON_Encode_Script_Context extends TestCase {

	/**
	 * Test: htmlspecialchars_decode() does not affect JSON escapes
	 *
	 * The output pipeline calls htmlspecialchars_decode() on the entire script block.
	 * This test verifies that JSON's backslash escaping survives this decode step.
	 */
	public function test_pipeline_does_not_decode_json_escapes() {
		$search_term = '"breakout';
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		// Simulate the output pipeline: htmlspecialchars_decode()
		$output = htmlspecialchars_decode( $json );

		// JSON must still be valid after htmlspecialchars_decode
		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'JSON must remain valid after htmlspecialchars_decode()' );

		// Escaped quote must survive the pipeline
		$this->assertStringContainsString( '\"', $output, 'Backslash-escaped quote must survive decode' );

		// Decoded value must match original
		$this->assertEquals( '"breakout', $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: JSON_HEX_TAG prevents script closure through pipeline
	 *
	 * Verifies that angle brackets remain escaped even after htmlspecialchars_decode()
	 */
	public function test_json_hex_tag_survives_pipeline() {
		$payload = '</script><img src=x onerror=alert(1)>';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		// Pass through htmlspecialchars_decode
		$output = htmlspecialchars_decode( $json );

		// Must NOT contain literal script tags after decode
		$this->assertStringNotContainsString( '</script>', $output, 'Script tags must not appear after pipeline' );

		// Unicode escapes must still be present
		$this->assertStringContainsString( '\u003C', $output, 'Unicode escapes must survive decode' );

		// JSON must be valid
		$this->assertNotNull( json_decode( $output ) );
	}

	/**
	 * Test: Multiple entity types in pipeline
	 *
	 * Tests various quote representations through the complete pipeline
	 */
	public function test_entity_bypass_through_pipeline() {
		$test_cases = array(
			'"'        => 'Direct quote',
			'test"end' => 'Quote in middle',
			'"start'   => 'Quote at start',
			'end"'     => 'Quote at end',
		);

		foreach ( $test_cases as $input => $description ) {
			$data   = array( 'siteSearchTerm' => $input );
			$json   = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );
			$output = htmlspecialchars_decode( $json );

			$decoded = json_decode( $output, true );
			$this->assertNotNull( $decoded, "Pipeline test failed for: {$description}" );
			$this->assertEquals( $input, $decoded['siteSearchTerm'], "Value mismatch for: {$description}" );
		}
	}

	/**
	 * Test: Complex payload through full pipeline
	 *
	 * Combines multiple attack vectors in single payload
	 */
	public function test_complex_payload_pipeline() {
		$payload = '"><script>alert(1)</script><img src="x"';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );
		$output  = htmlspecialchars_decode( $json );

		// Verify no XSS vectors survive
		$this->assertStringNotContainsString( '"><script>', $output );
		$this->assertStringNotContainsString( '</script>', $output );

		// Verify JSON integrity
		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'Complex payload must produce valid JSON' );
		$this->assertEquals( $payload, $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: Regression - Old vulnerable pattern
	 *
	 * Proves that the OLD code pattern (htmlspecialchars + wp_json_encode without flags)
	 * was indeed vulnerable to entity bypass.
	 */
	public function test_old_htmlspecialchars_pattern_was_vulnerable() {
		$search_term = '"';  // After WordPress decode of &#x22;

		// OLD VULNERABLE PATTERN
		$old_value = htmlspecialchars( $search_term );  // Produces &quot;
		$data      = array( 'siteSearchTerm' => $old_value );
		$json      = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK );  // No JSON_HEX_TAG
		$output    = htmlspecialchars_decode( $json );  // Decodes &quot; back to "

		// This SHOULD produce broken JSON (proving old code was vulnerable)
		$decoded = json_decode( $output, true );
		$this->assertNull( $decoded, 'OLD pattern should produce invalid JSON - proving vulnerability existed' );

		// Output should contain literal quote, not escaped
		$this->assertStringNotContainsString( '\"', $output, 'OLD pattern fails to escape quotes properly' );
	}

	/**
	 * Test: NEW fixed pattern is secure
	 *
	 * Demonstrates that removing htmlspecialchars() and using JSON_HEX_TAG fixes the issue
	 */
	public function test_new_pattern_is_secure() {
		$search_term = '"';  // Same input as vulnerable test

		// NEW FIXED PATTERN
		// No htmlspecialchars() - direct value
		$data   = array( 'siteSearchTerm' => $search_term );
		$json   = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );
		$output = htmlspecialchars_decode( $json );

		// This SHOULD produce valid JSON
		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'NEW pattern must produce valid JSON' );

		// Output should contain escaped quote
		$this->assertStringContainsString( '\"', $output, 'NEW pattern properly escapes quotes' );

		// Value should match original
		$this->assertEquals( '"', $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: Forward slash escaping
	 *
	 * Verifies that wp_json_encode() escapes forward slashes by default
	 */
	public function test_forward_slash_escaping() {
		$payload = '</script>';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		// Forward slashes should be escaped as \/
		// And angle brackets should be unicode-escaped
		$this->assertStringContainsString( '\u003C', $json, 'Opening angle bracket must be unicode-escaped' );
		$this->assertStringContainsString( '\/', $json, 'Forward slash must be escaped' );

		$decoded = json_decode( $json, true );
		$this->assertEquals( '</script>', $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: Unicode preservation through pipeline
	 *
	 * Ensures JSON_UNESCAPED_UNICODE flag works correctly with htmlspecialchars_decode()
	 */
	public function test_unicode_preservation_pipeline() {
		$unicode_tests = array(
			'日本語'       => 'Japanese',
			'العربية'      => 'Arabic',
			'Ελληνικά'     => 'Greek',
			'中文'         => 'Chinese',
			'emoji 🔥 test' => 'Emoji',
		);

		foreach ( $unicode_tests as $input => $description ) {
			$data   = array( 'siteSearchTerm' => $input );
			$json   = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );
			$output = htmlspecialchars_decode( $json );

			$decoded = json_decode( $output, true );
			$this->assertEquals( $input, $decoded['siteSearchTerm'], "Unicode test failed for: {$description}" );
		}
	}

	/**
	 * Test: pageTitle field (second vulnerable location)
	 *
	 * The fix also removes htmlspecialchars() from pageTitle at line 265
	 */
	public function test_page_title_encoding() {
		$page_title = 'Test "Page" Title <with> tags';
		$data       = array( 'pageTitle' => $page_title );
		$json       = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );
		$output     = htmlspecialchars_decode( $json );

		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'pageTitle must produce valid JSON' );
		$this->assertEquals( $page_title, $decoded['pageTitle'] );

		// Verify escaping
		$this->assertStringContainsString( '\"', $output, 'Quotes must be escaped in pageTitle' );
		$this->assertStringContainsString( '\u003C', $output, 'Angle brackets must be unicode-escaped' );
	}

	/**
	 * Test: wp_kses() integration
	 *
	 * The actual pipeline includes wp_kses() between wp_json_encode() and htmlspecialchars_decode().
	 * Since we're mocking, we simulate that wp_kses() passes script content through.
	 */
	public function test_pipeline_with_wp_kses_simulation() {
		$payload = '"><script>alert(1)</script>';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		// Simulate wp_kses() - in actual code it allows <script> tags in this context
		// For testing, we just pass it through (wp_kses doesn't modify JSON content)
		$after_kses = $json;

		// Then htmlspecialchars_decode()
		$output = htmlspecialchars_decode( $after_kses );

		// Final output must be safe
		$this->assertStringNotContainsString( '"><script>', $output );
		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded );
	}

	/**
	 * Test: Long payload performance
	 *
	 * Ensures the fix handles large search terms efficiently
	 */
	public function test_long_payload_handling() {
		$long_payload = str_repeat( 'test"quote<tag> ', 100 );
		$data         = array( 'siteSearchTerm' => $long_payload );
		$json         = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );
		$output       = htmlspecialchars_decode( $json );

		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'Long payloads must produce valid JSON' );
		$this->assertEquals( $long_payload, $decoded['siteSearchTerm'] );
	}
}
