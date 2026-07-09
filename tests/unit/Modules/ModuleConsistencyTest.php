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

	public function test_module_ids_are_unique_and_stable(): void {
		$modules = $this->builtin_modules();

		$this->assertSame(
			array(
				'container',
				'page-variables',
				'client-device-data',
				'user-events',
				'media-events',
				'scroll-tracking',
				'consent',
				'contact-form-7',
				'amp',
				'blacklist',
			),
			array_keys( $modules )
		);
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
