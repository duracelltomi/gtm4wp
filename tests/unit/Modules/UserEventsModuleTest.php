<?php
/**
 * Unit tests for the user login / registration event module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\Frontend;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\UserEvents\UserEventsModule;
use GTM4WP\Options\Options;
use GTM4WP\Plugin;
use GTM4WP\Tests\unit\TestCase;

/**
 * Until test-review Run 5 (2026-08-05) this module had NO test file: only its
 * hook gate was covered, by ModuleHooksTest. That left three surfaces unguarded
 * at once, which is why this file exists (gap T29):
 *
 * - output_event_scripts() is a raw <script> sink hooked to wp_footer, driven by
 *   $_COOKIE['gtm4wp_user_logged_in'] / ['gtm4wp_user_registered'] - the only
 *   superglobal reads anywhere in src/ that no test exercised (TC-5);
 * - on_login() / on_register() / clear_event_cookies() write and expire cookies
 *   whose secure + httponly flags nothing asserted;
 * - the per-event option gates decide whether any of it runs at all.
 *
 * setcookie() is redefinable through patchwork.json, so the cookie writes are
 * captured rather than inferred. Note this module uses the POSITIONAL setcookie()
 * signature (name, value, expires, path, domain, secure, httponly), not the
 * options-array form VisitorDataModule uses.
 */
final class UserEventsModuleTest extends TestCase {

	/**
	 * Captured setcookie() calls: each entry is
	 * array( name, value, expires, path, domain, secure, httponly ).
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $cookie_writes = array();

	/**
	 * Handles + args passed to wp_enqueue_script().
	 *
	 * @var array<int, array{handle: string, args: mixed}>
	 */
	private array $enqueued = array();

	/**
	 * Every wp_add_inline_script() call recorded during the test.
	 *
	 * @var array<int, array{handle: string, code: string, position: string}>
	 */
	private array $inline_scripts = array();

	/**
	 * Snapshot of $_COOKIE, restored in tearDown so a failing assertion cannot
	 * leak event cookies into a sibling test (TS-7).
	 *
	 * @var array<string, mixed>
	 */
	private array $cookie_backup = array();

