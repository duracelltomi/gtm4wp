<?php
/**
 * Unit tests for the status abilities.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Abilities\StatusAbilities;
use GTM4WP\Admin\ConfigurationChecks;
use GTM4WP\Google\KeyVault;
use GTM4WP\Module\Registry;
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\Container\StatusReport;
use GTM4WP\Modules\GoogleDataManager\CaptureStats;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\SiteHealth;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Admin\UndocumentedThirdPartyModule;
use GTM4WP\Tests\unit\Google\KeyFileFixture;

/**
 * The gtm4wp/get-status ability is the first thing an assistant calls, so its shape is
 * pinned field by field - except the Container module's slice, which is
 * pinned where the module builds it (ContainerStatusReportTest) and only
 * checked here for arriving intact. The per-module rows are the ability's
 * own: they come from the registry walk over StatusInfoInterface, so a
 * third-party module gets the same treatment as a built-in one.
 * gtm4wp/get-site-health is the one whose output goes verbatim into a
 * transcript on somebody else's servers, so what is NOT in it is the
 * property worth pinning (TS-2 both directions, over the whole serialised
 * answer rather than field by field, so a row added later is caught by the
 * same test).
 */
final class StatusAbilitiesTest extends AbilitiesTestCase {

	private const NOW         = 1_800_000_000;
	private const SECRET      = 'unit-test-site-secret-do-not-reuse';
	private const MEASUREMENT = 'G-ABC123';
	private const PROPERTY    = '123456789';

	private KeyVault $vault;

	private DestinationHealth $health;

	private CaptureStats $stats;

	protected function setUp(): void {
		parent::setUp();

		$this->vault  = new KeyVault( self::SECRET, static fn () => self::NOW );
		$this->health = new DestinationHealth( static fn () => self::NOW );
		$this->stats  = new CaptureStats( static fn () => self::NOW );

		StatusReportingThirdPartySchema::$received = null;

		( new StatusAbilities( $this->registry(), $this->vault, $this->health, $this->stats ) )->register();
	}

	/**
	 * The status of a registry holding the given modules only.
	 *
	 * @param array<int, object> $modules Modules to register.
	 * @return array<string, mixed>
	 */
	private function status_over( array $modules ): array {
		$registry = new Registry();

		foreach ( $modules as $module ) {
			$registry->add( $module );
		}

		$this->registered = array();
		( new StatusAbilities( $registry, $this->vault, $this->health, $this->stats ) )->register();

		return $this->execute( StatusAbilities::GET_STATUS );
	}

	// ---- get-status --------------------------------------------------------

