<?php
/**
 * Unit tests for the ScriptTag helper.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\ScriptTag;

/**
 * Ports the behavioral contract of gtm4wp_generate_script_opening_tag() from 1.x.
 */
final class ScriptTagTest extends FrontendTestCase {

	public function test_opening_tag_with_html5_theme(): void {
		$tag = new ScriptTag( $this->make_options() );

		$this->assertSame(
			'<script data-cfasync="false" data-pagespeed-no-defer>',
			$tag->opening_tag()
		);
	}

	public function test_opening_tag_without_html5_theme_adds_type(): void {
		Functions\when( 'current_theme_supports' )->justReturn( false );

		$tag = new ScriptTag( $this->make_options() );

		$this->assertSame(
			'<script data-cfasync="false" data-pagespeed-no-defer type="text/javascript">',
			$tag->opening_tag()
		);
	}

	public function test_opening_tag_with_cookiebot_integration(): void {
		$tag = new ScriptTag(
			$this->make_options( array( GTM4WP_OPTION_INTEGRATE_COOKIEBOT => true ) )
		);

		$this->assertSame(
			'<script data-cfasync="false" data-pagespeed-no-defer data-cookieconsent="ignore">',
			$tag->opening_tag()
		);
	}

	public function test_opening_tag_with_csp_nonce_filter(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GET_CSP_NONCE )
			->once()
			->with( '' )
			->andReturn( 'testnonce123' );

		$tag = new ScriptTag( $this->make_options() );

		$this->assertSame(
			'<script data-cfasync="false" data-pagespeed-no-defer nonce="testnonce123">',
			$tag->opening_tag()
		);
	}

	public function test_sanitize_rules_allow_expected_attributes(): void {
		$rules = ScriptTag::sanitize_rules();

		$this->assertArrayHasKey( 'script', $rules );
		$this->assertSame(
			array( 'data-cfasync', 'data-pagespeed-no-defer', 'data-cookieconsent', 'type', 'nonce' ),
			array_keys( $rules['script'] )
		);
	}

	public function test_print_script_block_outputs_decoded_content(): void {
		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_script_block( '<script>var a = 1 &amp;&amp; 2;</script>' );
		$output = ob_get_clean();

		$this->assertSame( '<script>var a = 1 && 2;</script>', $output );
	}

	public function test_print_script_block_does_not_decode_quote_and_tag_entities(): void {
		// wp_kses() encodes bare ampersands but leaves other named entities intact.
		// print_script_block() must restore only the ampersand: decoding &quot;,
		// &lt; or &gt; would turn an escaped value back into a raw quote or a
		// literal </script> and allow a break-out from the inline script.
		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_script_block( '<script>var s = "&quot;&lt;/script&gt;&#039;" &amp;&amp; done;</script>' );
		$output = ob_get_clean();

		// The ampersand operator is restored so the JavaScript stays valid...
		$this->assertStringContainsString( '&& done;', $output );
		// ...but the quote/tag entities stay encoded and inert.
		$this->assertStringContainsString( '&quot;&lt;/script&gt;&#039;', $output );
		$this->assertStringNotContainsString( '"</script>\'', $output );
	}
}
