<?php
/**
 * Unit tests for the ability registrar.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use Brain\Monkey\Filters;
use GTM4WP\Abilities\Registrar;

/**
 * The registrar owns three things: the two core actions it hangs the work
 * on, the one category every ability lives in, and the site-wide off switch.
 * The catalogue itself is ContractTest's.
 *
 * The WordPress < 6.9 branch (wp_register_ability() undefined) cannot be
 * exercised in-process: Brain Monkey defines a stubbed function permanently,
 * so function_exists() is true for the rest of the run. Below 6.9 the two
 * actions never fire in the first place, which is the real guard.
 */
final class RegistrarTest extends AbilitiesTestCase {

	public function test_registration_is_hung_on_the_two_core_actions(): void {
		$registrar = new Registrar( $this->registry() );

		$registrar->register_hooks();

		$this->assertNotFalse( has_action( Registrar::HOOK_CATEGORIES, array( $registrar, 'register_category' ) ) );
		$this->assertNotFalse( has_action( Registrar::HOOK_ABILITIES, array( $registrar, 'register_abilities' ) ) );
		$this->assertSame( 'wp_abilities_api_categories_init', Registrar::HOOK_CATEGORIES, 'Core fires the categories action first; an ability naming an unregistered category is silently dropped.' );
		$this->assertSame( 'wp_abilities_api_init', Registrar::HOOK_ABILITIES );
	}

	public function test_the_category_is_registered_with_a_label_and_a_description(): void {
		( new Registrar( $this->registry() ) )->register_category();

		$this->assertArrayHasKey( Registrar::CATEGORY, $this->categories );
		$this->assertSame( 'gtm4wp', Registrar::CATEGORY );
		$this->assertNotSame( '', $this->categories['gtm4wp']['label'] );
		$this->assertNotSame( '', $this->categories['gtm4wp']['description'] );
	}

	public function test_every_ability_is_registered_under_that_category(): void {
		( new Registrar( $this->registry() ) )->register_abilities();

		$this->assertNotEmpty( $this->registered );

		foreach ( $this->registered as $name => $args ) {
			$this->assertSame( Registrar::CATEGORY, $args['category'], $name );
			$this->assertStringStartsWith( Registrar::NAMESPACE_PREFIX, $name );
		}
	}

	public function test_the_surface_is_on_by_default(): void {
		$this->assertTrue( Registrar::is_enabled() );
		$this->assertTrue( Registrar::writes_allowed() );
	}

	public function test_the_off_switch_withholds_the_category_and_every_ability(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ENABLED )->twice()->with( true )->andReturn( false );

		$registrar = new Registrar( $this->registry() );
		$registrar->register_category();
		$registrar->register_abilities();

		$this->assertSame( array(), $this->categories, 'A site that opted out gets no category.' );
		$this->assertSame( array(), $this->registered, 'A site that opted out gets no ability - the MCP Adapter then lists none of ours.' );
	}

	public function test_the_off_switch_reads_the_constant_named_filter(): void {
		$this->assertSame( 'gtm4wp_abilities_enabled', GTM4WP_WPFILTER_ABILITIES_ENABLED );
		$this->assertSame( 'gtm4wp_abilities_allow_write', GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE );
	}

	public function test_the_write_switch_is_a_separate_filter(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->once()->with( true )->andReturn( false );

		$this->assertFalse( Registrar::writes_allowed() );
		$this->assertTrue( Registrar::is_enabled(), 'Turning writes off leaves the read-only surface on.' );
	}
}
