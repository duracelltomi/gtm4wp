<?php
/**
 * Hook registration gating tests for the simple modules.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\Frontend;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Module\ModuleInterface;
use GTM4WP\Modules\Amp\AmpModule;
use GTM4WP\Modules\ClientDeviceData\ClientDeviceDataModule;
use GTM4WP\Modules\ConsentMode\ConsentModeModule;
use GTM4WP\Modules\ContactForm7\ContactForm7Module;
use GTM4WP\Modules\MediaEvents\MediaEventsModule;
use GTM4WP\Modules\UserEvents\UserEventsModule;
use GTM4WP\Modules\VisitorData\VisitorDataModule;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Plugin;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * Each module must register its hooks only when its enabling option is on.
 */
final class ModuleHooksTest extends TestCase {

	/**
	 * Boots a module with the given stored options.
	 *
	 * @param ModuleInterface      $module The module under test.
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ModuleInterface
	 */
	private function boot( ModuleInterface $module, array $stored = array() ): ModuleInterface {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module->frontend( new Options( $module->defaults() ) );

		return $module;
	}

	public function test_consent_mode_registers_webtoffee_js_when_enabled(): void {
		$enabled = $this->boot( new ConsentModeModule(), array( GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR => true ) );
		$this->assertNotFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $enabled, 'add_webtoffee_header_js' ) ) );
	}

	public function test_consent_mode_inactive_when_disabled(): void {
		$disabled = $this->boot( new ConsentModeModule() );
		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $disabled, 'add_webtoffee_header_js' ) ) );
	}

	public function test_consent_mode_wires_axeptio_when_enabled(): void {
		// Axeptio is owned by the consent module; enabling it (with a project ID)
		// must register a head-block callback via the delegated handler.
		$this->boot(
			new ConsentModeModule(),
			array(
				GTM4WP_OPTION_INTEGRATE_AXEPTIO           => true,
				GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID => 'my-project',
			)
		);

		$this->assertNotFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS ) );
	}

	public function test_consent_mode_does_not_wire_any_head_js_when_all_tools_disabled(): void {
		$this->boot( new ConsentModeModule() );

		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS ) );
	}

	public function test_webtoffee_header_js_uses_custom_datalayer_name(): void {
		Functions\stubEscapeFunctions();

		$module = new ConsentModeModule();
		$this->boot( $module, array( GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR => true ) );

		$js = $module->add_webtoffee_header_js( '', 'customDL' );

		$this->assertStringContainsString( 'window.customDL = window.customDL || [];', $js );
		$this->assertStringContainsString( '"event": "cookie_consent_update"', $js );
		$this->assertStringContainsString( 'CookieLawInfo_Accept_Callback', $js );
	}

	public function test_contact_form_7_active_when_enabled(): void {
		$enabled = $this->boot( new ContactForm7Module(), array( GTM4WP_OPTION_INTEGRATE_WPCF7 => true ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $enabled, 'enqueue_scripts' ) ) );
		$this->assertNotFalse( has_filter( 'wpcf7_form_additional_atts', array( $enabled, 'add_form_name_attribute' ) ) );
	}

	public function test_contact_form_7_inactive_when_disabled(): void {
		$disabled = $this->boot( new ContactForm7Module() );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $disabled, 'enqueue_scripts' ) ) );
		$this->assertFalse( has_filter( 'wpcf7_form_additional_atts', array( $disabled, 'add_form_name_attribute' ) ) );
	}

	public function test_user_events_login_hook_active_when_enabled(): void {
		$enabled = $this->boot( new UserEventsModule(), array( GTM4WP_OPTION_EVENTS_USERLOGIN => true ) );
		$this->assertNotFalse( has_action( 'wp_login', array( $enabled, 'on_login' ) ) );
	}

	public function test_user_events_login_hook_inactive_when_disabled(): void {
		$disabled = $this->boot( new UserEventsModule() );
		$this->assertFalse( has_action( 'wp_login', array( $disabled, 'on_login' ) ) );
	}

	public function test_amp_hooks_active_with_amp_container_id(): void {
		$enabled = $this->boot( new AmpModule(), array( GTM4WP_OPTION_INTEGRATE_AMPID => 'GTM-AMP1' ) );
		$this->assertNotFalse( has_filter( 'amp_analytics_entries', array( $enabled, 'add_amp_analytics_entries' ) ) );
		$this->assertNotFalse( has_filter( ContainerCode::FILTER_AMP_RUNNING, array( $enabled, 'is_amp_request' ) ) );
	}

	public function test_amp_hooks_inactive_without_amp_container_id(): void {
		$disabled = $this->boot( new AmpModule() );
		$this->assertFalse( has_filter( 'amp_analytics_entries', array( $disabled, 'add_amp_analytics_entries' ) ) );
		$this->assertFalse( has_filter( ContainerCode::FILTER_AMP_RUNNING, array( $disabled, 'is_amp_request' ) ) );
	}

	public function test_client_device_data_enqueues_when_any_signal_enabled(): void {
		$enabled = $this->boot( new ClientDeviceDataModule(), array( GTM4WP_OPTION_INCLUDE_BROWSERDATA => true ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $enabled, 'enqueue_scripts' ) ) );
	}

	public function test_client_device_data_inactive_when_all_signals_disabled(): void {
		$disabled = $this->boot( new ClientDeviceDataModule() );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $disabled, 'enqueue_scripts' ) ) );
	}

	public function test_visitor_data_enqueues_and_declares_fields_when_cache_safe_on(): void {
		$enabled = $this->boot( new VisitorDataModule(), array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) );

		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $enabled, 'enqueue_scripts' ) ) );
	}

	public function test_visitor_data_inactive_when_cache_safe_off(): void {
		$disabled = $this->boot( new VisitorDataModule() );

		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $disabled, 'enqueue_scripts' ) ) );
	}

	public function test_media_events_youtube_filter_active_when_enabled(): void {
		$enabled = $this->boot( new MediaEventsModule(), array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$this->assertNotFalse( has_filter( 'oembed_result', array( $enabled, 'enable_youtube_js_api' ) ) );
	}

	public function test_media_events_youtube_filter_inactive_when_disabled(): void {
		$disabled = $this->boot( new MediaEventsModule() );
		$this->assertFalse( has_filter( 'oembed_result', array( $disabled, 'enable_youtube_js_api' ) ) );
	}

	protected function tearDown(): void {
		// Release the Plugin singleton the WooCommerce gate tests install and reset
		// the purchase-flow marker register_frontend_hooks() seeds (TS-7).
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );
		unset( $GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] );

		parent::tearDown();
	}

	/**
	 * Sets a private/protected instance property via reflection.
	 *
	 * @param object $target   Target object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	private function set_private( object $target, string $property, $value ): void {
		( new \ReflectionProperty( $target, $property ) )->setValue( $target, $value );
	}

	/**
	 * Runs WooCommerceModule::register_frontend_hooks() with the given stored
	 * options, installing the Plugin->Frontend->DataLayer/ScriptTag singleton chain
	 * the method reaches into (TC-8). register_frontend_hooks() is protected and
	 * gates on the WCTRACKECOMMERCE option, so it is invoked directly via reflection
	 * — bypassing is_available() (WooCommerce presence), which is separate wiring.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return void
	 */
	private function register_woocommerce_hooks( array $stored ): void {
		Functions\when( 'get_option' )->justReturn( $stored );

		$options = new Options( ( new WooCommerceModule() )->defaults() );

		// Frontend built without its constructor (no Registry needed); only
		// datalayer() and script_tag() are read when the sub-services are built.
		$frontend = ( new \ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $frontend, 'datalayer', new DataLayer( $options ) );
		$this->set_private( $frontend, 'script_tag', new ScriptTag( $options ) );

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $plugin, 'frontend', $frontend );
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $plugin );

		$module = new WooCommerceModule();
		$this->set_private( $module, 'options', $options );

		$method = new \ReflectionMethod( $module, 'register_frontend_hooks' );
		$method->invoke( $module );
	}

	public function test_woocommerce_wires_block_and_purchase_hooks_when_tracking_enabled(): void {
		$this->register_woocommerce_hooks( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );

		// The Store API block-data feed and the purchase fallback are wired.
		$this->assertNotFalse( has_action( 'woocommerce_blocks_loaded' ), 'The Store API block-data feed must be registered.' );
		$this->assertNotFalse( has_action( 'woocommerce_thankyou' ), 'The thank-you purchase fallback must be registered.' );
		$this->assertNotFalse( has_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY ), 'The global-vars filter must be registered.' );
	}

	public function test_woocommerce_wires_nothing_when_tracking_disabled(): void {
		// The whole integration gates on WCTRACKECOMMERCE (1.x load condition).
		$this->register_woocommerce_hooks( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => false ) );

		$this->assertFalse( has_action( 'woocommerce_blocks_loaded' ) );
		$this->assertFalse( has_action( 'woocommerce_thankyou' ) );
		$this->assertFalse( has_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY ) );
	}

	public function test_woocommerce_does_not_seed_purchase_hooks_by_default(): void {
		// With tracking on but neither reliability option, the order-remember seed
		// hooks (payment_complete / status_changed) stay off.
		$this->register_woocommerce_hooks( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );

		$this->assertFalse( has_action( 'woocommerce_payment_complete' ), 'The seed hook must be off by default.' );
	}

	public function test_woocommerce_seeds_purchase_hooks_when_reliability_enabled(): void {
		$this->register_woocommerce_hooks(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		);

		$this->assertNotFalse( has_action( 'woocommerce_payment_complete' ), 'Enabling reliable tracking must seed woocommerce_payment_complete.' );
	}
}
