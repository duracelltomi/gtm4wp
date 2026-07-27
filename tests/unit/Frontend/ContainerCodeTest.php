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

		$this->assertStringContainsString( "const stringVar = 'text';", $output );
		$this->assertStringContainsString( 'const boolVar = true;', $output );
		$this->assertStringContainsString( 'const falseVar = false;', $output );
		$this->assertStringContainsString( 'const arrayVar = [1,2];', $output );
		$this->assertStringContainsString( 'const nullVar = null;', $output );
		$this->assertStringContainsString( 'const zeroVar = 0;', $output );
		$this->assertStringContainsString( 'const floatVar = 1.5;', $output );
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

		$this->assertStringContainsString( "const emptyStrVar = '';", $output );
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

		$this->assertStringContainsString( "const goodVar = 'kept';", $output );
		$this->assertStringContainsString( "const _under\$var = 'kept';", $output );

		$this->assertStringNotContainsString( 'bad', $output );
		$this->assertStringNotContainsString( 'has space', $output );
		$this->assertStringNotContainsString( '1leading', $output );
		// The data layer initialization the malformed declaration would have
		// broken is still intact.
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
		// The $echo = false path (used by the AMP integration) returns the
		// markup instead of printing it.
		$container = $this->make_container( array( GTM4WP_OPTION_DATALAYER_NAME => 'customDL' ) );

		ob_start();
		$result = $container->header_top( false );
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed, 'With $echo = false nothing is printed.' );
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
			static function ( $content, $allowed_html ) {
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
