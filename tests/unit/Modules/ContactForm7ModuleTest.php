<?php
/**
 * Unit tests for the Contact Form 7 module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\ContactForm7\ContactForm7Module;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/cf7-stubs.php';

/**
 * Covers enqueue_scripts() (the inputs/GA4 config emitted as an inline script)
 * and add_form_name_attribute() (the server-side form-title injection).
 *
 * Note on the hex-flag sink: the JSON config encodes only the inputs mode
 * (constrained to full|names|none by the select sanitizer) and a boolean GA4
 * flag, so the value can never carry a `<`/`"`/`&` break-out character — a
 * hostile-input regression is not applicable (the flags are defense-in-depth
 * uniformity). These tests pin the emitted config and the `wp_add_inline_script`
 * (not raw-echo) delivery path. The form title injected by add_form_name_attribute()
 * is intentionally passed through raw: Contact Form 7 escapes it with esc_attr via
 * wpcf7_format_atts(), so the module must not pre-escape it.
 */
final class ContactForm7ModuleTest extends TestCase {

	/**
	 * Every wp_add_inline_script() call recorded during the test.
	 *
	 * @var array<int, array{handle: string, code: string, position: string}>
	 */
	private array $inline_scripts = array();

	protected function setUp(): void {
		parent::setUp();

		$this->inline_scripts        = array();
		\WPCF7_ContactForm::$current = null;

		Functions\when( 'plugins_url' )->alias( static fn ( $path, $plugin ) => 'https://example.com/' . $path ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- mock matches the real plugins_url() signature
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_scripts[] = array(
					'handle'   => $handle,
					'code'     => $code,
					'position' => $position,
				);
				return true;
			}
		);
	}

	protected function tearDown(): void {
		\WPCF7_ContactForm::$current = null;
		parent::tearDown();
	}

	/**
	 * Boots a Contact Form 7 module with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 */
	private function make_module( array $stored ): ContactForm7Module {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module = new ContactForm7Module();
		$module->frontend( new Options( $module->defaults() ) );

		return $module;
	}

	public function test_default_inputs_mode_is_full(): void {
		$module = new ContactForm7Module();

		$this->assertSame( 'full', $module->defaults()[ GTM4WP_OPTION_INTEGRATE_WPCF7_INPUTS ] );
	}

	public function test_ga4_events_default_off(): void {
		$module = new ContactForm7Module();

		$this->assertFalse( $module->defaults()[ GTM4WP_OPTION_INTEGRATE_WPCF7_GA4EVENTS ] );
	}

	public function test_enqueue_scripts_emits_default_config_before_the_handle(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WPCF7 => true ) );

		$module->enqueue_scripts();

		$this->assertCount( 1, $this->inline_scripts );
		$inline = $this->inline_scripts[0];

		$this->assertSame( 'gtm4wp-contact-form-7-tracker', $inline['handle'] );
		$this->assertSame( 'before', $inline['position'] );
		$this->assertStringContainsString(
			'var gtm4wp_cf7_config = {"inputs":"full","ga4events":false};',
			$inline['code']
		);
	}

	public function test_enqueue_scripts_propagates_a_restrictive_inputs_mode(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INTEGRATE_WPCF7        => true,
				GTM4WP_OPTION_INTEGRATE_WPCF7_INPUTS => 'none',
			)
		);

		$module->enqueue_scripts();

		$this->assertStringContainsString(
			'var gtm4wp_cf7_config = {"inputs":"none","ga4events":false};',
			$this->inline_scripts[0]['code']
		);
	}

	public function test_enqueue_scripts_enables_ga4_events_flag(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INTEGRATE_WPCF7           => true,
				GTM4WP_OPTION_INTEGRATE_WPCF7_GA4EVENTS => true,
			)
		);

		$module->enqueue_scripts();

		$this->assertStringContainsString(
			'var gtm4wp_cf7_config = {"inputs":"full","ga4events":true};',
			$this->inline_scripts[0]['code']
		);
	}

	public function test_add_form_name_attribute_injects_the_current_form_title(): void {
		$module = new ContactForm7Module();

		\WPCF7_ContactForm::$current = new \WPCF7_ContactForm( 'Quote request' );

		$atts = $module->add_form_name_attribute( array( 'class' => 'wpcf7-form' ) );

		$this->assertSame( 'Quote request', $atts['data-gtm4wp-form-name'] );
		$this->assertSame( 'wpcf7-form', $atts['class'], 'Existing attributes must be preserved.' );
	}

	public function test_add_form_name_attribute_passes_the_title_through_raw(): void {
		// The module must not pre-escape: Contact Form 7 escapes attribute values
		// with esc_attr downstream, so a hostile title reaches us and leaves us verbatim.
		$module = new ContactForm7Module();

		$hostile                     = '"><script>alert(1)</script>';
		\WPCF7_ContactForm::$current = new \WPCF7_ContactForm( $hostile );

		$atts = $module->add_form_name_attribute( array() );

		$this->assertSame( $hostile, $atts['data-gtm4wp-form-name'] );
	}

	public function test_add_form_name_attribute_leaves_atts_untouched_without_a_current_form(): void {
		$module = new ContactForm7Module();

		\WPCF7_ContactForm::$current = null;

		$atts = $module->add_form_name_attribute( array( 'class' => 'wpcf7-form' ) );

		$this->assertSame( array( 'class' => 'wpcf7-form' ), $atts );
		$this->assertArrayNotHasKey( 'data-gtm4wp-form-name', $atts );
	}
}
