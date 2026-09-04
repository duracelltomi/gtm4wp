<?php
/**
 * Unit tests for the rest_api_init wiring in Plugin::boot().
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Plugin;

/**
 * A REST route's gate has two halves: the permission callback and its
 * attachment (TS-12). GoogleAuthRestControllerTest pins the callback and the
 * register_rest_route() arguments by calling register_routes() directly -
 * which is exactly how the attachment itself stays unexecuted: the closure
 * Plugin::boot() hangs on rest_api_init could be deleted with every direct
 * test green (the T39 / issue #143 lesson, one hop up). These tests run the
 * real boot(), capture that closure, invoke it, and assert the routes land.
 *
 * The registration is deliberately unconditional (no option gate - see the
 * comment in boot()), so unlike the TC-4 module gates there is no disabled
 * state to assert; the one wiring test is the whole contract.
 */
final class PluginRestWiringTest extends TestCase {

	/**
	 * Every register_rest_route() call: namespace, route and args.
	 *
	 * @var array<int, array{ns: string, route: string, args: array}>
	 */
	private array $routes = array();

	protected function tearDown(): void {
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );
		unset(
			$GLOBALS['gtm4wp_options'],
			$GLOBALS['gtm4wp_datalayer_name'],
			$GLOBALS['gtm4wp_datalayer_data'],
			$GLOBALS['gtm4wp_additional_datalayer_pushes'],
			$GLOBALS['gtm4wp_container_code_written']
		);

