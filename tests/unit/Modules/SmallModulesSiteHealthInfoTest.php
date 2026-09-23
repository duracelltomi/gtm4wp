<?php
/**
 * Unit tests for the Site Health rows of the single-purpose modules.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\Amp\AmpModule;
use GTM4WP\Modules\Blacklist\BlacklistModule;
use GTM4WP\Modules\ClientDeviceData\ClientDeviceDataModule;
use GTM4WP\Modules\ConsentMode\ConsentModeModule;
use GTM4WP\Modules\MediaEvents\MediaEventsModule;
use GTM4WP\Modules\UserEvents\UserEventsModule;
use GTM4WP\Modules\VisitorData\VisitorDataModule;

/**
 * One or two rows each: the option states as a group, a text option as
 * set/empty, a list by its bare ids. Together in one file because each
 * module's rows are a direct reading of its options.
 */
final class SmallModulesSiteHealthInfoTest extends ModuleSiteHealthTestCase {

	public function test_client_device_data_lists_its_three_switches(): void {
		$rows = $this->rows( new ClientDeviceDataModule(), array( GTM4WP_OPTION_INCLUDE_OSDATA => true ) );

		$this->assertSame( array( 'options' ), array_keys( $rows ) );
		$this->assertSame(
			array(
				GTM4WP_OPTION_INCLUDE_BROWSERDATA => 'off',
				GTM4WP_OPTION_INCLUDE_OSDATA      => 'on',
				GTM4WP_OPTION_INCLUDE_DEVICEDATA  => 'off',
			),
			$rows['options']['debug']
		);
	}

