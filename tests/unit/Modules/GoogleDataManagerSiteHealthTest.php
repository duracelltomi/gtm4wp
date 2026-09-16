<?php
/**
 * Unit tests for the Site Health surfaces of the Data Manager integration.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Modules\GoogleDataManager\CaptureStats;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\GoogleDataManagerModule;
use GTM4WP\Modules\GoogleDataManager\SiteHealth;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * Two surfaces with opposite risks.
 *
 * The **status test** has to say something true about a feature whose failures
 * are silent: a key that stopped decrypting, a destination that keeps being
 * refused, or - the one nothing else would ever report - orders arriving with
 * no attribution captured at all.
 *
 * The **debug section** is the opposite problem. Its text is pasted wholesale
 * into public support threads by people who cannot review it first, so what is
 * NOT in it is the property worth pinning: no service-account address, no key
 * material, no raw error text from Google, no GA4 property IDs. That is
 * asserted against the whole rendered section rather than field by field, so a
 * row added later without thinking is caught by the same test.
 */
final class GoogleDataManagerSiteHealthTest extends TestCase {

	use OptionStoreTrait;

	private const NOW         = 1_800_000_000;
	private const SECRET      = 'unit-test-site-secret-do-not-reuse';
	private const MEASUREMENT = 'G-ABC123';
	private const PROPERTY    = '123456789';

	private KeyVault $vault;

	private DestinationHealth $health;

