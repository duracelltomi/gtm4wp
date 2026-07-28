<?php
/**
 * Unit tests for the gtm4wp_admin_page_capability filter.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Admin\RestController;
use GTM4WP\Admin\SettingsPage;
use GTM4WP\Module\Registry;
use GTM4WP\Plugin;
use GTM4WP\Tests\unit\TestCase;

/**
 * The capability needed to see and manage the GTM4WP settings is a single
 * filterable value - gtm4wp_admin_page_capability, default 'manage_options'
 * (since 1.20) - so an admin can delegate the settings to a non-admin role
 * (issue #143). These tests pin ALL FOUR enforcement sites:
 *
 * - Plugin::boot() - the gate that decides whether the admin code path loads at
 *   all, so it is the outermost of the four;
 * - RestController::can_manage() - the REST permission_callback shared by the
 *   read / save / export / import routes;
 * - SettingsPage::add_admin_page() - the add_options_page() capability argument,
 *   which WordPress enforces for BOTH the Settings submenu registration and the
 *   options page render guard;
 * - Notices::dismiss_notice() - the wp_ajax handler's own re-check (covered in
 *   NoticesTest, next to the nonce and notice-id assertions it belongs with).
 *
 * Each site is proven to (a) check 'manage_options' when the filter is not used,
 * so nothing changes by default, and (b) route a filtered custom capability
 * through to the real current_user_can() check - granting or denying access
 * accordingly. Without the filter these assertions fail, so the guard cannot be
 * silently removed. The registry is irrelevant to can_manage()/add_admin_page(),
 * so an empty one keeps those tests focused on the capability wiring.
 *
 * Test-review Run 5 (2026-08-05) added the Plugin::boot() cases: this file used
 * to say it covered "the two enforcement sites the issue names", and a sweep for
 * the sites rather than the ones a finding named turned up four (gap T30). A
 * gate closed where a finding pointed is a fix, not a sweep - TS-12.
 */
final class AdminCapabilityFilterTest extends TestCase {

	private const FILTER    = 'gtm4wp_admin_page_capability';
	private const CUSTOMCAP = 'manage_gtm4wp';

	public function test_rest_permission_checks_manage_options_by_default(): void {
		// No filter override: Brain Monkey's apply_filters() passes the default
		// through unchanged, so the endpoint keeps requiring manage_options.
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		$controller = new RestController( new Registry() );

		$this->assertTrue( $controller->can_manage() );
	}

	public function test_rest_permission_grants_when_filtered_capability_is_held(): void {
		Filters\expectApplied( self::FILTER )
			->once()
			->with( 'manage_options' )
			->andReturn( self::CUSTOMCAP );

		Functions\expect( 'current_user_can' )
			->once()
			->with( self::CUSTOMCAP )
			->andReturn( true );

		$controller = new RestController( new Registry() );

		$this->assertTrue(
			$controller->can_manage(),
			'A user holding the filtered capability is granted access to the settings REST endpoint.'
		);
	}

	public function test_rest_permission_denies_when_filtered_capability_is_missing(): void {
		Filters\expectApplied( self::FILTER )
			->once()
			->with( 'manage_options' )
			->andReturn( self::CUSTOMCAP );

		Functions\expect( 'current_user_can' )
			->once()
			->with( self::CUSTOMCAP )
			->andReturn( false );

		$controller = new RestController( new Registry() );

		$this->assertFalse(
			$controller->can_manage(),
			'A user lacking the filtered capability is denied access to the settings REST endpoint.'
		);
	}

	/**
	 * Drives SettingsPage::add_admin_page() and returns the capability it hands
	 * to add_options_page() - the value WordPress enforces for both the Settings
	 * submenu and the options page render.
	 *
	 * @return string|null
	 */
	private function capture_menu_capability(): ?string {
		// esc_html__() for the page/menu titles.
		Functions\stubTranslationFunctions();

		$captured = null;
		Functions\when( 'add_options_page' )->alias(
			function ( $page_title, $menu_title, $capability, $menu_slug, $callback ) use ( &$captured ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- mock matches the real add_options_page() signature
				$captured = $capability;
				return 'settings_page_' . GTM4WP_ADMINSLUG;
			}
		);

		$page = new SettingsPage( new Registry(), new RestController( new Registry() ) );
		$page->add_admin_page();

		return $captured;
	}

