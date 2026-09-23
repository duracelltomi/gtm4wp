<?php
/**
 * Unit tests for the Site Health surfaces of the Data Manager integration.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\CaptureStats;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\GoogleDataManagerModule;
use GTM4WP\Modules\GoogleDataManager\SiteHealth;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * Two surfaces with opposite risks.
 *
 * The **sending test** has to say something true about a feature whose
 * failures are silent: a destination that keeps being refused, or - the one
 * nothing else would ever report - orders arriving with no attribution
 * captured at all. (A key that stopped decrypting is the service accounts
 * module's test.)
 *
 * The **rows** are the opposite problem. Their text is pasted wholesale into
 * public support threads by people who cannot review it first, so what is
 * NOT in them is the property worth pinning: no raw error text from Google,
 * no GA4 property IDs. That is asserted against the whole rendered section
 * rather than field by field, so a row added later without thinking is
 * caught by the same test.
 */
final class GoogleDataManagerSiteHealthTest extends TestCase {

	use OptionStoreTrait;

	private const NOW         = 1_800_000_000;
	private const MEASUREMENT = 'G-ABC123';
	private const PROPERTY    = '123456789';

	private DestinationHealth $health;

	private CaptureStats $stats;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		// Marks every translation, so an English `debug` twin that went through
		// __() cannot pass as English (TS-22: the identity translator hides it).
		Functions\when( '__' )->alias( static fn ( string $text ): string => '[' . $text . ']' );
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url = '' ) {
				return $url . '?' . http_build_query( is_array( $args ) ? $args : array() );
			}
		);
		// Stubbed here rather than relied on: another suite defining an as_*
		// function defines it for the whole process, so which queue backend the
		// row reports would otherwise depend on test order (TS-16).
		Functions\when( '_get_cron_array' )->justReturn( array() );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'menu_page_url' )->alias( static fn ( $slug, $display = true ) => 'https://example.com/wp-admin/options-general.php?page=' . $slug );

		$this->stub_option_store();

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
			$this->stats
		);
	}

	/**
	 * The rows as one flat string, for the "this is nowhere in here" assertions.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return string
	 */
	private function debug_text( array $stored = array() ): string {
		$fields = $this->site_health( $stored )->debug_fields();

		return (string) json_encode( $fields ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for a substring assertion.
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

	// ---- The sending test --------------------------------------------------

	public function test_a_site_with_nothing_wrong_reports_good(): void {
		$result = $this->site_health()->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( '', $result['actions'] );
		$this->assertArrayNotHasKey( 'test', $result, 'The id and the badge are the collector\'s to stamp.' );
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

	// ---- The rows: what they say -------------------------------------------

	public function test_the_rows_state_which_features_are_on(): void {
		$fields = $this->site_health(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_SEND_REFUNDS        => false,
			)
		)->debug_fields();

		$this->assertSame( 'on', $fields['capture_attribution']['debug'] );
		$this->assertSame( 'off', $fields['send_refunds']['debug'] );
		$this->assertSame( 'eea-only', $fields['consent_policy']['value'] );
	}

	public function test_a_destination_is_described_by_label_stream_and_health(): void {
		$this->health->record_success( self::MEASUREMENT );
		$this->health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED: no access to property 123456789.', 'PERMISSION_DENIED' );

		$row = $this->site_health()->debug_fields()['destination_0'];

		$this->assertStringContainsString( 'Main property', $row['value'] );
		$this->assertStringContainsString( self::MEASUREMENT, $row['value'], 'The measurement ID is already in the site\'s public HTML.' );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $row['value'], 'The bare code name says what went wrong without quoting the request.' );
		$this->assertStringContainsString( '2027-01-15', $row['value'] );
		$this->assertStringStartsWith( '[', $row['value'], 'The page line is translated.' );
		$this->assertStringStartsWith( 'Main property (', $row['debug'], 'The copied line is English (U161).' );
		$this->assertStringNotContainsString( '[', $row['debug'] );
	}

	public function test_a_destination_without_a_label_is_named_by_its_measurement_id_and_a_failure_without_a_class_reads_as_a_dash(): void {
		// T114: the two fallbacks of the destination line.
		$this->health->record_success( self::MEASUREMENT );
		$this->health->record_failure( self::MEASUREMENT, 'timeout' );

		$row = $this->site_health( array( GTM4WP_OPTION_GDM_DESTINATIONS => array( array_merge( $this->destination_row(), array( DestinationRows::COLUMN_LABEL => '' ) ) ) ) )->debug_fields()['destination_0'];

		$this->assertStringStartsWith( self::MEASUREMENT . ' (' . DestinationRows::TYPE_GA4 . ', ' . self::MEASUREMENT . '): ', $row['debug'] );
		$this->assertStringEndsWith( '1 failures in a row, last reason -', $row['debug'] );
	}

	public function test_a_stored_destination_list_that_is_not_a_list_yields_no_destination_rows(): void {
		$fields = $this->site_health( array( GTM4WP_OPTION_GDM_DESTINATIONS => 'corrupt' ) )->debug_fields();

		$this->assertArrayNotHasKey( 'destination_0', $fields );
		$this->assertSame( 'good', $this->site_health( array( GTM4WP_OPTION_GDM_DESTINATIONS => 'corrupt' ) )->run_test()['status'] );
	}

	public function test_a_row_without_a_measurement_id_is_never_a_failing_destination(): void {
		// T114: a half-filled row has no stream to fail. DestinationHealth refuses
		// the empty id too, so the row-side skip alone is not pinned here (`[-]`);
		// this holds the pair: neither layer may start filing under ''.
		for ( $i = 0; $i < DestinationHealth::FAILURE_THRESHOLD; $i++ ) {
			$this->health->record_failure( '', 'PERMISSION_DENIED', 'PERMISSION_DENIED' );
		}

		$result = $this->site_health( array( GTM4WP_OPTION_GDM_DESTINATIONS => array( array_merge( $this->destination_row(), array( DestinationRows::COLUMN_MEASUREMENT => '' ) ) ) ) )->run_test();

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_a_failing_destination_label_is_escaped_into_the_description_once(): void {
		// T106: core prints the description unescaped; the label is admin-typed
		// text that keeps & and quotes. Both directions.
		$this->fail_the_destination();

		$result = $this->site_health( array( GTM4WP_OPTION_GDM_DESTINATIONS => array( array_merge( $this->destination_row(), array( DestinationRows::COLUMN_LABEL => 'Main & "property"' ) ) ) ) )->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Main &amp; &quot;property&quot;', $result['description'] );
		$this->assertStringNotContainsString( '& "', $result['description'] );
		$this->assertStringNotContainsString( '&amp;amp;', $result['description'], 'Escaped once, not twice.' );
	}

	/**
	 * The stored destination row of the fixture site.
	 *
	 * @return array<string, string>
	 */
	private function destination_row(): array {
		return array(
			DestinationRows::COLUMN_LABEL       => 'Main property',
			DestinationRows::COLUMN_ACCOUNT     => 'sa_aaaaaaaaaaaa',
			DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
			DestinationRows::COLUMN_PROPERTY    => self::PROPERTY,
			DestinationRows::COLUMN_MEASUREMENT => self::MEASUREMENT,
		);
	}

	public function test_a_destination_nothing_has_been_sent_to_says_so(): void {
		$fields = $this->site_health()->debug_fields();

		$this->assertStringContainsString( 'nothing sent yet', $fields['destination_0']['debug'] );
	}

	public function test_the_capture_rate_and_queue_depth_are_reported(): void {
		$this->stats->record( true );
		$this->stats->record( false );

		$fields = $this->site_health()->debug_fields();

		$this->assertStringContainsString( '1 of the last 2 orders', $fields['capture_rate']['value'] );
		$this->assertStringContainsString( '0 to send', $fields['queue']['value'] );
		$this->assertStringContainsString( 'Action Scheduler', $fields['queue']['debug'], 'The row says which queue is carrying the work, because the two behave differently.' );
	}

	// ---- The rows: what they must never say --------------------------------

	public function test_the_rows_carry_no_property_id(): void {
		$this->assertStringNotContainsString(
			self::PROPERTY,
			$this->debug_text(),
			'The property ID is configuration, not something the site publishes.'
		);
	}

	public function test_the_rows_carry_no_raw_error_text_from_google(): void {
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