	private CaptureStats $stats;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url = '' ) {
				return $url . '?' . http_build_query( is_array( $args ) ? $args : array() );
			}
		);
		// Stubbed here rather than relied on: another suite defining an as_*
		// function defines it for the whole process, so which queue backend the
		// debug row reports would otherwise depend on test order (TS-16).
		Functions\when( '_get_cron_array' )->justReturn( array() );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'menu_page_url' )->alias( static fn ( $slug, $display = true ) => 'https://example.com/wp-admin/options-general.php?page=' . $slug );

		$this->stub_option_store();

		$this->vault  = new KeyVault( self::SECRET, static fn () => self::NOW );
		$this->health = new DestinationHealth( static fn () => self::NOW );
		$this->stats  = new CaptureStats( static fn () => self::NOW );
	}

	/**
	 * The Site Health service over stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return SiteHealth
	 */
	private function site_health( array $stored = array() ): SiteHealth {
		$this->options['gtm4wp-options'] = array_merge(
			array(
				GTM4WP_OPTION_GDM_DESTINATIONS => array(
					array(
						DestinationRows::COLUMN_LABEL    => 'Main property',
						DestinationRows::COLUMN_ACCOUNT  => 'sa_aaaaaaaaaaaa',
						DestinationRows::COLUMN_TYPE     => DestinationRows::TYPE_GA4,
						DestinationRows::COLUMN_PROPERTY => self::PROPERTY,
						DestinationRows::COLUMN_MEASUREMENT => self::MEASUREMENT,
					),
				),
			),
			$stored
		);

		return new SiteHealth(
			new Options( ( new GoogleDataManagerModule() )->defaults() ),
			$this->health,
			$this->stats,
			$this->vault
		);
	}

	/**
	 * The debug section as one flat string, for the "this is nowhere in here"
	 * assertions.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return string
	 */
	private function debug_text( array $stored = array() ): string {
		$info = $this->site_health( $stored )->add_debug_information( array() );

		return (string) json_encode( $info ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for a substring assertion.
	}

	/**
	 * Records enough failures to cross the threshold.
	 *
	 * @return void
	 */
	private function fail_the_destination(): void {
		for ( $i = 0; $i < DestinationHealth::FAILURE_THRESHOLD; $i++ ) {
			$this->health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED: no access to property 123456789.', 'PERMISSION_DENIED' );
		}
	}

	// ---- Registration ------------------------------------------------------

	public function test_the_test_is_registered_as_a_direct_one(): void {
		$site_health = $this->site_health();
		$tests       = $site_health->add_test( array() );

		$this->assertArrayHasKey( SiteHealth::TEST_ID, $tests['direct'] );
		$this->assertSame( array( $site_health, 'run_test' ), $tests['direct'][ SiteHealth::TEST_ID ]['test'] );
	}

	public function test_a_filter_value_that_is_not_an_array_is_passed_through(): void {
		$site_health = $this->site_health();

		$this->assertSame( 'unexpected', $site_health->add_test( 'unexpected' ) );
		$this->assertSame( 'unexpected', $site_health->add_debug_information( 'unexpected' ) );
	}

	// ---- The status test ---------------------------------------------------

	public function test_a_site_with_nothing_wrong_reports_good(): void {
		$result = $this->site_health()->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( '', $result['actions'] );
		$this->assertSame( SiteHealth::TEST_ID, $result['test'] );
	}

	public function test_a_repeatedly_failing_destination_is_critical_and_named(): void {
		$this->fail_the_destination();

		$result = $this->site_health()->run_test();

		// The same condition raises a red, non-dismissible admin notice; Site
		// Health must not file it under improvements.
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Main property', $result['description'] );
		$this->assertStringContainsString( 'href=', $result['actions'], 'The result links to the settings the admin has to fix.' );
	}

	public function test_a_destination_below_the_threshold_is_not_reported_yet(): void {
		$this->health->record_failure( self::MEASUREMENT, 'UNAVAILABLE', 'UNAVAILABLE' );

		$this->assertSame( 'good', $this->site_health()->run_test()['status'], 'One failure is a blip Google\'s own guidance says to retry through.' );
	}

	public function test_an_unreadable_key_is_critical_and_outranks_a_failing_destination(): void {
		$id = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $id );

		// What a rotated wp-config salt does: the row survives, the key inside
		// it no longer decrypts. The status is not set by corrupting the row -
		// it is set the first time something tries to use the key, which is the
		// sequence a real site goes through.
		$accounts = $this->options[ KeyVault::OPTION_NAME ];
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the stored blob really is base64; this fabricates one that will not decrypt.
		$accounts[ $id ]['key']['ciphertext'] = base64_encode( 'not-the-key-any-more' );

		$this->options[ KeyVault::OPTION_NAME ] = $accounts;

		$this->assertInstanceOf( \WP_Error::class, $this->vault->open( $id ), 'Opening the key is what flags the account.' );

		$this->fail_the_destination();

		$result = $this->site_health()->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString(
			'no longer be read',
			$result['label'],
			'Every destination on that key is dead, which outranks one destination being refused: the key is what gets reported.'
		);
	}

	public function test_orders_arriving_with_no_attribution_is_reported(): void {
		$this->stats->record( false );
		$this->stats->record( false );

		$result = $this->site_health( array( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true ) )->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '2', $result['description'] );
		$this->assertStringContainsString( 'Google Analytics tag', $result['description'] );
	}

	public function test_one_captured_order_is_enough_to_stop_reporting_it(): void {
		$this->stats->record( false );
		$this->stats->record( true );

		$this->assertSame( 'good', $this->site_health( array( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true ) )->run_test()['status'] );
	}

	public function test_the_capture_rate_is_not_reported_while_capture_is_off(): void {
		$this->stats->record( false );

		$this->assertSame(
			'good',
			$this->site_health()->run_test()['status'],
			'A counter left behind by a feature that has since been turned off is not a problem to report.'
		);
	}

	// ---- The debug section: what it says -----------------------------------

	public function test_the_section_states_which_features_are_on(): void {
		$fields = $this->site_health(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_SEND_REFUNDS        => false,
			)
		)->add_debug_information( array() )['gtm4wp']['fields'];

		$this->assertSame( 'on', $fields['gdm_capture_attribution']['value'] );
		$this->assertSame( 'off', $fields['gdm_send_refunds']['value'] );
		$this->assertSame( 'eea-only', $fields['gdm_consent_policy']['value'] );
	}

	public function test_a_destination_is_described_by_label_stream_and_health(): void {
		$this->health->record_success( self::MEASUREMENT );
		$this->health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED: no access to property 123456789.', 'PERMISSION_DENIED' );

		$fields = $this->site_health()->add_debug_information( array() )['gtm4wp']['fields'];

		$row = $fields['gdm_destination_0']['value'];

		$this->assertStringContainsString( 'Main property', $row );
		$this->assertStringContainsString( self::MEASUREMENT, $row, 'The measurement ID is already in the site\'s public HTML.' );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $row, 'The bare code name says what went wrong without quoting the request.' );
		$this->assertStringContainsString( '2027-01-15', $row );
	}

	public function test_a_destination_nothing_has_been_sent_to_says_so(): void {
		$fields = $this->site_health()->add_debug_information( array() )['gtm4wp']['fields'];

		$this->assertStringContainsString( 'nothing sent yet', $fields['gdm_destination_0']['value'] );
	}

	public function test_the_capture_rate_and_queue_depth_are_reported(): void {
		$this->stats->record( true );
		$this->stats->record( false );

		$fields = $this->site_health()->add_debug_information( array() )['gtm4wp']['fields'];

		$this->assertStringContainsString( '1 of the last 2 orders', $fields['gdm_capture_rate']['value'] );
		$this->assertStringContainsString( '0 to send', $fields['gdm_queue']['value'] );
		$this->assertStringContainsString( 'Action Scheduler', $fields['gdm_queue']['value'], 'The row says which queue is carrying the work, because the two behave differently.' );
	}

	public function test_a_service_account_is_named_by_its_label_and_status(): void {
		$this->vault->add( KeyFileFixture::parse(), 'Production' );

		$fields = $this->site_health()->add_debug_information( array() )['gtm4wp']['fields'];

		$this->assertStringContainsString( 'Production', $fields['gdm_account_0']['value'] );
		$this->assertStringContainsString( 'unverified', $fields['gdm_account_0']['value'] );
	}

	// ---- The debug section: what it must never say -------------------------

	public function test_the_section_carries_no_service_account_address(): void {
		$this->vault->add( KeyFileFixture::parse(), 'Production' );

		$this->assertStringNotContainsString(
			KeyFileFixture::CLIENT_EMAIL,
			$this->debug_text(),
			'The account address identifies the site owner\'s Google Cloud project and has no business in text built to be pasted in public.'
		);
	}

	public function test_the_section_carries_no_key_material_in_any_form(): void {
		$this->vault->add( KeyFileFixture::parse(), 'Production' );

		$text = $this->debug_text();

		$this->assertStringNotContainsString( 'PRIVATE KEY', $text );
		$this->assertStringNotContainsString( KeyFileFixture::PRIVATE_KEY_ID, $text );

		$stored = $this->options[ KeyVault::OPTION_NAME ];
		$blob   = reset( $stored )['key']['ciphertext'];
		$this->assertStringNotContainsString( $blob, $text, 'Not even the encrypted blob.' );
	}

	public function test_the_section_carries_no_property_id(): void {
		$this->assertStringNotContainsString(
			self::PROPERTY,
			$this->debug_text(),
			'The property ID is configuration, not something the site publishes.'
		);
	}

	public function test_the_section_carries_no_raw_error_text_from_google(): void {
		$this->health->record_failure(
			self::MEASUREMENT,
			'PERMISSION_DENIED: The caller does not have permission for property 123456789 with transaction WC-1001.',
			'PERMISSION_DENIED'
		);

		$text = $this->debug_text();

		$this->assertStringNotContainsString( 'The caller does not have permission', $text, 'An error body from Google can quote fragments of what we sent.' );
		$this->assertStringNotContainsString( 'WC-1001', $text );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $text, 'The failure is still named - by its code, not by its sentence.' );
	}
}
