<?php
/**
 * Unit tests for the AMP module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\Frontend;
use GTM4WP\Modules\Amp\AmpModule;
use GTM4WP\Options\Options;
use GTM4WP\Plugin;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the AMP snippet output. The security-relevant assertion is the
 * finding #11 hex-flag regression: the compiled data layer (which carries
 * request-sourced values such as siteSearchTerm on an AMP search page) is
 * emitted into an application/json script block, so every break-out character
 * must be hex-encoded. This test fails if the flags on that wp_json_encode()
 * are dropped.
 *
 * Break-out characters are written with \xNN escapes and the expected encoded
 * fragments are computed with json_encode() using the same flags the source
 * uses (TC-2), so no literal break-out char appears in this file.
 */
final class AmpModuleTest extends TestCase {

	private const HEX_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;

	protected function tearDown(): void {
		// Release the singleton this test injected so it never leaks (TS-7).
		$this->set_static( Plugin::class, 'instance', null );
		parent::tearDown();
	}

	/**
	 * Sets a private/protected instance property via reflection.
	 *
	 * @param object $target   Target object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	private function set_private( object $target, string $property, $value ): void {
		( new \ReflectionProperty( $target, $property ) )->setValue( $target, $value );
	}

	/**
	 * Sets a static property via reflection.
	 *
	 * @param class-string $class_name Target class.
	 * @param string       $property   Property name.
	 * @param mixed        $value      Value to set.
	 */
	private function set_static( string $class_name, string $property, $value ): void {
		( new \ReflectionProperty( $class_name, $property ) )->setValue( null, $value );
	}

	/**
	 * Aliases wp_json_encode() to the real json_encode() so the hex flags apply.
	 */
	private function stub_json_encode(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
	}

	/**
	 * Boots an AMP module and installs a Plugin singleton whose frontend data
	 * layer reports the given compiled data.
	 *
	 * @param array<string, mixed> $compiled Compiled data layer to expose.
	 * @param string               $ampid    The configured AMP container id(s).
	 * @return AmpModule
	 */
	private function make_module_with_datalayer( array $compiled, string $ampid = 'GTM-AMP1' ): AmpModule {
		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_INTEGRATE_AMPID => $ampid ) );

		$options = new Options( ( new AmpModule() )->defaults() );

		// Real DataLayer with a seeded compiled() result (no compile filter run).
		$datalayer = new DataLayer( $options );
		$this->set_private( $datalayer, 'compiled', $compiled );

		// Frontend built without its constructor so no Registry is needed; only
		// datalayer() is exercised by the AMP body injection.
		$frontend = ( new \ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $frontend, 'datalayer', $datalayer );

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $plugin, 'frontend', $frontend );
		$this->set_static( Plugin::class, 'instance', $plugin );

		$module = new AmpModule();
		$module->frontend( $options );

		return $module;
	}

	public function test_render_amp_gtm_code_hex_encodes_script_breakout_characters(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		$this->stub_json_encode();

		// \x3C < , \x3E > , \x22 " , \x26 & , \x27 '  (no literal break-out char in source).
		$tag   = "\x3C/script\x3E";
		$quote = "x\x22y\x26z";
		$apos  = "it\x27s";

		$module = $this->make_module_with_datalayer(
			array(
				'siteSearchTerm' => $tag,
				'quoteAmp'       => $quote,
				'apos'           => $apos,
			)
		);

		ob_start();
		$result = $module->render_amp_gtm_code();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $result, 'One amp-analytics snippet is injected.' );

		// Present: the hex-encoded forms as the source's wp_json_encode() emits them
		// (json string minus its surrounding quotes).
		$this->assertStringContainsString( $this->encoded_fragment( $tag ), $output );
		$this->assertStringContainsString( $this->encoded_fragment( $quote ), $output );
		$this->assertStringContainsString( $this->encoded_fragment( $apos ), $output );

		// Absent: the raw data payloads that would appear if a flag were dropped.
		// The literal script-close that ends the application/json block is expected
		// and not asserted against; these substrings come only from the data values.
		$this->assertStringNotContainsString( $quote, $output );
		$this->assertStringNotContainsString( $apos, $output );
	}

	/**
	 * Returns the JSON-encoded form of a scalar without the surrounding quotes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function encoded_fragment( string $value ): string {
		return trim( (string) json_encode( $value, self::HEX_FLAGS ), '"' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_render_amp_gtm_code_runs_only_once(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		$this->stub_json_encode();

		$module = $this->make_module_with_datalayer( array( 'event' => 'x' ) );

		ob_start();
		$module->render_amp_gtm_code();
		$second = $module->render_amp_gtm_code();
		ob_end_clean();

		$this->assertFalse( $second, 'The body snippet is injected at most once.' );
	}

	public function test_render_amp_gtm_code_returns_false_for_empty_data_layer(): void {
		$module = $this->make_module_with_datalayer( array() );

		ob_start();
		$result = $module->render_amp_gtm_code();
		ob_end_clean();

		$this->assertFalse( $result );
	}

	public function test_is_amp_request_reflects_endpoint_and_passed_value(): void {
		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_INTEGRATE_AMPID => 'GTM-AMP1' ) );
		$module = new AmpModule();
		$module->frontend( new Options( $module->defaults() ) );

		Functions\when( 'is_amp_endpoint' )->justReturn( true );
		$this->assertTrue( $module->is_amp_request( false ) );

		Functions\when( 'is_amp_endpoint' )->justReturn( false );
		$this->assertTrue( $module->is_amp_request( true ), 'Falls back to the incoming filter value.' );
		$this->assertFalse( $module->is_amp_request( false ) );
	}
}
