<?php
/**
 * Writes core's own ability-input verdicts into the fixture that pins
 * tests/unit/Abilities/CoreInputDouble.php (T158, U155).
 *
 * Runs WordPress core's real rest_validate_value_from_schema() and, as 7.2's
 * WP_Ability::sanitize_input() does, rest_sanitize_value_from_schema() over a
 * fixed schema x value corpus, one schema per keyword the double models.
 * Re-run at each WordPress release (U155) and commit the fixture; CI needs no
 * core. The tree you point it at is code this script executes (PA-18): use a
 * wordpress.org download.
 *
 * USAGE
 *     php tools/generate-core-input-cases.php <path to an unpacked wordpress/>
 *
 * @package GTM4WP
 */

declare( strict_types = 1 );

if ( $argc < 2 || ! is_file( rtrim( $argv[1], '/\\' ) . '/wp-includes/rest-api.php' ) ) {
	fwrite( STDERR, "usage: php tools/generate-core-input-cases.php <wordpress dir>\n" );
	exit( 1 );
}

$wp_root = rtrim( $argv[1], '/\\' ) . '/wp-includes/';
$target  = dirname( __DIR__ ) . '/tests/unit/Abilities/core-input-cases.json';

define( 'ABSPATH', dirname( $wp_root ) . '/' );
require $wp_root . 'version.php';
require $wp_root . 'class-wp-error.php';

// The few helpers rest-api.php reaches on these paths; messages are not recorded.
function __( $text, $domain = null ) { return $text; }
function _n( $single, $plural, $number, $domain = null ) { return 1 === $number ? $single : $plural; }
function _x( $text, $context, $domain = null ) { return $text; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function _doing_it_wrong( ...$args ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( ...$args ) {}
function wp_sprintf( $pattern, ...$args ) { return $pattern; }
function number_format_i18n( $number, $decimals = 0 ) { return (string) $number; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_is_numeric_array( $data ) { return is_array( $data ) && array() === array_filter( array_keys( $data ), 'is_string' ); }
function wp_parse_list( $input_list ) { return is_array( $input_list ) ? array_values( array_filter( $input_list, 'is_scalar' ) ) : preg_split( '/[\s,]+/', (string) $input_list, -1, PREG_SPLIT_NO_EMPTY ); }
require $wp_root . 'rest-api.php';

$reference = '^[^:]+:[0-9]+:[0-9]+$';
$schemas   = array(
	'boolean'          => array( 'type' => 'boolean' ),
	'integer'          => array( 'type' => 'integer' ),
	'integer range'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
	'number'           => array( 'type' => 'number' ),
	'string'           => array( 'type' => 'string', 'description' => 'annotation only' ),
	'string pattern'   => array( 'type' => 'string', 'pattern' => $reference ),
	'string enum'      => array( 'type' => 'string', 'enum' => array( 'a', 'container' ) ),
	'string|integer'   => array( 'type' => array( 'string', 'integer' ) ),
	'boolean|null'     => array( 'type' => array( 'boolean', 'null' ) ),
	'array'            => array( 'type' => 'array' ),
	'array of refs'    => array( 'type' => 'array', 'minItems' => 1, 'items' => array( 'type' => 'string', 'pattern' => $reference ) ),
	'array of ints'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
	'array of enum'    => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'a', 'container' ) ) ),
	'object'           => array( 'type' => 'object' ),
	'object, strict'   => array(
		'type'                 => 'object',
		'default'              => array(),
		'required'             => array( 'flag' ),
		'minProperties'        => 1,
		'additionalProperties' => false,
		'properties'           => array(
			'flag' => array( 'type' => 'boolean' ),
			'n'    => array( 'type' => 'integer' ),
		),
	),
);
$values = array(
	true, false, 'true', 'false', 'TRUE', '1', '0', 1, 0, 2, -1, 51, 'yes', '', ' ', null, '5', '5.0', 5.5, '1e2',
	'abc', 'a', 'container', 'a,b', 'a, container', 'w:1:2', 'w:1:2,w:3:4', 'typo:x:y',
	array(), array( 'a' ), array( 'w:1:2', 42 ), array( '1', '2' ), array( 'x' => 1 ), array( 1 => 'a' ),
	array( 'flag' => true ), array( 'flag' => '1', 'n' => '5' ), array( 'n' => 5 ), array( 'flag' => true, 'extra' => 1 ),
);

$cases = array();
foreach ( $schemas as $id => $schema ) {
	foreach ( $values as $value ) {
		$valid   = ! is_wp_error( rest_validate_value_from_schema( $value, $schema, 'input' ) );
		$case    = array(
			'schema' => $id,
			'value'  => $value,
			'valid'  => $valid,
		);
		if ( $valid ) {
			$case['cast'] = rest_sanitize_value_from_schema( $value, $schema, 'input' );
		}
		$cases[] = $case;
	}
}

$json = json_encode(
	array(
		'generated_by' => 'tools/generate-core-input-cases.php',
		'wp_version'   => $wp_version,
		'schemas'      => $schemas,
		'cases'        => $cases,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
);
file_put_contents( $target, str_replace( '    ', "\t", $json ) . "\n" );
printf( "core %s: %d schemas x %d values = %d cases -> %s\n", $wp_version, count( $schemas ), count( $values ), count( $cases ), $target );