	protected function setUp(): void {
		parent::setUp();

		$this->cookie_writes  = array();
		$this->enqueued       = array();
		$this->inline_scripts = array();
		$this->cookie_backup  = $_COOKIE;

		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_theme_supports' )->justReturn( true );
		Functions\when( 'plugins_url' )->alias( static fn ( $path, $plugin ) => 'https://example.com/' . $path );
		// A realistic wp_kses(): ScriptTag::print_script_block() restores the
		// ampersand entity afterwards, and an identity stub would make every
		// assertion about that pipeline vacuous while still covering the line
		// (security #92 / test-review TS-1).
		Functions\when( 'wp_kses' )->alias( static fn ( $content ) => str_replace( '&', '&amp;', (string) $content ) );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
				$this->enqueued[] = array(
					'handle' => $handle,
					'args'   => $args,
				);
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_scripts[] = array(
					'handle'   => $handle,
					'code'     => (string) $code,
					'position' => $position,
				);
				return true;
			}
		);
		Functions\when( 'setcookie' )->alias(
			function ( $name, $value = '', $expires = 0, $path = '', $domain = '', $secure = false, $httponly = false ) {
				$this->cookie_writes[] = array( $name, (string) $value, (int) $expires, $path, $domain, (bool) $secure, (bool) $httponly );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_COOKIE = $this->cookie_backup;
		// Release the Plugin singleton output_event_scripts() reaches through (TC-8).
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Sets a private/protected instance property via reflection.
	 *
	 * @param object $target   Target object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 * @return void
	 */
	private function set_private( object $target, string $property, $value ): void {
		( new \ReflectionProperty( $target, $property ) )->setValue( $target, $value );
	}

	/**
	 * Builds the module with the given stored options. When $with_singleton is
	 * true the Plugin -> Frontend -> DataLayer/ScriptTag chain output_event_scripts()
	 * reads is installed by reflection (TC-8).
	 *
	 * @param array<string, mixed> $stored         Stored option values.
	 * @param bool                 $with_singleton Whether to install the Plugin singleton.
	 * @param string               $home           The `home` option value (drives the secure flag).
	 * @param bool                 $ssl            What is_ssl() should report.
	 * @return UserEventsModule
	 */
	private function make_module( array $stored = array(), bool $with_singleton = true, string $home = 'https://shop.example', bool $ssl = true ): UserEventsModule {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( $home, $stored ) {
				return 'home' === $name ? $home : $stored;
			}
		);
		Functions\when( 'is_ssl' )->justReturn( $ssl );

		$options = new Options( ( new UserEventsModule() )->defaults() );

		if ( $with_singleton ) {
			$frontend = ( new \ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
			$this->set_private( $frontend, 'datalayer', new DataLayer( $options ) );
			$this->set_private( $frontend, 'script_tag', new ScriptTag( $options ) );

			$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
			$this->set_private( $plugin, 'frontend', $frontend );
			( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $plugin );
		}

		$module = new UserEventsModule();
		$this->set_private( $module, 'options', $options );

		return $module;
	}

	/**
	 * Runs output_event_scripts() and returns everything it printed.
	 *
	 * @param UserEventsModule $module The module under test.
	 * @return string
	 */
	private function capture( UserEventsModule $module ): string {
		ob_start();
		$module->output_event_scripts();

		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// output_event_scripts() - the wp_footer <script> sink
	// -----------------------------------------------------------------------

	public function test_login_cookie_emits_the_user_logged_in_event(): void {
		$_COOKIE['gtm4wp_user_logged_in'] = '1';

		$output = $this->capture( $this->make_module( array( GTM4WP_OPTION_EVENTS_USERLOGIN => true ) ) );

		$this->assertStringContainsString( 'gtm4wp.userLoggedIn', $output );
		$this->assertStringContainsString( 'window.dataLayer.push', $output );
		$this->assertStringNotContainsString( 'gtm4wp.userRegistered', $output, 'Only the login event may fire.' );
	}

	public function test_registration_cookie_emits_the_user_registered_event(): void {
		$_COOKIE['gtm4wp_user_registered'] = '1';

		$output = $this->capture( $this->make_module( array( GTM4WP_OPTION_EVENTS_NEWUSERREG => true ) ) );

		$this->assertStringContainsString( 'gtm4wp.userRegistered', $output );
		$this->assertStringNotContainsString( 'gtm4wp.userLoggedIn', $output, 'Only the registration event may fire.' );
	}

	public function test_both_cookies_emit_both_events(): void {
		$_COOKIE['gtm4wp_user_logged_in']  = '1';
		$_COOKIE['gtm4wp_user_registered'] = '1';

		$output = $this->capture(
			$this->make_module(
				array(
					GTM4WP_OPTION_EVENTS_USERLOGIN  => true,
					GTM4WP_OPTION_EVENTS_NEWUSERREG => true,
				)
			)
		);

		$this->assertStringContainsString( 'gtm4wp.userLoggedIn', $output );
		$this->assertStringContainsString( 'gtm4wp.userRegistered', $output );
	}

	public function test_login_event_is_silent_when_the_option_is_off(): void {
		// TC-4's shape for an output method: prove the disabled state too. The
		// cookie is present, so only the option gate can be suppressing this.
		$_COOKIE['gtm4wp_user_logged_in'] = '1';

		$this->assertSame( '', $this->capture( $this->make_module( array( GTM4WP_OPTION_EVENTS_USERLOGIN => false ) ) ) );
	}

	public function test_registration_event_is_silent_when_the_option_is_off(): void {
		$_COOKIE['gtm4wp_user_registered'] = '1';

		$this->assertSame( '', $this->capture( $this->make_module( array( GTM4WP_OPTION_EVENTS_NEWUSERREG => false ) ) ) );
	}

	public function test_no_event_without_the_cookie(): void {
		unset( $_COOKIE['gtm4wp_user_logged_in'], $_COOKIE['gtm4wp_user_registered'] );

		$this->assertSame(
			'',
			$this->capture(
				$this->make_module(
					array(
						GTM4WP_OPTION_EVENTS_USERLOGIN  => true,
						GTM4WP_OPTION_EVENTS_NEWUSERREG => true,
					)
				)
			),
			'With both options on but no cookie set, nothing may be emitted.'
		);
	}

	/**
	 * The cookie value is request-controlled, so the integer validation is what
	 * stands between it and the emitted <script> (TC-5). Every non-integer form
	 * must be rejected outright rather than reaching the block.
	 *
	 * @param string $value The cookie value.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_non_integer_cookie_values' )]
	public function test_non_integer_cookie_value_emits_nothing( string $value ): void {
		$_COOKIE['gtm4wp_user_logged_in'] = $value;

		$output = $this->capture( $this->make_module( array( GTM4WP_OPTION_EVENTS_USERLOGIN => true ) ) );

		$this->assertSame( '', $output, 'A cookie value that is not a truthy integer must emit no script at all.' );
		$this->assertStringNotContainsString( 'userLoggedIn', $output );
	}

	/**
	 * Cookie values that must never produce an event.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_non_integer_cookie_values(): array {
		return array(
			// Break-out characters written as \xNN so no literal appears in the
			// test source and the payload cannot be re-encoded by tooling (TC-2).
			'script break-out' => array( "1\x3C/script\x3E" ),
			'quote and amp'    => array( "\x22\x26" ),
			'plain text'       => array( 'yes' ),
			'zero'             => array( '0' ),
			'empty'            => array( '' ),
			'float'            => array( '1.5' ),
		);
	}

	public function test_emitting_the_event_consumes_the_cookie(): void {
		// The cookie is unset after the push so a second call in the same request
		// (a theme printing wp_footer twice) cannot double-fire the event.
		$_COOKIE['gtm4wp_user_logged_in']  = '1';
		$_COOKIE['gtm4wp_user_registered'] = '1';

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_USERLOGIN  => true,
				GTM4WP_OPTION_EVENTS_NEWUSERREG => true,
			)
		);

		$this->assertNotSame( '', $this->capture( $module ), 'First pass emits.' );

		$this->assertArrayNotHasKey( 'gtm4wp_user_logged_in', $_COOKIE, 'The login cookie must be consumed.' );
		$this->assertArrayNotHasKey( 'gtm4wp_user_registered', $_COOKIE, 'The registration cookie must be consumed.' );
		$this->assertSame( '', $this->capture( $module ), 'A second pass in the same request must be silent.' );
	}

	public function test_output_is_skipped_when_the_frontend_is_unavailable(): void {
		// wp_footer can fire on a request where boot() never built the frontend
		// (an admin-side preview, a bailed boot). The method must return quietly
		// rather than fatal on a null service chain.
		$_COOKIE['gtm4wp_user_logged_in'] = '1';

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_USERLOGIN => true ), false );

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->set_private( $plugin, 'frontend', null );
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $plugin );

		$this->assertSame( '', $this->capture( $module ) );
	}

	public function test_event_script_uses_the_configured_datalayer_name(): void {
		// The block is built around the data layer variable name, so a renamed
		// data layer must be the one the push targets - otherwise the event is
		// pushed onto an array GTM is not reading.
		$_COOKIE['gtm4wp_user_logged_in'] = '1';

		$output = $this->capture(
			$this->make_module(
				array(
					GTM4WP_OPTION_EVENTS_USERLOGIN => true,
					GTM4WP_OPTION_DATALAYER_NAME   => 'myLayer',
				)
			)
		);

		$this->assertStringContainsString( 'window.myLayer.push', $output );
		$this->assertStringNotContainsString( 'window.dataLayer.push', $output );
	}

	public function test_event_script_carries_the_csp_nonce(): void {
		// The block is printed directly rather than through wp_add_inline_script(),
		// so it needs the plugin's own CSP nonce filter to survive a CSP setup.
		$_COOKIE['gtm4wp_user_logged_in'] = '1';

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return GTM4WP_WPFILTER_GET_CSP_NONCE === $tag ? 'nonce-abc123' : $value;
			}
		);

		$output = $this->capture( $this->make_module( array( GTM4WP_OPTION_EVENTS_USERLOGIN => true ) ) );

		$this->assertStringContainsString( 'nonce="nonce-abc123"', $output );
	}

	// -----------------------------------------------------------------------
	// Cookie writers
	// -----------------------------------------------------------------------

	public function test_on_login_sets_a_session_cookie_httponly_and_secure(): void {
		$this->make_module()->on_login();

		$this->assertCount( 1, $this->cookie_writes );
		[ $name, $value, $expires, $path, $domain, $secure, $httponly ] = $this->cookie_writes[0];

		$this->assertSame( 'gtm4wp_user_logged_in', $name );
		$this->assertSame( '1', $value );
		$this->assertSame( 0, $expires, 'A session cookie: it must not outlive the browser session.' );
		$this->assertSame( '/', $path );
		$this->assertSame( '', $domain );
		$this->assertTrue( $secure, 'An https site must get a Secure cookie.' );
		$this->assertTrue( $httponly, 'The flag cookie is never read by script, so it must be HttpOnly.' );
	}

	public function test_on_register_sets_its_own_session_cookie(): void {
		$this->make_module()->on_register();

		$this->assertCount( 1, $this->cookie_writes );
		[ $name, $value, $expires, , , $secure, $httponly ] = $this->cookie_writes[0];

		$this->assertSame( 'gtm4wp_user_registered', $name );
		$this->assertSame( '1', $value );
		$this->assertSame( 0, $expires );
		$this->assertTrue( $secure );
		$this->assertTrue( $httponly );
	}

	public function test_cookies_are_not_marked_secure_on_a_plain_http_site(): void {
		// TS-5: the else side of the secure flag. Both conditions must hold - an
		// https home_url AND is_ssl() - so a Secure cookie is never set on a
		// request the browser would then refuse to send back.
		$this->make_module( array(), true, 'http://shop.example', false )->on_login();

		$this->assertFalse( $this->cookie_writes[0][5], 'A plain http site must not get a Secure cookie.' );
		$this->assertTrue( $this->cookie_writes[0][6], 'HttpOnly still applies.' );
	}

	public function test_cookies_are_not_marked_secure_when_the_request_is_not_ssl(): void {
		// The mixed case: home_url says https but this particular request is not
		// TLS (a misconfigured proxy). is_ssl() is the deciding half.
		$this->make_module( array(), true, 'https://shop.example', false )->on_login();

		$this->assertFalse( $this->cookie_writes[0][5] );
	}

	public function test_clear_event_cookies_expires_both_cookies_when_present(): void {
		$_COOKIE['gtm4wp_user_logged_in']  = '1';
		$_COOKIE['gtm4wp_user_registered'] = '1';

		$this->make_module()->clear_event_cookies();

		$this->assertCount( 2, $this->cookie_writes );

		$names = array_column( $this->cookie_writes, 0 );
		$this->assertSame( array( 'gtm4wp_user_logged_in', 'gtm4wp_user_registered' ), $names );

		foreach ( $this->cookie_writes as $write ) {
			$this->assertSame( '', $write[1], 'An expiring cookie carries no value.' );
			$this->assertLessThan( 0, $write[2], 'The expiry must be in the past so the browser drops the cookie.' );
			$this->assertSame( '/', $write[3], 'The path must match the one on_login() used, or the cookie survives.' );
		}
	}

	public function test_clear_event_cookies_only_touches_the_cookie_that_is_present(): void {
		$_COOKIE['gtm4wp_user_registered'] = '1';
		unset( $_COOKIE['gtm4wp_user_logged_in'] );

		$this->make_module()->clear_event_cookies();

		$this->assertCount( 1, $this->cookie_writes );
		$this->assertSame( 'gtm4wp_user_registered', $this->cookie_writes[0][0] );
	}

	public function test_clear_event_cookies_writes_nothing_without_the_cookies(): void {
		unset( $_COOKIE['gtm4wp_user_logged_in'], $_COOKIE['gtm4wp_user_registered'] );

		$this->make_module()->clear_event_cookies();

		$this->assertSame( array(), $this->cookie_writes, 'No Set-Cookie header may be emitted for an ordinary visitor.' );
	}

	// -----------------------------------------------------------------------
	// enqueue_scripts()
	// -----------------------------------------------------------------------

	public function test_form_move_tracker_is_enqueued_when_enabled(): void {
		$this->make_module( array( GTM4WP_OPTION_EVENTS_FORMMOVE => true ) )->enqueue_scripts();

		$this->assertSame( array( 'gtm4wp-form-move-tracker' ), array_column( $this->enqueued, 'handle' ) );
		$this->assertTrue( $this->enqueued[0]['args']['in_footer'], 'The tracker defaults to the footer.' );
		$this->assertSame( 'defer', $this->enqueued[0]['args']['strategy'] );
	}

	public function test_form_move_tracker_is_not_enqueued_when_disabled(): void {
		$this->make_module( array( GTM4WP_OPTION_EVENTS_FORMMOVE => false ) )->enqueue_scripts();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_filled_only_config_is_printed_alongside_the_tracker(): void {
		// The option crosses the PHP -> JS boundary, so the assertion is on the
		// bytes the browser receives, not on the option value (RI-14). `var` is
		// load-bearing: the tracker reads window.gtm4wp_form_move_config, and a
		// top-level `const` would bind lexically and never reach it.
		$this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_FORMMOVE            => true,
				GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY => true,
			)
		)->enqueue_scripts();

		$this->assertCount( 1, $this->inline_scripts );
		$this->assertSame( 'gtm4wp-form-move-tracker', $this->inline_scripts[0]['handle'] );
		$this->assertSame( 'before', $this->inline_scripts[0]['position'], 'The config must be printed before the bundle that reads it.' );
		$this->assertSame(
			'var gtm4wp_form_move_config = {"filledOnly":true};',
			$this->inline_scripts[0]['code']
		);
	}

	public function test_filled_only_config_reports_the_option_being_off(): void {
		// The off state has to be transmitted too: the tracker defaults to false
		// only when no config reaches it at all.
		$this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_FORMMOVE            => true,
				GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY => false,
			)
		)->enqueue_scripts();

		$this->assertSame(
			'var gtm4wp_form_move_config = {"filledOnly":false};',
			$this->inline_scripts[0]['code']
		);
	}

	public function test_filled_only_value_is_always_a_boolean(): void {
		// A stored value from a hand-edited option row or an older import must not
		// reach the browser as a string or a number: the tracker branches on it
		// directly, and "0" would be truthy there while false is not.
		$this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_FORMMOVE            => true,
				GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY => '0',
			)
		)->enqueue_scripts();

		$this->assertSame(
			'var gtm4wp_form_move_config = {"filledOnly":false};',
			$this->inline_scripts[0]['code'],
			'A falsy stored value must be cast to a real JSON false.'
		);
	}

	public function test_upgrading_from_1x_leaves_the_form_events_unchanged(): void {
		// The upgrade path, asserted rather than assumed. A site coming from 1.x
		// has a gtm4wp-options row that predates the sub-option, so the key is
		// simply absent from storage. Options merges defaults UNDER the stored
		// values (array_merge( $defaults, $stored )), so an absent key keeps its
		// default - and the default has to be the 1.x behavior, or every existing
		// site silently loses leave events on upgrade.
		$stored_by_1x = array(
			GTM4WP_OPTION_EVENTS_FORMMOVE   => true,
			GTM4WP_OPTION_EVENTS_NEWUSERREG => true,
			GTM4WP_OPTION_EVENTS_USERLOGIN  => true,
		);

		$this->assertArrayNotHasKey(
			GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY,
			$stored_by_1x,
			'Guards the premise of this test: 1.x never stored this key.'
		);

		$module = $this->make_module( $stored_by_1x );

		$this->assertFalse(
			( new UserEventsModule() )->defaults()[ GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY ],
			'The sub-option must default to off.'
		);

		$module->enqueue_scripts();

		$this->assertSame( array( 'gtm4wp-form-move-tracker' ), array_column( $this->enqueued, 'handle' ) );
		$this->assertSame(
			'var gtm4wp_form_move_config = {"filledOnly":false};',
			$this->inline_scripts[0]['code'],
			'A 1.x options row must produce the unfiltered tracker.'
		);
	}

	public function test_no_config_is_printed_when_the_form_move_option_is_off(): void {
		// The sub-option is meaningless without its parent, and the tracker that
		// reads the config is not on the page at all in that state.
		$this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_FORMMOVE            => false,
				GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY => true,
			)
		)->enqueue_scripts();

		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->inline_scripts, 'No config may be printed without the tracker.' );
	}

	public function test_form_move_tracker_placement_is_filterable(): void {
		// The public gtm4wp_<option> filter moves the tracker into the head for
		// setups that need it bound before the first paint.
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return 'gtm4wp_' . GTM4WP_OPTION_EVENTS_FORMMOVE === $tag ? false : $value;
			}
		);

		$this->make_module( array( GTM4WP_OPTION_EVENTS_FORMMOVE => true ) )->enqueue_scripts();

		$this->assertCount( 1, $this->enqueued );
		$this->assertFalse( $this->enqueued[0]['args']['in_footer'], 'A false filter result moves the tracker out of the footer.' );
	}
}
