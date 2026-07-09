<?php
/**
 * Settings field definition value object.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one plugin option for the admin UI, the REST schema and
 * save-time sanitization.
 *
 * Field objects are created exclusively inside per-module AdminSchema
 * classes, therefore this class is never loaded on frontend requests.
 */
final class Field {

	public const TYPE_CHECKBOX    = 'checkbox';
	public const TYPE_TEXT        = 'text';
	public const TYPE_INTEGER     = 'integer';
	public const TYPE_SELECT      = 'select';
	public const TYPE_TEXTAREA    = 'textarea';
	public const TYPE_MULTISELECT = 'multiselect';
	public const TYPE_TABLE       = 'table';

	public const PHASE_STABLE       = 'stable';
	public const PHASE_BETA         = 'beta';
	public const PHASE_EXPERIMENTAL = 'experimental';
	public const PHASE_DEPRECATED   = 'deprecated';

	/**
	 * Constructor.
	 *
	 * @param string        $key           Option key inside the gtm4wp-options array (1.x compatible).
	 * @param string        $type          One of the TYPE_* constants; drives the React component and REST type.
	 * @param mixed         $default_value Default value, must match the lean module's defaults() entry.
	 * @param string        $label         Translated field label.
	 * @param string        $description   Translated field description, may contain limited safe HTML.
	 * @param string        $group         Accordion group id within the module panel.
	 * @param string        $phase         One of the PHASE_* constants, rendered as a badge.
	 * @param array         $choices       value => translated label map for TYPE_SELECT fields.
	 * @param callable|null $sanitizer     Save-time sanitizer: fn( mixed $value ): mixed. When null,
	 *                                     a type-based default sanitizer is applied by the REST controller.
	 * @param array         $columns       Column definitions for TYPE_TABLE fields: a list of
	 *                                     arrays with 'key', translated 'label' and optional 'placeholder'.
	 * @param callable|null $derive        Optional fn( mixed $sanitized ): array returning additional
	 *                                     option key => value pairs stored alongside this field
	 *                                     (used to keep 1.x mirror options in sync).
	 */
	public function __construct(
		public string $key,
		public string $type,
		public $default_value,
		public string $label,
		public string $description = '',
		public string $group = '',
		public string $phase = self::PHASE_STABLE,
		public array $choices = array(),
		public $sanitizer = null,
		public array $columns = array(),
		public $derive = null
	) {
	}

	/**
	 * Returns the additional option key => value pairs derived from the
	 * sanitized value of this field, empty when no derive callback is set.
	 *
	 * @param mixed $sanitized Sanitized field value.
	 * @return array<string, mixed>
	 */
	public function derived_values( $sanitized ): array {
		if ( null === $this->derive || ! is_callable( $this->derive ) ) {
			return array();
		}

		return (array) call_user_func( $this->derive, $sanitized );
	}

	/**
	 * Sanitizes a submitted value for this field.
	 *
	 * Falls back to a type-based sanitizer when no custom one is defined,
	 * mirroring how gtm4wp_sanitize_options() handled generic fields in 1.x.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return mixed Sanitized value.
	 */
	public function sanitize( $value ) {
		if ( null !== $this->sanitizer && is_callable( $this->sanitizer ) ) {
			return call_user_func( $this->sanitizer, $value );
		}

		switch ( $this->type ) {
			case self::TYPE_CHECKBOX:
				return (bool) $value;

			case self::TYPE_INTEGER:
				return (int) $value;

			case self::TYPE_SELECT:
				$value = (string) sanitize_text_field( (string) $value );
				if ( array() !== $this->choices && ! array_key_exists( $value, $this->choices ) ) {
					return $this->default_value;
				}
				return $value;

			case self::TYPE_MULTISELECT:
				$values = is_array( $value ) ? $value : explode( ',', (string) $value );
				$values = array_map( 'sanitize_text_field', array_map( 'strval', $values ) );
				if ( array() !== $this->choices ) {
					$values = array_values(
						array_filter(
							$values,
							fn ( $one_value ) => array_key_exists( $one_value, $this->choices )
						)
					);
				}
				return $values;

			case self::TYPE_TABLE:
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_values(
					array_map(
						static fn ( $row ) => is_array( $row )
							? array_map( static fn ( $cell ) => sanitize_text_field( (string) $cell ), $row )
							: array(),
						$value
					)
				);

			case self::TYPE_TEXTAREA:
				return sanitize_textarea_field( (string) $value );

			case self::TYPE_TEXT:
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Returns the JSON schema type of the field for the REST endpoint.
	 *
	 * @return string
	 */
	public function rest_type(): string {
		switch ( $this->type ) {
			case self::TYPE_CHECKBOX:
				return 'boolean';

			case self::TYPE_INTEGER:
				return 'integer';

			case self::TYPE_TABLE:
			case self::TYPE_MULTISELECT:
				return 'array';

			default:
				return 'string';
		}
	}

	/**
	 * Returns the field description as an array for the admin UI bootstrap data.
	 *
	 * @param mixed $current_value The current value of this field.
	 * @return array<string, mixed>
	 */
	public function to_ui_array( $current_value ): array {
		return array(
			'key'         => $this->key,
			'type'        => $this->type,
			'default'     => $this->default_value,
			'label'       => $this->label,
			'description' => $this->description,
			'group'       => $this->group,
			'phase'       => $this->phase,
			'choices'     => $this->choices,
			'columns'     => $this->columns,
			'value'       => $current_value,
		);
	}
}
