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
use GTM4WP\Tests\unit\WpJsonEncodeWithRepair;

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

	public function test_opening_tag_combines_all_attributes(): void {
		// No html5 support + Cookiebot + a CSP nonce all at once, in the fixed
		// attribute order the source produces them.
		Functions\when( 'current_theme_supports' )->justReturn( false );
		Filters\expectApplied( GTM4WP_WPFILTER_GET_CSP_NONCE )
			->once()
			->with( '' )
			->andReturn( 'testnonce123' );

		$tag = new ScriptTag(
			$this->make_options( array( GTM4WP_OPTION_INTEGRATE_COOKIEBOT => true ) )
		);

		$this->assertSame(
			'<script data-cfasync="false" data-pagespeed-no-defer type="text/javascript" data-cookieconsent="ignore" nonce="testnonce123">',
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

	public function test_print_script_block_forwards_custom_rules_to_wp_kses(): void {
		// A caller-supplied rule set (e.g. ContainerCode::the_tag() adding the
		// noscript iframe) must reach wp_kses() instead of the default rules.
		$custom_rules   = array( 'span' => array( 'class' => array() ) );
		$captured_rules = null;

		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) use ( &$captured_rules ) {
				$captured_rules = $allowed_html;
				return $content;
			}
		);

		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_script_block( '<span class="x">hi</span>', $custom_rules );
		ob_get_clean();

		$this->assertSame( $custom_rules, $captured_rules );
	}

	public function test_print_script_block_defaults_to_sanitize_rules(): void {
		$captured_rules = null;

		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) use ( &$captured_rules ) {
				$captured_rules = $allowed_html;
				return $content;
			}
		);

		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_script_block( '<script>var a = 1;</script>' );
		ob_get_clean();

		$this->assertSame( ScriptTag::sanitize_rules(), $captured_rules );
	}

	/**
	 * The print_markup_block() sibling of print_script_block(), for a block that
	 * mixes a <script> with ordinary markup (the container noscript iframe).
	 * Its ampersand restore is deliberately SCOPED to <script> bodies, where
	 * print_script_block()'s is blanket - which is the whole reason the second
	 * method exists, and the property no test asserted until test-review Run 5
	 * (gap T32: four tests for one sibling, none for the other; it was reachable
	 * only through ContainerCode's byte-exact output).
	 */
	public function test_print_markup_block_restores_ampersands_inside_the_script_body(): void {
		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_markup_block( '<script>var a = 1 &amp;&amp; 2;</script>' );
		$output = ob_get_clean();

		$this->assertSame( '<script>var a = 1 && 2;</script>', $output );
	}

	public function test_print_markup_block_leaves_ampersands_outside_a_script_encoded(): void {
		// The load-bearing difference from print_script_block(): an &amp; in an
		// HTML attribute is correct as-is, and decoding it would corrupt the URL
		// (and, in an attribute, is where a break-out would live). Only the
		// script body may be restored.
		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_markup_block(
			'<noscript><iframe src="https://example.com/ns.html?id=GTM-X&amp;gtm_auth=a"></iframe></noscript>' .
			'<script>var a = 1 &amp;&amp; 2;</script>',
			array(
				'noscript' => array(),
				'iframe'   => array( 'src' => array() ),
				'script'   => array(),
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id=GTM-X&amp;gtm_auth=a', $output, 'The attribute keeps its entity form.' );
		$this->assertStringNotContainsString( 'id=GTM-X&gtm_auth=a', $output, 'A bare ampersand in the attribute would mean the restore was not scoped.' );
		$this->assertStringContainsString( 'var a = 1 && 2;', $output, 'The script body is still restored.' );
	}

	public function test_print_markup_block_does_not_decode_quote_and_tag_entities(): void {
		// TS-2, both directions: the safe entity form survives and no raw
		// break-out appears. Decoding &lt;/script&gt; here would end the block.
		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_markup_block( '<script>var s = "&quot;&lt;/script&gt;&#039;" &amp;&amp; done;</script>' );
		$output = ob_get_clean();

		$this->assertStringContainsString( '&& done;', $output );
		$this->assertStringContainsString( '&quot;&lt;/script&gt;&#039;', $output );
		$this->assertStringNotContainsString( '"</script>\'', $output );
	}

	public function test_print_markup_block_forwards_custom_rules_to_wp_kses(): void {
		$custom_rules   = array( 'span' => array( 'class' => array() ) );
		$captured_rules = null;

		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) use ( &$captured_rules ) {
				$captured_rules = $allowed_html;
				return $content;
			}
		);

		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_markup_block( '<span class="x">hi</span>', $custom_rules );
		ob_get_clean();

		$this->assertSame( $custom_rules, $captured_rules );
	}

	public function test_print_markup_block_defaults_to_sanitize_rules(): void {
		$captured_rules = null;

		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) use ( &$captured_rules ) {
				$captured_rules = $allowed_html;
				return $content;
			}
		);

		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_markup_block( '<script>var a = 1;</script>' );
		ob_get_clean();

		$this->assertSame( ScriptTag::sanitize_rules(), $captured_rules );
	}

	public function test_print_markup_block_emits_a_block_with_no_script_unchanged(): void {
		// TS-5: the callback simply never matches, so the sanitized markup must be
		// emitted as-is rather than blanked.
		$tag = new ScriptTag( $this->make_options() );

		ob_start();
		$tag->print_markup_block( '<noscript>plain &amp; markup</noscript>', array( 'noscript' => array() ) );
		$output = ob_get_clean();

		$this->assertSame( '<noscript>plain &amp; markup</noscript>', $output );
	}

	/**
	 * #339: non-finite floats become `null`; every finite one prints exactly as
	 * the plain cast did, so ordinary totals keep their short form (json_encode
	 * under serialize_precision=-1 would print 0.15000000000000002 for 3 x 0.05).
	 */
	public function test_number_literal_nulls_non_finite_and_keeps_the_cast_form_otherwise(): void {
		$this->assertSame( 'null', ScriptTag::number_literal( INF ) );
		$this->assertSame( 'null', ScriptTag::number_literal( -INF ) );
		$this->assertSame( 'null', ScriptTag::number_literal( NAN ) );

		foreach ( array( 3 * 0.05, 299.97, 10.0, 0.0, 12.5, 1.0E+20 ) as $value ) {
			$this->assertSame( (string) $value, ScriptTag::number_literal( $value ) );
		}
		$this->assertSame( '0.15', ScriptTag::number_literal( 3 * 0.05 ) );
	}

	/**
	 * #141: the guard that makes an unencodable value survivable.
	 *
	 * NAN is used deliberately rather than an invalid UTF-8 sequence: real
	 * wp_json_encode() REPAIRS bad UTF-8 (_wp_json_sanity_check), so a test built
	 * on that trigger would pass against the stub and prove nothing about
	 * production. NAN fails in both, which is what makes this case faithful
	 * (test-review TS-13 - the double must be no more permissive than the real
	 * collaborator).
	 *
	 * @return void
	 */
	public function test_json_literal_falls_back_to_the_null_literal_when_the_value_cannot_be_encoded(): void {
		$literal = ScriptTag::json_literal( array( 'value' => NAN ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

		// Both directions (TS-2): the parseable fallback is present AND the empty
		// string that would emit `var x = ;` is absent. The second assertion is the
		// one that fails if the guard is removed.
		$this->assertSame( 'null', $literal );
		$this->assertNotSame( '', $literal, 'An empty literal makes the whole <script> block a SyntaxError.' );
	}

	/**
	 * The guard must be inert on every value that encodes normally.
	 *
	 * @return void
	 */
	public function test_json_literal_returns_the_encoded_value_unchanged_for_an_ordinary_value(): void {
		$value = array(
			'event' => 'view_item',
			'sku'   => '000035180',
		);

		$this->assertSame(
			wp_json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ),
			ScriptTag::json_literal( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS )
		);
	}

	/**
	 * An empty or list array would encode as `[...]`; json_object() makes both an
	 * object, and keeps the list's values under their indexes.
	 *
	 * @return void
	 */
	public function test_json_object_casts_an_empty_or_list_array_to_an_object(): void {
		$this->assertSame( '{}', wp_json_encode( ScriptTag::json_object( array() ) ) );
		$this->assertSame( '{"0":"a","1":"b"}', wp_json_encode( ScriptTag::json_object( array( 'a', 'b' ) ) ) );
	}

	/**
	 * Any other array already encodes as an object and is returned as the same
	 * array, never cast (#330): the cast changes wp_json_encode()'s repair pass.
	 * A non-zero-based integer key is not a list either.
	 *
	 * @return void
	 */
	public function test_json_object_returns_any_other_array_unchanged(): void {
		$assoc  = array(
			'pagePostType' => 'post',
			'nested'       => array( 1, 2 ),
		);
		$sparse = array( 1 => 'a' );

		$this->assertSame( $assoc, ScriptTag::json_object( $assoc ) );
		$this->assertSame( $sparse, ScriptTag::json_object( $sparse ) );
	}

	/**
	 * The failure json_object() exists to avoid, measured through a double of
	 * core's repair pass: with invalid UTF-8 in a value, the map still encodes
	 * (repaired) instead of throwing. Against a plain (object) cast this throws.
	 *
	 * @return void
	 */
	public function test_json_object_survives_the_wp_json_encode_repair_pass(): void {
		$data = array(
			"\0k" => 1,
			's'   => "caf\xE9",
		);

		$json = WpJsonEncodeWithRepair::encode( ScriptTag::json_object( $data ) );

		$this->assertIsString( $json );
		$this->assertStringContainsString( '"s":"caf', $json );
	}

	/**
	 * The harness half of the test above: the plain (object) cast must still
	 * throw in the double, as it does in core, or the #330 guards here, in
	 * ContainerCodeTest and in VisitorDataEndpointTest prove nothing (T134).
	 *
	 * @return void
	 */
	public function test_the_repair_double_throws_on_a_plain_object_cast_like_core(): void {
		$this->expectException( \Error::class );

		// Iterating the NUL-keyed object raises a notice first, in core as here.
		set_error_handler( static fn () => true, E_NOTICE | E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped to this call.
		try {
			WpJsonEncodeWithRepair::encode(
				(object) array(
					"\0k" => 1,
					's'   => "caf\xE9",
				)
			);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * The hex flags must still reach the encoder through the helper - a guard that
	 * quietly dropped them would turn every caller into an RI-2 defect.
	 *
	 * @return void
	 */
	public function test_json_literal_applies_the_hex_flags_it_is_given(): void {
		$literal = ScriptTag::json_literal( array( 'x' => '</script>"&' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

		// Both directions (TS-2): the hex-escaped forms are present AND no raw
		// break-out character survives. Built from the same encoder the source
		// uses rather than hand-typed \uXXXX (TC-2).
		$this->assertSame(
			wp_json_encode( array( 'x' => '</script>"&' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ),
			$literal
		);

		$this->assertStringNotContainsString( '</script>', $literal );
		$this->assertStringNotContainsString( '<', $literal );
		$this->assertStringNotContainsString( '&', $literal );
		$this->assertStringNotContainsString( '"&', $literal );
	}
}
