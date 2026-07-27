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
use GTM4WP\Tests\unit\TestCase;

/**
 * The capability needed to see and manage the GTM4WP settings is a single
 * filterable value - gtm4wp_admin_page_capability, default 'manage_options'
 * (since 1.20) - so an admin can delegate the settings to a non-admin role
 * (issue #143). These tests pin the two enforcement sites the issue names:
 *
 * - RestController::can_manage() - the REST permission_callback shared by the
 *   read / save / export / import routes;
 * - SettingsPage::add_admin_page() - the add_options_page() capability argument,
 *   which WordPress enforces for BOTH the Settings submenu registration and the
 *   options page render guard.
 *
 * Each site is proven to (a) check 'manage_options' when the filter is not used,
 * so nothing changes by default, and (b) route a filtered custom capability
 * through to the real current_user_can() check - granting or denying access
 * accordingly. Without the filter these assertions fail, so the guard cannot be
 * silently removed. The registry is irrelevant to both methods, so an empty one
 * keeps the tests focused on the capability wiring.
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
			function ( $page_title, $menu_title, $capability, $menu_slug, $callback ) use ( &$captured ) {
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
}
