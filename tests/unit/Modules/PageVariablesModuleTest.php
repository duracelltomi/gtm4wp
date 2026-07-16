<?php
/**
 * Unit tests for the PageVariables module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
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

	// ---------------------------------------------------------------------
	// Master-language output (issue #145). resolve_default_language_*_id()
	// swaps the current-language post/term for its default-language
	// equivalent, so pageTitle / pageCategory / pageAttributes / pagePostTerms
	// report the master values. The feature is opt-in and supports BOTH WPML
	// and Polylang, guarded the same way as the pageLanguage detection.
	//
	// ORDERING NOTE: the Polylang case defines pll_* through Brain Monkey,
	// which leaves function_exists() reporting them process-wide thereafter
	// (see the PublishPress note at the bottom). It is therefore the LAST of
	// this group, so the "neither active", WPML, off and filter cases (which
	// require pll_* to be undefined, or exercise the WPML path that returns
	// before the Polylang branch) all run first.
	// ---------------------------------------------------------------------

	/**
	 * Stubs a singular request whose language-dependent values the
	 * master-language tests drive. Term ids 30/55/70 (current language) map to
	 * 3/5/7 (default language) through whichever plugin the test activates;
	 * get_term() returns the master term for those default ids.
	 *
	 * @return void
	 */
	private function stub_master_language_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'wp_title' )->justReturn( 'CURRENT_TITLE' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_the_title' )->alias(
			static fn ( $id ): string => 8 === (int) $id ? 'Master title' : 'UNEXPECTED'
		);
		Functions\when( 'get_the_category' )->justReturn(
			array(
				new \WP_Term(
					array(
						'term_id' => 30,
						'slug'    => 'cat-current',
					)
				),
			)
		);
		Functions\when( 'get_the_tags' )->justReturn(
			array(
				new \WP_Term(
					array(
						'term_id' => 55,
						'slug'    => 'tag-current',
					)
				),
			)
		);
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				$master = array(
					3 => array(
						'slug' => 'cat-master',
						'name' => 'Cat master',
					),
					5 => array(
						'slug' => 'tag-master',
						'name' => 'Tag master',
					),
					7 => array(
						'slug' => 'genre-master',
						'name' => 'Genre master',
					),
				);
				if ( isset( $master[ (int) $id ] ) ) {
					return new \WP_Term(
						array(
							'term_id' => (int) $id,
							'slug'    => $master[ (int) $id ]['slug'],
							'name'    => $master[ (int) $id ]['name'],
						)
					);
				}
				return null;
			}
		);
	}

	/**
	 * Stored options that enable the language-dependent sinks (title, category,
	 * tags) so a test can assert the master vs. current value for each.
	 *
	 * @param bool $master_on Whether the master-language option is enabled.
	 * @return array<string, mixed>
	 */
	private function master_language_options( bool $master_on ): array {
		return array(
			GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE => $master_on,
			GTM4WP_OPTION_INCLUDE_POSTTITLE      => true,
			GTM4WP_OPTION_INCLUDE_POSTTYPE       => false,
			GTM4WP_OPTION_INCLUDE_CATEGORIES     => true,
			GTM4WP_OPTION_INCLUDE_TAGS           => true,
			GTM4WP_OPTION_INCLUDE_AUTHOR         => false,
			GTM4WP_OPTION_INCLUDE_AUTHORID       => false,
		);
	}

	/**
	 * With the option OFF but WPML active, nothing is resolved: the current
	 * (translated) title / category / tag values pass through unchanged. The
	 * WPML filters are mocked to return DIFFERENT (master) ids, so if the
	 * opt-in gate were broken the master values would appear and this test
	 * would fail. Proves the feature is opt-in.
	 */
	public function test_master_language_off_leaves_translated_values_unchanged(): void {
		$this->stub_master_language_singular();

		add_filter( 'wpml_current_language', static fn () => 'de' );
		Filters\expectApplied( 'wpml_default_language' )->zeroOrMoreTimes()->andReturn( 'en' );
		Filters\expectApplied( 'wpml_object_id' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( $id ) {
				$map = array(
					42 => 8,
					30 => 3,
					55 => 5,
				);
				return $map[ (int) $id ] ?? $id;
			}
		);

		$module     = $this->make_module( $this->master_language_options( false ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'CURRENT_TITLE', $data_layer['pageTitle'] );
		$this->assertSame( array( 'cat-current' ), $data_layer['pageCategory'] );
		$this->assertSame( array( 'tag-current' ), $data_layer['pageAttributes'] );
	}

	/**
	 * With the option ON but no multilingual plugin active, resolution yields
	 * the same id, so every value falls back to the current behavior
	 * unchanged (the "neither plugin active" branch of the feature).
	 */
	public function test_master_language_on_without_plugin_falls_back_unchanged(): void {
		$this->stub_master_language_singular();

		$module     = $this->make_module( $this->master_language_options( true ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'CURRENT_TITLE', $data_layer['pageTitle'] );
		$this->assertSame( array( 'cat-current' ), $data_layer['pageCategory'] );
		$this->assertSame( array( 'tag-current' ), $data_layer['pageAttributes'] );
	}

	/**
	 * WPML active + option ON: the post title, category slug and tag slug are
	 * resolved to the site's default language through wpml_default_language +
	 * wpml_object_id (the fourth `true` arg returns the original when there is
	 * no translation). Element type is the post type for the post and the
	 * taxonomy for the terms, per the WPML coding API.
	 */
	public function test_master_language_outputs_wpml_default_language_values(): void {
		$this->stub_master_language_singular();

		// WPML is detected exactly like the pageLanguage option: the presence
		// of the wpml_current_language filter.
		add_filter( 'wpml_current_language', static fn () => 'de' );
		Filters\expectApplied( 'wpml_default_language' )->zeroOrMoreTimes()->andReturn( 'en' );
		Filters\expectApplied( 'wpml_object_id' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( $id ) {
				// Current-language id => default-language id.
				$map = array(
					42 => 8,
					30 => 3,
					55 => 5,
				);
				return $map[ (int) $id ] ?? $id;
			}
		);

		$module     = $this->make_module( $this->master_language_options( true ) );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'Master title', $data_layer['pageTitle'] );
		$this->assertSame( array( 'cat-master' ), $data_layer['pageCategory'] );
		$this->assertSame( array( 'tag-master' ), $data_layer['pageAttributes'] );
	}

	/**
	 * The resolved id is filterable so integrators can support other
	 * multilingual plugins. With no plugin active, a third party using the
	 * gtm4wp_master_language_term_id filter alone drives the category to its
	 * master term. Proves "keep it filterable".
	 */
	public function test_master_language_term_id_filter_drives_resolution(): void {
		$this->stub_master_language_singular();

		Filters\expectApplied( 'gtm4wp_master_language_term_id' )->zeroOrMoreTimes()->andReturnUsing(
			static fn ( $resolved, $id ) => 30 === (int) $id ? 3 : $resolved
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE => true,
				GTM4WP_OPTION_INCLUDE_POSTTITLE      => false,
				GTM4WP_OPTION_INCLUDE_POSTTYPE       => false,
				GTM4WP_OPTION_INCLUDE_CATEGORIES     => true,
				GTM4WP_OPTION_INCLUDE_TAGS           => false,
				GTM4WP_OPTION_INCLUDE_AUTHOR         => false,
				GTM4WP_OPTION_INCLUDE_AUTHORID       => false,
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'cat-master' ), $data_layer['pageCategory'] );
	}

	/**
	 * Polylang active + option ON: the post (pll_get_post), category and tag
	 * (pll_get_term) and a CUSTOM taxonomy term are resolved to the default
	 * language returned by pll_default_language(). Kept LAST because defining
	 * pll_* leaks function_exists() into later tests (see the group note).
	 */
	public function test_master_language_outputs_polylang_default_language_values(): void {
		$this->stub_master_language_singular();

		// Polylang is detected exactly like the pageLanguage option: its
		// pll_* functions existing (and the wpml_current_language filter absent).
		Functions\when( 'pll_default_language' )->justReturn( 'en' );
		Functions\when( 'pll_get_post' )->alias(
			static fn ( $id ): int => 42 === (int) $id ? 8 : 0
		);
		Functions\when( 'pll_get_term' )->alias(
			static function ( $id ): int {
				$map = array(
					30 => 3,
					55 => 5,
					70 => 7,
				);
				return $map[ (int) $id ] ?? 0;
			}
		);

		// Custom-taxonomy term for the pagePostTerms sink.
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'genre' ) );
		Functions\when( 'get_the_terms' )->alias(
			static fn ( $post_id, $taxonomy ) => 'genre' === $taxonomy
				? array(
					new \WP_Term(
						array(
							'term_id' => 70,
							'name'    => 'Genre current',
						)
					),
				)
				: false
		);
		Functions\when( 'get_post_meta' )->justReturn( array() );

		$GLOBALS['post'] = (object) array(
			'ID'          => 42,
			'post_author' => 7,
		);

		$options                                       = $this->master_language_options( true );
		$options[ GTM4WP_OPTION_INCLUDE_POSTTERMLIST ] = true;

		$module     = $this->make_module( $options );
		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( 'Master title', $data_layer['pageTitle'] );
		$this->assertSame( array( 'cat-master' ), $data_layer['pageCategory'] );
		$this->assertSame( array( 'tag-master' ), $data_layer['pageAttributes'] );
		// Custom taxonomy term names are resolved to the default language too.
		$this->assertSame( array( 'Genre master' ), $data_layer['pagePostTerms']['genre'] );
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
	 * Single-author fallback: when PublishPress reports one author (or is not
	 * active), only the plain single-value vars are set from get_userdata() and
	 * neither array variable appears.
	 */
	public function test_single_author_uses_plain_vars_when_publishpress_returns_one_author(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		Functions\when( 'get_multiple_authors' )->justReturn(
			array(
				(object) array(
					'ID'           => 7,
					'display_name' => 'Only Author',
				),
			)
		);
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'ID'           => 7,
				'display_name' => 'Only Author',
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

		$this->assertSame( 'Only Author', $data_layer['pagePostAuthor'] );
		$this->assertSame( 7, $data_layer['pagePostAuthorID'] );
		$this->assertArrayNotHasKey( 'pagePostAuthors', $data_layer );
		$this->assertArrayNotHasKey( 'pagePostAuthorIDs', $data_layer );
	}
}
