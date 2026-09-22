<?php
/**
 * Unit tests for the ability registrar.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Abilities\Registrar;
use GTM4WP\Abilities\SettingsAbilities;
use GTM4WP\Abilities\StatusAbilities;
use GTM4WP\Module\Registry;
use GTM4WP\Modules\GoogleAuth\Abilities as GoogleAuthAbilities;
use GTM4WP\Modules\GoogleDataManager\Abilities;
use GTM4WP\Tests\unit\Admin\UndocumentedThirdPartyModule;

/**
 * The registrar owns four things: the two core actions it hangs the work
 * on, the one category every ability lives in, the site-wide off switch,
 * and the walk that lets a module - ours or a third party's - contribute
 * abilities by opting in on its admin schema. The catalogue itself is
 * ContractTest's.
 *
 * The WordPress < 6.9 branch (wp_register_ability() undefined) cannot be
 * exercised in-process: Brain Monkey defines a stubbed function permanently,
 * so function_exists() is true for the rest of the run. Below 6.9 the two
 * actions never fire in the first place, which is the real guard.
 */
final class RegistrarTest extends AbilitiesTestCase {

	/**
	 * The plugin-wide abilities, sorted: what a registry with no opted-in
	 * module yields.
	 */
	private const PLUGIN_WIDE = array(
		SettingsAbilities::EXPORT_SETTINGS,
		SettingsAbilities::GET_SETTINGS,
		StatusAbilities::GET_SITE_HEALTH,
		StatusAbilities::GET_STATUS,
		SettingsAbilities::IMPORT_SETTINGS,
		SettingsAbilities::UPDATE_SETTINGS,
	);

	protected function setUp(): void {
		parent::setUp();

		AbilityProvidingThirdPartySchema::$handed_over = 0;
		ThirdPartyAbilities::$registered               = 0;
	}

	/**
	 * A registrar over the given modules only, so what a module contributes
	 * can be told apart from the plugin-wide providers.
	 *
	 * @param array<int, object> $modules Modules to register.
	 * @return Registrar
	 */
	private function registrar_over( array $modules ): Registrar {
		$registry = new Registry();

		foreach ( $modules as $module ) {
			$registry->add( $module );
		}

		return new Registrar( $registry );
	}

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

	// ---- Module providers --------------------------------------------------

	public function test_a_module_whose_schema_opts_in_registers_under_the_plugins_category(): void {
		$this->registrar_over( array( new AbilityProvidingThirdPartyModule() ) )->register_abilities();

		$this->assertArrayHasKey( ThirdPartyAbilities::GET_WIDGETS, $this->registered );
		$this->assertSame( Registrar::CATEGORY, $this->registered[ ThirdPartyAbilities::GET_WIDGETS ]['category'] );
		$this->assertSame( 1, AbilityProvidingThirdPartySchema::$handed_over, 'The schema is asked for its provider once.' );
		$this->assertSame( 1, ThirdPartyAbilities::$registered, 'The provider registers once.' );

		foreach ( self::PLUGIN_WIDE as $name ) {
			$this->assertArrayHasKey( $name, $this->registered, "$name belongs to no module and comes along whatever the registry holds." );
		}
	}

	public function test_a_schema_without_the_interface_contributes_nothing_and_does_not_fatal(): void {
		// The same guarantee the settings page and Site Health give an old
		// third party schema: instanceof, never a method call that would fatal.
		$this->registrar_over( array( new UndocumentedThirdPartyModule() ) )->register_abilities();

		$actual = array_keys( $this->registered );
		sort( $actual );

		$this->assertSame( self::PLUGIN_WIDE, $actual, 'Only the plugin-wide abilities: a module ability comes from the registry walk, not from a list.' );
		$this->assertArrayNotHasKey( Abilities::GET_LOG, $this->registered, 'With the Data Manager module absent from the registry, its ability is absent too.' );
		$this->assertArrayNotHasKey( GoogleAuthAbilities::GET_ACCOUNTS, $this->registered, 'Same for the service-accounts module.' );
	}

	public function test_a_module_naming_a_schema_class_that_does_not_exist_is_skipped(): void {
		$module = new class() extends AbilityProvidingThirdPartyModule {
			public function admin_schema(): string {
				return 'GTM4WP_No_Such_Schema';
			}
		};

		$this->registrar_over( array( $module ) )->register_abilities();

		$this->assertArrayNotHasKey( ThirdPartyAbilities::GET_WIDGETS, $this->registered );
		$this->assertArrayHasKey( StatusAbilities::GET_STATUS, $this->registered, 'One broken module does not take the plugin-wide abilities down with it.' );
	}

	public function test_the_off_switch_withholds_a_modules_ability_without_asking_the_schema(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ENABLED )->once()->with( true )->andReturn( false );

		$this->registrar_over( array( new AbilityProvidingThirdPartyModule() ) )->register_abilities();

