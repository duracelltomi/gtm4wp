<?php
/**
 * Consistency tests across all built-in modules.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\ModuleInterface;
use GTM4WP\Module\Registry;
use GTM4WP\Tests\unit\TestCase;

/**
 * Asserts the lean module / admin schema pair of every built-in module
 * cannot drift apart: defaults() keys must exactly match the AdminSchema
 * field keys, and every field must reference a declared accordion group.
 */
final class ModuleConsistencyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) {
				return $content;
			}
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names(): array {
					return array();
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();

		// The container module's production-only field description reads the
		// environment type while building its schema.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	/**
	 * Returns all built-in modules.
	 *
	 * @return array<string, ModuleInterface>
	 */
	private function builtin_modules(): array {
		return Registry::with_default_modules()->all();
	}

	public function test_every_module_defaults_match_admin_schema_fields(): void {
		foreach ( $this->builtin_modules() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$this->assertTrue( class_exists( $schema_class ), "Admin schema class of module '{$module_id}' must exist." );

			$schema = new $schema_class();
			$this->assertInstanceOf( AdminSchemaInterface::class, $schema );

			$default_keys = array_keys( $module->defaults() );
			$field_keys   = array_map( static fn ( $field ) => $field->key, $schema->fields() );

			sort( $default_keys );
			sort( $field_keys );

			$this->assertSame(
				$default_keys,
				$field_keys,
				"Module '{$module_id}': defaults() keys must exactly match AdminSchema field keys."
			);
		}
	}

	public function test_every_field_references_a_declared_group(): void {
		foreach ( $this->builtin_modules() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$schema       = new $schema_class();
			$group_ids    = array_keys( $schema->groups() );

			foreach ( $schema->fields() as $field ) {
				$this->assertContains(
					$field->group,
					$group_ids,
					"Module '{$module_id}': field '{$field->key}' references undeclared group '{$field->group}'."
				);
			}
		}
	}

	public function test_every_field_dependency_references_a_known_option(): void {
		$found_dependency = false;

		foreach ( $this->builtin_modules() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$schema       = new $schema_class();
			$field_keys   = array_map( static fn ( $field ) => $field->key, $schema->fields() );

			foreach ( $schema->fields() as $field ) {
				if ( '' === $field->depends_on ) {
					continue;
				}

				$found_dependency = true;

				// A field can only depend on a real option so the React app can
				// resolve the current value it must gate the control on; a
				// dependency on itself would never resolve.
				$this->assertContains(
					$field->depends_on,
					$field_keys,
					"Module '{$module_id}': field '{$field->key}' depends on unknown option '{$field->depends_on}'."
				);
				$this->assertNotSame(
					$field->key,
					$field->depends_on,
					"Module '{$module_id}': field '{$field->key}' must not depend on itself."
				);
			}
		}

		$this->assertTrue(
			$found_dependency,
			'At least one built-in field is expected to declare a depends_on (e.g. parent categories on the category list).'
		);
	}

	public function test_module_ids_are_unique_and_stable(): void {
		$modules = $this->builtin_modules();

		$this->assertSame(
			array(
				'container',
				'page-variables',
				'client-device-data',
				'visitor-data',
				'user-events',
				'media-events',
				'consent',
				'contact-form-7',
				'woocommerce',
				'amp',
				'blacklist',
			),
			array_keys( $modules )
		);
	}

	/**
	 * The settings-import route hands every field its RAW decoded value, so a
	 * crafted file can supply an array (or deeper nesting) where a scalar is
	 * expected. The type-based default sanitizers are type-defensive via
	 * Field::to_string(); a CUSTOM sanitizer REPLACES them entirely
	 * (Field::sanitize() returns its result before the type branches run), so
	 * it must be type-defensive itself. This sweep feeds every field of every
	 * built-in module a nested array and promotes any PHP warning ("Array to
	 * string conversion" and friends) to a failure, pinning the class shut for
	 * future fields too.
	 */
	public function test_every_field_sanitizer_handles_non_scalar_input_without_warning(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $value ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only: promotes the PHP warning to a test failure; restored in finally.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception; the message is reported by PHPUnit, never rendered as HTML.
				throw new \ErrorException( $errstr, 0, $errno );
			},
			E_WARNING | E_NOTICE
		);

		try {
			foreach ( $this->builtin_modules() as $module_id => $module ) {
				$schema_class = $module->admin_schema();
				$schema       = new $schema_class();

				foreach ( $schema->fields() as $field ) {
					$sanitized = $field->sanitize( array( array( 'hostile' ) ) );

					$this->assertTrue(
						is_scalar( $sanitized ) || is_array( $sanitized ) || null === $sanitized || $sanitized instanceof \WP_Error,
						"Module '{$module_id}': field '{$field->key}' must reduce a non-scalar submission to a scalar/array/WP_Error."
					);

					// The warning promotion above is the primary detector, but it
					// only fires for a cast PHP complains about. A sanitizer that
					// stringifies the submission deliberately would satisfy it and
					// still store the useless literal "Array" - and a string IS
					// scalar, so the type assertion above passes too. Assert the
					// value contract as well, the way WooCommerceAdminSchemaTest
					// does per-field, so neither route is left open.
					foreach ( self::flatten_strings( $sanitized ) as $leaf ) {
						$this->assertStringNotContainsString(
							'Array',
							$leaf,
							"Module '{$module_id}': field '{$field->key}' must not stringify a non-scalar submission into the literal \"Array\"."
						);
					}
				}
			}
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Collects every string leaf of a sanitized value so the "Array" contract can
	 * be asserted on table/multiselect results too, not just scalar fields.
	 *
	 * @param mixed $value Sanitized field value of any shape.
	 * @return string[]
	 */
	private static function flatten_strings( $value ): array {
		if ( is_string( $value ) ) {
			return array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $one ) {
			$strings = array_merge( $strings, self::flatten_strings( $one ) );
		}

		return $strings;
	}

	public function test_option_keys_are_owned_by_exactly_one_module(): void {
		$seen = array();

		foreach ( $this->builtin_modules() as $module_id => $module ) {
			foreach ( array_keys( $module->defaults() ) as $option_key ) {
				$owner = $seen[ $option_key ] ?? '';
				$this->assertArrayNotHasKey(
					$option_key,
					$seen,
					"Option '{$option_key}' is owned by both '{$owner}' and '{$module_id}'."
				);
				$seen[ $option_key ] = $module_id;
			}
		}
	}
}
