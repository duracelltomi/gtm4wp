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
 * Covers the refreshed entity ID table and the gtm.allowlist/gtm.blocklist
 * data layer output (ported from 1.x, which wrote the undocumented legacy
 * gtm.whitelist/gtm.blacklist names).
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

		/*
		 * Documented by Google, so restrictable. `mf` was dropped during the
		 * 2.0 refresh alongside `ua`, but only `ua` was actually retired
		 * upstream - verified against the restriction page on 2026-08-05.
		 */
		$this->assertContains( 'mf', $valid, 'Mouseflow tag is still documented by Google and must be restrictable.' );

		// Removed in the 2.0 refresh (no longer documented by Google).
		$this->assertNotContains( 'ua', $valid, 'Universal Analytics tag is no longer documented.' );

		// Individual IDs only: no group classes.
		foreach ( array( 'google', 'nonGoogleScripts', 'nonGooglePixels', 'nonGoogleIframes', 'customScripts', 'customPixels', 'sandboxedScripts' ) as $group_class ) {
			$this->assertNotContains( $group_class, $valid, "Group class '{$group_class}' must not be part of the entity table." );
		}

		// No duplicates across the three groups.
		$this->assertSame( count( $valid ), count( array_unique( $valid ) ) );
	}

	public function test_blacklist_mode_populates_gtm_blocklist(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html,img,gaawe',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'html', 'img', 'gaawe' ), $data_layer['gtm.blocklist'] );

		/*
		 * The unselected mode's key must be OMITTED, never emitted as an empty
		 * array, and this is the assertion the whole feature rests on. Verified
		 * against Google Tag Manager's own runtime, not inferred - it reads the
		 * allowlist as `SA("gtm.allowlist") || SA("gtm.whitelist")` and then
		 * applies it with `a && (k = k && $A(g, h, b))`.
		 *
		 * An empty array is TRUTHY in JavaScript, so publishing an empty allowlist
		 * (under either name) alongside the blocklist tells the runtime an
		 * allowlist HAS been set - and its allow-test returns false for every
		 * entity, because nothing is in an empty list. 1.x and pre-fix 2.0 did
		 * exactly that in blocklist mode and blocked the ENTIRE container, not the
		 * selected entities. It fails closed, so it looks like an over-eager
		 * restriction rather than a bug.
		 *
		 * Do not "restore 1.x parity" here. The parity was the defect.
		 */
		$this->assertArrayNotHasKey( 'gtm.allowlist', $data_layer );
	}

	/**
	 * The rule the assertion above encodes, stated as its own case so a future
	 * change cannot satisfy the letter of it while reintroducing the behaviour:
	 * in blocklist mode the data layer must carry NO allowlist under any of the
	 * names Google Tag Manager reads, empty or otherwise.
	 *
	 * @return void
	 */
	public function test_blocklist_mode_publishes_no_allowlist_under_any_name(): void {
		$data_layer = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html,img',
			)
		)->add_datalayer_data( array() );

		// Every key the runtime consults for an allowlist (YA(): gtm.allowlist,
		// then gtm.whitelist). Present-and-empty is the failure mode, so assert
		// absence rather than emptiness.
		foreach ( array( 'gtm.allowlist', 'gtm.whitelist' ) as $allowlist_key ) {
			$this->assertArrayNotHasKey(
				$allowlist_key,
				$data_layer,
				"An allowlist under '{$allowlist_key}' - even an empty one - blocks every tag in the container."
			);
		}

		$this->assertSame( array( 'html', 'img' ), $data_layer['gtm.blocklist'] );
	}

	/**
	 * T45: the stored value can be an ARRAY, not only the comma string - the
	 * sanitizer accepts both shapes and a third party writing the option row
	 * directly can store either. The array branch skips the explode and was
	 * never exercised; it must validate each entry exactly like the string
	 * branch does.
	 *
	 * @return void
	 */
	public function test_blocklist_mode_reads_an_array_form_stored_status(): void {
		$data_layer = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => array( 'html', 'evil', 'img' ),
			)
		)->add_datalayer_data( array() );

		$this->assertSame( array( 'html', 'img' ), $data_layer['gtm.blocklist'], 'The array shape is validated entry by entry, like the comma string.' );
		$this->assertArrayNotHasKey( 'gtm.allowlist', $data_layer );
	}

	public function test_whitelist_mode_populates_gtm_allowlist(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 2,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'gaawc,u',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'gaawc', 'u' ), $data_layer['gtm.allowlist'] );
		$this->assertArrayNotHasKey( 'gtm.blocklist', $data_layer );
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

		$this->assertSame( array( 'html', 'sandboxedScripts' ), $blacklist['gtm.blocklist'] );
		$this->assertArrayNotHasKey( 'gtm.allowlist', $blacklist );

		$whitelist = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 2,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'sandboxedScripts',
			)
		)->add_datalayer_data( array() );

		$this->assertSame( array( 'sandboxedScripts' ), $whitelist['gtm.allowlist'] );
		$this->assertArrayNotHasKey( 'gtm.blocklist', $whitelist );
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

		$this->assertSame( array( 'sandboxedScripts' ), $data_layer['gtm.blocklist'] );
	}

	public function test_invalid_and_stale_entities_are_filtered_out(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				// 'ua' is a stale 1.x entry Google retired; 'evil' was never valid.
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html,ua,evil',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'html' ), $data_layer['gtm.blocklist'] );
	}

	/**
	 * Regression: `mf` was dropped in the 2.0 refresh alongside `ua`, but only
	 * `ua` was retired upstream - Google still documents Mouseflow. Dropping it
	 * failed silently: the id was filtered out of the emitted list and the
	 * label was absent from the settings screen, so a site that restricted
	 * Mouseflow simply stopped restricting it, with nothing to see. Asserts the
	 * whole path, because the entity table, the admin label and the migration
	 * each had to change for this to work.
	 */
	public function test_mouseflow_entity_survives_validation(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'mf',
			)
		);

		$data_layer = $module->add_datalayer_data( array() );

		$this->assertSame( array( 'mf' ), $data_layer['gtm.blocklist'] );
	}

	/**
	 * The key names are the whole contract with Google Tag Manager: the plugin
	 * writes them and something else entirely decides whether to read them, so a
	 * wrong name fails silently - every tag runs unrestricted while the settings
	 * screen still shows the restriction. gtm.allowlist/gtm.blocklist are what
	 * Google documents; gtm.whitelist/gtm.blacklist appear in neither the current
	 * page nor the older one, which is why they are not merely "also emitted".
	 *
	 * @param int $mode The restriction mode option value.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_restriction_modes' )]
	public function test_only_the_documented_key_names_are_emitted( int $mode ): void {
		$data_layer = $this->make_module(
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => $mode,
				GTM4WP_OPTION_BLACKLIST_STATUS => 'html',
			)
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'gtm.whitelist', $data_layer, 'The legacy key name is undocumented and must not be emitted.' );
		$this->assertArrayNotHasKey( 'gtm.blacklist', $data_layer, 'The legacy key name is undocumented and must not be emitted.' );

		// Exactly one restriction key, and it is a documented one.
		$restriction_keys = array_values(
			array_intersect( array_keys( $data_layer ), array( 'gtm.allowlist', 'gtm.blocklist' ) )
		);
		$this->assertCount( 1, $restriction_keys );
	}

	/**
	 * The two enabled restriction modes.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function provide_restriction_modes(): array {
		return array(
			'blocklist mode' => array( 1 ),
			'allowlist mode' => array( 2 ),
		);
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
