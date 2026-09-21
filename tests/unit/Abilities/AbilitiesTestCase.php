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

		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
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

		return call_user_func( $this->registered[ $name ]['execute_callback'], $input );
	}
}
