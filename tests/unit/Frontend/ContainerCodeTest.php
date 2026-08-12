<?php
/**
 * Unit tests for the ContainerCode service.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\ConsentDefaults;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Options\Options;

/**
 * Ports the behavioral contract of the container output functions of 1.x:
 * gtm4wp_get_the_gtm_tag(), gtm4wp_wp_header_begin(), gtm4wp_wp_header_top().
 */
final class ContainerCodeTest extends FrontendTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array() )
		);
	}

	/**
	 * Builds a fully wired ContainerCode instance.
	 *
	 * @param array<string, mixed> $overrides Option overrides.
	 * @return ContainerCode
	 */
	private function make_container( array $overrides = array() ): ContainerCode {
		$options = $this->make_options( $overrides );

		return new ContainerCode(
			$options,
			new DataLayer( $options ),
			new ScriptTag( $options ),
			new ConsentDefaults( $options )
		);
	}

	public function test_placement_string_mapping(): void {
		$this->assertSame( 'footer', $this->make_container( array( GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_FOOTER ) )->placement_string() );
		$this->assertSame( 'manual', $this->make_container( array( GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_BODYOPEN ) )->placement_string() );
		$this->assertSame( 'automatic', $this->make_container( array( GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_BODYOPEN_AUTO ) )->placement_string() );
		$this->assertSame( 'off', $this->make_container( array( GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_OFF ) )->placement_string() );
		$this->assertSame( 'unknown (99)', $this->make_container( array( GTM4WP_OPTION_GTM_PLACEMENT => 99 ) )->placement_string() );
	}

	public function test_get_tag_outputs_noscript_iframe(): void {
		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		$tag = $container->get_tag();

		$this->assertStringContainsString( '<!-- Google Tag Manager (noscript) -->', $tag );
		$this->assertStringContainsString( 'https://www.googletagmanager.com/ns.html?id=GTM-ABC123', $tag );
		$this->assertStringContainsString( '<!-- End Google Tag Manager (noscript) -->', $tag );
		$this->assertTrue( $GLOBALS['gtm4wp_container_code_written'] );
	}

	public function test_get_tag_deduplicates_output(): void {
		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		$container->get_tag();
		$second = $container->get_tag();

		$this->assertStringNotContainsString( 'ns.html', $second, 'Second call must not output the iframe again.' );
	}

	public function test_get_tag_skips_invalid_container_ids(): void {
		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-VALID1,invalid-id' ) );

		$tag = $container->get_tag();

		$this->assertStringContainsString( 'ns.html?id=GTM-VALID1', $tag );
		$this->assertStringNotContainsString( 'invalid-id', $tag );
	}

	public function test_get_tag_includes_environment_parameters(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE        => 'GTM-ABC123',
				GTM4WP_OPTION_ENV_GTM_AUTH    => 'authtoken',
				GTM4WP_OPTION_ENV_GTM_PREVIEW => 'env-2',
			)
		);

		$this->assertStringContainsString(
			'ns.html?id=GTM-ABC123&gtm_auth=authtoken&gtm_preview=env-2&gtm_cookies_win=x',
			$container->get_tag()
		);
	}

	public function test_get_tag_uses_valid_custom_domain(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE  => 'GTM-ABC123',
				GTM4WP_OPTION_GTMDOMAIN => 'gtm.example.com',
			)
		);

		$this->assertStringContainsString( 'https://gtm.example.com/ns.html', $container->get_tag() );
	}

	public function test_get_tag_falls_back_on_invalid_custom_domain(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE  => 'GTM-ABC123',
				GTM4WP_OPTION_GTMDOMAIN => 'not a domain!',
			)
		);

		$this->assertStringContainsString( 'https://www.googletagmanager.com/ns.html', $container->get_tag() );
	}

	public function test_get_tag_off_placement_warns_and_suppresses_iframe(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_OFF,
			)
		);

		$tag = $container->get_tag();

		$this->assertStringContainsString( 'container code placement set to OFF', $tag );
		$this->assertStringNotContainsString( 'ns.html', $tag );
	}

	public function test_get_tag_off_placement_suppresses_iframe_even_without_console_log(): void {
		// Regression: placement OFF must never emit the container iframe, even
		// when console logging is disabled (so no warning marks the code as
		// written). Only the data layer stays active in that case.
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_OFF,
				GTM4WP_OPTION_NOCONSOLELOG  => true,
			)
		);

		$tag = $container->get_tag();

		$this->assertStringNotContainsString( 'ns.html', $tag, 'Placement OFF must not emit the iframe with console logging off.' );
		$this->assertStringNotContainsString( 'container code placement set to OFF', $tag, 'No console warning is emitted when console logging is disabled.' );
	}

	public function test_get_tag_suppresses_iframe_on_non_production_when_production_only_enabled(): void {
		// Kill switch: with the production-only option on, a non-production
		// environment (staging/clone) never emits the noscript iframe; the code
		// is marked as written so the iframe block is skipped, exactly like OFF.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE       => 'GTM-ABC123',
				GTM4WP_OPTION_PRODUCTIONONLY => true,
			)
		);

		$tag = $container->get_tag();

		$this->assertStringNotContainsString( 'ns.html', $tag, 'The noscript iframe must be suppressed on a non-production environment.' );
		$this->assertStringContainsString( 'container code output has been suppressed', $tag );
		$this->assertTrue( $GLOBALS['gtm4wp_container_code_written'] );
	}

	public function test_get_tag_suppresses_iframe_when_output_filter_returns_false(): void {
		// The gtm4wp_output_container filter suppresses the noscript iframe too.
		Filters\expectApplied( GTM4WP_WPFILTER_OUTPUT_CONTAINER )
			->andReturn( false );

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		$tag = $container->get_tag();

		$this->assertStringNotContainsString( 'ns.html', $tag, 'A false gtm4wp_output_container filter must suppress the iframe.' );
		$this->assertTrue( $GLOBALS['gtm4wp_container_code_written'] );
	}

	public function test_get_tag_outputs_iframe_on_production_when_production_only_enabled(): void {
		// The production-only option must NOT suppress the iframe on a real
		// production environment.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE       => 'GTM-ABC123',
				GTM4WP_OPTION_PRODUCTIONONLY => true,
			)
		);

		$tag = $container->get_tag();

		$this->assertStringContainsString( 'ns.html?id=GTM-ABC123', $tag );
		$this->assertStringNotContainsString( 'container code output has been suppressed', $tag );
	}

	public function test_get_tag_suppresses_iframe_for_disabled_role(): void {
		// Regression: the "Exclude user roles" option suppressed only the <head>
		// container loader, while the noscript iframe - the fallback that loads
		// the container when JavaScript does not run - was still emitted, so an
		// excluded user kept loading GTM and showing up in the reports.
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array( 'editor' ) )
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-ABC123',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => 'administrator,editor',
			)
		);

		$tag = $container->get_tag();

		$this->assertStringNotContainsString( 'ns.html', $tag, 'The noscript iframe must be suppressed for an excluded user role.' );
		$this->assertStringContainsString( 'disabled for this user role: editor', $tag );
		$this->assertTrue( $GLOBALS['gtm4wp_container_code_written'] );
	}

	/**
	 * #105: the excluded role is a string VALUE inside a raw <script> body, so it
	 * goes through the hex-flag JSON encoder, not esc_js().
	 *
	 * The esc_js() helper emits HTML entities (&quot;, &amp;, &lt;), and the browser
	 * never HTML-decodes inside a <script> element - so an esc_js'd role slug reached
	 * the console as the entity text rather than the character (PA-4/RI-4). This is
	 * the same swap #72 made in global_var_literal(); this was the last esc_js'd
	 * string value left in a script body.
	 *
	 * Not a break-out either way - the value is A4-constrained at both ends (it must
	 * appear in the user's roles AND in the admin's option) - so this pins the
	 * encoding, which is where the defect actually was.
	 */
	public function test_disabled_role_warning_json_encodes_a_role_containing_special_characters(): void {
		$role = 'shop & "manager"';

		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array( $role ) )
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-ABC123',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => $role,
			)
		);

		$tag = $container->get_tag();

		// Both directions (TS-2). Present: the encoder's own output, built with the
		// same call the source uses rather than hand-typed escapes (TC-2).
		$expected = wp_json_encode(
			'[GTM4WP] Google Tag Manager container code was disabled for this user role: ' . $role . ' !!!',
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		$this->assertStringContainsString(
			'console.warn(' . $expected . ')',
			$tag,
			'The whole warning message must be emitted as one hex-flag JSON literal.'
		);

		// Absent: the entity forms esc_js() would have produced. &amp; is the one
		// that matters most, because print_markup_block() restores it inside a
		// <script> body - so an esc_js'd ampersand would arrive as a bare & and the
		// quote entities would stay visible as text.
		$this->assertStringNotContainsString( '&quot;', $tag, 'esc_js() quote entities must not reach a <script> body.' );
		$this->assertStringNotContainsString( '&amp;', $tag, 'esc_js() ampersand entities must not reach a <script> body.' );
	}

	public function test_get_tag_disabled_role_suppresses_iframe_even_without_console_log(): void {
		// The suppression must not depend on the console warning being emitted:
		// with console logging off nothing marks the code as written, so the
		// iframe block below would otherwise still run.
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array( 'editor' ) )
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-ABC123',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => 'administrator,editor',
				GTM4WP_OPTION_NOCONSOLELOG     => true,
			)
		);

		$tag = $container->get_tag();

		$this->assertStringNotContainsString( 'ns.html', $tag, 'An excluded role must not get the iframe with console logging off.' );
		$this->assertStringNotContainsString( 'console.warn', $tag, 'No console warning is emitted when console logging is disabled.' );
	}

	public function test_get_tag_outputs_iframe_for_role_not_excluded(): void {
		// The counter-direction: a user whose roles are not on the exclusion
		// list still gets the noscript iframe.
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array( 'subscriber' ) )
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-ABC123',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => 'administrator,editor',
			)
		);

		$tag = $container->get_tag();

		$this->assertStringContainsString( 'ns.html?id=GTM-ABC123', $tag );
		$this->assertStringNotContainsString( 'disabled for this user role', $tag );
	}

	public function test_header_begin_outputs_datalayer_and_container_loader(): void {
		Actions\expectDone( GTM4WP_WPACTION_AFTER_DATALAYER )->once();
		Actions\expectDone( GTM4WP_WPACTION_AFTER_CONTAINER_CODE )->once();

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111,GTM-BBB222' ) );

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'var dataLayer_content = [];', $output );
		$this->assertStringContainsString( 'dataLayer.push( dataLayer_content );', $output );
		$this->assertStringContainsString( "'//www.googletagmanager.com/gtm.js?id='+i+dl", $output );
		$this->assertStringContainsString( "'GTM-AAA111'", $output );
		$this->assertStringContainsString( "'GTM-BBB222'", $output );
		$this->assertStringContainsString( '<!-- End Google Tag Manager for WordPress by gtm4wp.com -->', $output );
	}

	public function test_header_begin_suppresses_container_code_on_amp(): void {
		// On an AMP page (FILTER_AMP_RUNNING true) the invalid GTM container
		// <script> must not be emitted - the AMP module injects an amp-analytics
		// tag instead. The data layer is still compiled (AFTER_DATALAYER fires,
		// the compat global is populated), but no container code is output.
		Filters\expectApplied( ContainerCode::FILTER_AMP_RUNNING )->andReturn( true );
		Actions\expectDone( GTM4WP_WPACTION_AFTER_DATALAYER )->once();
		Actions\expectDone( GTM4WP_WPACTION_AFTER_CONTAINER_CODE )->never();

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111' ) );

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'No container/script markup is emitted on AMP pages.' );
		$this->assertSame( array(), $GLOBALS['gtm4wp_datalayer_data'], 'The data layer is still compiled for the AMP integration.' );
	}

	public function test_header_begin_does_not_decode_html_entities_in_datalayer_values(): void {
		// get_search_query() returns esc_attr'd output, so a double quote in the
		// ?s= parameter reaches the data layer already encoded as &quot;. Because
		// ScriptTag::print_script_block() runs htmlspecialchars_decode() on the whole
		// block, the JSON must hex-encode the ampersand; otherwise &quot; is decoded
		// back into a raw " that breaks out of the JS string (reflected XSS via ?s=).
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->andReturn( array( 'siteSearchTerm' => '&quot;-alert(document.domain)-&quot;' ) );

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111' ) );

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringNotContainsString(
			'""-alert(document.domain)-""',
			$output,
			'HTML entities must not be decoded into raw quotes that break out of the data layer JS string.'
		);
		// The ampersand is hex-encoded, so the entity survives htmlspecialchars_decode()
		// as an inert JS string literal instead of a raw quote. Build the expected,
		// safely-encoded fragment the same way the data layer output does.
		$safe_fragment = trim(
			(string) wp_json_encode( '&quot;-alert(document.domain)-&quot;', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ),
			'"'
		);
		$this->assertStringContainsString( $safe_fragment, $output );
	}

	public function test_header_begin_preserves_numeric_looking_strings_in_datalayer(): void {
		// Regression (wp.org forum report): the data layer encode carried
		// JSON_NUMERIC_CHECK, which coerced every numeric-looking string into a
		// JSON number - a SKU of "000035180" reached GTM as 35180 in cartContent
		// while ecommerce.items (built on the flag-free additional-push path)
		// kept the correct string, so the two blocks disagreed about the same
		// product. Identifier-like values must survive as strings, byte-exact.
		$compiled = array(
			'cartContent' => array(
				'items' => array(
					array(
						'item_id' => '000035180',
						'sku'     => '000035180',
					),
				),
			),
			'orderNumber' => '0042',
		);

		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->andReturn( $compiled );

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111' ) );

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		// Expected output built with the same wp_json_encode() + flags the source
		// uses (TC-2), asserting the full encode including every value's type.
		$expected_json = wp_json_encode( $compiled, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );
		$this->assertStringContainsString( 'var dataLayer_content = ' . $expected_json . ';', $output );

		// Both directions (TS-2): the string form is present AND the coerced
		// number form is absent.
		$this->assertStringContainsString( '"item_id":"000035180"', $output );
		$this->assertStringNotContainsString( '"item_id":35180', $output, 'A leading-zero SKU must not be coerced into a JSON number.' );
		$this->assertStringNotContainsString( '"orderNumber":42', $output, 'A zero-padded order number must not be coerced into a JSON number.' );
	}

	/**
	 * #141: the primary data layer sink concatenated the encoder result straight
	 * into the block, so a value wp_json_encode() refuses emitted
	 * `var dataLayer_content = ;` - a SyntaxError that took the whole head
	 * <script>, the data layer initialization inside it AND every container loader
	 * after it. The guard that existed for exactly this (ContainerCode's private
	 * json_literal(), now ScriptTag::json_literal()) had one caller and never
	 * reached here.
	 *
	 * NAN, not invalid UTF-8: real wp_json_encode() repairs bad UTF-8, so only a
	 * trigger that fails in production too proves anything here.
	 *
	 * @return void
	 */
	public function test_header_begin_omits_the_datalayer_push_when_the_content_cannot_be_encoded(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->andReturn( array( 'brokenValue' => NAN ) );

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111' ) );

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		// The empty assignment is the defect. Assert its absence directly, in both
		// spellings it could take (TS-2).
		$this->assertStringNotContainsString( 'var dataLayer_content = ;', $output );
		$this->assertStringNotContainsString( 'var dataLayer_content =  ;', $output );

		// Omitted, not invented: no assignment and no push at all, rather than a
		// `null` the consumer would read as data (RI-13).
		$this->assertStringNotContainsString( 'var dataLayer_content', $output );
		$this->assertStringNotContainsString( 'dataLayer.push( dataLayer_content );', $output );

		// ...and the failure stays confined to the data layer content: the block
		// still closes and the container still loads. This is what separates the
		// fix from the defect - both omit a usable data layer, only one keeps GTM.
		$this->assertStringContainsString( "'GTM-AAA111'", $output );
		$this->assertStringContainsString( '</script>', $output );
	}

	public function test_header_begin_keeps_typed_numbers_as_json_numbers(): void {
		// The counter-direction of dropping JSON_NUMERIC_CHECK: values the
		// builders type as PHP floats/ints (prices, totals, quantities) must
		// still encode as JSON numbers - GA4 and Meta reject string prices
		// (the bug class the 1.22.4 JS-side parseFloat fix closed).
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->andReturn(
				array(
					'cartContent' => array(
						'totals' => array( 'total' => 71.8 ),
						'items'  => array(
							array(
								'price'    => 35.9,
								'quantity' => 2,
							),
						),
					),
				)
			);

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111' ) );

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"price":35.9', $output );
		$this->assertStringContainsString( '"quantity":2', $output );
		$this->assertStringContainsString( '"total":71.8', $output );
		$this->assertStringNotContainsString( '"price":"35.9"', $output, 'A float price must encode as a JSON number, not a string.' );
	}

	public function test_header_begin_omits_loader_when_placement_off(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-AAA111',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_OFF,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'var dataLayer_content', $output, 'Data layer must be output even with container off.' );
		$this->assertStringContainsString( 'container code placement set to OFF', $output );
		$this->assertStringNotContainsString( 'gtm.js?id=', $output );
	}

	public function test_header_begin_omits_loader_for_disabled_role(): void {
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array( 'editor' ) )
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-AAA111',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => 'administrator,editor',
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'disabled for this user role: editor', $output );
		$this->assertStringNotContainsString( 'gtm.js?id=', $output );
	}

	public function test_header_begin_off_placement_silent_when_console_log_disabled(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-AAA111',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_OFF,
				GTM4WP_OPTION_NOCONSOLELOG  => true,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'var dataLayer_content', $output, 'The data layer is still output with the container off.' );
		$this->assertStringNotContainsString( 'console.warn', $output, 'No console warning is emitted when console logging is disabled.' );
		$this->assertStringNotContainsString( 'gtm.js?id=', $output );
	}

	public function test_header_begin_disabled_role_silent_when_console_log_disabled(): void {
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array( 'editor' ) )
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-AAA111',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => 'administrator,editor',
				GTM4WP_OPTION_NOCONSOLELOG     => true,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'console.warn', $output, 'The disabled-role warning is suppressed when console logging is off.' );
		$this->assertStringNotContainsString( 'gtm.js?id=', $output, 'The container loader is still omitted for the disabled role.' );
	}

	public function test_header_begin_suppresses_container_on_non_production_when_production_only_enabled(): void {
		// Kill switch: with the production-only option on, a non-production
		// environment (staging/clone) gets the data layer but no container
		// loader - so it never sends hits to the live GTM container.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		Actions\expectDone( GTM4WP_WPACTION_AFTER_DATALAYER )->once();

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE       => 'GTM-AAA111',
				GTM4WP_OPTION_PRODUCTIONONLY => true,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'var dataLayer_content', $output, 'The data layer must still be compiled when the container is suppressed.' );
		$this->assertStringContainsString( 'container code output has been suppressed', $output );
		$this->assertStringNotContainsString( 'gtm.js?id=', $output, 'The container loader must not be emitted on a non-production environment.' );
	}

	public function test_header_begin_outputs_container_on_production_when_production_only_enabled(): void {
		// The production-only option must NOT suppress the container on a real
		// production environment.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE       => 'GTM-AAA111',
				GTM4WP_OPTION_PRODUCTIONONLY => true,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//www.googletagmanager.com/gtm.js?id='+i+dl", $output );
		$this->assertStringNotContainsString( 'container code output has been suppressed', $output );
	}

	public function test_header_begin_outputs_container_on_non_production_when_production_only_disabled(): void {
		// With the option off (default) the environment type is irrelevant: the
		// container is still emitted on staging, preserving 1.x behavior. This
		// is the else branch of the production-only gate.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE       => 'GTM-AAA111',
				GTM4WP_OPTION_PRODUCTIONONLY => false,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//www.googletagmanager.com/gtm.js?id='+i+dl", $output );
		$this->assertStringNotContainsString( 'container code output has been suppressed', $output );
	}

	public function test_header_begin_suppresses_container_when_output_filter_returns_false(): void {
		// The gtm4wp_output_container filter is the programmatic kill switch: a
		// site returns false from an mu-plugin/wp-config to suppress the
		// container while keeping the data layer active.
		Filters\expectApplied( GTM4WP_WPFILTER_OUTPUT_CONTAINER )
			->andReturn( false );
		Actions\expectDone( GTM4WP_WPACTION_AFTER_DATALAYER )->once();

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-AAA111' ) );

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'var dataLayer_content', $output, 'The data layer must still be compiled when the filter suppresses the container.' );
		$this->assertStringContainsString( 'container code output has been suppressed', $output );
		$this->assertStringNotContainsString( 'gtm.js?id=', $output );
	}

	public function test_header_begin_suppressed_container_silent_when_console_log_disabled(): void {
		// No console warning is emitted for the suppressed container when console
		// logging is turned off; the loader is still omitted.
		Filters\expectApplied( GTM4WP_WPFILTER_OUTPUT_CONTAINER )
			->andReturn( false );

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE     => 'GTM-AAA111',
				GTM4WP_OPTION_NOCONSOLELOG => true,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'var dataLayer_content', $output );
		$this->assertStringNotContainsString( 'console.warn', $output, 'No console warning is emitted when console logging is disabled.' );
		$this->assertStringNotContainsString( 'gtm.js?id=', $output );
	}

	public function test_header_begin_falls_back_to_default_path_on_invalid_custom_path(): void {
		// The custom loader path is re-validated at the output sink (PA-2); an
		// invalid value falls back to gtm.js instead of reaching the script src.
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'     => 'GTM-AAA111',
						'domain' => 'gtm.example.com',
						'path'   => 'bad path!.js',
					),
				),
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//gtm.example.com/gtm.js?id='+i+dl", $output );
		$this->assertStringNotContainsString( 'bad path', $output );
	}

	public function test_header_begin_outputs_consent_defaults_before_loader(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE              => 'GTM-AAA111',
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => true,
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$consent_pos = strpos( $output, 'gtag("consent", "default"' );
		$loader_pos  = strpos( $output, 'gtm.js?id=' );

		$this->assertNotFalse( $consent_pos );
		$this->assertNotFalse( $loader_pos );
		$this->assertLessThan( $loader_pos, $consent_pos, 'Consent defaults must be output before the container loader.' );
	}

	public function test_header_begin_custom_domain_and_path_in_loader(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-AAA111',
				GTM4WP_OPTION_GTMDOMAIN     => 'gtm.example.com',
				GTM4WP_OPTION_GTMCUSTOMPATH => 'custom/loader.js',
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//gtm.example.com/custom/loader.js?id='+i+dl", $output );
	}

	public function test_get_tag_uses_per_container_environment_and_domain(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-AAA111',
						'gtm_auth'    => 'authtoken',
						'gtm_preview' => 'env-2',
						'domain'      => '',
						'path'        => '',
					),
					array(
						'id'          => 'GTM-BBB222',
						'gtm_auth'    => '',
						'gtm_preview' => '',
						'domain'      => 'gtm.example.com',
						'path'        => '',
					),
				),
			)
		);

		$tag = $container->get_tag();

		$this->assertStringContainsString( 'https://www.googletagmanager.com/ns.html?id=GTM-AAA111&gtm_auth=authtoken&gtm_preview=env-2&gtm_cookies_win=x', $tag );
		$this->assertStringContainsString( 'https://gtm.example.com/ns.html?id=GTM-BBB222"', $tag );
	}

	public function test_get_tag_row_with_partial_environment_omits_env_parameters(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'       => 'GTM-AAA111',
						'gtm_auth' => 'authtoken',
					),
				),
			)
		);

		$tag = $container->get_tag();

		$this->assertStringContainsString( 'ns.html?id=GTM-AAA111"', $tag );
		$this->assertStringNotContainsString( 'gtm_auth', $tag );
	}

	public function test_header_begin_uses_per_container_settings_in_loader(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-AAA111',
						'gtm_auth'    => 'authtoken',
						'gtm_preview' => 'env-2',
						'domain'      => 'gtm.example.com',
						'path'        => 'custom/loader.js',
					),
					array(
						'id' => 'GTM-BBB222',
					),
				),
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//gtm.example.com/custom/loader.js?id='+i+dl+'&gtm_auth=authtoken&gtm_preview=env-2&gtm_cookies_win=x'", $output );
		$this->assertStringContainsString( "'//www.googletagmanager.com/gtm.js?id='+i+dl;", $output );
		$this->assertStringContainsString( "'GTM-AAA111'", $output );
		$this->assertStringContainsString( "'GTM-BBB222'", $output );
	}

	public function test_header_begin_omits_container_id_when_flagged_with_custom_path(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'     => 'GTM-AAA111',
						'domain' => 'sgtm.example.com',
						'path'   => 'custom/loader.js',
						'no_id'  => '1',
					),
				),
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//sgtm.example.com/custom/loader.js?'+dl;", $output );
		$this->assertStringNotContainsString( "?id='+i", $output );
		// The container ID is still passed to the loader function as its argument.
		$this->assertStringContainsString( "'GTM-AAA111'", $output );
	}

	public function test_header_begin_omit_id_keeps_environment_parameters(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-AAA111',
						'gtm_auth'    => 'authtoken',
						'gtm_preview' => 'env-2',
						'domain'      => 'sgtm.example.com',
						'path'        => 'custom/loader.js',
						'no_id'       => '1',
					),
				),
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//sgtm.example.com/custom/loader.js?'+dl+'&gtm_auth=authtoken&gtm_preview=env-2&gtm_cookies_win=x'", $output );
	}

	public function test_header_begin_omit_id_flag_ignored_without_custom_path(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'    => 'GTM-AAA111',
						'no_id' => '1',
					),
				),
			)
		);

		ob_start();
		$container->header_begin();
		$output = ob_get_clean();

		$this->assertStringContainsString( "'//www.googletagmanager.com/gtm.js?id='+i+dl;", $output );
	}

	public function test_get_tag_keeps_container_id_in_noscript_when_flagged(): void {
		// The "omit container ID" flag only affects the head loader; the
		// noscript iframe keeps the id so the container still loads there.
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'     => 'GTM-AAA111',
						'domain' => 'sgtm.example.com',
						'path'   => 'custom/loader.js',
						'no_id'  => '1',
					),
				),
			)
		);

		$this->assertStringContainsString( 'https://sgtm.example.com/ns.html?id=GTM-AAA111', $container->get_tag() );
	}

	public function test_header_top_outputs_datalayer_initialization(): void {
		$container = $this->make_container(
			array( GTM4WP_OPTION_DATALAYER_NAME => 'customDL' )
		);

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'var gtm4wp_datalayer_name = "customDL";', $output );
		$this->assertStringContainsString( 'var customDL = customDL || [];', $output );
		$this->assertStringContainsString( '<!-- Google Tag Manager for WordPress by gtm4wp.com -->', $output );
		$this->assertStringContainsString( '<!-- End Google Tag Manager for WordPress by gtm4wp.com -->', $output );
	}

	/**
	 * Findings #114 and #117 at the sink they actually matter for. This block
	 * emits the data layer name twice over in two different grammars - once as a
	 * string VALUE (json_literal + hex flags, RI-4 pile b) and twice as a bare
	 * IDENTIFIER, which no escaper can protect. A stored name that is not a
	 * valid identifier must therefore never reach either position: it would make
	 * `var my-layer = my-layer || [];` a SyntaxError and take the whole head
	 * <script> block down with it, including the data layer initialization.
	 *
	 * Assert both directions - the fallback IS emitted and the stored value is
	 * absent - so the test cannot pass by emitting nothing at all.
	 *
	 * @return void
	 */
	public function test_header_top_never_emits_a_datalayer_name_that_is_not_a_js_identifier(): void {
		// Accepted by the 1.x sanitizer, stored verbatim by the migration.
		$container = $this->make_container(
			array( GTM4WP_OPTION_DATALAYER_NAME => 'my-layer' )
		);

		ob_start();
		$container->header_top();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'my-layer', $output, 'The unusable name must not reach the script in any position.' );
		$this->assertStringContainsString( 'var gtm4wp_datalayer_name = "dataLayer";', $output );
		$this->assertStringContainsString( 'var dataLayer = dataLayer || [];', $output );
	}

	/**
	 * The name is a string VALUE on this line, so it goes through the hex-flag
	 * encoder rather than esc_js() (RI-4 pile b, the #105/#110 shape). Output is
	 * byte-identical for any real name - which is the point: a value that cannot
	 * break out today must still be emitted by the encoder that would stop one.
	 *
	 * @return void
	 */
	public function test_header_top_emits_the_datalayer_name_literal_as_json(): void {
		$container = $this->make_container(
			array( GTM4WP_OPTION_DATALAYER_NAME => 'myDataLayer' )
		);

		ob_start();
		$container->header_top();
		$output = (string) ob_get_clean();

		// json_literal() supplies its own quotes; nothing wraps it.
		$this->assertStringContainsString(
			'var gtm4wp_datalayer_name = ' . wp_json_encode( 'myDataLayer', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
			$output
		);
	}

	public function test_header_top_renders_global_vars_from_filter(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY )
			->once()
			->andReturn(
				array(
					'stringVar' => 'text',
					'boolVar'   => true,
					'falseVar'  => false,
					'arrayVar'  => array( 1, 2 ),
					'nullVar'   => null,
					'zeroVar'   => 0,
					'floatVar'  => 1.5,
				)
			);

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		// #72: every branch of global_var_literal() now uses the same hex-flag JSON
		// encoder, so a string is a JSON literal ("double quotes") rather than the
		// 1.x esc_js single-quoted shape. The array branch had already broken that
		// parity, so there was no consistent shape left to preserve.
		$this->assertStringContainsString( 'const stringVar = "text";', $output );
		$this->assertStringContainsString( 'const boolVar = true;', $output );
		$this->assertStringContainsString( 'const falseVar = false;', $output );
		$this->assertStringContainsString( 'const arrayVar = [1,2];', $output );
		$this->assertStringContainsString( 'const nullVar = null;', $output );
		$this->assertStringContainsString( 'const zeroVar = 0;', $output );
		$this->assertStringContainsString( 'const floatVar = 1.5;', $output );
	}

	/**
	 * #72: the string branch used esc_js() - an HTML-attribute escaper - three lines
	 * below an array branch already using hex-flag JSON. A filter-supplied string
	 * therefore reached the integrator with `"`, `<` and `>` as &quot;/&lt;/&gt;
	 * TEXT, because a browser never HTML-decodes inside a <script> body, while the
	 * same value inside an array arrived as real characters. Never a break-out
	 * (esc_js backslashed the quotes) - a data-corruption class.
	 *
	 * Both directions: the hex escapes must be present AND the entity forms absent,
	 * so a relapse to esc_js fails rather than merely looking different.
	 */
	public function test_header_top_encodes_string_global_vars_like_the_array_branch(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY )
			->andReturn(
				array(
					'strVar' => 'A & B "q" <b>',
					'arrVar' => array( 'A & B "q" <b>' ),
				)
			);

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		// Hex-escaped, exactly as the array branch encodes the same characters.
		$this->assertStringContainsString( '"', $output );
		$this->assertStringContainsString( '<', $output );
		// ...and no HTML entity forms, which is what esc_js produced.
		$this->assertStringNotContainsString( '&quot;', $output );
		$this->assertStringNotContainsString( '&lt;', $output );

		// The two branches now agree on the encoding of the identical value.
		$this->assertSame(
			1,
			preg_match( '/const strVar = (".*?");/', $output, $str_match ),
			'The string global var must be rendered.'
		);
		$this->assertSame(
			1,
			preg_match( '/const arrVar = \[(".*?")\];/', $output, $arr_match ),
			'The array global var must be rendered.'
		);
		$this->assertSame(
			$arr_match[1],
			$str_match[1],
			'A string must encode identically whether it arrives bare or inside an array.'
		);
	}

	/**
	 * Regression: an earlier `empty( $v ) && 0 !== $v` test ran before the array
	 * and null branches and swallowed three distinct types into the JS literal
	 * `false` - which also made the trailing is_null() branch unreachable. Each
	 * type must now render as itself; asserted in both directions so a relapse
	 * into `false` fails rather than merely missing the right value.
	 */
	public function test_header_top_renders_falsy_global_vars_as_their_own_type(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY )
			->once()
			->andReturn(
				array(
					'nullVar'       => null,
					'emptyArrayVar' => array(),
					'floatZeroVar'  => 0.0,
					'emptyStrVar'   => '',
				)
			);

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'const nullVar = null;', $output );
		$this->assertStringNotContainsString( 'const nullVar = false;', $output );

		$this->assertStringContainsString( 'const emptyArrayVar = [];', $output );
		$this->assertStringNotContainsString( 'const emptyArrayVar = false;', $output );

		$this->assertStringContainsString( 'const floatZeroVar = 0;', $output );
		$this->assertStringNotContainsString( 'const floatZeroVar = false;', $output );

		$this->assertStringContainsString( 'const emptyStrVar = "";', $output );
		$this->assertStringNotContainsString( 'const emptyStrVar = false;', $output );
	}

	/**
	 * A filter-supplied name that is not a valid JavaScript identifier would emit
	 * a `const <junk> = ...` declaration and throw a SyntaxError that kills the
	 * whole head block - including the data layer initialization. Such entries are
	 * skipped; valid siblings in the same array must still be rendered.
	 */
	public function test_header_top_skips_global_vars_with_invalid_js_identifiers(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY )
			->once()
			->andReturn(
				array(
					"quo'te"     => 'bad',
					'has space'  => 'bad',
					'1leading'   => 'bad',
					'</script>'  => 'bad',
					'goodVar'    => 'kept',
					'_under$var' => 'kept',
				)
			);

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'const goodVar = "kept";', $output );
		$this->assertStringContainsString( 'const _under$var = "kept";', $output );

		$this->assertStringNotContainsString( 'bad', $output );
		$this->assertStringNotContainsString( 'has space', $output );
		$this->assertStringNotContainsString( '1leading', $output );
		// The data layer initialization the malformed declaration would have
		// broken is still intact.
		$this->assertStringContainsString( 'var dataLayer = dataLayer || [];', $output );
	}

	/**
	 * The value half of the same hazard (#85). wp_json_encode() returns false when it
	 * cannot encode a value at all - invalid UTF-8 is the reachable case, and the
	 * filter supplying these values is public, so the input is third-party. Casting
	 * that false to a string used to emit `const brokenVar = ;`, a SyntaxError that
	 * takes down the whole head block including the data layer initialization - the
	 * exact failure the identifier allow-list above exists to prevent, arriving
	 * through the value instead of the name.
	 */
	public function test_header_top_falls_back_to_null_when_a_global_var_cannot_be_encoded(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY )
			->once()
			->andReturn(
				array(
					// A lone continuation byte: not valid UTF-8, so json_encode() fails.
					'brokenVar' => "\xB1\x31",
					'goodVar'   => 'kept',
				)
			);

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'const brokenVar = null;', $output );
		$this->assertStringNotContainsString( 'const brokenVar = ;', $output );

		// The declaration after it, and the data layer initialization before it, both
		// survive: the failure is confined to the one variable that could not encode.
		$this->assertStringContainsString( 'const goodVar = "kept";', $output );
		$this->assertStringContainsString( 'var dataLayer = dataLayer || [];', $output );
	}

	public function test_header_top_suppressed_on_amp_requests(): void {
		Filters\expectApplied( ContainerCode::FILTER_AMP_RUNNING )
			->once()
			->andReturn( true );

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_header_top_returns_markup_when_echo_disabled(): void {
		// The $echo_output = false path (used by the AMP integration) returns the
		// markup instead of printing it.
		$container = $this->make_container( array( GTM4WP_OPTION_DATALAYER_NAME => 'customDL' ) );

		ob_start();
		$result = $container->header_top( false );
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed, 'With $echo_output = false nothing is printed.' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'var customDL = customDL || [];', $result );
		$this->assertStringContainsString( '<!-- End Google Tag Manager for WordPress by gtm4wp.com -->', $result );
	}

	public function test_header_top_appends_header_top_inline_js_filter(): void {
		// Modules (e.g. consent tool integrations) append their inline JS to the
		// data layer initialization through the FILTER_HEADER_TOP_JS filter.
		Filters\expectApplied( ContainerCode::FILTER_HEADER_TOP_JS )
			->once()
			->andReturn( "\n\tgtm4wp_consent_tool_ready();" );

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'gtm4wp_consent_tool_ready();', $output );
	}

	public function test_header_top_restores_ampersands_in_head_block_js(): void {
		// header_top must emit through ScriptTag::print_script_block(), which
		// restores the ampersand that wp_kses() entity-encodes. Model a realistic
		// wp_kses() that turns every bare & into &amp; (the real one does), so a
		// consent-tool integration appending JS with && and an &-joined loader URL
		// via FILTER_HEADER_TOP_JS survives. A raw wp_kses() echo without the
		// restore would leave &amp;&amp; / x=1&amp;y=2 and break the script.
		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- mock matches the real wp_kses() signature
				return str_replace( '&', '&amp;', (string) $content );
			}
		);

		Filters\expectApplied( ContainerCode::FILTER_HEADER_TOP_JS )
			->once()
			->andReturn( "\n\tif ( a && b ) { loadCmp('https://cmp.example/sdk?x=1&y=2'); }" );

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'if ( a && b )', $output );
		$this->assertStringContainsString( 'x=1&y=2', $output );
		$this->assertStringNotContainsString( '&amp;', $output );
	}

	public function test_body_open_outputs_iframe_for_body_placement(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_BODYOPEN,
			)
		);

		ob_start();
		$container->body_open();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ns.html?id=GTM-ABC123', $output );
	}

	public function test_body_open_outputs_nothing_for_footer_placement(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_FOOTER,
			)
		);

		ob_start();
		$container->body_open();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'The body-open hook stays silent when placement is Footer.' );
	}

	public function test_footer_outputs_iframe_for_footer_placement(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_FOOTER,
			)
		);

		ob_start();
		$container->footer();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ns.html?id=GTM-ABC123', $output );
	}

	public function test_footer_outputs_nothing_for_body_placement(): void {
		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE      => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_BODYOPEN,
			)
		);

		ob_start();
		$container->footer();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'The footer hook stays silent when placement is Body open.' );
	}

	/**
	 * Both halves of the mixed-content ampersand rule.
	 *
	 * This sink emits a block that MIXES markup with <script>, and the two contexts
	 * want opposite things from wp_kses()'s ampersand encoding. Model the real
	 * wp_kses() (every bare & becomes &amp;, which it does) and pin both halves:
	 *
	 * - the console warnings must come back with `&&` intact, or the block is a
	 *   SyntaxError and no warning is ever shown. This was live for the placement
	 *   OFF, kill-switch and excluded-user-role warnings; the sibling head sink has
	 *   had the restore since #29 and only this one was left behind.
	 * - the iframe src must KEEP `&amp;`, which is the correct spelling of a query
	 *   separator in an HTML attribute and what both 2.0 and 1.x have always
	 *   emitted. A blanket restore would have fixed the script by corrupting this.
	 */
	public function test_the_tag_restores_ampersands_in_scripts_but_not_in_the_iframe(): void {
		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) {
				return str_replace( '&', '&amp;', (string) $content );
			}
		);
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array( 'roles' => array( 'editor' ) )
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE         => 'GTM-ABC123',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => 'editor',
			)
		);

		ob_start();
		$container->the_tag();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'console.warn && console.warn(',
			$output,
			'The JavaScript AND operator must survive wp_kses() or the whole warning block is a SyntaxError.'
		);
		$this->assertStringNotContainsString(
			'&amp;&amp;',
			$output,
			'An entity-encoded && is the defect this guards against.'
		);
	}

	public function test_the_tag_keeps_the_entity_form_in_the_iframe_attribute(): void {
		// The other direction of the same rule: only <script> bodies get the
		// ampersand back. An environment-parameter iframe URL must stay &amp;-joined.
		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) {
				return str_replace( '&', '&amp;', (string) $content );
			}
		);

		$container = $this->make_container(
			array(
				GTM4WP_OPTION_GTM_CODE        => 'GTM-ABC123',
				GTM4WP_OPTION_ENV_GTM_AUTH    => 'authtoken',
				GTM4WP_OPTION_ENV_GTM_PREVIEW => 'env-2',
			)
		);

		ob_start();
		$container->the_tag();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ns.html?id=GTM-ABC123&amp;gtm_auth=authtoken', $output );
	}

	public function test_the_tag_wraps_get_tag_through_kses_with_iframe_rules(): void {
		$captured_rules = null;
		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) use ( &$captured_rules ) {
				$captured_rules = $allowed_html;
				return $content;
			}
		);

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		ob_start();
		$container->the_tag();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ns.html?id=GTM-ABC123', $output );
		$this->assertArrayHasKey( 'script', $captured_rules );
		$this->assertArrayHasKey( 'noscript', $captured_rules, 'the_tag() must allow the noscript wrapper through wp_kses().' );
		$this->assertArrayHasKey( 'iframe', $captured_rules, 'the_tag() must allow the container iframe through wp_kses().' );
	}

	/**
	 * The CSS allow-list is a WordPress-wide control: safe_style_css governs every
	 * wp_kses() and wp_kses_post() call in the request, not just ours. The iframe's
	 * style attribute needs two declarations that safecss_filter_attr() strips by
	 * default, so the allow-list is widened - but only around the sink that needs
	 * it, and put back immediately afterwards.
	 *
	 * Both directions matter and neither is sufficient alone: widened AT the
	 * sink (or the iframe loses its style attribute) and gone AFTER it (or every
	 * later sanitizer call in the request runs against the widened rule).
	 */
	public function test_the_tag_widens_the_css_allow_list_only_around_its_own_kses_call(): void {
		$widened_during_kses = null;
		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) use ( &$widened_during_kses ) {
				$widened_during_kses = has_filter(
					'safe_style_css',
					array( ContainerCode::class, 'allow_iframe_hiding_styles' )
				);

				return $content;
			}
		);

		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		ob_start();
		$container->the_tag();
		ob_get_clean();

		$this->assertNotFalse(
			$widened_during_kses,
			'The CSS allow-list must be widened while the container markup is sanitized, or the iframe loses display/visibility.'
		);
		$this->assertFalse(
			has_filter( 'safe_style_css', array( ContainerCode::class, 'allow_iframe_hiding_styles' ) ),
			'The widening must be removed once the container markup is printed - leaving it registered relaxes wp_kses() for the whole request.'
		);
	}

	public function test_register_hooks_does_not_widen_the_css_allow_list(): void {
		// The regression this pins: the widening used to be registered here, for
		// the lifetime of the request, on every non-admin request including REST.
		$container = $this->make_container( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );
		$container->register_hooks();

		// Deliberately asks "is ANY callback attached", not "is ours attached":
		// the code this replaces used an anonymous closure, which a
		// callback-specific assertion would not have seen.
		$this->assertFalse(
			has_filter( 'safe_style_css' ),
			'Registering hooks must not touch the site-wide CSS allow-list; only the_tag() may widen it, and only around its own sanitizer call.'
		);
	}

	public function test_allow_iframe_hiding_styles_extends_the_incoming_allow_list(): void {
		$widened = ContainerCode::allow_iframe_hiding_styles( array( 'color' ) );

		$this->assertContains( 'display', $widened );
		$this->assertContains( 'visibility', $widened );
		$this->assertContains( 'color', $widened, 'The callback must extend the allow-list it is given, never replace it.' );

		// A filter callback receives whatever the previous callback returned, which
		// is not guaranteed to be an array.
		$this->assertSame(
			array( 'display', 'visibility' ),
			ContainerCode::allow_iframe_hiding_styles( null )
		);
	}

	public function test_register_hooks_uses_early_priority_when_loadearly_enabled(): void {
		$container = $this->make_container( array( GTM4WP_OPTION_LOADEARLY => true ) );
		$container->register_hooks();

		$this->assertSame( 2, has_action( 'wp_head', array( $container, 'header_begin' ) ), 'Load-early moves header_begin to priority 2.' );
		$this->assertSame( 1, has_action( 'wp_head', array( $container, 'header_top' ) ) );
	}

	public function test_register_hooks_uses_default_priority_without_loadearly(): void {
		$container = $this->make_container( array( GTM4WP_OPTION_LOADEARLY => false ) );
		$container->register_hooks();

		$this->assertSame( 10, has_action( 'wp_head', array( $container, 'header_begin' ) ) );
		$this->assertNotFalse( has_action( 'wp_footer', array( $container, 'footer' ) ) );
	}

	public function test_rocket_excluded_inline_js_content_adds_gtm_patterns(): void {
		$container = $this->make_container();

		$this->assertSame(
			array( 'existing', 'dataLayer', 'gtm4wp' ),
			$container->rocket_excluded_inline_js_content( array( 'existing' ) )
		);
	}
}
