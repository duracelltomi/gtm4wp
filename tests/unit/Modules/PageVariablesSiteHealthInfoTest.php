<?php
/**
 * Unit tests for the Page variables module's Site Health rows.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\PageVariables\PageVariablesModule;

/**
 * Every checkbox as a state, the meta keys as a count, the visitor-IP setup
 * as a sentence - and never the header name or the proxy list, which
 * describe the site's infrastructure.
 */
final class PageVariablesSiteHealthInfoTest extends ModuleSiteHealthTestCase {

	public function test_every_switch_is_listed_and_the_text_options_are_not(): void {
		$rows = $this->rows( new PageVariablesModule(), array( GTM4WP_OPTION_INCLUDE_POSTID => true ) );

		$this->assertSame( array( 'variables', 'post_meta_keys', 'visitor_ip' ), array_keys( $rows ) );
		$this->assertSame( 'on', $rows['variables']['debug'][ GTM4WP_OPTION_INCLUDE_POSTID ] );
		$this->assertSame( 'off', $rows['variables']['debug'][ GTM4WP_OPTION_INCLUDE_POSTTITLE ] );
		$this->assertSame( 'on', $rows['variables']['debug'][ GTM4WP_OPTION_INCLUDE_POSTTYPE ], 'A default-on variable reads on.' );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS, $rows['variables']['debug'] );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER, $rows['variables']['debug'] );
		$this->assertSame( '0', $rows['post_meta_keys']['debug'] );
		$this->assertSame( 'off', $rows['visitor_ip']['debug'] );
	}

	public function test_meta_keys_are_counted_never_named(): void {
		$rows = $this->rows( new PageVariablesModule(), array( GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS => "_internal_secret_key\npublic_key" ) );

		$this->assertSame( '2', $rows['post_meta_keys']['debug'] );
		$this->assertStringNotContainsString( '_internal_secret_key', $this->text( $rows ) );
	}

	public function test_a_custom_header_is_reported_by_its_proxy_count_never_by_name_or_list(): void {
		$rows = $this->rows(
			new PageVariablesModule(),
			array(
				GTM4WP_OPTION_INCLUDE_VISITOR_IP         => true,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_SECRET_EDGE_IP',
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => "10.0.0.1\n192.168.1.0/24",
			)
		);

		$this->assertSame( 'on, custom header, 2 trusted proxies', $rows['visitor_ip']['debug'] );
		$text = $this->text( $rows );
		$this->assertStringNotContainsString( 'SECRET_EDGE', $text );
		$this->assertStringNotContainsString( '10.0.0.1', $text );
		$this->assertStringNotContainsString( '192.168', $text );
	}

	public function test_the_default_header_is_named_as_such(): void {
		$rows = $this->rows( new PageVariablesModule(), array( GTM4WP_OPTION_INCLUDE_VISITOR_IP => true ) );

		$this->assertSame( 'on, default header', $rows['visitor_ip']['debug'] );
	}
}
