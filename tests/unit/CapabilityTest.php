<?php
/**
 * Unit tests for the shared settings capability helper.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Capability;

/**
 * Capability is the fifth enforcement site of the gtm4wp_admin_page_capability
 * filter (after Plugin::boot(), the settings page, the REST controllers and
 * the notice dismissal, all pinned in AdminCapabilityFilterTest) and the one
 * the abilities' permission callbacks use directly. Same three cases as every
 * other site (TS-12 / TC-13): the default when the filter is unused, a
 * filtered capability granted, a filtered capability denied.
 */
final class CapabilityTest extends TestCase {

	private const CUSTOMCAP = 'manage_gtm4wp';

	public function test_the_default_capability_is_manage_options(): void {
		$this->assertSame( 'manage_options', Capability::settings() );
		$this->assertSame( 'manage_options', Capability::DEFAULT_CAPABILITY );
		$this->assertSame( 'gtm4wp_admin_page_capability', Capability::FILTER );
	}

	public function test_the_filter_changes_the_required_capability(): void {
		Filters\expectApplied( Capability::FILTER )
			->once()
			->with( 'manage_options' )
			->andReturn( self::CUSTOMCAP );

		$this->assertSame( self::CUSTOMCAP, Capability::settings() );
	}

	public function test_can_manage_settings_checks_manage_options_by_default(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		$this->assertTrue( Capability::can_manage_settings() );
	}

	public function test_can_manage_settings_grants_the_filtered_capability(): void {
		Filters\expectApplied( Capability::FILTER )->once()->with( 'manage_options' )->andReturn( self::CUSTOMCAP );
		Functions\expect( 'current_user_can' )->once()->with( self::CUSTOMCAP )->andReturn( true );

		$this->assertTrue( Capability::can_manage_settings() );
	}

	public function test_can_manage_settings_denies_a_user_lacking_the_filtered_capability(): void {
		Filters\expectApplied( Capability::FILTER )->once()->with( 'manage_options' )->andReturn( self::CUSTOMCAP );
		Functions\expect( 'current_user_can' )->once()->with( self::CUSTOMCAP )->andReturn( false );

		$this->assertFalse( Capability::can_manage_settings() );
	}

	/**
	 * Core hands an ability's permission callback the ability's input. The
	 * helper declares no parameter on purpose and must not mind the argument:
	 * a TypeError here would deny every ability call with a confusing error.
	 */
	public function test_can_manage_settings_ignores_an_input_argument(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		$this->assertTrue( call_user_func( array( Capability::class, 'can_manage_settings' ), array( 'modules' => array( 'container' ) ) ) );
	}
}
