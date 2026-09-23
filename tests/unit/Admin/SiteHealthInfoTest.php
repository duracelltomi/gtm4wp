<?php
/**
 * Tests for the plugin's Site Health Info section.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Admin\ConfigurationChecks;
use GTM4WP\Admin\SiteHealthInfo;
use GTM4WP\Module\Registry;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * The collector owns three things: that there is ONE section for the whole
 * plugin, that a module reaches it only by opting in through the interface,
 * and that a module's rows arrive as the module wrote them, under keys that
 * cannot collide. Everything about what a row may contain is the module's,
 * and is tested where the module builds it.
 */
final class SiteHealthInfoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->justReturn( array() );

		ReportingThirdPartySchema::$received = null;
	}

	/**
	 * A collector over the given modules.
	 *
	 * @param array<int, object> $modules Modules to register.
	 * @return SiteHealthInfo
	 */
	private function collector( array $modules ): SiteHealthInfo {
		$registry = new Registry();

		foreach ( $modules as $module ) {
			$registry->add( $module );
		}

		return new SiteHealthInfo( $registry, new Options( $registry->defaults() ) );
	}

	public function test_the_filter_is_registered(): void {
		$collector = $this->collector( array() );

		Filters\expectAdded( 'debug_information' )->once()->with( array( $collector, 'add_debug_information' ) );

		$collector->register_hooks();
	}

	public function test_a_module_that_opts_in_contributes_its_rows_under_prefixed_keys(): void {
		$info = $this->collector( array( new ReportingThirdPartyModule() ) )->add_debug_information( array() );

		$this->assertArrayHasKey( SiteHealthInfo::SECTION, $info );

		$fields = $info[ SiteHealthInfo::SECTION ]['fields'];

		// The module wrote `status` and `items`; the collector filed them
		// under the module id, after its own two rows, so a second module
		// writing `status` cannot overwrite them.
		$this->assertSame( array( 'version', 'problems', 'reporting_status', 'reporting_items', 'reporting_raw' ), array_keys( $fields ) );
		$this->assertSame(
			array(
				'label' => 'Reporting',
				'value' => 'on',
			),
			$fields['reporting_status'],
			'The row arrives as the module wrote it - nothing added, nothing removed.'
		);
		$this->assertSame( array( 'one', 'two' ), $fields['reporting_items']['value'], 'A list value is passed through as a list.' );
	}

	/**
	 * The boundary must NOT pre-escape (TS-11): wp-admin/site-health-info.php
	 * runs esc_html() over every label and value it prints, so a value that
	 * left here as `&amp;` would render as `&amp;amp;`. The `'on'` row above
	 * cannot see that - esc_html( 'on' ) is 'on' - so this row carries every
	 * character the escaper would touch and asserts it arrives verbatim, with
	 * the real escaper stubbed in so an accidental esc_html() goes red (T95a).
	 */
	public function test_values_are_handed_over_raw_for_core_to_escape(): void {
		Functions\stubEscapeFunctions();

		$fields = $this->collector( array( new ReportingThirdPartyModule() ) )->add_debug_information( array() )[ SiteHealthInfo::SECTION ]['fields'];

		$this->assertSame( 'A & "B" <C>', $fields['reporting_raw']['label'] );
		$this->assertSame( "Ties & Shirts <b>\"quoted\"</b> 'apos'", $fields['reporting_raw']['value'] );
		$this->assertStringNotContainsString( '&amp;', $fields['reporting_raw']['value'] );
		$this->assertStringNotContainsString( '&lt;', $fields['reporting_raw']['value'] );
	}

	public function test_the_module_reads_the_plugins_own_options_service(): void {
		$this->collector( array( new ReportingThirdPartyModule() ) )->fields();

		$this->assertInstanceOf( Options::class, ReportingThirdPartySchema::$received );
		$this->assertTrue( ReportingThirdPartySchema::$received->get( 'reporting-option' ), 'The module default reached the row through Options, not through a second read of the database.' );
	}

	public function test_a_schema_without_the_interface_contributes_nothing_and_does_not_fatal(): void {
		$fields = $this->collector( array( new UndocumentedThirdPartyModule() ) )->fields();

		// The same guarantee the settings page gives an old third party schema:
		// instanceof, never a method call that would fatal.
		$this->assertSame( array( 'version', 'problems' ), array_keys( $fields ) );
	}

	public function test_the_plugin_rows_are_there_whatever_the_modules_say(): void {
		$info = $this->collector( array() )->add_debug_information( array( 'wp-core' => array() ) );

		$this->assertSame( array( 'wp-core', SiteHealthInfo::SECTION ), array_keys( $info ), 'Core\'s sections are kept; the plugin\'s is appended.' );

		$fields = $info[ SiteHealthInfo::SECTION ]['fields'];

		$this->assertSame( GTM4WP_VERSION, $fields['version']['value'] );
		$this->assertSame( ConfigurationChecks::CODE_MISSING_CONTAINER_ID, $fields['problems']['debug'], 'No container is stored: the same problem the admin notice reports, as its code.' );
	}

	/**
	 * The problems row carries codes only: a message quotes what the admin
	 * typed (the rejected dataLayer name here), and the section is pasted in
	 * public.
	 */
	public function test_the_problems_row_carries_codes_never_the_messages(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( 'id' => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_DATALAYER_NAME => 'my-secret-layer',
			)
		);

		$fields = $this->collector( array() )->fields();
		$text   = (string) json_encode( $fields ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for a substring assertion.

		$this->assertSame( ConfigurationChecks::CODE_INVALID_DATALAYER_NAME, $fields['problems']['debug'] );
		$this->assertStringNotContainsString( 'my-secret-layer', $text );
	}

	public function test_there_is_one_section_for_the_plugin_however_many_modules_report(): void {
		$info = $this->collector(
			array(
				new ReportingThirdPartyModule(),
				new UndocumentedThirdPartyModule(),
			)
		)->add_debug_information( array() );

		$this->assertCount( 1, $info );
		$this->assertSame( 'gtm4wp', SiteHealthInfo::SECTION, 'The section id is what support threads are told to look for.' );
	}

	public function test_a_module_row_that_is_not_an_array_is_dropped(): void {
		$module = new class() extends ReportingThirdPartyModule {
			public function admin_schema(): string {
				return MalformedReportingSchema::class;
			}
		};

		$fields = $this->collector( array( $module ) )->fields();

		$this->assertSame( array( 'version', 'problems', 'reporting_ok' ), array_keys( $fields ) );
	}

	public function test_a_module_whose_schema_class_is_missing_is_skipped_without_a_fatal(): void {
		// T113: a third-party module naming a class that does not exist (a
		// half-installed plugin) contributes nothing; the plugin rows still print.
		$module = new class() extends ReportingThirdPartyModule {
			public function admin_schema(): string {
				return 'GTM4WP\Tests\unit\Admin\NoSuchSchema';
			}
		};

		$this->assertSame( array( 'version', 'problems' ), array_keys( $this->collector( array( $module ) )->fields() ) );
	}

	public function test_a_value_that_is_not_an_array_is_passed_through(): void {
		$this->assertSame( 'unexpected', $this->collector( array() )->add_debug_information( 'unexpected' ) );
	}

	public function test_site_code_can_add_rows_through_the_filter(): void {
		Filters\expectApplied( 'gtm4wp_site_health_info' )
			->once()
			->andReturnUsing(
				static function ( array $fields ) {
					$fields['custom_row'] = array(
						'label' => 'Custom',
						'value' => 'yes',
					);

					return $fields;
				}
			);

		$fields = $this->collector( array( new ReportingThirdPartyModule() ) )->fields();

		$this->assertArrayHasKey( 'reporting_status', $fields, 'Filtering adds to the module rows; it does not start from nothing.' );
		$this->assertSame( 'yes', $fields['custom_row']['value'] );
	}

	public function test_a_filter_that_returns_junk_is_ignored(): void {
		Filters\expectApplied( 'gtm4wp_site_health_info' )->once()->andReturn( 'junk' );

		$fields = $this->collector( array( new ReportingThirdPartyModule() ) )->fields();

		$this->assertArrayHasKey( 'reporting_status', $fields );
	}
}
