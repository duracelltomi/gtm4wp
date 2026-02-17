<?php
/**
 * Test suite for XSS vulnerability fix in GTM4WP
 * Tests all known entity bypass vectors and script injection attempts
 *
 * @package GTM4WP
 * @subpackage Tests
 */

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * XSS Search Term Encoding Tests
 *
 * Validates that the fix properly handles:
 * - HTML entity bypass attempts (&#x22;, &#34;, &quot;)
 * - Script tag closure attacks (</script>)
 * - Legitimate search terms with special characters
 * - Unicode/multibyte characters
 */
class Test_XSS_Search_Term_Encoding extends TestCase {

	/**
	 * Test: Hex entity quote does not break JSON
	 *
	 * Input: " (literal quote, as if from &#x22; after WP decode)
	 * Expected: wp_json_encode escapes as \" in JSON, not &quot;
	 */
	public function test_hex_entity_quote_does_not_break_json() {
		$search_term = '"';  // After WordPress decode of &#x22;
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		// Must contain escaped quote, not literal
		$this->assertStringContainsString( '\"', $json, 'JSON must contain escaped quote (\")' );

		// Must be valid JSON
		$decoded = json_decode( $json, true );
		$this->assertNotNull( $decoded, 'JSON must be valid and parseable' );
		$this->assertEquals( '"', $decoded['siteSearchTerm'], 'Decoded value must match original' );
	}

	/**
	 * Test: Decimal entity quote does not break JSON
	 *
	 * Input: " (from &#34;)
	 * Expected: Properly escaped as \"
	 */
	public function test_decimal_entity_quote_does_not_break_json() {
		$search_term = '"';  // After WordPress decode of &#34;
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringContainsString( '\"', $json );
		$this->assertNotNull( json_decode( $json ), 'Decimal entity bypass must not break JSON' );
	}

	/**
	 * Test: Direct quote character is properly escaped
	 *
	 * Input: " (direct quote in search)
	 * Expected: Escaped as \"
	 */
	public function test_direct_quote_is_escaped() {
		$search_term = 'test"breakout';
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringContainsString( '\"', $json );
		$decoded = json_decode( $json, true );
		$this->assertEquals( 'test"breakout', $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: Script tag closure is prevented
	 *
	 * Input: </script><script>alert(1)</script>
	 * Expected: Angle brackets encoded as \u003C and \u003E via JSON_HEX_TAG
	 */
	public function test_script_tag_closure_is_prevented() {
		$payload = '</script><script>alert(1)</script>';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		// Must NOT contain literal </script>
		$this->assertStringNotContainsString( '</script>', $json, 'Literal script tag must not appear in JSON' );

		// Must contain unicode-escaped angle brackets
		$this->assertStringContainsString( '\u003C', $json, 'Angle brackets must be unicode-escaped' );
		$this->assertStringContainsString( '\u003E', $json, 'Angle brackets must be unicode-escaped' );

		// Must be valid JSON
		$this->assertNotNull( json_decode( $json ), 'JSON must remain valid with script tags' );
	}

	/**
	 * Test: Script tag closure with uppercase
	 *
	 * Input: </SCRIPT>
	 * Expected: Also prevented via JSON_HEX_TAG
	 */
	public function test_script_tag_closure_uppercase() {
		$payload = '</SCRIPT><img src=x>';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringNotContainsString( '</SCRIPT>', $json );
		$this->assertStringContainsString( '\u003C', $json );
	}

	/**
	 * Test: Backslash is properly escaped
	 *
	 * Input: \ (backslash)
	 * Expected: Escaped as \\
	 */
	public function test_backslash_is_escaped() {
		$search_term = 'test\\path';
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringContainsString( '\\\\', $json, 'Backslash must be escaped' );
		$decoded = json_decode( $json, true );
		$this->assertEquals( 'test\\path', $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: Control characters are properly escaped
	 *
	 * Input: newline character
	 * Expected: Escaped as \n
	 */
	public function test_control_characters_are_escaped() {
		$search_term = "test\nline";
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringContainsString( '\\n', $json, 'Newline must be escaped' );
		$this->assertNotNull( json_decode( $json ) );
	}

	/**
	 * Test: Normal search terms work correctly
	 *
	 * Input: normal search
	 * Expected: Preserved as-is
	 */
	public function test_legitimate_search_term_preserved() {
		$search_term = 'normal search query';
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$decoded = json_decode( $json, true );
		$this->assertEquals( 'normal search query', $decoded['siteSearchTerm'], 'Normal text must be preserved' );
	}

	/**
	 * Test: Search with quoted words
	 *
	 * Input: test "quoted" words
	 * Expected: Quotes escaped, text preserved
	 */
	public function test_quoted_search_terms() {
		$search_term = 'test "quoted" words';
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringContainsString( '\"', $json );
		$decoded = json_decode( $json, true );
		$this->assertEquals( 'test "quoted" words', $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: CJK (Chinese/Japanese/Korean) characters preserved
	 *
	 * Input: 東京 検索
	 * Expected: Preserved via JSON_UNESCAPED_UNICODE
	 */
	public function test_cjk_unicode_characters_preserved() {
		$search_term = '東京 検索';
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringContainsString( '東京', $json, 'CJK characters must be preserved' );
		$decoded = json_decode( $json, true );
		$this->assertEquals( '東京 検索', $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: Single quotes are preserved (no escaping needed in JSON)
	 *
	 * Input: test'single'quotes
	 * Expected: Preserved as-is
	 */
	public function test_single_quotes_preserved() {
		$search_term = "test'single'quotes";
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$decoded = json_decode( $json, true );
		$this->assertEquals( "test'single'quotes", $decoded['siteSearchTerm'] );
	}

	/**
	 * Test: HTML injection attempt is neutralized
	 *
	 * Input: <img onerror=alert(1)>
	 * Expected: Angle brackets escaped via JSON_HEX_TAG
	 */
	public function test_html_injection_is_neutralized() {
		$payload = '<img onerror=alert(1)>';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringNotContainsString( '<img', $json );
		$this->assertStringContainsString( '\u003C', $json );
		$this->assertNotNull( json_decode( $json ) );
	}

	/**
	 * Test: Full XSS breakout attempt
	 *
	 * Input: ";alert(1);//
	 * Expected: Quote escaped, forward slash escaped
	 */
	public function test_full_xss_breakout_blocked() {
		$payload = '";alert(1);//';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringContainsString( '\"', $json, 'Quote must be escaped' );
		$this->assertStringContainsString( '\\/', $json, 'Forward slash must be escaped' );
		$this->assertNotNull( json_decode( $json ), 'JSON must remain valid' );
	}

	/**
	 * Test: Complex XSS with multiple vectors
	 *
	 * Input: </script><script>alert(document.cookie)</script>
	 * Expected: All components properly escaped
	 */
	public function test_complex_xss_attack() {
		$payload = '</script><script>alert(document.cookie)</script>';
		$data    = array( 'siteSearchTerm' => $payload );
		$json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$this->assertStringNotContainsString( '</script>', $json );
		$this->assertStringNotContainsString( '<script>', $json );
		$this->assertStringContainsString( '\u003C', $json );
		$this->assertNotNull( json_decode( $json ) );
	}

	/**
	 * Test: Empty search query
	 *
	 * Input: (empty string)
	 * Expected: Valid empty JSON
	 */
	public function test_empty_search_query() {
		$search_term = '';
		$data        = array( 'siteSearchTerm' => $search_term );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG );

		$decoded = json_decode( $json, true );
		$this->assertEquals( '', $decoded['siteSearchTerm'] );
	}
}
