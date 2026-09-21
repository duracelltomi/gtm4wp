<?php
/**
 * Settings read/write service.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Module\Registry;
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\Container\HardcodedContainers;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The one path through which the plugin's settings are read for editing and
 * written back: the settings REST routes, the settings screen bootstrap and
 * the abilities are thin adapters over this class, so no writer can bypass a
 * Field sanitizer and no reader can disagree with another about what a site
 * has configured.
 *
 * Sanitization and validation is schema driven: each submitted value runs
 * through its Field sanitizer (ported from gtm4wp_sanitize_options() of 1.x).
 * Sanitizers may return WP_Error; those values are rejected and reported back,
 * the stored value stays unchanged - mirroring how add_settings_error() flows
 * worked in 1.x.
 *
 * Every read goes to the option row afresh, never to the request-scoped
 * Options service, so a write followed by a read in the same request - a
 * settings save answering with the new values, an agent changing an option
 * and reading it back - sees the write.
 */
final class SettingsStore {

	/**
	 * Marker written into - and required by - a settings export file. An
	 * uploaded file that does not carry this exact type is rejected on import.
	 */
	public const EXPORT_TYPE = 'gtm4wp-settings-export';

	/**
	 * Hard upper bound on the raw import payload size, in bytes. The settings
	 * JSON is a few KB in practice, so this rejects oversized/abusive uploads
	 * before they are ever decoded.
	 */
	public const IMPORT_MAX_BYTES = 512 * 1024;

	/**
	 * Maximum nesting depth json_decode() accepts for an import payload. The
	 * deepest legitimate structure (a table/container-rows field) is two levels
	 * deep, so this bounds decoder recursion without any unserialize/eval.
	 */
	public const IMPORT_MAX_DEPTH = 16;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 */
	public function __construct( private Registry $registry ) {
	}

	/**
	 * Returns the current raw option values (defaults overlaid with the
	 * stored values), keyed by the 1.x compatible option keys.
	 *
	 * @return array<string, mixed>
	 */
	public function current_values(): array {
		$stored = $this->stored_row();
		$values = array_merge( $this->registry->defaults(), $stored );

		// The Options service's pre-migration fallback, so the admin UI shows
		// what the frontend loads.
		if ( ! array_key_exists( GTM4WP_OPTION_GTM_CONTAINERS, $stored ) ) {
			$values[ GTM4WP_OPTION_GTM_CONTAINERS ] = ContainerRows::from_legacy( $values );
		}

		return $values;
	}

	/**
	 * A fingerprint of the stored option row as it is right now: the same
	 * value for the same row, a different one after any write to it, whoever
	 * made it (the settings screen, an import, an assistant). A writer that
	 * read the settings first sends it back as its expectation, so two editors
	 * cannot overwrite each other without noticing - a lost-update guard, not
	 * an authorization control.
	 *
	 * @return string 32 hex characters.
	 */
	public function values_hash(): string {
		return md5( (string) wp_json_encode( $this->stored_row() ) );
	}

	/**
	 * The values the settings screen renders: the current values with the
	 * container rows replaced by the ones actually loaded under a
	 * GTM4WP_HARDCODED_* constant (recomputed here, since the Options service
	 * predates this request's write). Separate from current_values(): an
	 * export must carry the site's OWN stored configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function ui_values(): array {
		$values = $this->current_values();

		if ( HardcodedContainers::is_active() ) {
			list( $rows ) = HardcodedContainers::apply(
				ContainerRows::normalize( $values[ GTM4WP_OPTION_GTM_CONTAINERS ] ?? array() )
			);

			$values[ GTM4WP_OPTION_GTM_CONTAINERS ] = $rows;
		}

		return $values;
	}

	/**
	 * Sanitizes a partial map of option key => raw value through the Field
	 * definitions and writes the result onto the stored row.
	 *
	 * @param array<string, mixed> $submitted Raw option key => value map (untrusted).
	 * @return array<string, string> Error map, option key => sanitizer message; empty when everything was stored.
	 */
	public function save( array $submitted ): array {
		$submitted = $this->keep_hardcoded_containers( $submitted );

		list( $stored, $errors ) = $this->sanitize_onto( $submitted, $this->stored_row() );

		update_option( GTM4WP_OPTIONS, $stored );

		return $errors;
	}

	/**
	 * Replaces the whole stored row: every value of $options that maps to a
	 * registered field is sanitized onto the module defaults, so options
	 * missing from the input fall back to their default and the row lands
	 * complete and coherent. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $options Raw option key => value map (untrusted).
	 * @return array<string, string> Error map, option key => sanitizer message.
	 */
	public function replace( array $options ): array {
		list( $stored, $errors ) = $this->sanitize_onto( $options, $this->registry->defaults() );

		update_option( GTM4WP_OPTIONS, $stored );

		return $errors;
	}

	/**
	 * Builds the settings export envelope: a header plus the current option
	 * values. Schema driven, so every registered option is included and
	 * nothing else; on import every value passes its Field sanitizer again.
	 *
	 * @return array<string, mixed>
	 */
	public function export_data(): array {
		return array(
			'plugin'  => 'gtm4wp',
			'type'    => self::EXPORT_TYPE,
			'version' => defined( 'GTM4WP_VERSION' ) ? GTM4WP_VERSION : '',
			'options' => $this->current_values(),
		);
	}

