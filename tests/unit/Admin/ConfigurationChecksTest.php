<?php
/**
 * Unit tests for the configuration checks.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Functions;
use GTM4WP\Admin\ConfigurationChecks;
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * One list, two readers (the admin notices and gtm4wp/get-status). Each
 * problem is pinned in both directions - raised on the state it is about,
 * silent otherwise - together with the three things a reader keys on: its
 * code, its severity and whether it may be dismissed. The rendered notices
 * are NoticesTest's.
 */
final class ConfigurationChecksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $number ) => ( 1 === (int) $number ? $single : $plural ) );
	}

	/**
	 * Checks over the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ConfigurationChecks
	 */
	private function checks( array $stored ): ConfigurationChecks {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new ConfigurationChecks( new Options( $stored ) );
	}

	/**
	 * The codes of the problems found.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return string[]
	 */
	private function codes( array $stored ): array {
		return array_column( $this->checks( $stored )->problems(), 'code' );
	}

	public function test_a_configured_site_has_no_problems(): void {
		$this->assertSame(
			array(),
			$this->checks(
				array(
					GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
					GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_FOOTER,
				)
			)->problems()
		);
	}

	public function test_a_missing_container_id_is_a_dismissible_error_about_the_container_list(): void {
		$problems = $this->checks(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(),
				GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_FOOTER,
			)
		)->problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( ConfigurationChecks::CODE_MISSING_CONTAINER_ID, $problems[0]['code'] );
		$this->assertSame( ConfigurationChecks::SEVERITY_ERROR, $problems[0]['severity'] );
		$this->assertSame( GTM4WP_OPTION_GTM_CONTAINERS, $problems[0]['option_key'], 'The container list, not the derived 1.x gtm-code mirror, which has no control.' );
		$this->assertTrue( $problems[0]['dismissible'] );
	}

	public function test_a_missing_container_id_is_not_a_problem_when_the_container_code_is_off(): void {
		$this->assertSame(
			array(),
			$this->codes(
				array(
					GTM4WP_OPTION_GTM_CONTAINERS => array(),
					GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_OFF,
				)
			),
			'Data layer only mode needs no container ID.'
		);
	}

	public function test_an_incomplete_environment_names_the_container_and_cannot_be_dismissed(): void {
		$problems = $this->checks(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID      => 'GTM-INCOMPLETE',
						ContainerRows::COLUMN_AUTH    => 'some-auth-token',
						ContainerRows::COLUMN_PREVIEW => '',
					),
					array(
						ContainerRows::COLUMN_ID      => 'GTM-COMPLETE',
						ContainerRows::COLUMN_AUTH    => 'some-auth-token',
						ContainerRows::COLUMN_PREVIEW => 'env-1',
					),
				),
				GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_FOOTER,
			)
		)->problems();

		$this->assertCount( 1, $problems, 'The complete container raises nothing.' );
		$this->assertSame( ConfigurationChecks::CODE_INCOMPLETE_ENVIRONMENT, $problems[0]['code'] );
		$this->assertStringContainsString( 'GTM-INCOMPLETE', $problems[0]['message'] );
		$this->assertFalse( $problems[0]['dismissible'] );
	}

	public function test_an_untrusted_visitor_ip_header_is_a_warning_about_the_proxy_list(): void {
		$base = array(
			GTM4WP_OPTION_GTM_CONTAINERS             => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
			GTM4WP_OPTION_GTM_PLACEMENT              => GTM4WP_PLACEMENT_FOOTER,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP         => true,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_FORWARDED_FOR',
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
		);

		$problems = $this->checks( $base )->problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( ConfigurationChecks::CODE_UNTRUSTED_VISITOR_IP, $problems[0]['code'] );
		$this->assertSame( ConfigurationChecks::SEVERITY_WARNING, $problems[0]['severity'] );
		$this->assertSame( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES, $problems[0]['option_key'] );
		$this->assertFalse( $problems[0]['dismissible'] );

		$this->assertSame( array(), $this->codes( array_merge( $base, array( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '10.0.0.0/8' ) ) ), 'Silent once trusted proxies are configured.' );
		$this->assertSame( array(), $this->codes( array_merge( $base, array( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER => '' ) ) ), 'Silent with REMOTE_ADDR only.' );
		$this->assertSame( array(), $this->codes( array_merge( $base, array( GTM4WP_OPTION_INCLUDE_VISITOR_IP => false ) ) ), 'Silent while the variable is off.' );
	}

	/**
	 * #272: the Cloudflare country header is the same unauthenticated read as a
	 * custom visitor-IP header, so it gets the same warning until trusted
	 * proxies are configured. Grant and deny.
	 */
	public function test_the_cloudflare_country_header_without_trusted_proxies_is_a_warning_about_the_proxy_list(): void {
		$base = array(
			GTM4WP_OPTION_GTM_CONTAINERS             => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
			GTM4WP_OPTION_GTM_PLACEMENT              => GTM4WP_PLACEMENT_FOOTER,
			GTM4WP_OPTION_INCLUDE_MISCGEOCF          => true,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
		);

		$problems = $this->checks( $base )->problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( ConfigurationChecks::CODE_UNTRUSTED_COUNTRY, $problems[0]['code'] );
		$this->assertSame( ConfigurationChecks::SEVERITY_WARNING, $problems[0]['severity'] );
		$this->assertSame( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES, $problems[0]['option_key'] );
		$this->assertFalse( $problems[0]['dismissible'] );

		$this->assertSame( array(), $this->codes( array_merge( $base, array( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '103.21.244.0/22' ) ) ), 'Silent once trusted proxies are configured.' );
		$this->assertSame( array(), $this->codes( array_merge( $base, array( GTM4WP_OPTION_INCLUDE_MISCGEOCF => false ) ) ), 'Silent while the variable is off.' );
	}

	public function test_an_unusable_data_layer_name_is_an_error_about_that_option(): void {
		$problems = $this->checks(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_FOOTER,
				GTM4WP_OPTION_DATALAYER_NAME => 'my-layer',
			)
		)->problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( ConfigurationChecks::CODE_INVALID_DATALAYER_NAME, $problems[0]['code'] );
		$this->assertSame( GTM4WP_OPTION_DATALAYER_NAME, $problems[0]['option_key'] );
		$this->assertStringContainsString( 'my-layer', $problems[0]['message'], 'The rejected value is named so it can be found.' );
		$this->assertFalse( $problems[0]['dismissible'] );
	}

	/**
	 * The deny half of the data layer name check.
	 *
	 * @param string $stored A usable stored name.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_usable_datalayer_names' )]
	public function test_a_usable_data_layer_name_raises_nothing( string $stored ): void {
		$this->assertSame(
			array(),
			$this->codes(
				array(
					GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
					GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_FOOTER,
					GTM4WP_OPTION_DATALAYER_NAME => $stored,
				)
			)
		);
	}

	/**
	 * Stored names that must raise nothing.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_usable_datalayer_names(): array {
		return array(
			'unset (the default)'  => array( '' ),
			'the GTM default'      => array( 'dataLayer' ),
			'a plain rename'       => array( 'myDataLayer' ),
			'a leading underscore' => array( '_private' ),
		);
	}

	public function test_a_conflicting_analytics_plugin_is_a_dismissible_warning_with_no_setting_to_point_at(): void {
		Functions\when( 'is_plugin_active' )->alias(
			static fn ( $plugin ) => 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' === $plugin
		);

		$problems = $this->checks(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS             => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT              => GTM4WP_PLACEMENT_FOOTER,
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
			)
		)->problems();

		$this->assertCount( 1, $problems, 'Only the plugin that is active.' );
		$this->assertSame( ConfigurationChecks::CODE_CONFLICT_WC_GA, $problems[0]['code'] );
		$this->assertSame( ConfigurationChecks::SEVERITY_WARNING, $problems[0]['severity'] );
		$this->assertSame( '', $problems[0]['option_key'] );
		$this->assertTrue( $problems[0]['dismissible'] );
	}

	public function test_both_conflicting_plugins_are_reported_one_problem_each_in_order(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );

		$problems = $this->checks(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS             => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT              => GTM4WP_PLACEMENT_FOOTER,
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
			)
		)->problems();

		$this->assertSame(
			array( ConfigurationChecks::CODE_CONFLICT_WC_GA, ConfigurationChecks::CODE_CONFLICT_MONSTERINSIGHTS ),
			array_column( $problems, 'code' ),
			'Each conflicting plugin is its own problem, WooCommerce\'s first (T105c: the MonsterInsights branch had never been raised).'
		);
		$this->assertStringContainsString( 'MonsterInsights', $problems[1]['message'] );
		$this->assertNotSame( $problems[0]['message'], $problems[1]['message'] );
		$this->assertSame( ConfigurationChecks::SEVERITY_WARNING, $problems[1]['severity'] );
		$this->assertSame( '', $problems[1]['option_key'] );
		$this->assertTrue( $problems[1]['dismissible'] );
	}

	/**
	 * The order the notices show them and get-status lists them: container
	 * first, then the request-side and data-layer checks, conflicts last.
	 */
	public function test_problems_are_listed_in_the_order_the_notices_show_them(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );

		$this->assertSame(
			array(
				ConfigurationChecks::CODE_MISSING_CONTAINER_ID,
				ConfigurationChecks::CODE_UNTRUSTED_VISITOR_IP,
				ConfigurationChecks::CODE_INVALID_DATALAYER_NAME,
				ConfigurationChecks::CODE_CONFLICT_WC_GA,
				ConfigurationChecks::CODE_CONFLICT_MONSTERINSIGHTS,
			),
			$this->codes(
				array(
					GTM4WP_OPTION_GTM_CONTAINERS     => array(),
					GTM4WP_OPTION_GTM_PLACEMENT      => GTM4WP_PLACEMENT_FOOTER,
					GTM4WP_OPTION_INCLUDE_VISITOR_IP => true,
					GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER => 'HTTP_X_FORWARDED_FOR',
					GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
					GTM4WP_OPTION_DATALAYER_NAME     => 'not-an-identifier',
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				)
			)
		);
	}

	public function test_conflicting_plugins_are_not_checked_while_ecommerce_tracking_is_off(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );

		$this->assertSame(
			array(),
			$this->codes(
				array(
					GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
					GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_FOOTER,
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => false,
				)
			),
			'A second Analytics plugin only double-counts what this one tracks.'
		);
	}

	/**
	 * The real integration: a malformed wp-config constant, rejected while the
	 * options are built, surfaced by the checks with the constant named.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_a_malformed_hardcoded_constant_is_named_and_cannot_be_dismissed(): void {
		define( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', 'oops preview' );

		$problems = $this->checks(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array( array( ContainerRows::COLUMN_ID => 'GTM-ABC123' ) ),
				GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_FOOTER,
			)
		)->problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( ConfigurationChecks::CODE_INVALID_HARDCODED, $problems[0]['code'] );
		$this->assertStringContainsString( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', $problems[0]['message'] );
		$this->assertStringContainsString( 'wp-config.php', $problems[0]['message'] );
		$this->assertFalse( $problems[0]['dismissible'] );
	}
}