	public function test_the_cache_safe_data_layer_is_one_switch(): void {
		$this->assertSame( 'off', $this->rows( new VisitorDataModule() )['cache_safe_datalayer']['debug'] );
		$this->assertSame( 'on', $this->rows( new VisitorDataModule(), array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) )['cache_safe_datalayer']['debug'] );
	}

	public function test_user_events_lists_its_four_switches(): void {
		$rows = $this->rows( new UserEventsModule(), array( GTM4WP_OPTION_EVENTS_USERLOGIN => true ) );

		$this->assertSame( array( 'options' ), array_keys( $rows ) );
		$this->assertSame(
			array( GTM4WP_OPTION_EVENTS_FORMMOVE, GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY, GTM4WP_OPTION_EVENTS_NEWUSERREG, GTM4WP_OPTION_EVENTS_USERLOGIN ),
			array_keys( $rows['options']['debug'] )
		);
		$this->assertSame( 'on', $rows['options']['debug'][ GTM4WP_OPTION_EVENTS_USERLOGIN ] );
	}

	public function test_media_events_lists_the_players_and_keeps_the_dailymotion_id_to_set_or_empty(): void {
		$rows = $this->rows(
			new MediaEventsModule(),
			array(
				GTM4WP_OPTION_EVENTS_YOUTUBE              => true,
				GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID => 'x1abc2',
			)
		);

		$this->assertSame( array( 'players', 'dynamic_media', 'dailymotion_player_id' ), array_keys( $rows ) );
		$this->assertSame(
			array(
				GTM4WP_OPTION_EVENTS_YOUTUBE,
				GTM4WP_OPTION_EVENTS_VIMEO,
				GTM4WP_OPTION_EVENTS_SOUNDCLOUD,
				GTM4WP_OPTION_EVENTS_HTML5MEDIA,
				GTM4WP_OPTION_EVENTS_DAILYMOTION,
				GTM4WP_OPTION_EVENTS_MIXCLOUD,
				GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM,
				GTM4WP_OPTION_EVENTS_WISTIA,
				GTM4WP_OPTION_EVENTS_JWPLAYER,
				GTM4WP_OPTION_EVENTS_VIDEOPRESS,
				GTM4WP_OPTION_EVENTS_SPOTIFY,
				GTM4WP_OPTION_EVENTS_TWITCH,
			),
			array_keys( $rows['players']['debug'] )
		);
		$this->assertSame( 'on', $rows['players']['debug'][ GTM4WP_OPTION_EVENTS_YOUTUBE ] );
		$this->assertArrayNotHasKey( GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC, $rows['players']['debug'], 'Not a player: its own row.' );
		$this->assertSame( 'off', $rows['dynamic_media']['debug'] );
		$this->assertSame( 'set', $rows['dailymotion_player_id']['debug'] );
		$this->assertStringNotContainsString( 'x1abc2', $this->text( $rows ) );
	}

	public function test_consent_mode_reports_the_defaults_the_signals_and_the_tools(): void {
		$rows = $this->rows(
			new ConsentModeModule(),
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE => true,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => true,
				GTM4WP_OPTION_INTEGRATE_COOKIEBOT   => true,
			)
		);

		$this->assertSame( array( 'consent_mode', 'granted_by_default', 'consent_tools' ), array_keys( $rows ), 'No Axeptio row while Axeptio is off.' );
		$this->assertSame( 'on', $rows['consent_mode']['debug'] );
		$this->assertSame(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC,
				GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY,
			),
			array_keys( $rows['granted_by_default']['debug'] )
		);
		$this->assertSame( 'on', $rows['granted_by_default']['debug'][ GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS ] );
		$this->assertSame( 'off', $rows['granted_by_default']['debug'][ GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS ] );
		$this->assertSame(
			array( GTM4WP_OPTION_INTEGRATE_COOKIEBOT, GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR, GTM4WP_OPTION_INTEGRATE_COOKIEYES, GTM4WP_OPTION_INTEGRATE_AXEPTIO ),
			array_keys( $rows['consent_tools']['debug'] )
		);
		$this->assertSame( 'on', $rows['consent_tools']['debug'][ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ] );
	}

	public function test_axeptio_gets_its_own_row_with_the_project_id_as_set_or_empty(): void {
		$rows = $this->rows(
			new ConsentModeModule(),
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => '5f1a2b3c4d5e6f7a8b9c0d1e',
			)
		);

		$this->assertSame(
			array(
				'project_id'      => 'set',
				'cookies_version' => 'empty',
				'consent_mode'    => 'off',
			),
			$rows['axeptio']['debug']
		);
		$this->assertStringNotContainsString( '5f1a2b3c', $this->text( $rows ) );
	}

	public function test_amp_lists_its_container_ids_or_says_off(): void {
		$this->assertSame( 'off', $this->rows( new AmpModule() )['amp_containers']['debug'] );

		$rows = $this->rows( new AmpModule(), array( GTM4WP_OPTION_INTEGRATE_AMPID => 'GTM-AMP111, GTM-AMP222' ) );

		$this->assertSame( 'GTM-AMP111, GTM-AMP222', $rows['amp_containers']['debug'], 'Container IDs are in every AMP page.' );
	}

	public function test_the_amp_plugin_is_present_when_its_function_exists_whatever_it_returns(): void {
		// T109: existence decides, not the return value. Stubbed here on purpose:
		// AmpModuleTest defines the function process-wide, so under a random order
		// this file cannot tell "present" from "absent" without saying which it is.
		\Brain\Monkey\Functions\when( 'amp_is_request' )->justReturn( false );

		$this->assertSame( 'present', $this->rows( new AmpModule() )['amp_plugin']['debug'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_the_amp_plugin_is_absent_when_neither_of_its_functions_exists(): void {
		$this->assertFalse( function_exists( 'amp_is_request' ), 'Precondition (TS-16): no AMP in this process.' );
		$this->assertFalse( function_exists( 'is_amp_endpoint' ) );

		$rows = $this->rows( new AmpModule() );

		$this->assertSame( 'absent', $rows['amp_plugin']['debug'] );
		$this->assertSame( '[absent]', $rows['amp_plugin']['value'] );
	}

	public function test_tag_restrictions_report_the_mode_and_a_count_of_valid_entries(): void {
		$rows = $this->rows( new BlacklistModule() );

		$this->assertSame( 'disabled', $rows['mode']['debug'] );
		$this->assertSame( '0', $rows['restrictions']['debug'] );

		$valid = BlacklistModule::valid_restrictions();
		$rows  = $this->rows(
			new BlacklistModule(),
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 2,
				GTM4WP_OPTION_BLACKLIST_STATUS => array( $valid[0], $valid[1], 'not-a-real-entity' ),
			)
		);

		$this->assertSame( 'allowlist', $rows['mode']['debug'] );
		$this->assertSame( '2', $rows['restrictions']['debug'], 'Counted after the same re-validation the data layer applies.' );

		// T114: mode 1 is the block list, and a 1.x comma-string is split before counting.
		$rows = $this->rows(
			new BlacklistModule(),
			array(
				GTM4WP_OPTION_BLACKLIST_ENABLE => 1,
				GTM4WP_OPTION_BLACKLIST_STATUS => $valid[0] . ',' . $valid[2] . ',not-a-real-entity',
			)
		);

		$this->assertSame( 'blocklist', $rows['mode']['debug'] );
		$this->assertSame( '[blocklist]', $rows['mode']['value'] );
		$this->assertSame( '2', $rows['restrictions']['debug'] );
	}
}
