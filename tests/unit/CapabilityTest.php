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

	/**
	 * #285: a wrong-typed or empty filter return used to reach current_user_can()
	 * as '' or "Array" and deny everyone silently. It is a site bug: reported
	 * through _doing_it_wrong() and the default capability is kept.
	 *
	 * @param mixed $returned What the site's callback returned.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_non_capability_returns' )]
	public function test_a_non_string_filter_return_is_reported_and_falls_back_to_the_default( $returned ): void {
		Filters\expectApplied( Capability::FILTER )->once()->with( 'manage_options' )->andReturn( $returned );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\expect( '_doing_it_wrong' )->once()->with( 'GTM4WP\Capability::settings', \Mockery::type( 'string' ), '2.1' );

		$this->assertSame( 'manage_options', Capability::settings() );
	}

	/**
	 * The returns a site callback can hand back that are not a capability name.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_non_capability_returns(): array {
		return array(
			'null'         => array( null ),
			'false'        => array( false ),
			'empty string' => array( '' ),
			'array'        => array( array( 'manage_options' ) ),
		);
	}

	public function test_a_capability_string_from_the_filter_raises_no_notice(): void {
		Filters\expectApplied( Capability::FILTER )->once()->with( 'manage_options' )->andReturn( 'do_not_allow' );
		Functions\expect( '_doing_it_wrong' )->never();

		$this->assertSame( 'do_not_allow', Capability::settings() );
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
