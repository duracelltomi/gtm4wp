<?php
/**
 * Unit tests for the PageVariables module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\PageVariables\PageVariablesModule;
use GTM4WP\Modules\VisitorData\VisitorDataModule;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers key branches of the data layer population ported from
 * gtm4wp_add_basic_datalayer_data(), including the search term XSS
 * regression test absorbed from the 1.x test suite.
 */
final class PageVariablesModuleTest extends TestCase {

	/**
	 * Snapshot of $_SERVER, restored in tearDown so header-reading tests do
	 * not leak state into siblings even if an assertion fails early (TS-7).
	 *
	 * @var array<string, mixed>
	 */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();

		$this->server_backup = $_SERVER;

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
		$_SERVER = $this->server_backup;
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

	/**
	 * Issue #220: with the "include parent categories" option ON, each of the
	 * post's category slugs is followed by its ancestor slugs (get_ancestors()
	 * order: immediate parent first, up to the top-level category).
	 */
	public function test_parent_categories_included_on_single_post_when_enabled(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_category' )->justReturn(
			array(
				(object) array(
					'slug'    => 'child',
					'term_id' => 30,
				),
			)
		);
		// Ancestry chain: child (term 30), parent (term 20), grandparent (term 10).
		Functions\when( 'get_ancestors' )->alias(
			static fn ( int $term_id, string $taxonomy ): array =>
				( 30 === $term_id && 'category' === $taxonomy ) ? array( 20, 10 ) : array()
		);
		Functions\when( 'get_term' )->alias(
			static function ( int $term_id ) {
				$slugs = array(
					20 => 'parent',
					10 => 'grandparent',
				);
				return new \WP_Term(
					array(
						'term_id' => $term_id,
						'slug'    => $slugs[ $term_id ] ?? 'unknown',
					)
				);
			}
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE         => false,
				GTM4WP_OPTION_INCLUDE_TAGS             => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR           => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES       => true,
				GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'child', 'parent', 'grandparent' ), $data_layer['pageCategory'] );
	}

	/**
	 * Issue #220 negative case: with the option OFF (the default) the ancestor
	 * slugs must NOT appear - the output stays exactly the immediate category
	 * slugs, as in 1.x. get_ancestors() is stubbed to return parents anyway to
	 * prove the code path is not even consulted when the option is off.
	 */
	public function test_parent_categories_excluded_on_single_post_when_disabled(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_category' )->justReturn(
			array(
				(object) array(
					'slug'    => 'child',
					'term_id' => 30,
				),
			)
		);
		Functions\when( 'get_ancestors' )->justReturn( array( 20 ) );
		Functions\when( 'get_term' )->justReturn(
			new \WP_Term(
				array(
					'term_id' => 20,
					'slug'    => 'parent',
				)
			)
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE         => false,
				GTM4WP_OPTION_INCLUDE_TAGS             => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR           => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES       => true,
				GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES => false,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'child' ), $data_layer['pageCategory'] );
		$this->assertNotContains( 'parent', $data_layer['pageCategory'] );
	}

	/**
	 * Issue #220: two categories that share the same ancestor must not repeat
	 * the shared parent slug - the list is de-duplicated while keeping order.
	 */
	public function test_parent_categories_deduped_when_categories_share_ancestor(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_category' )->justReturn(
			array(
				(object) array(
					'slug'    => 'news',
					'term_id' => 30,
				),
				(object) array(
					'slug'    => 'tech',
					'term_id' => 31,
				),
			)
		);
		// Both categories share the top-level "blog" (term 10) parent.
		Functions\when( 'get_ancestors' )->justReturn( array( 10 ) );
		Functions\when( 'get_term' )->justReturn(
			new \WP_Term(
				array(
					'term_id' => 10,
					'slug'    => 'blog',
				)
			)
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE         => false,
				GTM4WP_OPTION_INCLUDE_TAGS             => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR           => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES       => true,
				GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		// "blog" appears once even though both news and tech report it.
		$this->assertSame( array( 'news', 'blog', 'tech' ), $data_layer['pageCategory'] );
	}

	/**
	 * Issue #220: the parent-category inclusion also applies to the second
	 * pageCategory build site - the category / taxonomy archive path.
	 */
	public function test_parent_categories_included_on_category_archive_when_enabled(): void {
		Functions\when( 'is_archive' )->justReturn( true );
		Functions\when( 'is_category' )->justReturn( true );
		Functions\when( 'get_the_category' )->justReturn(
			array(
				(object) array(
					'slug'    => 'child',
					'term_id' => 30,
				),
			)
		);
		Functions\when( 'get_ancestors' )->justReturn( array( 20 ) );
		Functions\when( 'get_term' )->justReturn(
			new \WP_Term(
				array(
					'term_id' => 20,
					'slug'    => 'parent',
				)
			)
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE         => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES       => true,
				GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'child', 'parent' ), $data_layer['pageCategory'] );
	}

	public function test_content_word_count_and_reading_time(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_post_field' )->justReturn( implode( ' ', array_fill( 0, 450, 'word' ) ) );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE         => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES       => false,
				GTM4WP_OPTION_INCLUDE_TAGS             => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR           => false,
				GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT => true,
				GTM4WP_OPTION_INCLUDE_READINGTIME      => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 450, $data_layer['pageContentWordCount'] );
		// 450 words / 200 wpm rounds up to 3 minutes.
		$this->assertSame( 3, $data_layer['pageReadingTime'] );
	}

	public function test_modified_date_family(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_modified_date' )->alias(
			static fn ( string $format = '' ): string => 'MOD:' . $format
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE     => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES   => false,
				GTM4WP_OPTION_INCLUDE_TAGS         => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR       => false,
				GTM4WP_OPTION_INCLUDE_MODIFIEDDATE => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'MOD:', $data_layer['pageModifiedDate'] );
		$this->assertSame( 'MOD:Y', $data_layer['pageModifiedDateYear'] );
		$this->assertSame( 'MOD:c', $data_layer['pageModifiedDateIso'] );
		$this->assertSame( 'MOD:U', $data_layer['pageModifiedDateUnix'] );
	}

	public function test_content_age_in_days(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_time' )->justReturn( time() - ( 5 * DAY_IN_SECONDS ) );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE   => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES => false,
				GTM4WP_OPTION_INCLUDE_TAGS       => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR     => false,
				GTM4WP_OPTION_INCLUDE_CONTENTAGE => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 5, $data_layer['pageContentAgeDays'] );
	}

	public function test_comment_count_and_status(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_comments_number' )->justReturn( '5' );
		Functions\when( 'comments_open' )->justReturn( true );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE     => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES   => false,
				GTM4WP_OPTION_INCLUDE_TAGS         => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR       => false,
				GTM4WP_OPTION_INCLUDE_COMMENTCOUNT => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 5, $data_layer['pageCommentCount'] );
		$this->assertSame( 'open', $data_layer['pageCommentStatus'] );
	}

	public function test_layout_and_structure_variables(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_page_template_slug' )->justReturn( 'template-landing.php' );
		Functions\when( 'has_post_thumbnail' )->justReturn( true );
		Functions\when( 'get_post_ancestors' )->justReturn( array( 12, 5 ) );
		Functions\when( 'is_sticky' )->justReturn( true );

		$GLOBALS['post'] = (object) array(
			'ID'          => 42,
			'post_parent' => 12,
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE      => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES    => false,
				GTM4WP_OPTION_INCLUDE_TAGS          => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR        => false,
				GTM4WP_OPTION_INCLUDE_PAGETEMPLATE  => true,
				GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE => true,
				GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY => true,
				GTM4WP_OPTION_INCLUDE_POSTSTICKY    => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'template-landing.php', $data_layer['pageTemplate'] );
		$this->assertTrue( $data_layer['pageHasFeaturedImage'] );
		$this->assertSame( 12, $data_layer['pageParentID'] );
		$this->assertSame( 2, $data_layer['pageDepth'] );
		$this->assertTrue( $data_layer['pagePostSticky'] );
	}

	public function test_page_template_defaults_when_empty(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_page_template_slug' )->justReturn( '' );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE     => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES   => false,
				GTM4WP_OPTION_INCLUDE_TAGS         => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR       => false,
				GTM4WP_OPTION_INCLUDE_PAGETEMPLATE => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'default', $data_layer['pageTemplate'] );
	}

	public function test_primary_category_from_yoast_meta(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) {
				return '_yoast_wpseo_primary_category' === $key ? '9' : '';
			}
		);
		Functions\when( 'get_term' )->justReturn(
			new \WP_Term(
				array(
					'term_id' => 9,
					'slug'    => 'guides',
					'name'    => 'Guides',
				)
			)
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE        => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES      => false,
				GTM4WP_OPTION_INCLUDE_TAGS            => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR          => false,
				GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'guides', $data_layer['pagePrimaryCategory'] );
		$this->assertSame( 'Guides', $data_layer['pagePrimaryCategoryName'] );
	}

	public function test_primary_category_falls_back_to_first_category(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn(
			array( (object) array( 'term_id' => 3 ) )
		);
		Functions\when( 'get_term' )->justReturn(
			new \WP_Term(
				array(
					'term_id' => 3,
					'slug'    => 'news',
					'name'    => 'News',
				)
			)
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE        => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES      => false,
				GTM4WP_OPTION_INCLUDE_TAGS            => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR          => false,
				GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'news', $data_layer['pagePrimaryCategory'] );
		$this->assertSame( 'News', $data_layer['pagePrimaryCategoryName'] );
	}

	public function test_page_language_falls_back_to_site_locale(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$module = $this->make_module( array( GTM4WP_OPTION_INCLUDE_PAGELANGUAGE => true ) );

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'en_US', $data_layer['pageLanguage'] );
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

	/**
	 * Regression for review finding #12 (RI-4): the spoofable HTTP_CF_IPCOUNTRY
	 * value must reach the data layer RAW so the single output sink
	 * (wp_json_encode with the full hex flag set) can escape it for the inline
	 * script. Re-introducing an esc_js()/esc_attr() pre-escape here would
	 * corrupt the value (& -> &amp;, " -> &quot;, < -> &lt;) — this test fails
	 * if that regression returns. The XSS itself is guarded at the sink
	 * (ContainerCodeTest / DataLayerTest); here we pin the "arrives raw" contract.
	 */
	public function test_cloudflare_country_code_reaches_data_layer_without_entity_escaping(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// A spoofed, hostile country header carrying every break-out character.
		$hostile = 'A&"<B';

		$_SERVER['HTTP_CF_IPCOUNTRY'] = $hostile;

		$module     = $this->make_module( array( GTM4WP_OPTION_INCLUDE_MISCGEOCF => true ) );
		$data_layer = $module->add_datalayer_data( array() );

		// Present: the raw value, byte-for-byte.
		$this->assertSame( $hostile, $data_layer['geoCloudflareCountryCode'] );
		// Absent: any HTML-entity encoding an esc_js()/esc_attr() pre-escape would add.
		$this->assertStringNotContainsString( '&amp;', $data_layer['geoCloudflareCountryCode'] );
		$this->assertStringNotContainsString( '&quot;', $data_layer['geoCloudflareCountryCode'] );
		$this->assertStringNotContainsString( '&lt;', $data_layer['geoCloudflareCountryCode'] );

		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	/**
	 * The GTM4WP_OPTION_INCLUDE_VISITOR_IP branch was previously untested at the
	 * module level (VisitorIp::get() is covered in isolation by VisitorIpTest,
	 * but its integration into the data layer was not). Proves the branch wires
	 * the validated IP into visitorIP raw (finding #12 sibling; TS-10 branch).
	 */
	public function test_visitor_ip_added_to_data_layer(): void {
		Functions\when( 'wp_unslash' )->returnArg();

		// A public, non-reserved IP passes VisitorIp::get()'s FILTER_VALIDATE_IP.
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';

		$module     = $this->make_module( array( GTM4WP_OPTION_INCLUDE_VISITOR_IP => true ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( '8.8.8.8', $data_layer['visitorIP'] );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_visitor_ip_absent_when_option_disabled(): void {
		Functions\when( 'wp_unslash' )->returnArg();

		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';

		$module     = $this->make_module( array( GTM4WP_OPTION_INCLUDE_VISITOR_IP => false ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'visitorIP', $data_layer );

		unset( $_SERVER['REMOTE_ADDR'] );
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

	/**
	 * Stored options that turn on every visitor/session field this test class
	 * exercises for the cache-safe on/off pair, plus the given cache-safe flag.
	 *
	 * @param bool $cache_safe Whether the cache-safe data layer is enabled.
	 * @return array<string, mixed>
	 */
	private function all_visitor_fields_on( bool $cache_safe ): array {
		return array(
			GTM4WP_OPTION_CACHE_SAFE_DATALAYER => $cache_safe,
			GTM4WP_OPTION_INCLUDE_LOGGEDIN     => true,
			GTM4WP_OPTION_INCLUDE_USERROLE     => true,
			GTM4WP_OPTION_INCLUDE_USEREMAIL    => true,
			GTM4WP_OPTION_INCLUDE_USERID       => true,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP   => true,
			GTM4WP_OPTION_INCLUDE_MISCGEOCF    => true,
			GTM4WP_OPTION_INCLUDE_SEARCHDATA   => true,
		);
	}

	/**
	 * Puts the request into a state where every gated visitor field WOULD be
	 * populated (search page, logged-in user, spoofable IP/referrer/CF headers),
	 * so a cache-safe test proves the gate suppresses them rather than the data
	 * merely being absent. The header values carry break-out characters so the
	 * "nothing leaks" assertion is meaningful (TC-5 hostile input).
	 *
	 * @return void
	 */
	private function arrange_hostile_visitor_request(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'get_search_query' )->justReturn( 'SEARCH_MARKER"&<x' );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'              => 3,
				'roles'           => array( 'administrator' ),
				'user_email'      => 'editor@example.com',
				'user_registered' => '2020-01-01 00:00:00',
				'user_login'      => 'EDITOR_MARKER',
			)
		);
		Functions\when( 'get_current_user_id' )->justReturn( 3 );

		$_SERVER['REMOTE_ADDR']       = '8.8.8.8';
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'CF_MARKER"&<x';
		$_SERVER['HTTP_REFERER']      = 'https://evil.example/?REF_MARKER="><script>';

		$GLOBALS['wp_query']->post_count = 5;
	}

	/**
	 * Issue #398 (1b): with the cache-safe data layer ON, none of the visitor /
	 * session specific fields — logged-in user data, visitor IP, Cloudflare
	 * country, and the server copies of the search term / referrer — may be
	 * rendered into the (cacheable) HTML. The URL-scoped search-results count and
	 * page type stay. A hostile IP/referrer/CF header proves nothing leaks.
	 */
	public function test_cache_safe_on_omits_all_visitor_and_pii_fields(): void {
		$this->arrange_hostile_visitor_request();

		$module     = $this->make_module( $this->all_visitor_fields_on( true ) );
		$data_layer = $module->add_datalayer_data( array() );

		// URL/content-scoped search data is still safe to cache.
		$this->assertSame( 'search-results', $data_layer['pagePostType'] );
		$this->assertSame( 5, $data_layer['siteSearchResults'] );

		// Every visitor/session field is withheld from the cacheable HTML.
		foreach (
			array(
				'visitorLoginState',
				'visitorType',
				'visitorEmail',
				'visitorEmailHash',
				'visitorRegistrationDate',
				'visitorUsername',
				'visitorId',
				'visitorIP',
				'geoCloudflareCountryCode',
				'siteSearchTerm',
				'siteSearchFrom',
			) as $withheld
		) {
			$this->assertArrayNotHasKey( $withheld, $data_layer, "'{$withheld}' must not be rendered into cacheable HTML when cache-safe mode is on." );
		}

		// Nothing visitor-specific leaks anywhere in the compiled data layer, not
		// even a spoofed header value hiding inside another field.
		$serialized = wp_json_encode( $data_layer );
		$this->assertStringNotContainsString( 'SEARCH_MARKER', $serialized );
		$this->assertStringNotContainsString( 'EDITOR_MARKER', $serialized );
		$this->assertStringNotContainsString( 'editor@example.com', $serialized );
		$this->assertStringNotContainsString( 'CF_MARKER', $serialized );
		$this->assertStringNotContainsString( 'REF_MARKER', $serialized );
		$this->assertStringNotContainsString( '8.8.8.8', $serialized );
	}

	/**
	 * Issue #398 negative case: with the cache-safe data layer OFF (the default),
	 * every server-rendered visitor field is produced exactly as before, so
	 * turning the mode off restores today's behavior with no change.
	 */
	public function test_cache_safe_off_keeps_rendering_visitor_fields(): void {
		$this->arrange_hostile_visitor_request();

		$module     = $this->make_module( $this->all_visitor_fields_on( false ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'logged-in', $data_layer['visitorLoginState'] );
		$this->assertSame( 'administrator', $data_layer['visitorType'] );
		$this->assertSame( 'editor@example.com', $data_layer['visitorEmail'] );
		$this->assertSame( 3, $data_layer['visitorId'] );
		$this->assertSame( '8.8.8.8', $data_layer['visitorIP'] );
		// The header values still reach the data layer raw (the sink escapes them).
		$this->assertSame( 'CF_MARKER"&<x', $data_layer['geoCloudflareCountryCode'] );
		$this->assertSame( 'SEARCH_MARKER"&<x', $data_layer['siteSearchTerm'] );
		$this->assertArrayHasKey( 'siteSearchFrom', $data_layer );
	}

	/**
	 * The Tier 1 search fields are handed to the client runtime (under their
	 * existing data layer names) on a search page when cache-safe mode is on.
	 */
	public function test_declare_visitor_scoped_fields_moves_search_fields_client_side(): void {
		Functions\when( 'is_search' )->justReturn( true );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
				GTM4WP_OPTION_INCLUDE_SEARCHDATA   => true,
			)
		);

		$fields = $module->declare_visitor_scoped_fields( array() );

		$this->assertCount( 2, $fields );
		$this->assertContainsOnlyInstancesOf( VisitorField::class, $fields );

		$map = array();
		foreach ( $fields as $field ) {
			$this->assertSame( VisitorField::TIER_CLIENT, $field->tier );
			$map[ $field->key ] = $field->client_source;
		}

		$this->assertSame(
			array(
				'siteSearchTerm' => 'searchTerm',
				'siteSearchFrom' => 'searchReferrer',
			),
			$map
		);
	}

	/**
	 * Issue #398 Phase 2: with cache-safe on, the server-only visitor fields (IP,
	 * Cloudflare country, logged-in user data) are declared independent of the page
	 * type — the session endpoint resolves them for the current request — each with
	 * the right tier, cookie gate and a callable resolver. Off a search page, so the
	 * two Tier 1 search fields are NOT among them.
	 */
	public function test_declare_visitor_scoped_fields_registers_tier2_3_server_fields(): void {
		Functions\when( 'is_search' )->justReturn( false );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP   => true,
				GTM4WP_OPTION_INCLUDE_MISCGEOCF    => true,
				GTM4WP_OPTION_INCLUDE_LOGGEDIN     => true,
				GTM4WP_OPTION_INCLUDE_USERROLE     => true,
				GTM4WP_OPTION_INCLUDE_USEREMAIL    => true,
				GTM4WP_OPTION_INCLUDE_USERID       => true,
			)
		);

		$fields = $module->declare_visitor_scoped_fields( array() );

		$by_key = array();
		foreach ( $fields as $field ) {
			$this->assertInstanceOf( VisitorField::class, $field );
			$by_key[ $field->key ] = $field;
		}

		// Tier 2: server-only, constant per session, no cookie gate (once/session).
		$this->assertSame( VisitorField::TIER_SESSION, $by_key['visitorIP']->tier );
		$this->assertSame( '', $by_key['visitorIP']->cookie_gate );
		$this->assertTrue( is_callable( $by_key['visitorIP']->resolver ) );
		$this->assertSame( VisitorField::TIER_SESSION, $by_key['geoCloudflareCountryCode']->tier );

		// Tier 3: logged-in user data, gated on the login companion cookie.
		foreach ( array( 'visitorLoginState', 'visitorType', 'visitorEmail', 'visitorEmailHash', 'visitorId' ) as $key ) {
			$this->assertArrayHasKey( $key, $by_key, "'{$key}' must be declared." );
			$this->assertSame( VisitorField::TIER_ACTION, $by_key[ $key ]->tier );
			$this->assertSame( VisitorDataModule::LOGIN_GATE_COOKIE, $by_key[ $key ]->cookie_gate );
			$this->assertTrue( is_callable( $by_key[ $key ]->resolver ) );
		}

		// Tier 1 search fields are only declared on a search page.
		$this->assertArrayNotHasKey( 'siteSearchTerm', $by_key );
	}

	public function test_declare_visitor_scoped_fields_skips_server_fields_when_options_off(): void {
		Functions\when( 'is_search' )->justReturn( false );

		// Cache-safe on, but every visitor option off (defaults): no server field.
		$module = $this->make_module( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );

		$this->assertSame( array(), $module->declare_visitor_scoped_fields( array() ) );
	}

	public function test_visitor_ip_resolver_returns_validated_ip_or_null(): void {
		Functions\when( 'wp_unslash' )->returnArg();

		$module = $this->make_module( array( GTM4WP_OPTION_INCLUDE_VISITOR_IP => true ) );

		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		$this->assertSame( '8.8.8.8', $module->resolve_visitor_ip() );

		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertNull( $module->resolve_visitor_ip(), 'No IP available resolves to null (omitted).' );
	}

	/**
	 * The Cloudflare-country resolver mirrors the server path (RI-4): the spoofable
	 * header value arrives RAW so the endpoint's single output sink hex-encodes it.
	 * A hostile country must not be entity-escaped by the resolver.
	 */
	public function test_cloudflare_country_resolver_returns_raw_value(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$module = $this->make_module( array( GTM4WP_OPTION_INCLUDE_MISCGEOCF => true ) );

		$hostile                      = 'A&"<B';
		$_SERVER['HTTP_CF_IPCOUNTRY'] = $hostile;

		$value = $module->resolve_cloudflare_country();

		$this->assertSame( $hostile, $value );
		$this->assertStringNotContainsString( '&amp;', (string) $value );
		$this->assertStringNotContainsString( '&quot;', (string) $value );

		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
		$this->assertNull( $module->resolve_cloudflare_country(), 'Absent header resolves to null (omitted).' );
	}

	/**
	 * The identity gate: every logged-in-user resolver returns null for an
	 * anonymous request, so the session endpoint omits it — a logged-out caller
	 * receives no user data.
	 */
	public function test_user_resolvers_return_null_for_anonymous_request(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$module = $this->make_module();

		$this->assertNull( $module->resolve_visitor_login_state() );
		$this->assertNull( $module->resolve_visitor_type() );
		$this->assertNull( $module->resolve_visitor_email() );
		$this->assertNull( $module->resolve_visitor_email_hash() );
		$this->assertNull( $module->resolve_visitor_registration_date() );
		$this->assertNull( $module->resolve_visitor_username() );
		$this->assertNull( $module->resolve_visitor_id() );
	}

	public function test_user_resolvers_return_values_for_logged_in_request(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'              => 3,
				'roles'           => array( 'editor', 'shop_manager' ),
				'user_email'      => 'user@example.com',
				'user_registered' => '2020-01-01 00:00:00',
				'user_login'      => 'editoruser',
			)
		);

		$module = $this->make_module();

		$this->assertSame( 'logged-in', $module->resolve_visitor_login_state() );
		$this->assertSame( 'editor,shop_manager', $module->resolve_visitor_type() );
		$this->assertSame( 'user@example.com', $module->resolve_visitor_email() );
		$this->assertSame( hash( 'sha256', 'user@example.com' ), $module->resolve_visitor_email_hash() );
		$this->assertSame( strtotime( '2020-01-01 00:00:00' ), $module->resolve_visitor_registration_date() );
		$this->assertSame( 'editoruser', $module->resolve_visitor_username() );
		$this->assertSame( 3, $module->resolve_visitor_id() );
	}

	public function test_declare_visitor_scoped_fields_noop_when_cache_safe_off(): void {
		Functions\when( 'is_search' )->justReturn( true );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false,
				GTM4WP_OPTION_INCLUDE_SEARCHDATA   => true,
			)
		);

		$this->assertSame( array(), $module->declare_visitor_scoped_fields( array() ) );
	}

	public function test_declare_visitor_scoped_fields_noop_off_a_search_page(): void {
		// Cache-safe on and search data on, but not a search results page: the two
		// search fields are only output on is_search(), so nothing is declared.
		Functions\when( 'is_search' )->justReturn( false );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
				GTM4WP_OPTION_INCLUDE_SEARCHDATA   => true,
			)
		);

		$this->assertSame( array(), $module->declare_visitor_scoped_fields( array() ) );
	}

	/**
	 * PublishPress Authors (issue #258): a post with multiple authors emits
	 * pagePostAuthors (names) and pagePostAuthorIDs (IDs) arrays, while the
	 * back-compat single-value vars stay and point at the primary/first author.
	 *
	 * NOTE: stubbing get_multiple_authors() defines the function process-wide,
	 * so function_exists() reports it thereafter — these two PublishPress tests
	 * are the LAST in the class so no single-author test runs after them (the
	 * only consumer of that gate is this module). See the single-author
	 * fallback test below for the count() === 1 branch.
	 */
	public function test_multiple_authors_output_as_arrays_for_publishpress(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		// PublishPress Author objects expose ->display_name and ->ID (a positive
		// WordPress user id, or a negative term id for a guest author). Passed
		// raw so the data layer sink escapes them once and correctly.
		Functions\when( 'get_multiple_authors' )->justReturn(
			array(
				(object) array(
					'ID'           => 7,
					'display_name' => 'Jane Writer',
				),
				(object) array(
					'ID'           => -12,
					'display_name' => 'Guest & <b>Co</b>',
				),
			)
		);

		$GLOBALS['post'] = (object) array(
			'post_author' => 7,
			'ID'          => 42,
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE   => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES => false,
				GTM4WP_OPTION_INCLUDE_TAGS       => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR     => true,
				GTM4WP_OPTION_INCLUDE_AUTHORID   => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		// Every author is present in the array variables, values raw (the sink
		// hex-encodes them; a re-introduced esc_* pre-escape would corrupt them).
		$this->assertSame( array( 'Jane Writer', 'Guest & <b>Co</b>' ), $data_layer['pagePostAuthors'] );
		$this->assertSame( array( 7, -12 ), $data_layer['pagePostAuthorIDs'] );
		$this->assertStringNotContainsString( '&amp;', $data_layer['pagePostAuthors'][1] );
		$this->assertStringNotContainsString( '&lt;', $data_layer['pagePostAuthors'][1] );

		// Back-compat single-value vars stay, set to the primary/first author.
		$this->assertSame( 'Jane Writer', $data_layer['pagePostAuthor'] );
		$this->assertSame( 7, $data_layer['pagePostAuthorID'] );
	}

	/**
	 * Single-author sourcing (issue #258 / finding #37): when PublishPress reports
	 * exactly ONE author, the single-value vars come from that author - crucially a
	 * GUEST author, whose name is not the WordPress user in $post->post_author that
	 * get_userdata() would return - and neither array variable appears. Before the fix
	 * a single author fell through to get_userdata(), giving the wrong name for a guest.
	 */
	public function test_single_publishpress_author_is_sourced_from_that_author_not_get_userdata(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		Functions\when( 'get_multiple_authors' )->justReturn(
			array(
				(object) array(
					'ID'           => -5,
					'display_name' => 'Guest Writer',
				),
			)
		);
		// The creating WordPress user is a different person; the pre-fix code emitted
		// THIS name/id for a single author, which is wrong for a guest author.
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'ID'           => 7,
				'display_name' => 'Creating User',
			)
		);

		$GLOBALS['post'] = (object) array(
			'post_author' => 7,
			'ID'          => 42,
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE   => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES => false,
				GTM4WP_OPTION_INCLUDE_TAGS       => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR     => true,
				GTM4WP_OPTION_INCLUDE_AUTHORID   => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'Guest Writer', $data_layer['pagePostAuthor'], 'The single PublishPress author (a guest) is used, not the creating WP user.' );
		$this->assertSame( -5, $data_layer['pagePostAuthorID'] );
		$this->assertArrayNotHasKey( 'pagePostAuthors', $data_layer, 'No array vars appear for a single author.' );
		$this->assertArrayNotHasKey( 'pagePostAuthorIDs', $data_layer );
	}

	/**
	 * Fallback (finding #37): when PublishPress is active but reports NO authors
	 * (count 0), the plain single-value vars come from get_userdata( post_author ) -
	 * the unchanged behavior, still exercised via the else branch.
	 */
	public function test_no_publishpress_authors_falls_back_to_get_userdata(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_multiple_authors' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'ID'           => 7,
				'display_name' => 'Creating User',
			)
		);

		$GLOBALS['post'] = (object) array(
			'post_author' => 7,
			'ID'          => 42,
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE   => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES => false,
				GTM4WP_OPTION_INCLUDE_TAGS       => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR     => true,
				GTM4WP_OPTION_INCLUDE_AUTHORID   => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'Creating User', $data_layer['pagePostAuthor'] );
		$this->assertSame( 7, $data_layer['pagePostAuthorID'] );
		$this->assertArrayNotHasKey( 'pagePostAuthors', $data_layer );
		$this->assertArrayNotHasKey( 'pagePostAuthorIDs', $data_layer );
	}

	/**
	 * #43: a real PublishPress Author resolves display_name/ID through __get() and
	 * may not implement __isset(). The old isset()-based read would report false and
	 * blank every author; read_author_prop() must still read the value through
	 * __get(). This stub mirrors that object shape (magic __get, NO __isset).
	 */
	public function test_publishpress_author_value_is_read_through_magic_get_without_isset(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		$magic_author = new class() {
			/**
			 * Backing store the magic getter reads (not exposed as real properties).
			 *
			 * @var array<string, mixed>
			 */
			private array $data = array(
				'display_name' => 'Magic Guest',
				'ID'           => -9,
			);

			public function __get( $name ) {
				return $this->data[ $name ] ?? null;
			}
			// Deliberately NO __isset(): isset( $author->display_name ) is false here,
			// exactly the case the fix must survive.
		};

		Functions\when( 'get_multiple_authors' )->justReturn( array( $magic_author ) );
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'ID'           => 7,
				'display_name' => 'Creating User',
			)
		);

		$GLOBALS['post'] = (object) array(
			'post_author' => 7,
			'ID'          => 42,
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_POSTTYPE   => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES => false,
				GTM4WP_OPTION_INCLUDE_TAGS       => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR     => true,
				GTM4WP_OPTION_INCLUDE_AUTHORID   => true,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'Magic Guest', $data_layer['pagePostAuthor'], 'The __get value is read even though __isset() is absent.' );
		$this->assertSame( -9, $data_layer['pagePostAuthorID'] );
	}
}