		parent::tearDown();
	}

	/**
	 * Boots the plugin (lightest path: admin request, capability denied - the
	 * rest_api_init wiring precedes the admin/frontend split, so neither code
	 * path needs to load) and returns the captured rest_api_init closure.
	 *
	 * @param array<string, mixed> $stored_options The gtm4wp-options row boot() reads.
	 * @return callable|null
	 */
	private function boot_and_capture_rest_api_init( array $stored_options = array() ): ?callable {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static fn ( $key, $default_value = false ) => ( GTM4WP_OPTIONS === $key ) ? $stored_options : $default_value
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'plugin_basename' )->alias( static fn ( $file ) => basename( (string) $file ) );

		// register_routes() on the settings controller walks every AdminSchema
		// for the value schema, which needs the settings-page stub set (TS-16).
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $value ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);
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
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$captured = null;
		Actions\expectAdded( 'rest_api_init' )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$captured ) {
					$captured = $callback;
				}
			);

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $plugin );
		$plugin->boot();

		return $captured;
	}

	/**
	 * Invokes the captured closure with register_rest_route() recorded.
	 *
	 * @param callable $rest_api_init The captured closure.
	 * @return void
	 */
	private function run_rest_api_init( callable $rest_api_init ): void {
		$this->routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route, $args = array() ) {
				$this->routes[] = array(
					'ns'    => $ns,
					'route' => $route,
					'args'  => $args,
				);
				return true;
			}
		);

		$rest_api_init();
	}

	/**
	 * Every permission_callback in one registration's args (a call registers
	 * either one endpoint or a list of endpoint arrays).
	 *
	 * @param array $args The register_rest_route() args.
	 * @return array<int, mixed>
	 */
	private static function permission_callbacks( array $args ): array {
		if ( isset( $args['permission_callback'] ) ) {
			return array( $args['permission_callback'] );
		}

		$callbacks = array();
		foreach ( $args as $endpoint ) {
			if ( is_array( $endpoint ) && isset( $endpoint['permission_callback'] ) ) {
				$callbacks[] = $endpoint['permission_callback'];
			}
		}

		return $callbacks;
	}

	public function test_boot_wires_a_rest_api_init_closure_that_registers_the_settings_and_google_routes(): void {
		$closure = $this->boot_and_capture_rest_api_init();
		$this->assertNotNull( $closure, 'boot() must hang the REST registration on rest_api_init.' );

		$this->run_rest_api_init( $closure );

		// The whole-namespace CORS withdrawal rides the same closure (#97).
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request' ), 'RestCors must be registered by the same closure.' );

		// One namespace for every registration - the RestCorsTest namespace
		// consistency property, re-checked at the attachment.
		$namespaces = array_unique( array_column( $this->routes, 'ns' ) );
		$this->assertCount( 1, $namespaces, 'Every route registers under the one gtm4wp namespace RestCors withdraws CORS for.' );

		// The settings routes land, gated by the Admin controller.
		$settings_callbacks = $this->callbacks_of_controller( \GTM4WP\Admin\RestController::class );
		$this->assertNotEmpty( $settings_callbacks, 'The settings routes must be attached.' );

		// The five google/service-accounts endpoints land (list+upload share a
		// route, relabel+delete another, test its own), every one gated by the
		// GoogleAuth controller's can_manage.
		$google_routes = array_values(
			array_filter( $this->routes, static fn ( array $call ) => str_contains( $call['route'], 'google/service-accounts' ) )
		);
		$this->assertCount( 3, $google_routes, 'Listing+upload, relabel+delete and test-connection registrations must all be attached.' );

		$google_callbacks = array_merge( ...array_map( static fn ( array $call ) => self::permission_callbacks( $call['args'] ), $google_routes ) );
		$this->assertCount( 5, $google_callbacks, 'All five endpoints carry a permission callback.' );
		foreach ( $google_callbacks as $callback ) {
			$this->assertIsArray( $callback );
			$this->assertInstanceOf( \GTM4WP\Modules\GoogleAuth\RestController::class, $callback[0] );
			$this->assertSame( 'can_manage', $callback[1] );
		}

		// The destinations test endpoint lands, gated by the GoogleDataManager
		// controller's can_manage.
		$test_routes = array_values(
			array_filter( $this->routes, static fn ( array $call ) => str_contains( $call['route'], 'google/destinations/test' ) )
		);
		$this->assertCount( 1, $test_routes, 'The destinations test registration must be attached.' );

		$test_callbacks = self::permission_callbacks( $test_routes[0]['args'] );
		$this->assertCount( 1, $test_callbacks );
		$this->assertIsArray( $test_callbacks[0] );
		$this->assertInstanceOf( \GTM4WP\Modules\GoogleDataManager\RestController::class, $test_callbacks[0][0] );
		$this->assertSame( 'can_manage', $test_callbacks[0][1] );
	}

	/**
	 * The deletion veto is the other half of the destinations wiring: without
	 * it the delete route removes a key that stored destination rows still
	 * reference. Attachment AND effect are asserted - the captured callback
	 * must resolve a stored reference through the live Options service
	 * (Brain Monkey's apply_filters() does not run attached callbacks, so the
	 * callback is invoked directly).
	 */
	public function test_the_rest_closure_wires_the_service_account_in_use_veto(): void {
		$account_id = 'sa_0123456789ab';

		$veto = null;
		Filters\expectAdded( GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$veto ) {
					$veto = $callback;
				}
			);

		// The stored destination row must be in place BEFORE boot():
		// Plugin::boot() constructs the Options service the veto reads.
		$closure = $this->boot_and_capture_rest_api_init(
			array(
				GTM4WP_OPTION_GDM_DESTINATIONS => array(
					array(
						'label'           => 'Production',
						'service_account' => $account_id,
						'type'            => 'ga4',
						'property_id'     => '123456789',
						'measurement_id'  => 'G-ABC123',
					),
				),
			)
		);
		$this->assertNotNull( $closure );

		$this->run_rest_api_init( $closure );

		$this->assertNotNull( $veto, 'The veto filter must be attached by the same closure.' );
		$this->assertTrue( $veto( false, $account_id ), 'An account a stored destination references is reported in use.' );
		$this->assertFalse( $veto( false, 'sa_ffffffffffff' ), 'An unreferenced account stays deletable.' );
		$this->assertTrue( $veto( true, 'sa_ffffffffffff' ), 'A veto another consumer already raised is never overturned.' );
	}

	/**
	 * The attribution backfill is the plugin's only guest-facing route that is
	 * registered conditionally, so both directions of that condition are
	 * pinned here: an endpoint nobody needs should not exist, and one the
	 * feature needs must.
	 */
	public function test_the_backfill_route_is_registered_only_while_capture_is_on(): void {
		$this->run_rest_api_init(
			$this->boot_and_capture_rest_api_init( array( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true ) )
		);

		$this->assertNotSame(
			array(),
			$this->callbacks_of_controller( \GTM4WP\Modules\GoogleDataManager\BackfillEndpoint::class ),
			'With capture on the route exists, behind its own permission callback.'
		);
	}

	public function test_no_backfill_route_while_capture_is_off(): void {
		$this->run_rest_api_init( $this->boot_and_capture_rest_api_init() );

		$this->assertSame(
			array(),
			$this->callbacks_of_controller( \GTM4WP\Modules\GoogleDataManager\BackfillEndpoint::class )
		);
	}

	/**
	 * Personal-data requests run from wp-admin, from cron and from WP-CLI, so
	 * the exporter and eraser are attached in boot() rather than in either
	 * branch below it. Without this assertion that line can be deleted and the
	 * suite stays green while every real site loses the removal path for data
	 * uninstalling deliberately leaves behind.
	 */
	public function test_boot_registers_the_privacy_exporter_and_eraser(): void {
		$this->boot_and_capture_rest_api_init();

		$this->assertNotFalse(
			has_filter( 'wp_privacy_personal_data_exporters', 'GTM4WP\Modules\GoogleDataManager\PrivacyData->register_exporter()' )
		);
		$this->assertNotFalse(
			has_filter( 'wp_privacy_personal_data_erasers', 'GTM4WP\Modules\GoogleDataManager\PrivacyData->register_eraser()' )
		);
	}

	/**
	 * And they are attached whatever the capture setting says: a request has to
	 * find data captured while the feature was on, including after it has been
	 * turned off again.
	 */
	public function test_the_privacy_wiring_does_not_depend_on_the_capture_setting(): void {
		$this->boot_and_capture_rest_api_init( array( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => false ) );

		$this->assertNotFalse(
			has_filter( 'wp_privacy_personal_data_erasers', 'GTM4WP\Modules\GoogleDataManager\PrivacyData->register_eraser()' )
		);
	}

	/**
	 * The permission callbacks bound to methods of the given controller class.
	 *
	 * @param string $controller_class Controller class name.
	 * @return array<int, mixed>
	 */
	private function callbacks_of_controller( string $controller_class ): array {
		$matching = array();
		foreach ( $this->routes as $call ) {
			foreach ( self::permission_callbacks( $call['args'] ) as $callback ) {
				if ( is_array( $callback ) && $callback[0] instanceof $controller_class ) {
					$matching[] = $callback;
				}
			}
		}

		return $matching;
	}
}
