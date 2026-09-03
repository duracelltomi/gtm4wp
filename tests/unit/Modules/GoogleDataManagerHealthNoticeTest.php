<?php
/**
 * Unit tests for the failing-destination admin notice.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\HealthNotice;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The notice is how a silently failing destination reaches whoever uses
 * wp-admin. Destination labels are admin free text printed into admin HTML,
 * so both directions of the escape are asserted on a hostile label (TS-2),
 * and the silent states - healthy, below threshold, stale record - are each
 * asserted as exactly no output.
 */
final class GoogleDataManagerHealthNoticeTest extends TestCase {

	use OptionStoreTrait;

	private const SETTINGS_URL = 'https://example.com/wp-admin/admin.php?page=gtm4wp-settings';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $number ) => ( 1 === $number ) ? $single : $plural );
		Functions\when( 'menu_page_url' )->justReturn( self::SETTINGS_URL );
		Functions\when( 'add_query_arg' )->alias( static fn ( $key, $value, $url ) => $url . '&' . $key . '=' . rawurlencode( (string) $value ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );

		$this->stub_option_store();
	}

	/**
	 * Stores a destinations table.
	 *
	 * @param array<int, array<string, string>> $rows Destination rows.
	 * @return void
	 */
	private function store_destinations( array $rows ): void {
		$this->options[ GTM4WP_OPTIONS ] = array( GTM4WP_OPTION_GDM_DESTINATIONS => $rows );
	}

	/**
	 * A stored destination row.
	 *
	 * @param string $label       Label.
	 * @param string $measurement Measurement id.
	 * @return array<string, string>
	 */
	private static function row( string $label, string $measurement ): array {
		return array(
			DestinationRows::COLUMN_LABEL       => $label,
			DestinationRows::COLUMN_ACCOUNT     => 'sa_0123456789ab',
			DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
			DestinationRows::COLUMN_PROPERTY    => '123456789',
			DestinationRows::COLUMN_MEASUREMENT => $measurement,
		);
	}

	/**
	 * Drives a destination's record past the failure threshold.
	 *
	 * @param string $measurement Measurement id.
	 * @return void
	 */
	private function make_failing( string $measurement ): void {
		$health = new DestinationHealth( static fn () => 1_800_000_000 );

		for ( $i = 0; $i < DestinationHealth::FAILURE_THRESHOLD; $i++ ) {
			$health->record_failure( $measurement, 'PERMISSION_DENIED' );
		}
	}

	/**
	 * Renders the notice against the stored options.
	 *
	 * @return string
	 */
	private function render(): string {
		$options = new Options( array( GTM4WP_OPTION_GDM_DESTINATIONS => array() ) );

		ob_start();
		( new HealthNotice( $options, new DestinationHealth() ) )->show_notice();

		return (string) ob_get_clean();
	}

	public function test_registers_on_admin_notices(): void {
		$notice = new HealthNotice( new Options( array() ), new DestinationHealth() );

		$notice->register_hooks();

		$this->assertNotFalse( has_action( 'admin_notices', array( $notice, 'show_notice' ) ) );
	}

	public function test_prints_nothing_with_no_destinations_even_when_stale_records_are_failing(): void {
		// A record for a destination that was since removed must not alarm.
		$this->make_failing( 'G-REMOVED1' );

		$this->assertSame( '', $this->render() );
	}

	public function test_prints_nothing_while_every_destination_is_healthy(): void {
		$this->store_destinations( array( self::row( 'Production', 'G-ABC123' ) ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_prints_nothing_below_the_threshold(): void {
		$this->store_destinations( array( self::row( 'Production', 'G-ABC123' ) ) );
		( new DestinationHealth() )->record_failure( 'G-ABC123', 'blip' );

		$this->assertSame( '', $this->render() );
	}

	public function test_names_the_failing_destination_and_deep_links_the_destinations_option(): void {
		$this->store_destinations( array( self::row( 'Production', 'G-ABC123' ) ) );
		$this->make_failing( 'G-ABC123' );

		$html = $this->render();

		$this->assertStringStartsWith( '<div class="gtm4wp-notice notice notice-error"', $html );
		$this->assertStringContainsString( 'destination Production keeps failing', $html );
		$this->assertStringContainsString( 'gtm4wp-focus=' . GTM4WP_OPTION_GDM_DESTINATIONS, $html, 'The link addresses the option key, so it survives regrouping.' );
	}

	public function test_a_destination_without_a_label_is_named_by_its_measurement_id(): void {
		$this->store_destinations( array( self::row( '', 'G-ABC123' ) ) );
		$this->make_failing( 'G-ABC123' );

		$this->assertStringContainsString( 'destination G-ABC123 keeps failing', $this->render() );
	}

	public function test_only_the_failing_destinations_are_named_in_the_plural_form(): void {
		$this->store_destinations(
			array(
				self::row( 'Production', 'G-ABC123' ),
				self::row( 'Staging', 'G-STAGE12' ),
				self::row( 'Healthy', 'G-FINE123' ),
			)
		);
		$this->make_failing( 'G-ABC123' );
		$this->make_failing( 'G-STAGE12' );

		$html = $this->render();

		$this->assertStringContainsString( 'destinations Production, Staging keeps failing', $html );
		$this->assertStringNotContainsString( 'Healthy', $html );
	}

	/**
	 * A label is admin free text; the save-time sanitizer strips tags, so the
	 * hostile part that survives storage is the quote and ampersand - the
	 * notice must escape those itself (TS-2, both directions).
	 */
	public function test_a_hostile_label_is_escaped_in_the_notice(): void {
		$this->store_destinations( array( self::row( 'Tom & Jerry "prod"', 'G-ABC123' ) ) );
		$this->make_failing( 'G-ABC123' );

		$html = $this->render();

		$this->assertStringContainsString( 'Tom &amp; Jerry &quot;prod&quot;', $html );
		$this->assertStringNotContainsString( 'Tom & Jerry "prod"', $html );
	}

	public function test_the_notice_is_not_dismissible(): void {
		$this->store_destinations( array( self::row( 'Production', 'G-ABC123' ) ) );
		$this->make_failing( 'G-ABC123' );

		$this->assertStringNotContainsString( 'is-dismissible', $this->render(), 'A live gap stays visible until it is fixed.' );
	}
}
