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
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\GoogleDataManager\CaptureStats;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\SiteHealth;
use GTM4WP\Tests\unit\Google\KeyFileFixture;

/**
 * The gtm4wp/get-status ability is the first thing an assistant calls, so its shape is
 * pinned field by field; gtm4wp/get-site-health is the one whose output goes
 * verbatim into a transcript on somebody else's servers, so what is NOT in it
 * is the property worth pinning (TS-2 both directions, over the whole
 * serialised answer rather than field by field, so a row added later is
 * caught by the same test).
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

		( new StatusAbilities( $this->registry(), $this->vault, $this->health, $this->stats ) )->register();
	}

	// ---- get-status --------------------------------------------------------

	public function test_the_status_reports_the_containers_as_loaded(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID      => 'GTM-ABC123',
						ContainerRows::COLUMN_AUTH    => 'auth-token',
						ContainerRows::COLUMN_PREVIEW => 'env-3',
						ContainerRows::COLUMN_DOMAIN  => 'gtm.example.com',
						ContainerRows::COLUMN_PATH    => 'loader.js',
						ContainerRows::COLUMN_NO_ID   => '1',
					),
					array( ContainerRows::COLUMN_ID => 'GTM-DEF456' ),
				),
			)
		);

		$status = $this->execute( StatusAbilities::GET_STATUS );

		$this->assertSame( GTM4WP_VERSION, $status['plugin_version'] );
		$this->assertSame(
			array(
				array(
					'id'          => 'GTM-ABC123',
					'environment' => true,
					'domain'      => 'gtm.example.com',
					'path'        => 'loader.js',
					'omit_id'     => true,
				),
				array(
					'id'          => 'GTM-DEF456',
					'environment' => false,
					'domain'      => '',
					'path'        => '',
					'omit_id'     => false,
				),
			),
			$status['containers']
		);
		$this->assertStringNotContainsString( 'auth-token', (string) json_encode( $status ), 'The environment parameters travel to the browser in the loader URL, but an assistant only needs to know they are set.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_the_status_reports_the_placement_and_whether_the_container_code_is_emitted(): void {
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

	/**
	 * One placement, one word.
	 *
	 * @param int    $stored   The stored placement.
	 * @param string $expected The word.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_placements' )]
	public function test_every_placement_has_a_word( int $stored, string $expected ): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT  => $stored,
			)
		);

		$status = $this->execute( StatusAbilities::GET_STATUS );

		$this->assertSame( $expected, $status['placement'] );
		$this->assertSame( 'off' !== $expected, $status['container_code_output'] );
	}

	/**
	 * Every placement value with its word.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function provide_placements(): array {
		return array(
			'footer'         => array( GTM4WP_PLACEMENT_FOOTER, 'footer' ),
			'body open'      => array( GTM4WP_PLACEMENT_BODYOPEN, 'body_open_manual' ),
			'body open auto' => array( GTM4WP_PLACEMENT_BODYOPEN_AUTO, 'body_open_auto' ),
			'off'            => array( GTM4WP_PLACEMENT_OFF, 'off' ),
		);
	}

	public function test_the_status_names_an_unusable_data_layer_name_and_the_fallback(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_DATALAYER_NAME => 'my-layer',
			)
		);

		$status = $this->execute( StatusAbilities::GET_STATUS );

		$this->assertSame(
			array(
				'configured' => 'my-layer',
				'effective'  => 'dataLayer',
				'valid'      => false,
			),
			$status['datalayer_name']
		);

		$codes = array_column( $status['problems'], 'code' );
		$this->assertContains( ConfigurationChecks::CODE_INVALID_DATALAYER_NAME, $codes, 'The same problem the admin notice reports.' );
	}

	public function test_a_usable_data_layer_name_is_valid_and_empty_means_the_default(): void {
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$status = $this->execute( StatusAbilities::GET_STATUS );

		$this->assertSame(
			array(
				'configured' => '',
				'effective'  => 'dataLayer',
				'valid'      => true,
			),
			$status['datalayer_name']
		);
		$this->assertSame( array(), $status['problems'] );
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

	public function test_the_status_lists_every_module_with_its_master_switch(): void {
		$this->store_settings(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS             => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
			)
		);

		$modules = array_column( $this->execute( StatusAbilities::GET_STATUS )['modules'], null, 'id' );

		$this->assertSame( array_keys( $this->registry()->all() ), array_keys( $modules ), 'Every registered module, in registry order.' );
		$this->assertNull( $modules['container']['enabled'], 'A module with no single switch reports null, not false.' );
		$this->assertTrue( $modules['woocommerce']['enabled'] );
		$this->assertFalse( $modules['woocommerce']['available'], 'No WooCommerce in this process.' );
		$this->assertFalse( $modules['edd']['enabled'] );
		$this->assertNotSame( '', $modules['woocommerce']['title'] );
	}

	/**
	 * The integrations are read from the plugins' version constants, which are
	 * process-global: the EDD and WooCommerce stub files of other suites define
	 * them, so under a random order any of the three may be "present" here.
	 * What is pinned is therefore the mapping - active exactly when the constant
	 * is defined, the version exactly its value, null otherwise - against the
	 * process state at the time of the call (TS-16).
	 */
	public function test_the_status_reports_each_integration_from_its_version_constant(): void {
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$integrations = $this->execute( StatusAbilities::GET_STATUS )['integrations'];

		$constants = array(
			'woocommerce'            => 'WC_VERSION',
			'easy_digital_downloads' => 'EDD_VERSION',
			'contact_form_7'         => 'WPCF7_VERSION',
		);

		$this->assertSame( array_keys( $constants ), array_keys( $integrations ) );

		foreach ( $constants as $integration => $constant ) {
			$defined = defined( $constant );

			$this->assertSame( $defined, $integrations[ $integration ]['active'], $integration );
			$this->assertSame( $defined ? (string) constant( $constant ) : null, $integrations[ $integration ]['version'], $integration );
		}
	}

	public function test_the_status_reports_no_hardcoded_override_when_no_constant_is_defined(): void {
		$this->store_settings( array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ) );

		$this->assertSame(
			array(
				'active'         => false,
				'locked_columns' => array(),
				'locked_rows'    => false,
				'errors'         => array(),
			),
			$this->execute( StatusAbilities::GET_STATUS )['hardcoded']
		);
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
