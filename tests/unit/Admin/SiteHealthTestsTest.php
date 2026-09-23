<?php
/**
 * Tests for the plugin's Site Health status-test collector.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Admin\SiteHealthTests;
use GTM4WP\Module\ModuleInterface;
use GTM4WP\Module\Registry;
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * The collector owns four things: ONE filter for the plugin, the
 * configuration test that turns the admin-notice problems into a result, the
 * instanceof opt-in through which a module adds tests, and what it stamps on
 * every result (the id, the badge) versus what it leaves alone (the module's
 * HTML - the inverse of the Info collector's no-pre-escape rule, T95a).
 */
final class SiteHealthTestsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $number ) => ( 1 === (int) $number ? $single : $plural ) );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'menu_page_url' )->alias( static fn ( $slug, $display = true ) => 'https://example.com/wp-admin/options-general.php?page=' . $slug );
		Functions\when( 'add_query_arg' )->alias( static fn ( $key, $value, $url ) => $url . '&' . $key . '=' . rawurlencode( (string) $value ) );

		TestingThirdPartySchema::$received = null;
	}

	/**
	 * A collector over the given modules and stored options.
	 *
	 * @param array<int, object>   $modules Modules to register.
	 * @param array<string, mixed> $stored  The stored option row.
	 * @return SiteHealthTests
	 */
	private function collector( array $modules = array(), array $stored = array() ): SiteHealthTests {
		Functions\when( 'get_option' )->alias( static fn ( $key, $default_value = false ) => ( GTM4WP_OPTIONS === $key ) ? $stored : $default_value );

		$registry = new Registry();

		foreach ( $modules as $module ) {
			$registry->add( $module );
		}

		return new SiteHealthTests( $registry, new Options( $registry->defaults() ) );
	}

	/**
	 * A stored row with one container, so the configuration is clean.
	 *
	 * @param array<string, mixed> $more Further stored values.
	 * @return array<string, mixed>
	 */
	private function configured( array $more = array() ): array {
		return array_merge(
			array( GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ) ),
			$more
		);
	}

	public function test_the_filter_is_registered(): void {
		$collector = $this->collector();

		Filters\expectAdded( 'site_status_tests' )->once()->with( array( $collector, 'add_tests' ) );

		$collector->register_hooks();
	}

	public function test_every_test_is_filed_as_a_direct_one(): void {
		$collector = $this->collector( array( new TestingThirdPartyModule() ) );

		$tests = $collector->add_tests( array( 'direct' => array( 'core' => array( 'test' => 'core_test' ) ) ) );

		$this->assertSame(
			array( 'core', SiteHealthTests::TEST_CONFIGURATION, 'gtm4wp_acme_testing_checks_out', 'gtm4wp_acme_testing_overrides' ),
			array_keys( $tests['direct'] ),
			'Core\'s own tests are kept; the plugin\'s follow, the configuration test first.'
		);

		foreach ( array_keys( $collector->tests() ) as $id ) {
			$this->assertIsCallable( $tests['direct'][ $id ]['test'] );
		}
	}

	public function test_a_filter_value_that_is_not_an_array_is_passed_through(): void {
		$this->assertSame( 'unexpected', $this->collector()->add_tests( 'unexpected' ) );
	}

	public function test_a_scalar_left_under_direct_by_another_plugin_is_replaced_not_fataled(): void {
		// Core builds both keys as arrays and only restores an ABSENT one after
		// the filter; a present scalar would otherwise fatal on the nested write.
		$tests = $this->collector()->add_tests(
			array(
				'direct' => 'abc',
				'async'  => array(),
			)
		);

		$this->assertIsArray( $tests['direct'] );
		$this->assertArrayHasKey( SiteHealthTests::TEST_CONFIGURATION, $tests['direct'] );
		$this->assertSame( array(), $tests['async'] );
	}

	// ---- The configuration test --------------------------------------------

	public function test_a_clean_configuration_is_good(): void {
		$result = $this->collector( array(), $this->configured() )->run_all()[ SiteHealthTests::TEST_CONFIGURATION ];

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( SiteHealthTests::TEST_CONFIGURATION, $result['test'] );
		$this->assertSame( 'Google Tag Manager', $result['badge']['label'] );
		$this->assertSame( 'blue', $result['badge']['color'] );
		$this->assertSame( '', $result['actions'] );
		$this->assertStringContainsString( 'no problem', $result['description'], 'The container loads: the plain good wording, not the switched-off one.' );
		$this->assertStringNotContainsString( 'switched off', $result['description'] );
	}

	public function test_a_problem_that_quotes_a_typed_value_is_escaped_into_the_description(): void {
		// T106: core prints a status test's description unescaped. The invalid
		// dataLayer-name message quotes the typed value, so the message is escaped
		// once here, both directions: entities present, the raw characters absent.
		$stored = $this->configured( array( GTM4WP_OPTION_DATALAYER_NAME => 'x<b>&"y' ) );

		$result = $this->collector( array(), $stored )->run_all()[ SiteHealthTests::TEST_CONFIGURATION ];

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( '&quot;x&lt;b&gt;&amp;&quot;y&quot;', $result['description'] );
		$this->assertStringNotContainsString( '<b>', $result['description'] );
		$this->assertStringNotContainsString( '&"y', $result['description'] );
		$this->assertSame( 1, substr_count( $result['description'], '<p>' ), 'The only markup is the paragraph the collector adds.' );
	}

	public function test_a_missing_container_id_is_critical_and_links_to_the_setting(): void {
		$result = $this->collector()->run_all()[ SiteHealthTests::TEST_CONFIGURATION ];

		$this->assertSame( 'critical', $result['status'], 'An error-severity problem is something not working, not an improvement.' );
		$this->assertStringContainsString( 'GTM ID', $result['description'], 'The same message the admin notice shows.' );
		$this->assertStringContainsString( 'gtm4wp-focus=' . rawurlencode( GTM4WP_OPTION_GTM_CONTAINERS ), $result['actions'] );
	}

	public function test_placement_off_is_good_and_says_it_is_deliberate(): void {
		$result = $this->collector( array(), array( GTM4WP_OPTION_GTM_PLACEMENT => GTM4WP_PLACEMENT_OFF ) )->run_all()[ SiteHealthTests::TEST_CONFIGURATION ];

		$this->assertSame( 'good', $result['status'], 'No container ID is not a problem in the data-layer-only setup.' );
		$this->assertStringContainsString( 'switched off', $result['description'] );
		$this->assertStringNotContainsString( 'no problem', $result['description'] );
	}

	public function test_a_warning_alone_is_recommended(): void {
		$stored = $this->configured(
			array(
				GTM4WP_OPTION_INCLUDE_VISITOR_IP         => true,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_FORWARDED_FOR',
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
			)
		);

		$result = $this->collector( array(), $stored )->run_all()[ SiteHealthTests::TEST_CONFIGURATION ];

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'warning', $result['label'] );
		$this->assertStringContainsString( 'gtm4wp-focus=' . rawurlencode( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES ), $result['actions'] );
	}

	public function test_an_error_next_to_a_warning_is_critical_and_lists_both(): void {
		$stored = array(
			GTM4WP_OPTION_INCLUDE_VISITOR_IP         => true,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_FORWARDED_FOR',
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
		);

		$result = $this->collector( array(), $stored )->run_all()[ SiteHealthTests::TEST_CONFIGURATION ];

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 2, substr_count( $result['description'], '<p>' ), 'One paragraph per problem.' );
		// T113: the link opens the FIRST problem's setting (the missing id), not the last.
		$this->assertStringContainsString( 'gtm4wp-focus=' . rawurlencode( GTM4WP_OPTION_GTM_CONTAINERS ), $result['actions'] );
		$this->assertStringNotContainsString( rawurlencode( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES ), $result['actions'] );
	}

	public function test_a_module_whose_schema_class_is_missing_is_skipped_without_a_fatal(): void {
		// T113: a half-installed third party naming a class that does not exist.
		$results = $this->collector( array( $this->module_with_id( 'acme-gone', 'GTM4WP\Tests\unit\Admin\NoSuchSchema' ) ) )->run_all();

		$this->assertSame( array( SiteHealthTests::TEST_CONFIGURATION ), array_keys( $results ) );
	}

	public function test_the_wrapper_absorbs_odd_result_shapes_and_odd_keys(): void {
		// T113: a scalar result becomes an empty good result; a label-only result
		// keeps its label and gets the good status; a key core could not select
		// (dot, space, upper case) is slugged like a module id (#266).
		$results = $this->collector( array( $this->module_with_id( 'acme-odd', OddShapesThirdPartySchema::class ) ) )->run_all();

		$this->assertSame(
			array( SiteHealthTests::TEST_CONFIGURATION, 'gtm4wp_acme_odd_scalar', 'gtm4wp_acme_odd_label_only', 'gtm4wp_acme_odd_odd_key_v2' ),
			array_keys( $results )
		);
		$this->assertSame( 'good', $results['gtm4wp_acme_odd_scalar']['status'] );
		$this->assertSame( '', $results['gtm4wp_acme_odd_scalar']['label'] );
		$this->assertSame( 'gtm4wp_acme_odd_scalar', $results['gtm4wp_acme_odd_scalar']['test'] );
		$this->assertSame( 'good', $results['gtm4wp_acme_odd_label_only']['status'] );
		$this->assertSame( 'Only a label', $results['gtm4wp_acme_odd_label_only']['label'] );
		$this->assertSame( 'recommended', $results['gtm4wp_acme_odd_odd_key_v2']['status'] );
	}

	// ---- The module opt-in -------------------------------------------------

	public function test_a_module_that_opts_in_contributes_prefixed_tests(): void {
		$results = $this->collector( array( new TestingThirdPartyModule() ) )->run_all();

		$this->assertSame(
			array( SiteHealthTests::TEST_CONFIGURATION, 'gtm4wp_acme_testing_checks_out', 'gtm4wp_acme_testing_overrides' ),
			array_keys( $results ),
			'Hyphens become underscores: core uses the id as an HTML id. The non-callable entry is dropped.'
		);
		$this->assertInstanceOf( Options::class, TestingThirdPartySchema::$received );
		$this->assertTrue( TestingThirdPartySchema::$received->get( 'acme-testing-option' ), 'The module default reached the schema through Options.' );
	}

	public function test_the_wrapper_stamps_the_id_and_badge_and_hands_the_html_over_raw(): void {
		$result = $this->collector( array( new TestingThirdPartyModule() ) )->run_all()['gtm4wp_acme_testing_checks_out'];

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'gtm4wp_acme_testing_checks_out', $result['test'] );
		$this->assertSame( 'Google Tag Manager', $result['badge']['label'] );
		// Core prints these unescaped, so an escape here would show as &amp;amp;.
		$this->assertSame( '<p>Ties & "Shirts" <b>bold</b></p>', $result['description'] );
		$this->assertSame( '<p><a href="https://example.com/?a=1&b=2">Fix & go</a></p>', $result['actions'] );
		$this->assertStringNotContainsString( '&amp;', $result['description'] );
	}

	public function test_the_wrapper_keeps_the_modules_badge_but_owns_the_id(): void {
		$result = $this->collector( array( new TestingThirdPartyModule() ) )->run_all()['gtm4wp_acme_testing_overrides'];

		$this->assertSame( 'red', $result['badge']['color'], 'A module may badge its own test.' );
		$this->assertSame( 'gtm4wp_acme_testing_overrides', $result['test'], 'The id is the collector\'s: it must be unique and prefixed.' );
		$this->assertSame( '', $result['description'], 'A key the module left out is defaulted so core reads it.' );
		$this->assertSame( '', $result['actions'] );
	}

	public function test_a_module_id_core_cannot_select_is_slugged_to_a_safe_test_id(): void {
		// Core interpolates the id into an HTML id and reads it back through a
		// jQuery '#' selector: a space or a dot leaves the panel unopenable.
		$results = $this->collector( array( $this->module_with_id( 'My Module.v2' ) ) )->run_all();

		$this->assertSame(
			array( SiteHealthTests::TEST_CONFIGURATION, 'gtm4wp_my_module_v2_checks_out', 'gtm4wp_my_module_v2_overrides' ),
			array_keys( $results )
		);
		$this->assertSame( 'gtm4wp_my_module_v2_checks_out', $results['gtm4wp_my_module_v2_checks_out']['test'] );
	}

	public function test_two_modules_landing_on_the_same_test_id_both_keep_their_tests(): void {
		$results = $this->collector( array( $this->module_with_id( 'acme-x' ), $this->module_with_id( 'acme_x' ) ) )->run_all();

		$this->assertSame(
			array(
				SiteHealthTests::TEST_CONFIGURATION,
				'gtm4wp_acme_x_checks_out',
				'gtm4wp_acme_x_overrides',
				'gtm4wp_acme_x_checks_out_2',
				'gtm4wp_acme_x_overrides_2',
			),
			array_keys( $results ),
			'A collision suffixes the later test instead of silently dropping one.'
		);

		foreach ( $results as $id => $result ) {
			$this->assertSame( $id, $result['test'] );
		}
	}

	/**
	 * A third-party module under any id, reporting the Acme testing schema.
	 *
	 * @param string $id The module id.
	 * @return ModuleInterface
	 */
	private function module_with_id( string $id, string $schema = TestingThirdPartySchema::class ): ModuleInterface {
		return new class( $id, $schema ) implements ModuleInterface {
			public function __construct( private string $module_id, private string $schema ) {
			}

			public function id(): string {
				return $this->module_id;
			}

			public function defaults(): array {
				return array();
			}

			public function is_available(): bool {
				return true;
			}

			public function frontend( Options $options ): void {
			}

			public function admin_schema(): string {
				return $this->schema;
			}
		};
	}

	public function test_a_schema_without_the_interface_contributes_nothing_and_does_not_fatal(): void {
		$results = $this->collector( array( new UndocumentedThirdPartyModule() ) )->run_all();

		$this->assertSame( array( SiteHealthTests::TEST_CONFIGURATION ), array_keys( $results ) );
	}
}