	public function test_settings_menu_registers_with_manage_options_by_default(): void {
		$this->assertSame(
			'manage_options',
			$this->capture_menu_capability(),
			'Unfiltered, the settings page keeps requiring manage_options for the menu and the render.'
		);
	}

	public function test_settings_menu_registers_with_filtered_capability(): void {
		Filters\expectApplied( self::FILTER )
			->once()
			->with( 'manage_options' )
			->andReturn( self::CUSTOMCAP );

		$this->assertSame(
			self::CUSTOMCAP,
			$this->capture_menu_capability(),
			'A filtered capability becomes the add_options_page() requirement, gating both the menu and the render.'
		);
	}

	/**
	 * Runs Plugin::boot() on an admin request and reports whether the admin code
	 * path loaded, detected through the hooks Admin::boot() registers - nothing
	 * else in boot() touches admin_menu.
	 *
	 * Note that boot() also builds the real module registry and populates the
	 * compat globals, so the WordPress functions those need are stubbed here;
	 * the singleton is released in tearDown (TC-8/TS-7).
	 *
	 * @return Plugin The booted instance (also installed as the singleton).
	 */
	private function boot_admin_request(): Plugin {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'plugin_basename' )->alias( static fn ( $file ) => basename( (string) $file ) );

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $plugin );
		$plugin->boot();

		return $plugin;
	}

	/**
	 * Whether the admin code path loaded, detected through the hooks
	 * Admin::boot() registers.
	 *
	 * @return bool
	 */
	private function admin_path_loaded(): bool {
		return false !== has_action( 'admin_menu' );
	}

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
	 * The outermost gate: Plugin::boot() decides whether ANY admin code loads.
	 * Unfiltered it must keep asking for manage_options, so a site that does not
	 * use the filter sees no change.
	 */
	public function test_admin_boot_checks_manage_options_by_default(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		$this->boot_admin_request();

		$this->assertTrue( $this->admin_path_loaded(), 'An administrator loads the admin code path.' );
	}

	public function test_admin_boot_loads_for_a_user_holding_the_filtered_capability(): void {
		Filters\expectApplied( self::FILTER )
			->once()
			->with( 'manage_options' )
			->andReturn( self::CUSTOMCAP );

		Functions\expect( 'current_user_can' )
			->once()
			->with( self::CUSTOMCAP )
			->andReturn( true );

		$this->boot_admin_request();

		$this->assertTrue(
			$this->admin_path_loaded(),
			'A delegated role holding the filtered capability gets the admin code path (issue #143).'
		);
	}

	public function test_admin_boot_is_skipped_for_a_user_without_the_capability(): void {
		Filters\expectApplied( self::FILTER )
			->once()
			->with( 'manage_options' )
			->andReturn( self::CUSTOMCAP );

		Functions\expect( 'current_user_can' )
			->once()
			->with( self::CUSTOMCAP )
			->andReturn( false );

		$this->boot_admin_request();

		$this->assertFalse(
			$this->admin_path_loaded(),
			'Without the capability no admin hook is registered at all - the settings page, the notices and the AJAX dismiss handler are all absent.'
		);
		$this->assertFalse( has_action( 'wp_ajax_gtm4wp_dismiss_notice' ), 'The AJAX dismiss handler is not even wired up.' );
	}

	public function test_admin_boot_never_registers_the_frontend_path(): void {
		// The two paths are mutually exclusive (boot() returns after the admin
		// branch). A denied capability must not fall through to boot_frontend().
		Functions\expect( 'current_user_can' )->once()->andReturn( false );

		$plugin = $this->boot_admin_request();

		$this->assertFalse( has_action( 'wp_head' ), 'The frontend path must not load on an admin request.' );
		$this->assertNull( $plugin->frontend(), 'No Frontend orchestrator may be built for an admin request.' );
	}
}
