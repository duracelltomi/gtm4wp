<?php
/**
 * Unit tests for the container module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\Container\AdminSchema;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the "User roles to exclude" field, which renders every available
 * WordPress role as a checkbox (multiselect) as it did in 1.x.
 */
final class ContainerAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_kses' )->alias(
			static function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
			}
		);

		// A settings page always has the role API available.
		$roles = new class() {
			public function get_names(): array {
				return array(
					'administrator' => 'Administrator',
					'editor'        => 'Editor',
					'subscriber'    => 'Subscriber',
				);
			}
		};
		Functions\when( 'wp_roles' )->justReturn( $roles );
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

		$this->fail( "Field '{$key}' not found in the container admin schema." );
	}

	public function test_user_roles_to_exclude_is_a_checkbox_list_of_all_roles(): void {
		$field = $this->field( GTM4WP_OPTION_NOGTMFORLOGGEDIN );

		$this->assertSame( Field::TYPE_MULTISELECT, $field->type );
		$this->assertSame(
			array(
				'administrator' => 'Administrator',
				'editor'        => 'Editor',
				'subscriber'    => 'Subscriber',
			),
			$field->choices
		);
	}

	public function test_container_table_has_an_omit_id_checkbox_column(): void {
		$field   = $this->field( GTM4WP_OPTION_GTM_CONTAINERS );
		$columns = array_column( $field->columns, null, 'key' );

		$this->assertArrayHasKey( 'no_id', $columns, 'The container table must expose the "omit container ID" column.' );
		$this->assertSame( 'checkbox', $columns['no_id']['type'] );
		$this->assertSame( 'path', $columns['no_id']['depends_on'], 'The checkbox depends on the custom path column.' );
	}

	public function test_container_table_stores_omit_id_flag_only_with_custom_path(): void {
		$field = $this->field( GTM4WP_OPTION_GTM_CONTAINERS );

		// A custom path plus the flag on is stored as the canonical '1'.
		$rows = $field->sanitize(
			array(
				array(
					'id'    => 'GTM-AAA111',
					'path'  => 'custom/loader.js',
					'no_id' => true,
				),
			)
		);
		$this->assertSame( '1', $rows[0]['no_id'] );

		// The flag has no effect without a custom path, so it is dropped.
		$rows = $field->sanitize(
			array(
				array(
					'id'    => 'GTM-AAA111',
					'no_id' => '1',
				),
			)
		);
		$this->assertSame( '', $rows[0]['no_id'] );

		// The flag off with a custom path stays off.
		$rows = $field->sanitize(
			array(
				array(
					'id'    => 'GTM-AAA111',
					'path'  => 'custom/loader.js',
					'no_id' => '',
				),
			)
		);
		$this->assertSame( '', $rows[0]['no_id'] );
	}

	public function test_checked_roles_are_stored_as_the_1x_comma_separated_string(): void {
		$field = $this->field( GTM4WP_OPTION_NOGTMFORLOGGEDIN );

		// The multiselect submits an array of role ids.
		$this->assertSame( 'administrator,editor', $field->sanitize( array( 'administrator', 'editor' ) ) );

		// A legacy comma separated string keeps working (imports, downgrades).
		$this->assertSame( 'administrator,editor', $field->sanitize( 'administrator,editor' ) );

		// Nothing checked stores an empty string, matching the default.
		$this->assertSame( '', $field->sanitize( array() ) );
	}
}
