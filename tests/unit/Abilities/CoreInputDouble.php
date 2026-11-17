<?php
/**
 * A stand-in for WordPress core's ability input pipeline (T158).
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

/**
 * Core validates ability input against its schema (6.9+) and, from 7.2, casts
 * it to the schema types for every caller (U155). This models both halves for
 * the keywords in KEYWORDS only: ContractTest refuses a schema using anything
 * else, and CoreInputDoubleTest replays core's own verdicts from
 * core-input-cases.json, which tools/generate-core-input-cases.php writes from
 * a real core tree. Regenerate the fixture at each WordPress release.
 */
final class CoreInputDouble {

	/**
	 * The schema keywords modelled. Annotations change nothing.
	 */
	public const KEYWORDS = array(
		'type',
		'properties',
		'required',
		'additionalProperties',
		'enum',
		'pattern',
		'minimum',
		'maximum',
		'minItems',
		'minProperties',
		'items',
		'default',
		'description',
	);

	/**
	 * Core's validation verdict: true, or the reason it refuses.
	 *
	 * @param mixed                $value  The input.
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $path   Where the value sits, for the reason.
	 * @return true|string
	 */
	public static function validate( $value, array $schema, string $path = 'input' ): bool|string {
		$type = self::best_type( $value, (array) ( $schema['type'] ?? array() ) );

		if ( '' === $type ) {
			return "$path is not of type " . implode( ',', (array) ( $schema['type'] ?? array() ) );
		}

		if ( isset( $schema['enum'] ) && ! in_array( self::cast( $value, $schema ), (array) $schema['enum'], true ) ) {
			return "$path is not one of the enum";
		}

		switch ( $type ) {
			case 'object':
				return self::validate_object( self::to_object( $value ), $schema, $path );
			case 'array':
				return self::validate_list( self::to_list( $value ), $schema, $path );
			case 'string':
				if ( isset( $schema['pattern'] ) && ! preg_match( '#' . str_replace( '#', '\\#', (string) $schema['pattern'] ) . '#u', $value ) ) {
					return "$path does not match pattern";
				}
				return true;
			case 'integer':
			case 'number':
				if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
					return "$path is below minimum";
				}
				if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
					return "$path is above maximum";
				}
				return true;
			default:
				return true;
		}
	}

	/**
	 * Core 7.2's cast of validated input (rest_sanitize_value_from_schema()).
	 *
	 * @param mixed                $value  Validated input.
	 * @param array<string, mixed> $schema The schema.
	 * @return mixed
	 */
	public static function cast( $value, array $schema ) {
		switch ( self::best_type( $value, (array) ( $schema['type'] ?? array() ) ) ) {
			case 'boolean':
				if ( is_string( $value ) && in_array( strtolower( $value ), array( 'false', '0' ), true ) ) {
					return false;
				}
				return (bool) $value;
			case 'integer':
				return (int) $value;
			case 'number':
				return (float) $value;
			case 'string':
				return (string) $value;
			case 'array':
				$list = self::to_list( $value );
				if ( isset( $schema['items'] ) ) {
					foreach ( $list as $index => $item ) {
						$list[ $index ] = self::cast( $item, (array) $schema['items'] );
					}
				}
				return $list;
			case 'object':
				$object = self::to_object( $value );
				foreach ( $object as $key => $member ) {
					if ( isset( $schema['properties'][ $key ] ) ) {
						$object[ $key ] = self::cast( $member, (array) $schema['properties'][ $key ] );
					}
				}
				return $object;
			default:
				return $value;
		}
	}

	/**
	 * Validates an object's members.
	 *
	 * @param array<string, mixed> $value  The object as an array.
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $path   Where the value sits.
	 * @return true|string
	 */
	private static function validate_object( array $value, array $schema, string $path ): bool|string {
		foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
			if ( ! array_key_exists( $required, $value ) ) {
				return "$required is a required property of $path";
			}
		}

		if ( isset( $schema['minProperties'] ) && count( $value ) < $schema['minProperties'] ) {
			return "$path has too few properties";
		}

		foreach ( $value as $key => $member ) {
			if ( isset( $schema['properties'][ $key ] ) ) {
				$valid = self::validate( $member, (array) $schema['properties'][ $key ], "{$path}[{$key}]" );
				if ( true !== $valid ) {
					return $valid;
				}
			} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
				return "$key is not a valid property of $path";
			}
		}

		return true;
	}

	/**
	 * Validates a list's items.
	 *
	 * @param array<int, mixed>    $value  The list.
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $path   Where the value sits.
	 * @return true|string
	 */
	private static function validate_list( array $value, array $schema, string $path ): bool|string {
		if ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] ) {
			return "$path has too few items";
		}

		foreach ( $value as $index => $item ) {
			if ( isset( $schema['items'] ) ) {
				$valid = self::validate( $item, (array) $schema['items'], "{$path}[{$index}]" );
				if ( true !== $valid ) {
					return $valid;
				}
			}
		}

		return true;
	}

	/**
	 * The first schema type the value can be, in the schema's order, as
	 * rest_get_best_type_for_value() picks it; '' when none.
	 *
	 * @param mixed    $value The value.
	 * @param string[] $types The schema types.
	 * @return string
	 */
	private static function best_type( $value, array $types ): string {
		if ( '' === $value && in_array( 'string', $types, true ) ) {
			return 'string';
		}

		foreach ( $types as $type ) {
			if ( self::is_type( $value, (string) $type ) ) {
				return (string) $type;
			}
		}

		return '';
	}

	/**
	 * Core's lenient type test (rest_is_boolean(), rest_is_integer(), ...).
	 *
	 * @param mixed  $value The value.
	 * @param string $type  The schema type.
	 * @return bool
	 */
	private static function is_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'array':
				return null !== self::list_or_null( $value );
			case 'object':
				return '' === $value || is_array( $value ) || $value instanceof \stdClass;
			case 'integer':
				return is_numeric( $value ) && round( (float) $value ) === (float) $value;
			case 'number':
				return is_numeric( $value );
			case 'boolean':
				if ( is_string( $value ) ) {
					return in_array( strtolower( $value ), array( 'true', 'false', '0', '1' ), true );
				}
				return is_bool( $value ) || 0 === $value || 1 === $value;
			case 'string':
				return is_string( $value );
			case 'null':
				return null === $value;
			default:
				return false;
		}
	}

	/**
	 * The value as a list the way core reads one, or null when it is not one:
	 * a scalar splits on commas and whitespace, an array needs no string keys
	 * (wp_is_numeric_array(), not keys 0..n-1).
	 *
	 * @param mixed $value The value.
	 * @return array<int, mixed>|null
	 */
	private static function list_or_null( $value ): ?array {
		if ( is_scalar( $value ) ) {
			return (array) preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $value ) || array() !== array_filter( array_keys( $value ), 'is_string' ) ) {
			return null;
		}

		return array_values( $value );
	}

	/**
	 * The value as a list.
	 *
	 * @param mixed $value The value.
	 * @return array<int, mixed>
	 */
	private static function to_list( $value ): array {
		return (array) self::list_or_null( $value );
	}

	/**
	 * The value as an object, '' being an empty one.
	 *
	 * @param mixed $value The value.
	 * @return array<string, mixed>
	 */
	private static function to_object( $value ): array {
		return '' === $value ? array() : (array) $value;
	}
}
