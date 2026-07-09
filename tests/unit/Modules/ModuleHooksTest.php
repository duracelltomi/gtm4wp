<?php
/**
 * Hook registration gating tests for the simple modules.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Module\ModuleInterface;
use GTM4WP\Modules\Amp\AmpModule;
use GTM4WP\Modules\ConsentMode\ConsentModeModule;
use GTM4WP\Modules\ContactForm7\ContactForm7Module;
use GTM4WP\Modules\ScrollTracking\ScrollTrackingModule;
use GTM4WP\Modules\UserEvents\UserEventsModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

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

	public function test_scroll_tracking_registers_hooks_only_when_enabled(): void {
		$module = $this->boot( new ScrollTrackingModule(), array( GTM4WP_OPTION_SCROLLER_ENABLED => true ) );

		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $module, 'enqueue_scripts' ) ) );
		$this->assertNotFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $module, 'add_header_js' ) ) );
	}

	public function test_scroll_tracking_inactive_when_disabled(): void {
		$module = $this->boot( new ScrollTrackingModule() );

		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $module, 'enqueue_scripts' ) ) );
		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $module, 'add_header_js' ) ) );
	}

	public function test_scroll_tracking_header_js_contains_configuration(): void {
		$module = new ScrollTrackingModule();
		$this->boot(
			$module,
			array(
				GTM4WP_OPTION_SCROLLER_ENABLED      => true,
				GTM4WP_OPTION_SCROLLER_DEBUGMODE    => true,
				GTM4WP_OPTION_SCROLLER_CALLBACKTIME => 250,
				GTM4WP_OPTION_SCROLLER_DISTANCE     => 300,
				GTM4WP_OPTION_SCROLLER_CONTENTID    => 'main',
				GTM4WP_OPTION_SCROLLER_READERTIME   => 90,
			)
		);

		Functions\stubEscapeFunctions();

		$js = $module->add_header_js( '' );

		$this->assertStringContainsString( 'const gtm4wp_scrollerscript_debugmode         = true;', $js );
		$this->assertStringContainsString( 'const gtm4wp_scrollerscript_callbacktime      = 250;', $js );
		$this->assertStringContainsString( 'const gtm4wp_scrollerscript_readerlocation    = 300;', $js );
		$this->assertStringContainsString( 'const gtm4wp_scrollerscript_contentelementid  = "main";', $js );
		$this->assertStringContainsString( 'const gtm4wp_scrollerscript_scannertime       = 90;', $js );
	}

	public function test_consent_mode_registers_webtoffee_js_when_enabled(): void {
		$enabled = $this->boot( new ConsentModeModule(), array( GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR => true ) );
		$this->assertNotFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $enabled, 'add_webtoffee_header_js' ) ) );
	}

	public function test_consent_mode_inactive_when_disabled(): void {
		$disabled = $this->boot( new ConsentModeModule() );
		$this->assertFalse( has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $disabled, 'add_webtoffee_header_js' ) ) );
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
	}

	public function test_contact_form_7_inactive_when_disabled(): void {
		$disabled = $this->boot( new ContactForm7Module() );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $disabled, 'enqueue_scripts' ) ) );
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
		$this->assertNotFalse( has_action( 'amp_post_template_head', array( $enabled, 'render_header_begin' ) ) );
		$this->assertNotFalse( has_filter( ContainerCode::FILTER_AMP_RUNNING, array( $enabled, 'is_amp_request' ) ) );
	}

	public function test_amp_hooks_inactive_without_amp_container_id(): void {
		$disabled = $this->boot( new AmpModule() );
		$this->assertFalse( has_action( 'amp_post_template_head', array( $disabled, 'render_header_begin' ) ) );
	}
}