	public function test_the_status_carries_the_container_modules_report_intact(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID      => 'GTM-ABC123',
						ContainerRows::COLUMN_AUTH    => 'auth-token',
						ContainerRows::COLUMN_PREVIEW => 'env-3',
					),
				),
				GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_BODYOPEN,
				GTM4WP_OPTION_DATALAYER_NAME => 'my-layer',
			)
		);

		$status = $this->execute( StatusAbilities::GET_STATUS );
		$report = ( new StatusReport( new Options( $this->registry()->defaults() ) ) )->report();

		$this->assertSame( GTM4WP_VERSION, $status['plugin_version'] );
		$this->assertSame( $report, array_intersect_key( $status, $report ), 'The module\'s report arrives as the module built it - nothing added, nothing reshaped.' );
		$this->assertSame(
			array( 'plugin_version', 'containers', 'placement', 'container_code_output', 'datalayer_name', 'hardcoded', 'modules', 'problems' ),
			array_keys( $status ),
			'The key order of the contract.'
		);
		$this->assertStringNotContainsString( 'auth-token', (string) json_encode( $status ), 'The environment parameters travel to the browser in the loader URL, but an assistant only needs to know they are set.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_the_placement_enum_of_the_schema_is_the_modules(): void {
		$this->assertSame( StatusReport::PLACEMENTS, $this->registered[ StatusAbilities::GET_STATUS ]['output_schema']['properties']['placement']['enum'], 'One definition: the module names the words, the schema declares them.' );
	}

	public function test_placement_off_is_not_reported_as_a_missing_container_problem(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_OFF,
			)
		);

		$status = $this->execute( StatusAbilities::GET_STATUS );

		$this->assertSame( 'off', $status['placement'] );
		$this->assertFalse( $status['container_code_output'] );
		$this->assertSame( array(), $status['problems'], 'Placement off is the deliberate data-layer-only setup: no container ID is not a problem there.' );
	}

	public function test_an_unusable_data_layer_name_is_also_a_reported_problem(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_DATALAYER_NAME => 'my-layer',
			)
		);

		$status = $this->execute( StatusAbilities::GET_STATUS );

		$this->assertFalse( $status['datalayer_name']['valid'] );
		$this->assertContains( ConfigurationChecks::CODE_INVALID_DATALAYER_NAME, array_column( $status['problems'], 'code' ), 'The same problem the admin notice reports.' );
	}

	public function test_a_clean_setup_reports_no_problem(): void {
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$this->assertSame( array(), $this->execute( StatusAbilities::GET_STATUS )['problems'] );
	}

	public function test_the_status_reports_the_problems_the_notices_report(): void {
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array() ) );

		$problems = $this->execute( StatusAbilities::GET_STATUS )['problems'];

		$this->assertCount( 1, $problems );
		$this->assertSame( ConfigurationChecks::CODE_MISSING_CONTAINER_ID, $problems[0]['code'] );
		$this->assertSame( ConfigurationChecks::SEVERITY_ERROR, $problems[0]['severity'] );
		$this->assertSame( GTM4WP_OPTION_GTM_CONTAINERS, $problems[0]['option_key'] );
		$this->assertTrue( $problems[0]['dismissible'] );
		$this->assertNotSame( '', $problems[0]['message'] );
	}

	public function test_the_status_lists_every_module_with_what_its_schema_reports(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS             => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
			)
		);

		$modules = array_column( $this->execute( StatusAbilities::GET_STATUS )['modules'], null, 'id' );

		$this->assertSame( array_keys( $this->registry()->all() ), array_keys( $modules ), 'Every registered module, in registry order.' );
		$this->assertSame( array( 'id', 'title', 'available', 'enabled', 'integration' ), array_keys( $modules['container'] ) );
		$this->assertNull( $modules['container']['enabled'], 'A module with no single switch reports null, not false.' );
		$this->assertNull( $modules['container']['integration'], 'A module integrating nothing reports null, not an inactive entry.' );
		$this->assertTrue( $modules['woocommerce']['enabled'] );
		$this->assertFalse( $modules['woocommerce']['available'], 'No WooCommerce in this process.' );
		$this->assertFalse( $modules['edd']['enabled'] );
		$this->assertFalse( $modules['contact-form-7']['enabled'] );
		$this->assertNotSame( '', $modules['woocommerce']['title'] );
	}

	/**
	 * The host plugins' version constants are process-global: the EDD and
	 * WooCommerce stub files of other suites define them, so under a random
	 * order any of the three may be "present" here. What is pinned is the
	 * mapping the schemas promise - active exactly when the constant is
	 * defined, the version exactly its value, null otherwise - against the
	 * process state at the time of the call (TS-16).
	 */
	public function test_the_integrating_modules_report_their_host_plugin_in_their_own_row(): void {
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$modules = array_column( $this->execute( StatusAbilities::GET_STATUS )['modules'], 'integration', 'id' );

		$constants = array(
			'woocommerce'    => 'WC_VERSION',
			'edd'            => 'EDD_VERSION',
			'contact-form-7' => 'WPCF7_VERSION',
		);

		foreach ( $constants as $module_id => $constant ) {
			$defined = defined( $constant );

			$this->assertSame( array( 'active', 'version' ), array_keys( $modules[ $module_id ] ), $module_id );
			$this->assertSame( $defined, $modules[ $module_id ]['active'], $module_id );
			$this->assertSame( $defined ? (string) constant( $constant ) : null, $modules[ $module_id ]['version'], $module_id );
		}
	}

	public function test_a_third_party_module_whose_schema_opts_in_reports_its_switch_and_host(): void {
		$this->store_settings( array( 'acme-status-switch' => true ) );

		$rows = array_column( $this->status_over( array( new StatusReportingThirdPartyModule() ) )['modules'], null, 'id' );

		$this->assertSame( array( 'acme-status' ), array_keys( $rows ) );
		$this->assertTrue( $rows['acme-status']['enabled'], 'The switch state the schema read from the options it was given.' );
		$this->assertSame(
			array(
				'active'  => true,
				'version' => '9.9.0',
			),
			$rows['acme-status']['integration']
		);
		$this->assertInstanceOf( Options::class, StatusReportingThirdPartySchema::$received );
		$this->assertTrue( StatusReportingThirdPartySchema::$received->get( 'acme-status-switch' ), 'The schema reads the fresh Options service the ability built, so it sees the stored value merged over its own default.' );
	}

	public function test_a_third_party_module_without_the_interface_reports_unknown_not_off(): void {
		$rows = array_column( $this->status_over( array( new UndocumentedThirdPartyModule() ) )['modules'], null, 'id' );

		// The same guarantee the settings page and Site Health give an old
		// third party schema: instanceof, never a method call that would fatal.
		$this->assertSame( array( 'third-party' ), array_keys( $rows ) );
		$this->assertSame( 'Third party', $rows['third-party']['title'] );
		$this->assertTrue( $rows['third-party']['available'] );
		$this->assertNull( $rows['third-party']['enabled'] );
		$this->assertNull( $rows['third-party']['integration'] );
	}

	// ---- get-site-health ---------------------------------------------------

	public function test_site_health_reports_the_status_test_and_the_info_rows(): void {
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$health = $this->execute( StatusAbilities::GET_SITE_HEALTH );

		$this->assertCount( 1, $health['tests'] );
		$this->assertSame( SiteHealth::TEST_ID, $health['tests'][0]['id'] );
		$this->assertSame( 'good', $health['tests'][0]['status'] );
		$this->assertStringNotContainsString( '<', $health['tests'][0]['description'], 'The description is text, not the HTML Site Health renders.' );

		$rows = array_column( $health['info'], 'value', 'key' );
		$this->assertArrayHasKey( 'google-data-manager_gdm_send_refunds', $rows, 'The rows the Info tab shows, under the same prefixed keys.' );
		$this->assertSame( 'off', $rows['google-data-manager_gdm_send_refunds'] );
	}

	public function test_site_health_reports_a_failing_destination_as_critical(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS   => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GDM_DESTINATIONS => array( $this->destination() ),
			)
		);

		for ( $i = 0; $i < DestinationHealth::FAILURE_THRESHOLD; $i++ ) {
			$this->health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED: no access to property ' . self::PROPERTY . '.', 'PERMISSION_DENIED' );
		}

		$health = $this->execute( StatusAbilities::GET_SITE_HEALTH );

		$this->assertSame( 'critical', $health['tests'][0]['status'] );
		$this->assertStringContainsString( 'Main property', $health['tests'][0]['description'] );
	}

	/**
	 * The disclosure test. With a stored service account and a destination in
	 * place, the whole serialised answer names the account by its label and the
	 * destination by its measurement ID (already in the site's public HTML) and
	 * carries none of: the account e-mail, the key id, key material, the GA4
	 * property ID, or Google's raw error text.
	 */
	public function test_site_health_never_discloses_the_account_address_key_material_or_property_ids(): void {
		$id = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $id );

		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS   => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GDM_DESTINATIONS => array( $this->destination( $id ) ),
			)
		);
		$this->health->record_failure( self::MEASUREMENT, 'PERMISSION_DENIED: caller does not have access to property ' . self::PROPERTY, 'PERMISSION_DENIED' );

		$text = (string) json_encode( $this->execute( StatusAbilities::GET_SITE_HEALTH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for substring assertions.

		// Present: what an assistant needs.
		$this->assertStringContainsString( 'Production', $text, 'The account is named by its label.' );
		$this->assertStringContainsString( self::MEASUREMENT, $text, 'The destination is named by its measurement ID.' );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $text, 'The bare reason code.' );

		// Absent: what nobody on the other end is entitled to.
		$this->assertStringNotContainsString( KeyFileFixture::CLIENT_EMAIL, $text );
		$this->assertStringNotContainsString( KeyFileFixture::PRIVATE_KEY_ID, $text );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $text );
		$this->assertStringNotContainsString( self::PROPERTY, $text );
		$this->assertStringNotContainsString( 'caller does not have access', $text, 'Google\'s wording can quote what was sent; only the class survives.' );
	}

	/**
	 * A destination row over the seeded account.
	 *
	 * @param string $account The account id.
	 * @return array<string, string>
	 */
	private function destination( string $account = 'sa_aaaaaaaaaaaa' ): array {
		return array(
			DestinationRows::COLUMN_LABEL       => 'Main property',
			DestinationRows::COLUMN_ACCOUNT     => $account,
			DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
			DestinationRows::COLUMN_PROPERTY    => self::PROPERTY,
			DestinationRows::COLUMN_MEASUREMENT => self::MEASUREMENT,
		);
	}
}
