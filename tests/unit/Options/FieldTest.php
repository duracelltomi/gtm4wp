<?php
/**
 * Unit tests for the settings Field value object.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Options;

use Brain\Monkey\Functions;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the per-type save-time sanitizer, the choice-validation fallback,
 * the derive callback, the REST type mapping and the UI array shape.
 */
final class FieldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn ( $v ) => trim( (string) $v ) );
	}

	/**
	 * Builds a Field with sensible defaults and the given overrides.
	 *
	 * @param array<string, mixed> $overrides Constructor overrides keyed by param name.
	 */
	private function make_field( string $type, $default_value = '', array $overrides = array() ): Field {
		return new Field(
			$overrides['key'] ?? 'test-key',
			$type,
			$default_value,
			$overrides['label'] ?? 'Label',
			$overrides['description'] ?? '',
			$overrides['group'] ?? '',
			$overrides['phase'] ?? Field::PHASE_STABLE,
			$overrides['choices'] ?? array(),
			$overrides['sanitizer'] ?? null,
			$overrides['columns'] ?? array(),
			$overrides['derive'] ?? null,
			$overrides['depends_on'] ?? ''
		);
	}

	public function test_checkbox_sanitizes_to_bool(): void {
		$field = $this->make_field( Field::TYPE_CHECKBOX, false );

		$this->assertTrue( $field->sanitize( '1' ) );
		$this->assertTrue( $field->sanitize( 'on' ) );
		$this->assertFalse( $field->sanitize( '' ) );
		$this->assertFalse( $field->sanitize( 0 ) );
	}

	public function test_integer_sanitizes_to_int(): void {
		$field = $this->make_field( Field::TYPE_INTEGER, 0 );

		$this->assertSame( 42, $field->sanitize( '42' ) );
		$this->assertSame( 5, $field->sanitize( 5.9 ) );
		$this->assertSame( 0, $field->sanitize( 'abc' ) );
	}

	public function test_select_returns_valid_choice(): void {
		$field = $this->make_field(
			Field::TYPE_SELECT,
			'a',
			array(
				'choices' => array(
					'a' => 'A',
					'b' => 'B',
				),
			)
		);

		$this->assertSame( 'b', $field->sanitize( 'b' ) );
	}

	public function test_select_falls_back_to_default_for_invalid_choice(): void {
		$field = $this->make_field(
			Field::TYPE_SELECT,
			'a',
			array(
				'choices' => array(
					'a' => 'A',
					'b' => 'B',
				),
			)
		);

		$this->assertSame( 'a', $field->sanitize( 'not-a-choice' ), 'An out-of-list value falls back to the default.' );
	}

	public function test_select_without_choices_returns_sanitized_string(): void {
		$field = $this->make_field( Field::TYPE_SELECT, '' );

		$this->assertSame( 'freeform', $field->sanitize( '  freeform  ' ) );
	}

	public function test_multiselect_filters_to_known_choices(): void {
		$field = $this->make_field(
			Field::TYPE_MULTISELECT,
			array(),
			array(
				'choices' => array(
					'x' => 'X',
					'y' => 'Y',
					'z' => 'Z',
				),
			)
		);

		$this->assertSame( array( 'x', 'z' ), $field->sanitize( array( 'x', 'unknown', 'z' ) ) );
	}

	public function test_multiselect_accepts_comma_separated_string(): void {
		$field = $this->make_field(
			Field::TYPE_MULTISELECT,
			array(),
			array(
				'choices' => array(
					'x' => 'X',
					'y' => 'Y',
				),
			)
		);

		$this->assertSame( array( 'x', 'y' ), $field->sanitize( 'x,y' ) );
	}

	public function test_table_rejects_non_array_and_sanitizes_rows(): void {
		$field = $this->make_field( Field::TYPE_TABLE, array() );

		$this->assertSame( array(), $field->sanitize( 'not-an-array' ) );

		$sanitized = $field->sanitize(
			array(
				array(
					'id'   => ' GTM-XXXX ',
					'auth' => ' abc ',
				),
				'garbage-row',
			)
		);

		$this->assertSame( 'GTM-XXXX', $sanitized[0]['id'] );
		$this->assertSame( array(), $sanitized[1], 'A non-array row is reduced to an empty array.' );
	}

	public function test_textarea_uses_textarea_sanitizer(): void {
		$field = $this->make_field( Field::TYPE_TEXTAREA, '' );

		$this->assertSame( 'line', $field->sanitize( '  line  ' ) );
	}

	/**
	 * A crafted settings-import file can hand an array to a scalar field: the
	 * import path reaches sanitize() without the REST-layer per-field type
	 * coercion the normal save gets. The scalar branches must not raise a PHP
	 * "Array to string conversion" warning, and the array must not leak in as
	 * the literal string "Array" - it collapses to a safe empty string.
	 */
	public function test_scalar_sanitizers_handle_non_scalar_input_without_warning(): void {
		$raised = array();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test asserts no PHP warning is raised.
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$raised ) {
				$raised[] = $errstr;
				return true;
			}
		);

		try {
			foreach ( array( Field::TYPE_TEXT, Field::TYPE_TEXTAREA, Field::TYPE_SELECT ) as $type ) {
				$result = $this->make_field( $type, '' )->sanitize( array( 'hostile', 'array' ) );

				$this->assertIsString( $result, $type . ' always sanitizes to a string.' );
				$this->assertStringNotContainsString( 'Array', $result, $type . ' does not leak the array in as the literal string "Array".' );
			}
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $raised, 'No PHP warning is raised when a scalar field receives an array.' );
	}

	public function test_multiselect_and_table_handle_nested_arrays_without_warning(): void {
		$raised = array();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test asserts no PHP warning is raised.
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$raised ) {
				$raised[] = $errstr;
				return true;
			}
		);

		try {
			$multi_result = $this->make_field( Field::TYPE_MULTISELECT, array() )
				->sanitize( array( 'ok', array( 'nested' ) ) );

			$table_result = $this->make_field( Field::TYPE_TABLE, array() )
				->sanitize( array( array( 'id' => array( 'nested' ) ) ) );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $raised, 'A nested array in a multiselect/table value raises no conversion warning.' );
		$this->assertContains( 'ok', $multi_result, 'The scalar multiselect entry survives.' );
		$this->assertSame( '', $table_result[0]['id'], 'A nested-array table cell collapses to an empty string.' );
	}

	public function test_custom_sanitizer_takes_precedence(): void {
		$field = $this->make_field(
			Field::TYPE_TEXT,
			'',
			array( 'sanitizer' => static fn ( $v ) => strtoupper( (string) $v ) )
		);

		$this->assertSame( 'HELLO', $field->sanitize( 'hello' ), 'A custom sanitizer overrides the type default.' );
	}

	public function test_derived_values_with_and_without_callback(): void {
		$without = $this->make_field( Field::TYPE_TEXT, '' );
		$this->assertSame( array(), $without->derived_values( 'anything' ) );

		$with = $this->make_field(
			Field::TYPE_TEXT,
			'',
			array( 'derive' => static fn ( $v ) => array( 'mirror-key' => $v . '-mirror' ) )
		);
		$this->assertSame( array( 'mirror-key' => 'v-mirror' ), $with->derived_values( 'v' ) );
	}

	public function test_rest_type_maps_each_field_type(): void {
		$this->assertSame( 'boolean', $this->make_field( Field::TYPE_CHECKBOX, false )->rest_type() );
		$this->assertSame( 'integer', $this->make_field( Field::TYPE_INTEGER, 0 )->rest_type() );
		$this->assertSame( 'array', $this->make_field( Field::TYPE_TABLE, array() )->rest_type() );
		$this->assertSame( 'array', $this->make_field( Field::TYPE_MULTISELECT, array() )->rest_type() );
		$this->assertSame( 'string', $this->make_field( Field::TYPE_TEXT, '' )->rest_type() );
		$this->assertSame( 'string', $this->make_field( Field::TYPE_SELECT, '' )->rest_type() );
	}

	public function test_to_ui_array_carries_schema_and_current_value(): void {
		$field = $this->make_field(
			Field::TYPE_TEXT,
			'def',
			array(
				'key'   => 'gtm-code',
				'label' => 'GTM ID',
				'group' => 'general',
			)
		);

		$ui = $field->to_ui_array( 'GTM-CURRENT' );

		$this->assertSame( 'gtm-code', $ui['key'] );
		$this->assertSame( Field::TYPE_TEXT, $ui['type'] );
		$this->assertSame( 'def', $ui['default'] );
		$this->assertSame( 'GTM ID', $ui['label'] );
		$this->assertSame( 'general', $ui['group'] );
		$this->assertSame( 'GTM-CURRENT', $ui['value'], 'The current value is threaded into the UI array.' );
	}

	public function test_to_ui_array_carries_the_field_phase(): void {
		// A non-stable phase must reach the UI array so the admin renders its maturity badge.
		$field = $this->make_field(
			Field::TYPE_CHECKBOX,
			false,
			array( 'phase' => Field::PHASE_EXPERIMENTAL )
		);

		$this->assertSame( Field::PHASE_EXPERIMENTAL, $field->to_ui_array( false )['phase'] );
	}

	public function test_depends_on_defaults_to_empty_and_reaches_the_ui_array(): void {
		// Without a dependency the key is present but empty, so the React app
		// never disables the control.
		$standalone = $this->make_field( Field::TYPE_CHECKBOX, false );
		$this->assertSame( '', $standalone->depends_on );
		$this->assertSame( '', $standalone->to_ui_array( false )['depends_on'] );

		// A declared dependency must reach the UI array under the same key the
		// admin app reads (field.depends_on) so it can grey out the control
		// while the field it points at is off.
		$dependent = $this->make_field(
			Field::TYPE_CHECKBOX,
			false,
			array( 'depends_on' => 'gtm4wp-options-include-categories' )
		);
		$this->assertSame( 'gtm4wp-options-include-categories', $dependent->depends_on );
		$this->assertSame(
			'gtm4wp-options-include-categories',
			$dependent->to_ui_array( false )['depends_on']
		);
	}
}
