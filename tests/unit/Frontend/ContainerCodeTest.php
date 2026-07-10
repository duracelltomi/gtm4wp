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
					'arrayVar'  => array( 1, 2 ),
					'nullVar'   => null,
					'zeroVar'   => 0,
				)
			);

		$container = $this->make_container();

		ob_start();
		$container->header_top();
		$output = ob_get_clean();

		$this->assertStringContainsString( "const stringVar = 'text';", $output );
		$this->assertStringContainsString( 'const boolVar = true;', $output );
		$this->assertStringContainsString( 'const arrayVar = [1,2];', $output );
		// 1.x parity: null values hit the empty() branch before the is_null()
		// branch, so they render as false, not null.
		$this->assertStringContainsString( 'const nullVar = false;', $output );
		$this->assertStringContainsString( 'const zeroVar = 0;', $output );
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
}
