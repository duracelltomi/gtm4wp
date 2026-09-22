<?php
/**
 * Shared fixture of the ability tests.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use Brain\Monkey\Functions;
use GTM4WP\Module\Registry;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * Every ability test builds the REAL module registry, so the stubs every
 * AdminSchema reaches are declared here, in the base class of each file
 * (TS-16: a test stubs what it reaches, in its own setUp, never borrowing
 * another file's process-wide stub). The list is the one RestControllerTest
 * needs for the same reason, plus what the abilities themselves call.
 *
 * The option table is the in-memory one of OptionStoreTrait, so a test can
 * seed the settings row, the service-account row and the send log the same
 * way and read back what an ability wrote.
 */
abstract class AbilitiesTestCase extends TestCase {

	use OptionStoreTrait;

	/**
	 * Every wp_register_ability() call, keyed by ability name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $registered = array();

	/**
	 * Every wp_register_ability_category() call, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $categories = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// Reached by the AdminSchemas while the real registry is built.
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn ( $value ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names(): array {
					return array();
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		// Reached by the abilities.
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $number ) => ( 1 === (int) $number ? $single : $plural ) );
		// The conflicting-plugin checks reach it once e-commerce tracking is on; on a
		// REST request the function is loaded from wp-admin, here it is stubbed absent.
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'menu_page_url' )->alias( static fn ( $slug, $display = true ) => 'https://example.com/wp-admin/options-general.php?page=' . $slug );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url = '' ) {
				if ( is_array( $args ) ) {
					return $url . '?' . http_build_query( $args );
				}

				return (string) func_get_arg( 2 ) . '&' . $args . '=' . rawurlencode( (string) $url );
			}
		);
		// The queue backend probes; stubbed so the reported backend never depends
		// on which file defined an as_* function first (TS-16).
		Functions\when( '_get_cron_array' )->justReturn( array() );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );

		$this->registered = array();
		$this->categories = array();

		// Core _doing_it_wrong()s a duplicate name and keeps the first
		// registration; the fake is stricter and fails outright (TS-13: a
		// double that silently overwrote would hide a provider registering the
		// same ability twice). A test that re-registers resets $registered first.
		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->assertArrayNotHasKey( (string) $name, $this->registered, "$name registered twice." );

				$this->registered[ (string) $name ] = (array) $args;

				return null;
			}
		);
		Functions\when( 'wp_register_ability_category' )->alias(
			function ( $slug, $args ) {
				$this->categories[ (string) $slug ] = (array) $args;

				return null;
			}
		);

		$this->stub_option_store();
	}

	/**
	 * The real module registry.
	 *
	 * @return Registry
	 */
	protected function registry(): Registry {
		return Registry::with_default_modules();
	}

	/**
	 * Seeds the settings row.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return void
	 */
	protected function store_settings( array $stored ): void {
		$this->options[ GTM4WP_OPTIONS ] = $stored;
	}

	/**
	 * The settings row as the option table holds it now - what a write
	 * ability left behind. The trait's table is private to this class, so a
	 * subclass reads it through here.
	 *
	 * @return array<string, mixed>|null Null when no row exists.
	 */
	protected function stored_settings(): ?array {
		return $this->options[ GTM4WP_OPTIONS ] ?? null;
	}

	/**
	 * How many times the settings row was written (update_option() calls
	 * for it), unchanged writes included.
	 *
	 * @return int
	 */
	protected function settings_writes(): int {
		return count( array_filter( $this->option_writes, static fn ( array $write ): bool => GTM4WP_OPTIONS === $write['key'] ) );
	}

	/**
	 * A registered ability's execute callback, run with the given input.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input The input.
	 * @return mixed
	 */
	protected function execute( string $name, $input = array() ) {
		$this->assertArrayHasKey( $name, $this->registered, "$name must be registered." );

		$result = call_user_func( $this->registered[ $name ]['execute_callback'], $input );

		// Core validates every result against the output schema and answers
		// ability_invalid_output on a miss, which a direct call to the execute
		// callback never sees (T105g). Every ability test inherits the check.
		if ( ! $result instanceof \WP_Error ) {
			$this->assert_matches_schema( $result, (array) $this->registered[ $name ]['output_schema'], "$name output" );
		}

		return $result;
	}

	/**
	 * Asserts a value validates against a JSON-schema fragment the way core's
	 * WP_Ability::validate_output() (rest_validate_value_from_schema()) would:
	 * type (one or several), enum, required, properties, additionalProperties
	 * and items. Stricter than core where core is lenient - an integer is
	 * is_int(), never a numeric string - which is the safe direction for a
	 * double.
	 *
	 * @param mixed                $value  The value.
	 * @param array<string, mixed> $schema The schema fragment.
	 * @param string               $path   Where the value sits, for the message.
	 * @return void
	 */
	protected function assert_matches_schema( $value, array $schema, string $path ): void {
		$types = (array) ( $schema['type'] ?? array() );
		$type  = null;

		foreach ( $types as $candidate ) {
			if ( self::is_of_type( $value, (string) $candidate ) ) {
				$type = (string) $candidate;
				break;
			}
		}

		$this->assertNotNull( $type, "$path: " . get_debug_type( $value ) . ' is none of [' . implode( ', ', $types ) . '].' );

		if ( isset( $schema['enum'] ) ) {
			$this->assertContains( $value, (array) $schema['enum'], "$path: value outside the enum." );
		}

		if ( 'object' === $type ) {
			$members    = (array) $value;
			$properties = (array) ( $schema['properties'] ?? array() );

			foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
				$this->assertArrayHasKey( $required, $members, "$path: required member $required missing." );
			}

			foreach ( $members as $key => $member ) {
				if ( isset( $properties[ $key ] ) ) {
					$this->assert_matches_schema( $member, (array) $properties[ $key ], "$path.$key" );
					continue;
				}

				$additional = $schema['additionalProperties'] ?? true;

				$this->assertNotFalse( $additional, "$path.$key: not a declared member, and additionalProperties is false." );

				if ( is_array( $additional ) ) {
					$this->assert_matches_schema( $member, $additional, "$path.$key" );
				}
			}
		}

		if ( 'array' === $type && isset( $schema['items'] ) ) {
			foreach ( (array) $value as $index => $item ) {
				$this->assert_matches_schema( $item, (array) $schema['items'], "{$path}[{$index}]" );
			}
		}
	}

	/**
	 * Whether a PHP value is of a JSON-schema type. An empty array is both a
	 * list and an object, as for core.
	 *
	 * @param mixed  $value The value.
	 * @param string $type  The schema type word.
	 * @return bool
	 */
	private static function is_of_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'object':
				return $value instanceof \stdClass || ( is_array( $value ) && ( array() === $value || ! self::is_list( $value ) ) );
			case 'array':
				return is_array( $value ) && self::is_list( $value );
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'null':
				return null === $value;
			default:
				return false;
		}
	}

	/**
	 * Whether an array is a list (keys 0..n-1 in order); PHP 8.0 has no array_is_list().
	 *
	 * @param array<mixed> $value The array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
