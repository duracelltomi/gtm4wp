<?php
/**
 * Unit tests for the page variables module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\VisitorIp;
use GTM4WP\Modules\PageVariables\AdminSchema;
use GTM4WP\Modules\PageVariables\PageVariablesModule;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the admin-schema wiring that the frontend module cannot express -
 * in particular the field-level dependency that greys out "Include parent
 * categories" while the category list itself is off (issue #220 follow-up).
 */
final class PageVariablesAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	/**
	 * Returns the field owning the given option key.
	 *
	 * @param string $key Option key.
	 * @return Field
	 */
	private function field( string $key ): Field {
		foreach ( ( new AdminSchema() )->fields() as $field ) {
			if ( $key === $field->key ) {
				return $field;
			}
		}

		$this->fail( "Field '{$key}' not found in the page variables admin schema." );
	}

	public function test_parent_categories_field_depends_on_the_category_list(): void {
		$parent_categories = $this->field( GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES );

		// The admin UI disables this control while the category list is off, so
		// the two options can no longer be set to a nonsensical combination
		// (parent categories on while the list they extend is off).
		$this->assertSame(
			GTM4WP_OPTION_INCLUDE_CATEGORIES,
			$parent_categories->depends_on,
			'Include parent categories must declare its dependency on the category list option.'
		);
	}

	public function test_category_list_field_has_no_dependency(): void {
		// The parent's dependency target must itself be free-standing, otherwise
		// the control could never be enabled.
		$this->assertSame( '', $this->field( GTM4WP_OPTION_INCLUDE_CATEGORIES )->depends_on );
	}

	/**
	 * The WRITE end of the header-name allow-list. #62 anchored the identical check in
	 * VisitorIp::get() and left this one behind, so the sanitizer accepted any string
	 * containing a single allowed character and stored the mangled result. Nothing
	 * unsafe reached a sink - the reader's anchored check rejected it a second time -
	 * but the admin saw a malformed value accepted and then silently ignored. Both ends
	 * now reject the same inputs (PA-2: a validated column is validated at every entry
	 * point, not just the one the finding was reported against).
	 */
	public function test_visitor_ip_header_sanitizer_rejects_malformed_header_names(): void {
		$sanitize = $this->field( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER )->sanitizer;

		$this->assertSame( 'X_REAL_IP', ( $sanitize )( 'X-Real-IP' ), 'A well formed name is normalized and kept.' );
		$this->assertSame( 'CF_CONNECTING_IP', ( $sanitize )( 'CF-Connecting-IP' ) );

		// Each of these contains at least one [A-Z0-9_] character, which is all the
		// unanchored pattern ever required.
		$this->assertSame( '', ( $sanitize )( 'X-Real-IP: 1.2.3.4' ) );
		$this->assertSame( '', ( $sanitize )( 'has space' ) );
		$this->assertSame( '', ( $sanitize )( '!!!' ) );
		$this->assertSame( '', ( $sanitize )( '' ) );
	}

	/**
	 * The trusted-proxy list is a security-relevant allow-list, so it ships both
	 * directions: valid entries survive, invalid ones are dropped rather than stored
	 * for the reader to skip in silence.
	 */
	public function test_trusted_proxy_sanitizer_keeps_only_valid_entries(): void {
		$sanitize = $this->field( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )->sanitizer;

		$this->assertSame(
			"10.0.0.0/8\n2001:db8::/32\n198.51.100.7",
			( $sanitize )( "10.0.0.0/8, not-an-ip\n2001:db8::/32\n999.1.1.1\n198.51.100.7\n10.0.0.0/99" )
		);

		$this->assertSame( '', ( $sanitize )( 'nonsense' ) );
		$this->assertSame( '', ( $sanitize )( '' ) );

		// Type-defensive: a custom sanitizer replaces Field::sanitize()'s type branches
		// rather than sitting behind them, so it sees raw import values (RI-6).
		$this->assertSame( '', ( $sanitize )( array( '10.0.0.0/8' ) ) );
	}

	/**
	 * The post-meta allow-list normalizes through the READER's own parser, so a
	 * stored list is exactly the list PageVariablesModule will honour - the same
	 * one-rule-one-place shape #94 anchored for the visitor IP header. Asserting
	 * agreement rather than a hardcoded expectation is the point.
	 */
	public function test_post_meta_key_list_sanitizer_agrees_with_the_reader(): void {
		$sanitize = $this->field( GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS )->sanitizer;

		$raw = "  rank_math_title\n, my_field ,\n\nmy_field\n";

		$this->assertSame( "rank_math_title\nmy_field", ( $sanitize )( $raw ) );

		// What is stored round-trips through the reader unchanged: no entry the
		// reader would silently skip, and none it would split differently.
		$this->assertSame(
			PageVariablesModule::parse_meta_key_list( $raw ),
			PageVariablesModule::parse_meta_key_list( ( $sanitize )( $raw ) )
		);

		$this->assertSame( '', ( $sanitize )( '' ) );
		$this->assertSame( '', ( $sanitize )( " \n , \n" ) );

		// Type-defensive: a custom sanitizer replaces Field::sanitize()'s type branches
		// rather than sitting behind them, so it sees raw import values (RI-6).
		$this->assertSame( '', ( $sanitize )( array( 'my_field' ) ) );
	}

	/**
	 * The list is inert unless the custom-field option it narrows is on.
	 */
	public function test_post_meta_key_list_field_depends_on_the_post_meta_option(): void {
		$this->assertSame(
			GTM4WP_OPTION_INCLUDE_POSTMETA,
			$this->field( GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS )->depends_on
		);
	}

	public function test_trusted_proxy_field_depends_on_the_visitor_ip_option(): void {
		$this->assertSame(
			GTM4WP_OPTION_INCLUDE_VISITOR_IP,
			$this->field( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )->depends_on
		);
	}

	/**
	 * #96: the header field is inert unless the Visitor IP variable is on - and it is
	 * the field that carries the risk the "no trusted proxies" notice warns about, so
	 * it rendering enabled on a screen where that notice can never fire was the wrong
	 * way round. Its sibling three lines below always declared the dependency.
	 */
	public function test_visitor_ip_header_field_depends_on_the_visitor_ip_option(): void {
		$this->assertSame(
			GTM4WP_OPTION_INCLUDE_VISITOR_IP,
			$this->field( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER )->depends_on
		);
	}

	/**
	 * #94: the sanitizer must validate with the READER's predicate, not a copy of it.
	 * Asserting agreement rather than a hardcoded list is the point - a future
	 * tightening of VisitorIp::normalize_header_name() then cannot leave this end
	 * behind, which is exactly how the two ends drifted apart in the first place.
	 */
	public function test_visitor_ip_header_sanitizer_agrees_with_the_reader(): void {
		$sanitize = $this->field( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER )->sanitizer;

		foreach ( array( 'X-Real-IP', 'CF-Connecting-IP', 'x_forwarded_for', 'X-Real-IP: 1.2.3.4', 'has space', '!!!', '' ) as $input ) {
			$this->assertSame(
				VisitorIp::normalize_header_name( $input ),
				( $sanitize )( $input ),
				sprintf( 'The stored value for "%s" must be exactly what the reader honors.', $input )
			);
		}
	}

	/**
	 * RI-15: an option's description is its consent contract, and the quiet two thirds
	 * of it are the data layer VARIABLE NAME and the FORM the values take. #84 fixed
	 * the tag option after its sibling had both corrected in the same commit; this
	 * pins the rest of the group so the next one is not left behind again.
	 *
	 * @param string   $key      Option key.
	 * @param string[] $expected Substrings the description must contain.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_description_contracts' )]
	public function test_description_names_its_variable_and_value_form( string $key, array $expected ): void {
		$description = $this->field( $key )->description;

		foreach ( $expected as $needle ) {
			$this->assertStringContainsString(
				$needle,
				$description,
				sprintf( 'The description of "%s" must name %s.', $key, $needle )
			);
		}
	}

	/**
	 * Supplies each option with the variable name and value-form terms it must name.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function provide_description_contracts(): array {
		return array(
			// Emits the format SLUG into a variable that, alone in this group, has no
			// "page" prefix - so neither half was guessable from the old wording.
			'post format'      => array( GTM4WP_OPTION_INCLUDE_POSTFORMAT, array( 'postFormat', 'slug', 'standard' ) ),
			// Emits TWO variables; the description named neither, nor the second one's
			// existence.
			'primary category' => array( GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY, array( 'pagePrimaryCategory', 'pagePrimaryCategoryName', 'slug' ) ),
			// Singular description, plural payload on a multi-author post.
			'author id'        => array( GTM4WP_OPTION_INCLUDE_AUTHORID, array( 'pagePostAuthorID', 'pagePostAuthorIDs' ) ),
			'author name'      => array( GTM4WP_OPTION_INCLUDE_AUTHOR, array( 'pagePostAuthor', 'pagePostAuthors' ) ),
			// Role slugs, comma joined, and a value emitted for logged-OUT visitors.
			'user role'        => array( GTM4WP_OPTION_INCLUDE_USERROLE, array( 'visitorType', 'slug', 'visitor-logged-out' ) ),
			'login state'      => array( GTM4WP_OPTION_INCLUDE_LOGGEDIN, array( 'visitorLoginState', 'logged-in', 'logged-out' ) ),
			// Already correct - kept so a regression on the two that prompted the rule
			// fails here too.
			'categories'       => array( GTM4WP_OPTION_INCLUDE_CATEGORIES, array( 'pageCategory', 'slug' ) ),
			'tags'             => array( GTM4WP_OPTION_INCLUDE_TAGS, array( 'pageAttributes', 'slug' ) ),
		);
	}
}
