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

	/**
	 * A single-select whose options are fetched client side from the Axeptio
	 * project API (the published cookies versions cannot be known server side).
	 * Behaves like a free-text string for REST typing and save-time
	 * sanitization; the React app renders it with a custom dropdown control.
	 */
	public const TYPE_AXEPTIO_VERSION = 'axeptio-version';

	/**
	 * Option maturity phases.
	 *
	 * A phase is a per-option (per Field) maturity signal, not a module-wide
	 * status — a stable module can still expose an experimental option. It is
	 * rendered as a badge next to the field label in the admin UI; STABLE shows
	 * no badge. When adding a Field, choose the phase by these criteria:
	 *
	 * - EXPERIMENTAL: correctness depends on factors GTM4WP cannot verify on
	 *   every site — the active theme, a third-party embed/player API, external
	 *   infrastructure (e.g. Cloudflare), or timing/logic that still needs
	 *   real-world validation. May not work, or work inconsistently, on some
	 *   installs. Keep it off by default and spell out the caveat in the field
	 *   description.
	 * - BETA: implementation is complete and expected to work on any standard
	 *   WP/WC install with no known environment dependency; held in beta only
	 *   until it has accumulated enough real-world usage to be declared stable.
	 * - STABLE (default): proven in the field with no open reproducible issues.
	 *   Promote an experimental/beta option here only after it has shipped for a
	 *   meaningful period (~5 months / a few release cycles) AND has real
	 *   adoption AND has no confirmed reproducible defect. Promotion is a
	 *   deliberate act — change the constant and add a CHANGELOG bullet; it is
	 *   never automatic, and "no reports" on an unused option is not evidence of
	 *   stability.
	 * - DEPRECATED: still works but is superseded (e.g. a native GTM trigger) and
	 *   receives no new development. Discourage new use and name the recommended
	 *   replacement in the field description; kept for backward compatibility,
	 *   not removed yet.
	 */
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
	 * @param string        $depends_on    Optional option key of another field this one depends on:
	 *                                     the admin UI disables this field's control while that field
	 *                                     is empty/off. Purely an admin-UX affordance — the frontend
	 *                                     module is still responsible for ignoring the value when the
	 *                                     dependency is not met. Mirrors the per-column 'depends_on'
	 *                                     used inside TYPE_TABLE checkbox columns.
	 * @param bool          $rows_locked   TYPE_TABLE only: the row set is decided outside the settings
	 *                                     screen (a wp-config.php constant), so the admin UI renders the
	 *                                     whole table read-only and offers no add/remove row action.
	 *                                     A single column whose value is fixed that way is marked with
	 *                                     a 'readonly' entry in $columns instead.
	 * @param array         $choice_sections TYPE_MULTISELECT only: an ordered list of arrays with a
	 *                                     translated 'label' and a 'choices' list of $choices KEYS,
	 *                                     rendered as labelled sections instead of one flat checkbox
	 *                                     list. Purely presentational - the stored value stays the
	 *                                     same flat list, so sanitize(), rest_type() and the REST
	 *                                     schema never see the sections. A section carries ids only,
	 *                                     so the labels keep their single definition in $choices; a
	 *                                     choice no section claims is still rendered (after the
	 *                                     sections), because a choice that exists but cannot be seen
	 *                                     is the worse failure. Last in the signature so adding it
	 *                                     cannot shift a positional argument of an existing caller.
	 * @param string        $doc           Documentation path on gtm4wp.com, relative to the base URL in
	 *                                     \GTM4WP\Admin\Docs and WITHOUT a fragment: the anchor is always
	 *                                     this field's own $key, appended by Docs::url(). Storing only the
	 *                                     path keeps the domain in one place, and deriving the anchor from
	 *                                     the key means the deep link cannot drift out of step with the
	 *                                     option it points at - the key is frozen public API, the heading
	 *                                     wording on the page is not. Empty for an option with no page yet,
	 *                                     which renders no help icon rather than a broken one. Same
	 *                                     end-of-signature rule as $choice_sections above.
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
		public $derive = null,
		public string $depends_on = '',
		public bool $rows_locked = false,
		public array $choice_sections = array(),
		public string $doc = ''
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
				$value = (string) sanitize_text_field( self::to_string( $value ) );
				if ( array() !== $this->choices && ! array_key_exists( $value, $this->choices ) ) {
					return $this->default_value;
				}
				return $value;

			case self::TYPE_MULTISELECT:
				$values = is_array( $value ) ? $value : explode( ',', self::to_string( $value ) );
				$values = array_map( 'sanitize_text_field', array_map( static fn ( $one ) => self::to_string( $one ), $values ) );
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
							? array_map( static fn ( $cell ) => sanitize_text_field( self::to_string( $cell ) ), $row )
							: array(),
						$value
					)
				);

			case self::TYPE_TEXTAREA:
				return sanitize_textarea_field( self::to_string( $value ) );

			case self::TYPE_TEXT:
			default:
				return sanitize_text_field( self::to_string( $value ) );
		}
	}

	/**
	 * Casts a submitted value to a string without emitting a PHP "Array to
	 * string conversion" warning (or a fatal on an object without __toString).
	 *
	 * The settings save route type-normalizes each value at the REST layer
	 * before it reaches sanitize(); the settings import route decodes the raw
	 * file itself and reaches sanitize() without that coercion, so a crafted
	 * file can hand an array to a scalar field. Non-scalar values collapse to
	 * an empty string here, so every entry point into sanitize() behaves
	 * identically (null already casts to '' - kept for parity).
	 *
	 * Public because a CUSTOM field sanitizer sits in front of the type-based
	 * branches (sanitize() returns its result immediately), so the guard here
	 * never protects it: every custom sanitizer that needs a string must run
	 * its own cast through this helper instead of a bare (string) cast.
	 *
	 * @param mixed $value Raw value of any type.
	 * @return string
	 */
	public static function to_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
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
			'sections'    => $this->choice_sections,
			'columns'     => $this->columns,
			'depends_on'  => $this->depends_on,
			'rows_locked' => $this->rows_locked,
			'value'       => $current_value,
		);
	}
}
