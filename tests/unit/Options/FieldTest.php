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
	private function make_field( string $type, $default = '', array $overrides = array() ): Field {
		return new Field(
			$overrides['key'] ?? 'test-key',
			$type,
			$default,
			$overrides['label'] ?? 'Label',
			$overrides['description'] ?? '',
			$overrides['group'] ?? '',
			$overrides['phase'] ?? Field::PHASE_STABLE,
			$overrides['choices'] ?? array(),
			$overrides['sanitizer'] ?? null,
			$overrides['columns'] ?? array(),
			$overrides['derive'] ?? null
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
}
