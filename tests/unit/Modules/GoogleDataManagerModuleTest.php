<?php
/**
 * Unit tests for the Google Data Manager module's frontend wiring.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\GoogleDataManager\AttributionCookies;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\GoogleDataManagerModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * The capture bundle's three enable conditions and the config it is handed.
 *
 * The conditions are asserted separately because they answer to different
 * things: the option is the site owner's choice, the commerce platform is the
 * environment, and the destination is what supplies the measurement ID the
 * lookups need. The field's depends_on covers only the third one, and only in
 * the admin UI, so the runtime guard here is what actually holds - a site that
 * enabled capture and later deleted its destinations arrives in exactly that
 * state.
 */
final class GoogleDataManagerModuleTest extends TestCase {

	/**
	 * Script handles that were enqueued during a test.
	 *
	 * @var array<int, string>
	 */
	private array $enqueued = array();

	/**
	 * Inline script bodies keyed by handle.
	 *
	 * @var array<string, string>
	 */
	private array $inline = array();

	protected function setUp(): void {
		parent::setUp();

		$this->enqueued = array();
		$this->inline   = array();

		// Brain Monkey defines a stubbed WC()/EDD() process-wide and
		// permanently, so whether this process "has WooCommerce" depends on
		// which other test files ran. The namespaced shim makes that explicit
		// per test instead (TS-16); the default is a store present, which is
		// the state every test but the platform ones needs.
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => true,
			'EDD' => false,
		);

		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ): void {
				$this->enqueued[] = $handle;
			}
		);

		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $data ): bool {
				$this->inline[ $handle ] = $data;

				return true;
			}
		);

		Functions\when( 'plugins_url' )->alias(
			static fn ( $path ) => 'https://example.com/wp-content/plugins/gtm4wp/' . $path
		);

		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The stand-in for the function under stub; calling it here would recurse.
			static fn ( $value, $flags = 0 ) => json_encode( $value, $flags )
		);

		// Not a confirmation page: the backfill flag has its own suite, and
		// leaving these unstubbed would make this one fail on whichever
		// receipt helper the module happens to reach first.
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'edd_is_success_page' )->justReturn( false );
	}

	protected function tearDown(): void {
		// Process-wide state, so it is cleared whatever the test did (TS-7).
		$GLOBALS['gtm4wp_test_forced_functions'] = array();

		parent::tearDown();
	}

	/**
	 * A booted module over the given options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return GoogleDataManagerModule
	 */
	private function module( array $stored ): GoogleDataManagerModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module  = new GoogleDataManagerModule();
		$options = new Options( $module->defaults() );

		$module->frontend( $options );

		return $module;
	}

	/**
	 * One valid stored destination row.
	 *
	 * @param array<string, string> $overrides Column overrides.
	 * @return array<string, string>
	 */
	private static function row( array $overrides = array() ): array {
		return array_merge(
			array(
				DestinationRows::COLUMN_LABEL       => 'Production',
				DestinationRows::COLUMN_ACCOUNT     => 'sa_0123456789ab',
				DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
				DestinationRows::COLUMN_PROPERTY    => '123456789',
				DestinationRows::COLUMN_MEASUREMENT => 'G-ABC123',
			),
			$overrides
		);
	}

	// ---- Hook registration -------------------------------------------------

	public function test_no_capture_hook_while_the_option_is_off(): void {
		$this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => false,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		$this->assertFalse( has_action( 'wp_enqueue_scripts' ) );
	}

	/**
	 * Capture exists to attach data to orders, so with neither commerce
	 * platform active there is nothing for it to attach to and the bundle must
	 * not load on the site's pages at all.
	 */
	public function test_no_capture_hook_without_a_commerce_platform(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => false,
			'EDD' => false,
		);

		$this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		$this->assertFalse(
			has_action( 'wp_enqueue_scripts' ),
			'With no store there are no orders to attach captured data to.'
		);
	}

	public function test_the_capture_hook_registers_on_a_woocommerce_store(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => true,
			'EDD' => false,
		);

		$this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		$this->assertNotFalse( has_action( 'wp_enqueue_scripts' ) );
	}

	/**
	 * The parity half: a store running only Easy Digital Downloads captures
	 * exactly like a WooCommerce one. Asserted separately rather than trusted
	 * to the `||`, because "the feature quietly only works on WooCommerce" is
	 * the failure this project has a standing rule against.
	 */
	public function test_the_capture_hook_registers_on_an_edd_store(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => false,
			'EDD' => true,
		);

		$this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		$this->assertNotFalse( has_action( 'wp_enqueue_scripts' ) );
	}

	// ---- The printed config ------------------------------------------------

	/**
	 * The enqueue path is exercised directly rather than through the hook,
	 * because the commerce-platform guard above keeps the hook from being
	 * registered in a process where neither platform exists. What matters here
	 * is what the bundle is handed once it does load.
	 */
	public function test_the_config_carries_the_measurement_ids_and_the_cookie_contract(): void {
		$module = $this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array(
					self::row(),
					self::row( array( DestinationRows::COLUMN_MEASUREMENT => 'G-SECOND' ) ),
				),
			)
		);

		$module->enqueue_capture_script();

		$this->assertContains( 'gtm4wp-attribution', $this->enqueued );

		$config = $this->decoded_config();

		$this->assertSame( array( 'G-ABC123', 'G-SECOND' ), $config['measurementIds'] );
		$this->assertSame( AttributionCookies::IDS_COOKIE, $config['idsCookie'] );
		$this->assertSame( AttributionCookies::CONSENT_COOKIE, $config['consentCookie'] );
		$this->assertSame( AttributionCookies::FORMAT_VERSION, $config['version'] );
		$this->assertSame( AttributionCookies::LIFETIME_DAYS, $config['lifetimeDays'] );
		$this->assertSame( AttributionCookies::MAX_BYTES, $config['maxBytes'] );
		$this->assertSame( array( 'gclid', 'gbraid', 'wbraid' ), $config['clickIds'] );
	}

	/**
	 * The `var` spelling is load-bearing, not style: the bundle reads the
	 * config as window.gtm4wp_gdm_attribution_config, and a top-level `const`
	 * binds lexically and never becomes a window property, which is how three
	 * shipped features were silently dead before (RI-14).
	 */
	public function test_the_config_is_printed_as_a_window_property(): void {
		$module = $this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		$module->enqueue_capture_script();

		$this->assertStringStartsWith(
			'var gtm4wp_gdm_attribution_config = ',
			$this->inline['gtm4wp-attribution']
		);
	}

	/**
	 * The runtime destination list is a public filter, so a third party can
	 * hand this path a row nobody validated at save time. Such a row must not
	 * reach the printed config: a measurement ID is written into a script
	 * block and then sent to Google as a `get` target, so a crafted one is
	 * both an output-escaping and a "we ask Google about a stranger's stream"
	 * problem. The grammar check drops it before either can happen.
	 */
	public function test_a_measurement_id_injected_through_the_filter_is_dropped(): void {
		$module = $this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( GTM4WP_WPFILTER_GDM_DESTINATIONS === $hook ) {
					return array(
						self::row( array( DestinationRows::COLUMN_MEASUREMENT => 'G-A"</script><b>' ) ),
						self::row( array( DestinationRows::COLUMN_MEASUREMENT => 'not-a-measurement-id' ) ),
					);
				}

				return $value;
			}
		);

		$module->enqueue_capture_script();

		$this->assertSame( array(), $this->enqueued, 'Every row was invalid, so there is nothing to capture with.' );
		$this->assertSame( array(), $this->inline );
	}

	/**
	 * The config lands inside a script block, so whatever it carries has to
	 * come out hex-escaped: the safe form present, the raw break-out character
	 * absent (TS-2). Asserted on a value forced through the same encoder the
	 * config uses, because the config's own members are all grammar-checked
	 * before they get here - the point is that the encoding, not the
	 * validation, is what makes the printing safe.
	 */
	public function test_the_config_encoder_escapes_script_breakout_characters(): void {
		$hostile = '</script><b>"&\'';
		$printed = ScriptTag::json_literal(
			array( 'label' => $hostile ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		// Neither the tag that would end the script block nor any of the
		// characters that could break out of the JSON string survives raw.
		$this->assertStringNotContainsString( '</script>', $printed );
		$this->assertStringNotContainsString( '<', $printed );
		$this->assertStringNotContainsString( '>', $printed );
		$this->assertStringNotContainsString( '&', $printed );
		$this->assertStringNotContainsString( "'", $printed );

		// And the escaping is lossless: what the browser parses back is the
		// value that went in, so safety here costs no fidelity.
		$this->assertSame( $hostile, json_decode( $printed, true )['label'] );
	}

	public function test_nothing_is_enqueued_without_a_usable_destination(): void {
		$module = $this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array(),
			)
		);

		$module->enqueue_capture_script();

		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->inline );
	}

	/**
	 * A row of a type that is not the Google Analytics one supplies no
	 * measurement ID to ask about. No type like that exists yet, but the
	 * column is a select from day one precisely so one can be added, and the
	 * capture path must not start asking Google about it by accident.
	 */
	public function test_a_non_analytics_destination_contributes_no_measurement_id(): void {
		$module = $this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( GTM4WP_WPFILTER_GDM_DESTINATIONS === $hook ) {
					return array( self::row( array( DestinationRows::COLUMN_TYPE => 'something-else' ) ) );
				}

				return $value;
			}
		);

		$module->enqueue_capture_script();

		$this->assertSame( array(), $this->enqueued );
	}

	/**
	 * The same data stream listed twice must not produce two identical
	 * session_id lookups.
	 */
	public function test_duplicate_measurement_ids_are_asked_about_once(): void {
		$module = $this->module(
			array(
				GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => true,
				GTM4WP_OPTION_GDM_DESTINATIONS        => array( self::row() ),
			)
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( GTM4WP_WPFILTER_GDM_DESTINATIONS === $hook ) {
					return array( self::row(), self::row( array( DestinationRows::COLUMN_LABEL => 'Copy' ) ) );
				}

				return $value;
			}
		);

		$module->enqueue_capture_script();

		$this->assertSame( array( 'G-ABC123' ), $this->decoded_config()['measurementIds'] );
	}

	/**
	 * The printed config as an array.
	 *
	 * @return array<string, mixed>
	 */
	private function decoded_config(): array {
		$printed = $this->inline['gtm4wp-attribution'];
		$json    = substr( $printed, strlen( 'var gtm4wp_gdm_attribution_config = ' ), -1 );

		return json_decode( $json, true );
	}
}