		$this->assertSame( array(), $this->registered );
		$this->assertSame( 0, AbilityProvidingThirdPartySchema::$handed_over, 'The switch is checked before the walk, so an opted-out site builds no provider at all.' );
	}

	public function test_the_data_manager_ability_reaches_the_registry_through_its_module(): void {
		( new Registrar( $this->registry() ) )->register_abilities();

		$this->assertArrayHasKey( Abilities::GET_LOG, $this->registered, 'The built-in module opts in the same way a third party does; the Registrar names no module.' );
		$this->assertArrayHasKey( Abilities::TEST_DESTINATION, $this->registered );
		$this->assertArrayHasKey( Abilities::REPLAY_REFUNDS, $this->registered );
	}

	public function test_the_service_account_abilities_reach_the_registry_through_their_module(): void {
		( new Registrar( $this->registry() ) )->register_abilities();

		$this->assertArrayHasKey( GoogleAuthAbilities::GET_ACCOUNTS, $this->registered, 'GoogleAuth opts in on its admin schema like the Data Manager module; the Registrar names neither.' );
		$this->assertArrayHasKey( GoogleAuthAbilities::TEST_ACCOUNT, $this->registered );
	}

	// ---- The write switch (TS-12: both halves of the gate) -----------------

	public function test_the_write_switch_withholds_every_write_and_keeps_every_read(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );

		( new Registrar( $this->registry() ) )->register_abilities();

		$this->assertArrayNotHasKey( SettingsAbilities::UPDATE_SETTINGS, $this->registered, 'A read-only site never lists the write, so a client cannot discover an ability it may not run.' );
		$this->assertArrayNotHasKey( SettingsAbilities::IMPORT_SETTINGS, $this->registered );
		$this->assertArrayNotHasKey( GoogleAuthAbilities::TEST_ACCOUNT, $this->registered, 'Contacting Google with the site\'s credentials is withheld with the writes.' );
		$this->assertArrayNotHasKey( Abilities::TEST_DESTINATION, $this->registered );
		$this->assertArrayNotHasKey( Abilities::REPLAY_REFUNDS, $this->registered );
		$this->assertArrayHasKey( SettingsAbilities::GET_SETTINGS, $this->registered );
		$this->assertArrayHasKey( SettingsAbilities::EXPORT_SETTINGS, $this->registered );
		$this->assertArrayHasKey( StatusAbilities::GET_STATUS, $this->registered );
		$this->assertArrayHasKey( GoogleAuthAbilities::GET_ACCOUNTS, $this->registered );
		$this->assertArrayHasKey( Abilities::GET_LOG, $this->registered );
		$this->assertCount( 6, $this->registered, 'The six reads of the catalogue.' );

		// The generic half: whatever the catalogue grows to, nothing registered
		// on a read-only site claims to write - a provider that forgets to check
		// the switch fails here.
		foreach ( $this->registered as $name => $args ) {
			$this->assertTrue( $args['meta']['annotations']['readonly'], "$name is registered on a read-only site, so it must be a read." );
		}
	}

	public function test_can_write_needs_the_switch_and_the_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		$this->assertTrue( Registrar::can_write(), 'Switch on (default) and capability held.' );
	}

	public function test_can_write_denies_a_user_without_the_capability_even_with_the_switch_on(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		$this->assertFalse( Registrar::can_write() );
	}

	public function test_can_write_denies_when_the_switch_is_off_without_asking_for_the_capability(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->once()->with( true )->andReturn( false );
		Functions\expect( 'current_user_can' )->never();

		$this->assertFalse( Registrar::can_write() );
	}

	public function test_can_write_ignores_the_input_core_hands_over(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		$this->assertTrue( call_user_func( array( Registrar::class, 'can_write' ), array( 'values' => array() ) ), 'Core passes the ability input; a TypeError here would deny every write.' );
	}

	public function test_a_write_run_after_the_switch_flipped_is_denied_and_refuses_with_403(): void {
		// Registered while writes were allowed - the ability exists and a client
		// may still hold its name - then the site turns writes off.
		( new Registrar( $this->registry() ) )->register_abilities();
		$this->assertArrayHasKey( SettingsAbilities::UPDATE_SETTINGS, $this->registered );

		$this->store_settings( array( GTM4WP_OPTION_DATALAYER_NAME => 'keep' ) );

		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertFalse( call_user_func( $this->registered[ SettingsAbilities::UPDATE_SETTINGS ]['permission_callback'], array() ), 'The permission callback core consults denies first.' );

		$result = $this->execute( SettingsAbilities::UPDATE_SETTINGS, array( 'values' => array( GTM4WP_OPTION_DATALAYER_NAME => 'changed' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_abilities_write_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'keep', $this->stored_settings()[ GTM4WP_OPTION_DATALAYER_NAME ], 'Nothing was written.' );
		$this->assertSame( 0, $this->settings_writes() );
	}
}
