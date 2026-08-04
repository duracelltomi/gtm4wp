<?php
/**
 * Unit tests for the page variables module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\PageVariables\AdminSchema;
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

	public function test_trusted_proxy_field_depends_on_the_visitor_ip_option(): void {
		$this->assertSame(
			GTM4WP_OPTION_INCLUDE_VISITOR_IP,
			$this->field( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )->depends_on
		);
	}
}
