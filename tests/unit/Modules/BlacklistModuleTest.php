<?php
/**
 * Unit tests for the Blacklist module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\Blacklist\BlacklistModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the refreshed entity ID table and the gtm.whitelist/gtm.blacklist
 * data layer output ported from 1.x.
 */
final class BlacklistModuleTest extends TestCase {

	/**
	 * Builds a booted module with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return BlacklistModule
	 */
	private function make_module( array $stored ): BlacklistModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module  = new BlacklistModule();
		$options = new Options( $module->defaults() );
		$module->frontend( $options );

		return $module;
	}

	public function test_entity_table_matches_google_restrict_documentation(): void {
		$valid = BlacklistModule::valid_entity_ids();

		// Added in the 2.0 refresh.
		$this->assertContains( 'gaawc', $valid, 'Google tag (GA4 Configuration) must be restrictable.' );
		$this->assertContains( 'gaawe', $valid, 'GA4 Event tag must be restrictable.' );
		$this->assertContains( 'gas', $valid, 'Google Analytics Settings variable must be restrictable.' );

		// Removed in the 2.0 refresh (no longer documented by Google).
		$this->assertNotContains( 'ua', $valid, 'Universal Analytics tag is no longer documented.' );
		$this->assertNotContains( 'mf', $valid, 'Mouseflow tag is no longer documented.' );

		// Individual IDs only: no group classes.
		foreach ( array( 'google', 'nonGoogleScripts', 'nonGooglePixels', 'nonGoogleIframes', 'customScripts', 'customPixels', 'sandboxedScripts' ) as $group_class ) {
			$this->assertNotContains( $group_class, $valid, "Group class '{$group_class}' must not be part of the entity table." );
		}

		// No duplicates across the three groups.
		$this->assertSame( count( $valid ), count( array_unique( $valid ) ) );
	}

	public function test_blacklist_mode_populates_gtm_blacklist(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html,img,gaawe',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'html', 'img', 'gaawe' ), $data_layer['gtm.blacklist'] );
		$this->assertSame( array(), $data_layer['gtm.whitelist'] );
	}

	public function test_whitelist_mode_populates_gtm_whitelist(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 2,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'gaawc,u',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'gaawc', 'u' ), $data_layer['gtm.whitelist'] );
		$this->assertSame( array(), $data_layer['gtm.blacklist'] );
	}

	public function test_sandboxed_scripts_group_class_is_restrictable(): void {
		// sandboxedScripts is a valid GTM group class with no individual entity
		// ID equivalent - it lives in the group-class list, not the entity table.
		$this->assertContains( 'sandboxedScripts', BlacklistModule::valid_group_classes() );
		$this->assertContains( 'sandboxedScripts', BlacklistModule::valid_restrictions() );
		$this->assertNotContains( 'sandboxedScripts', BlacklistModule::valid_entity_ids() );

		$blacklist = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html,sandboxedScripts',
			)
		)->add_datalayer_data( array() );

		$this->assertSame( array( 'html', 'sandboxedScripts' ), $blacklist['gtm.blacklist'] );
		$this->assertSame( array(), $blacklist['gtm.whitelist'] );

		$whitelist = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 2,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'sandboxedScripts',
			)
		)->add_datalayer_data( array() );

		$this->assertSame( array( 'sandboxedScripts' ), $whitelist['gtm.whitelist'] );
		$this->assertSame( array(), $whitelist['gtm.blacklist'] );
	}

	public function test_unsupported_group_classes_and_hostile_input_are_filtered_out(): void {
		// Only sandboxedScripts is supported: other group classes, and a script
		// break-out string, must be dropped by the allow-list before emission,
		// so nothing but the one supported class reaches the data layer sink.
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'sandboxedScripts,customScripts,google,' . "\x3c/script\x3e" . ',nonGoogleScripts',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'sandboxedScripts' ), $data_layer['gtm.blacklist'] );
	}

	public function test_invalid_and_stale_entities_are_filtered_out(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				// 'ua' and 'mf' are stale 1.x entries; 'evil' was never valid.
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html,ua,mf,evil',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'html' ), $data_layer['gtm.blacklist'] );
	}

	public function test_frontend_hooks_only_registered_when_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$module  = new BlacklistModule();
		$options = new Options( $module->defaults() );
		$module->frontend( $options );

		$this->assertFalse(
			has_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, array( $module, 'add_datalayer_data' ) ),
			'Disabled blacklist must not register the compile filter.'
		);
	}
}
