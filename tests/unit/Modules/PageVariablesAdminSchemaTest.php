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
}
