<?php
/**
 * Unit tests for the PageVariables module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\PageVariables\PageVariablesModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers key branches of the data layer population ported from
 * gtm4wp_add_basic_datalayer_data(), including the search term XSS
 * regression test absorbed from the 1.x test suite.
 */
final class PageVariablesModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();

		// All conditional tags default to false; individual tests override.
		foreach ( array(
			'is_singular',
			'is_archive',
			'is_post_type_archive',
			'is_search',
			'is_front_page',
			'is_home',
			'is_404',
			'is_category',
			'is_tag',
			'is_tax',
			'is_author',
			'is_year',
			'is_month',
			'is_day',
			'is_time',
			'is_date',
			'is_user_logged_in',
		) as $conditional ) {
			Functions\when( $conditional )->justReturn( false );
		}

		$GLOBALS['wp_query'] = (object) array(
			'post_count'  => 0,
			'found_posts' => 0,
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'], $GLOBALS['post'] );
		parent::tearDown();
	}

	/**
	 * Builds a booted module with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return PageVariablesModule
	 */
	private function make_module( array $stored = array() ): PageVariablesModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module  = new PageVariablesModule();
		$options = new Options( $module->defaults() );
		$module->frontend( $options );

		return $module;
	}

	public function test_search_page_data_with_escaped_search_term(): void {
		Functions\when( 'is_search' )->justReturn( true );

		// Mirrors WordPress get_search_query() default behavior: the search
		// term arrives esc_attr() escaped. Absorbed from the 1.x XSS test.
		$malicious_term = '<script>alert("xss")</script>';
		Functions\when( 'get_search_query' )->justReturn(
			htmlspecialchars( $malicious_term, ENT_QUOTES, 'UTF-8' )
		);

		$_SERVER['HTTP_REFERER'] = 'https://example.com/page/?param=value';
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$GLOBALS['wp_query']->post_count = 5;

		$module     = $this->make_module( array( GTM4WP_OPTION_INCLUDE_SEARCHDATA => true ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'search-results', $data_layer['pagePostType'] );
		$this->assertSame( 5, $data_layer['siteSearchResults'] );

		// The raw script tag must never appear in the data layer value.
		$this->assertStringNotContainsString( '<script>', $data_layer['siteSearchTerm'] );
		$this->assertSame( htmlspecialchars( $malicious_term, ENT_QUOTES, 'UTF-8' ), $data_layer['siteSearchTerm'] );

		// Referrer query strings are rawurlencoded as in 1.x.
		$this->assertSame( 'https://example.com/page/?param%3Dvalue', $data_layer['siteSearchFrom'] );

		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function test_singular_post_data(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_the_category' )->justReturn(
			array(
				(object) array( 'slug' => 'news' ),
				(object) array( 'slug' => 'tech' ),
			)
		);
		Functions\when( 'get_the_tags' )->justReturn(
			array( (object) array( 'slug' => 'gtm' ) )
		);
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'ID'           => 7,
				'display_name' => 'Author Name',
			)
		);
		Functions\when( 'get_the_ID' )->justReturn( 42 );

		$GLOBALS['post'] = (object) array(
			'post_author' => 7,
			'ID'          => 42,
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTID   => true,
				GTM4WP_OPTION_INCLUDE_AUTHORID => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'post', $data_layer['pagePostType'] );
		$this->assertSame( 'single-post', $data_layer['pagePostType2'] );
		$this->assertSame( array( 'news', 'tech' ), $data_layer['pageCategory'] );
		$this->assertSame( array( 'gtm' ), $data_layer['pageAttributes'] );
		$this->assertSame( 7, $data_layer['pagePostAuthorID'] );
		$this->assertSame( 'Author Name', $data_layer['pagePostAuthor'] );
		$this->assertSame( 42, $data_layer['postID'] );
	}

	public function test_404_page_type(): void {
		Functions\when( 'is_404' )->justReturn( true );

		$module     = $this->make_module();
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( '404-error', $data_layer['pagePostType'] );
	}

	public function test_logged_in_visitor_data(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'              => 3,
				'roles'           => array( 'editor' ),
				'user_email'      => 'user@example.com',
				'user_registered' => '2020-01-01 00:00:00',
				'user_login'      => 'editoruser',
			)
		);
		Functions\when( 'get_current_user_id' )->justReturn( 3 );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_LOGGEDIN  => true,
				GTM4WP_OPTION_INCLUDE_USERROLE  => true,
				GTM4WP_OPTION_INCLUDE_USEREMAIL => true,
				GTM4WP_OPTION_INCLUDE_USERID    => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'logged-in', $data_layer['visitorLoginState'] );
		$this->assertSame( 'editor', $data_layer['visitorType'] );
		$this->assertSame( 'user@example.com', $data_layer['visitorEmail'] );
		$this->assertSame( hash( 'sha256', 'user@example.com' ), $data_layer['visitorEmailHash'] );
		$this->assertSame( 3, $data_layer['visitorId'] );
	}

	public function test_cloudflare_country_code(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'HU';

		$module     = $this->make_module( array( GTM4WP_OPTION_INCLUDE_MISCGEOCF => true ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'HU', $data_layer['geoCloudflareCountryCode'] );

		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	public function test_disabled_options_produce_empty_data_layer(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE   => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES => false,
				GTM4WP_OPTION_INCLUDE_TAGS       => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR     => false,
			)
		);

		$this->assertSame( array(), $module->add_datalayer_data( array() ) );
	}
}