	/**
	 * Decodes and checks an export file's contents: json_decode() only (never
	 * unserialize/eval), a size cap before decoding, a depth cap on the
	 * decoder, and the envelope's type marker. Nothing in the file is trusted
	 * beyond that; replace() sanitizes every value it carries.
	 *
	 * @param mixed $payload The raw file contents, a JSON string.
	 * @return array<string, mixed>|\WP_Error The option key => value map of the file.
	 */
	public function decode_import( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload ) {
			return new \WP_Error(
				'gtm4wp_import_empty',
				__( 'No settings file was uploaded.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $payload ) > self::IMPORT_MAX_BYTES ) {
			return new \WP_Error(
				'gtm4wp_import_too_large',
				__( 'The settings file is too large to be a GTM4WP export.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		$decoded = json_decode( $payload, true, self::IMPORT_MAX_DEPTH );

		if ( ! is_array( $decoded )
			|| ! isset( $decoded['type'], $decoded['options'] )
			|| self::EXPORT_TYPE !== $decoded['type']
			|| ! is_array( $decoded['options'] )
		) {
			return new \WP_Error(
				'gtm4wp_import_invalid',
				__( 'The uploaded file is not a valid GTM4WP settings export.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		return $decoded['options'];
	}

	/**
	 * Builds the JSON schema properties of the settings object from the
	 * registered fields: each option key maps to its REST value type, so a
	 * caller can validate each value's type before it reaches the sanitizer.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function value_schema(): array {
		$properties = array();

		foreach ( $this->fields_by_key() as $option_key => $field ) {
			$properties[ $option_key ] = array(
				'type' => $field->rest_type(),
			);
		}

		return $properties;
	}

	/**
	 * Collects the Field definitions of every registered module, keyed by
	 * option key. Only these keys are ever written, so a submitted key that
	 * matches no field can not be injected into the option row.
	 *
	 * @return array<string, Field>
	 */
	public function fields_by_key(): array {
		$fields = array();

		foreach ( $this->fields_by_module() as $module_fields ) {
			foreach ( $module_fields as $field ) {
				$fields[ $field->key ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * The Field definitions of every registered module, keyed by module id in
	 * registry order - the order the settings screen lists the modules in.
	 *
	 * @return array<string, Field[]>
	 */
	public function fields_by_module(): array {
		$by_module = array();

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();
			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$by_module[ $module->id() ] = ( new $schema_class() )->fields();
		}

		return $by_module;
	}

	/**
	 * The stored option row, or an empty array when it is missing or not an array.
	 *
	 * @return array<string, mixed>
	 */
	private function stored_row(): array {
		$stored = get_option( GTM4WP_OPTIONS, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Keeps the container values wp-config.php controls out of the stored row:
	 * the screen submits the rows actually loaded, so a save would otherwise
	 * persist the constant's value over the admin's own setup. When the
	 * constants decide the row set the submitted value is dropped; when only
	 * columns are locked, each locked cell is restored from the stored row with
	 * the same container ID (matching by ID survives reordering). A crafted
	 * request can still write anything; it stays inert at output time.
	 *
	 * @param array<string, mixed> $submitted Raw option key => value map (untrusted).
	 * @return array<string, mixed> The submitted map with the locked container values restored.
	 */
	private function keep_hardcoded_containers( array $submitted ): array {
		if ( ! array_key_exists( GTM4WP_OPTION_GTM_CONTAINERS, $submitted ) || ! HardcodedContainers::is_active() ) {
			return $submitted;
		}

		$locks = HardcodedContainers::locks();

		if ( array() !== $locks['rows'] ) {
			unset( $submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] );

			return $submitted;
		}

		$stored_rows  = ContainerRows::normalize( $this->current_values()[ GTM4WP_OPTION_GTM_CONTAINERS ] ?? array() );
		$stored_by_id = array_column( $stored_rows, null, ContainerRows::COLUMN_ID );

		$rows = is_array( $submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] ) ? $submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] : array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row_id = isset( $row[ ContainerRows::COLUMN_ID ] ) && is_scalar( $row[ ContainerRows::COLUMN_ID ] )
				? trim( (string) $row[ ContainerRows::COLUMN_ID ] )
				: '';

			foreach ( array_keys( $locks['columns'] ) as $column ) {
				$rows[ $index ][ $column ] = $stored_by_id[ $row_id ][ $column ] ?? '';
			}
		}

		$submitted[ GTM4WP_OPTION_GTM_CONTAINERS ] = $rows;

		return $submitted;
	}

	/**
	 * Runs every submitted value that maps to a registered field through its
	 * sanitizer onto $base: the single validation path of save and replace.
	 * Unknown keys are ignored (no arbitrary option key can be injected), a
	 * rejected value leaves $base untouched and lands in the error map, and
	 * each field's derived values (the flat 1.x mirrors) are regenerated.
	 *
	 * @param array<string, mixed> $submitted Raw option key => value map (untrusted).
	 * @param array<string, mixed> $base      Option row the sanitized values are written onto.
	 * @return array{0: array<string, mixed>, 1: array<string, string>} [ stored row, error map ].
	 */
	private function sanitize_onto( array $submitted, array $base ): array {
		$stored = $base;
		$errors = array();

		foreach ( $this->fields_by_key() as $option_key => $field ) {
			if ( ! array_key_exists( $option_key, $submitted ) ) {
				continue;
			}

			$sanitized = $field->sanitize( $submitted[ $option_key ] );

			if ( is_wp_error( $sanitized ) ) {
				$errors[ $option_key ] = $sanitized->get_error_message();
				continue;
			}

			$stored[ $option_key ] = $sanitized;

			foreach ( $field->derived_values( $sanitized ) as $derived_key => $derived_value ) {
				$stored[ $derived_key ] = $derived_value;
			}
		}

		return array( $stored, $errors );
	}
}
