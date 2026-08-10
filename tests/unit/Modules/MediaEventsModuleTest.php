<?php
/**
 * Unit tests for the MediaEvents module PHP surface.
 *
 * Covers enqueue_scripts() — the conditional loading of the 12 tracker bundles,
 * and the rule that PHP enqueues NO provider SDK: each tracker fetches its own
 * from the DOM scan, so an enabled provider costs nothing on a page without one
 * of its embeds. Also covers enable_youtube_js_api(), the oEmbed HTML rewrite
 * that turns on the YouTube JS API. The JS trackers themselves are tested under
 * js/frontend/test/.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\MediaEvents\MediaEventsModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * The module must load a tracker bundle when — and only when — its option is
 * enabled, and must never enqueue a third-party player SDK.
 */
final class MediaEventsModuleTest extends TestCase {

	/**
	 * Every media option, mapped to the bundle handle it enqueues, in the order
	 * enqueue_scripts() emits them.
	 *
	 * @return array<string, string>
	 */
	private static function all_trackers(): array {
		return array(
			GTM4WP_OPTION_EVENTS_YOUTUBE          => 'gtm4wp-youtube',
			GTM4WP_OPTION_EVENTS_VIMEO            => 'gtm4wp-vimeo',
			GTM4WP_OPTION_EVENTS_SOUNDCLOUD       => 'gtm4wp-soundcloud',
			GTM4WP_OPTION_EVENTS_HTML5MEDIA       => 'gtm4wp-html5media',
			GTM4WP_OPTION_EVENTS_DAILYMOTION      => 'gtm4wp-dailymotion',
			GTM4WP_OPTION_EVENTS_MIXCLOUD         => 'gtm4wp-mixcloud',
			GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM => 'gtm4wp-cloudflarestream',
			GTM4WP_OPTION_EVENTS_WISTIA           => 'gtm4wp-wistia',
			GTM4WP_OPTION_EVENTS_JWPLAYER         => 'gtm4wp-jwplayer',
			GTM4WP_OPTION_EVENTS_VIDEOPRESS       => 'gtm4wp-videopress',
			GTM4WP_OPTION_EVENTS_SPOTIFY          => 'gtm4wp-spotify',
			GTM4WP_OPTION_EVENTS_TWITCH           => 'gtm4wp-twitch',
		);
	}

	/**
	 * The provider SDK handles PHP used to enqueue on every front-end page.
	 *
	 * The Dailymotion entry now also stands for geo.dailymotion.com: PHP builds
	 * that library's URL (it carries the configured player ID) but publishes it as
	 * data for the tracker to fetch — it must never become an enqueue again.
	 *
	 * @var string[]
	 */
	private const VENDOR_SDK_HANDLES = array(
		'gtm4wp-vimeo-api',
		'gtm4wp-soundcloud-api',
		'gtm4wp-dailymotion-api',
		'gtm4wp-mixcloud-api',
		'gtm4wp-cloudflarestream-api',
		'gtm4wp-spotify-api',
		'gtm4wp-twitch-api',
	);

	/**
	 * Handles passed to wp_enqueue_script() during a test.
	 *
	 * @var string[]
	 */
	private array $enqueued = array();

	/**
	 * Handle => src for every wp_enqueue_script() call, so a test can assert on
	 * the URL and not only on the handle: a vendor SDK re-added under any name
	 * is still a third-party request.
	 *
	 * @var array<string, string>
	 */
	private array $enqueued_src = array();

	/**
	 * Inline scripts captured from wp_add_inline_script(): each entry is
	 * array( handle, code, position ).
	 *
	 * @var array<int, array{0:string,1:string,2:string}>
	 */
	private array $inline_scripts = array();

	/**
	 * Snapshot of $GLOBALS['post'] so a test that sets it cannot leak (TS-7).
	 *
	 * @var mixed
	 */
	private $post_backup;

	protected function setUp(): void {
		parent::setUp();

		$this->enqueued       = array();
		$this->enqueued_src   = array();
		$this->inline_scripts = array();
		$this->post_backup    = $GLOBALS['post'] ?? null;

		Functions\when( 'plugins_url' )->alias( static fn ( $path, $plugin ) => 'https://example.com/' . $path );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
				$this->enqueued[]              = $handle;
				$this->enqueued_src[ $handle ] = (string) $src;
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_scripts[] = array( $handle, $code, $position );
				return true;
			}
		);
		// Stubbed HERE rather than relied on from whichever test file ran first:
		// Brain Monkey defines a mocked function process-wide and permanently, so a
		// borrowed stub makes these cases pass for a reason that vanishes on a
		// reorder (TS-16). The real flags are passed straight through, because the
		// hex escaping is the subject of two cases below.
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		// Pass-through by default, because most cases here are about which bundle
		// loads, not about escaping. The one case where the escaping IS the subject
		// overrides this with a stub that models the real allow-list - a pass-through
		// there would make the assertion vacuous (TS-1).
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		if ( null === $this->post_backup ) {
			unset( $GLOBALS['post'] );
		} else {
			$GLOBALS['post'] = $this->post_backup;
		}

		parent::tearDown();
	}

	/**
	 * Boots a MediaEvents module with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 */
	private function make_module( array $stored ): MediaEventsModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module = new MediaEventsModule();
		$module->frontend( new Options( $module->defaults() ) );

		return $module;
	}

	/**
	 * Installs a $GLOBALS['post'] with the given content.
	 *
	 * @param string $content The post_content.
	 */
	private function set_post_content( string $content ): void {
		$GLOBALS['post'] = (object) array( 'post_content' => $content );
	}

	/**
	 * With every provider on, the enqueued set must be EXACTLY the consent gate
	 * plus the 12 own bundles. Asserted as an exact list rather than 13
	 * assertContains calls so that re-adding a vendor SDK fails here even if it is
	 * given a handle this test never heard of — the named-handle check below
	 * cannot do that. The gate leads because every tracker declares it as a
	 * dependency, so it has to be enqueued before the first one.
	 */
	public function test_every_enabled_tracker_is_enqueued_and_nothing_else(): void {
		$module = $this->make_module( array_fill_keys( array_keys( self::all_trackers() ), true ) );
		$module->enqueue_scripts();

		$this->assertSame(
			array_merge( array( MediaEventsModule::GATE_HANDLE ), array_values( self::all_trackers() ) ),
			$this->enqueued
		);

		foreach ( self::VENDOR_SDK_HANDLES as $sdk_handle ) {
			$this->assertNotContains(
				$sdk_handle,
				$this->enqueued,
				$sdk_handle . ' must be fetched by the tracker on demand, never enqueued for every page.'
			);
		}
	}

	/**
	 * The handle list above proves which names were used; this proves where the
	 * bytes come from. Every enqueued src must be one of our own build files —
	 * a vendor URL re-added under any handle at all is a third-party request on
	 * every front-end page, which is the whole defect being guarded here.
	 */
	public function test_no_enqueued_script_points_at_a_third_party_host(): void {
		$module = $this->make_module( array_fill_keys( array_keys( self::all_trackers() ), true ) );
		$module->enqueue_scripts();

		$this->assertNotEmpty( $this->enqueued_src, 'Nothing was enqueued, so the loop below would assert nothing.' );

		foreach ( $this->enqueued_src as $handle => $src ) {
			// plugins_url() is stubbed to https://example.com/<path> in setUp, so
			// anything else came from a hardcoded vendor URL.
			$this->assertStringStartsWith(
				'https://example.com/',
				$src,
				$handle . ' points outside the plugin: ' . $src
			);
		}
	}

	/**
	 * The YouTube tracker used to load only when $GLOBALS['post']->post_content
	 * mentioned youtube.com / youtu.be or carried the legacy block. That silently
	 * dropped tracking for every YouTube embed living anywhere else — a widget, a
	 * block template, page-builder meta, a shortcode's output, a reusable block —
	 * and on an archive it inspected only the first post of the loop. The tracker
	 * now loads whenever the option is on and lets its own DOM scan decide, which
	 * is the only check that sees the rendered page.
	 */
	public function test_youtube_tracker_is_enqueued_regardless_of_post_content(): void {
		$this->set_post_content( '<p>Just some text, no embeds at all.</p>' );

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-youtube', $this->enqueued );
	}

	/**
	 * TC-14: the branch used to read $GLOBALS['post'] behind an isset() guard, so
	 * a request where the global is absent (a 404, a REST-rendered view, a
	 * template with no main query post) took the silent no-enqueue path. Nothing
	 * may read that global now — asserted with warnings promoted to failures so an
	 * unguarded read cannot pass quietly.
	 */
	public function test_youtube_tracker_is_enqueued_when_there_is_no_global_post(): void {
		unset( $GLOBALS['post'] );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only: promotes the reported PHP warning to a test failure; restored in finally.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception; the message is reported by PHPUnit, never rendered as HTML.
				throw new \ErrorException( $errstr, 0, $errno );
			},
			E_WARNING | E_NOTICE
		);

		try {
			$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
			$module->enqueue_scripts();

			$this->assertContains( 'gtm4wp-youtube', $this->enqueued );
		} finally {
			restore_error_handler();
		}
	}

	public function test_youtube_bundle_not_enqueued_when_option_disabled(): void {
		$this->set_post_content( '<iframe src="https://www.youtube.com/embed/abc"></iframe>' );

		// Option off: the whole YouTube branch is skipped regardless of the embed.
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => false ) );
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_enabling_one_provider_loads_only_that_tracker(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_VIMEO => true ) );
		$module->enqueue_scripts();

		$this->assertSame( array( MediaEventsModule::GATE_HANDLE, 'gtm4wp-vimeo' ), $this->enqueued );
	}

	public function test_no_tracker_is_enqueued_when_every_option_is_disabled(): void {
		$module = $this->make_module( array() );
		$module->enqueue_scripts();

		$this->assertSame( array(), $this->enqueued );
	}

	/**
	 * Filters the captured inline scripts down to the ones that publish the
	 * runtime-observer opt-in flag.
	 *
	 * @return array<int, array{0:string,1:string,2:string}>
	 */
	private function flag_inline_scripts(): array {
		return array_values(
			array_filter(
				$this->inline_scripts,
				static fn ( $script ) => false !== strpos( $script[1], 'gtm4wp_media_observe_dynamic' )
			)
		);
	}

	public function test_dynamic_observer_flag_published_once_when_option_enabled(): void {
		// Two trackers enabled plus the opt-in: the flag must be published exactly
		// once (on the first tracker), not once per bundle.
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_VIMEO         => true,
				GTM4WP_OPTION_EVENTS_HTML5MEDIA    => true,
				GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC => true,
			)
		);
		$module->enqueue_scripts();

		$flag_scripts = $this->flag_inline_scripts();
		$this->assertCount( 1, $flag_scripts );

		[ $handle, $code, $position ] = $flag_scripts[0];
		$this->assertSame( 'window.gtm4wp_media_observe_dynamic = true;', $code );
		// Printed before the tracker bundle so the flag is set when it runs.
		$this->assertSame( 'before', $position );
		// Attached to a media tracker handle that was actually enqueued.
		$this->assertContains( $handle, $this->enqueued );
	}

	/**
	 * #125 (option A): the consent gate is enqueued once, before the trackers,
	 * and every tracker declares it as a dependency.
	 *
	 * The dependency is the load-order guarantee - without it a tracker could
	 * read the flag before the gate had set it, which is a race that would show
	 * up as intermittently missing media events rather than as anything
	 * diagnosable.
	 *
	 * @return void
	 */
	public function test_consent_gate_is_enqueued_once_and_every_tracker_depends_on_it(): void {
		$deps   = array();
		$handle = MediaEventsModule::GATE_HANDLE;

		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $script_handle, $src = '', $script_deps = array(), $ver = false, $args = array() ) use ( &$deps ) {
				$this->enqueued[]                     = $script_handle;
				$this->enqueued_src[ $script_handle ] = (string) $src;
				$deps[ $script_handle ]               = $script_deps;
				return true;
			}
		);

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_VIMEO      => true,
				GTM4WP_OPTION_EVENTS_HTML5MEDIA => true,
			)
		);
		$module->enqueue_scripts();

		$this->assertSame(
			1,
			count( array_keys( $this->enqueued, $handle, true ) ),
			'The gate is shared, so it must be enqueued once however many trackers are on.'
		);
		$this->assertSame( $handle, $this->enqueued[0], 'The gate is enqueued before the first tracker.' );

		$this->assertContains( $handle, $deps['gtm4wp-vimeo'] );
		$this->assertContains( $handle, $deps['gtm4wp-html5media'] );

		// Our own build file, never a vendor URL - the gate exists to be blocked,
		// so it must not itself be a third-party request.
		$this->assertStringStartsWith( 'https://example.com/', $this->enqueued_src[ $handle ] );
	}

	/**
	 * #125 (option A): the gate is only enqueued when something needs it.
	 *
	 * @return void
	 */
	public function test_consent_gate_is_not_enqueued_without_a_tracker(): void {
		$module = $this->make_module( array() );
		$module->enqueue_scripts();

		$this->assertSame( array(), $this->enqueued );
	}

	/**
	 * #125 (option A): the "expect a gate" flag rides on the GATE's handle, in a
	 * separate tag printed before it.
	 *
	 * That placement is the whole mechanism. A consent manager rewrites the src
	 * tag and leaves the inline one standing, so the expectation survives while
	 * the gate does not - which is exactly the state that has to read as
	 * "refused". Put the flag inside the gate file and blocking it would erase
	 * the evidence it was ever expected, and the trackers would fetch as though
	 * no gate existed. Fails open, silently, in the one case it exists for.
	 *
	 * @return void
	 */
	public function test_gate_expectation_is_published_before_the_gate_itself(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_VIMEO => true ) );
		$module->enqueue_scripts();

		$expectation = array_values(
			array_filter(
				$this->inline_scripts,
				static fn ( $script ) => false !== strpos( $script[1], 'gtm4wp_media_gate_expected' )
			)
		);

		$this->assertCount( 1, $expectation );

		[ $handle, $code, $position ] = $expectation[0];
		$this->assertSame( MediaEventsModule::GATE_HANDLE, $handle, 'Attached to the gate, not to a tracker.' );
		$this->assertSame( 'window.gtm4wp_media_gate_expected = true;', $code );
		$this->assertSame( 'before', $position );
	}

	/**
	 * Filters the captured inline scripts down to the third-party SDK veto.
	 *
	 * @return array<int, array{0:string,1:string,2:string}>
	 */
	private function sdk_blocked_inline_scripts(): array {
		return array_values(
			array_filter(
				$this->inline_scripts,
				static fn ( $script ) => false !== strpos( $script[1], 'gtm4wp_media_sdk_blocked' )
			)
		);
	}

	/**
	 * #125: the gtm4wp_media_sdk_blocked filter is the lever a site gets back for
	 * the SDK requests that no longer pass through script_loader_tag.
	 *
	 * Published exactly once, before the bundle that reads it, on a handle that was
	 * really enqueued - the same three properties the dynamic-observer flag has.
	 *
	 * @return void
	 */
	public function test_sdk_veto_published_once_when_filtered_on(): void {
		Filters\expectApplied( 'gtm4wp_media_sdk_blocked' )->andReturn( true );

		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_VIMEO      => true,
				GTM4WP_OPTION_EVENTS_HTML5MEDIA => true,
			)
		);
		$module->enqueue_scripts();

		$veto_scripts = $this->sdk_blocked_inline_scripts();
		$this->assertCount( 1, $veto_scripts, 'Published once, not once per bundle.' );

		[ $handle, $code, $position ] = $veto_scripts[0];
		$this->assertSame( 'window.gtm4wp_media_sdk_blocked = true;', $code );
		$this->assertSame( 'before', $position );
		$this->assertContains( $handle, $this->enqueued );
	}

	/**
	 * #125, the other direction: with no filter the page carries nothing at all.
	 *
	 * A veto that shipped as `= false` would read the same to a consent manager
	 * and would still cost every page a line of inline script, so the absence is
	 * the contract worth pinning (RI-13's omit-don't-invent, applied to a flag).
	 *
	 * @return void
	 */
	public function test_sdk_veto_absent_by_default(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_VIMEO => true ) );
		$module->enqueue_scripts();

		$this->assertSame( array(), $this->sdk_blocked_inline_scripts() );
	}

	public function test_dynamic_observer_flag_not_published_when_option_disabled(): void {
		// A tracker is enabled but the opt-in is off: the tracker still loads, yet
		// no observer flag is published, so the shared MutationObserver is never
		// created on the page.
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_VIMEO => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-vimeo', $this->enqueued );
		$this->assertCount( 0, $this->flag_inline_scripts() );
	}

	/**
	 * Filters the captured inline scripts down to the ones publishing the
	 * Dailymotion player library URL.
	 *
	 * @return array<int, array{0:string,1:string,2:string}>
	 */
	private function dailymotion_config_scripts(): array {
		return array_values(
			array_filter(
				$this->inline_scripts,
				static fn ( $script ) => false !== strpos( $script[1], 'gtm4wp_dailymotion_config' )
			)
		);
	}

	/**
	 * Returns the `sdk` URL published for Dailymotion, decoded from the emitted
	 * JSON rather than string-matched, so the assertion is about the value that
	 * reaches JavaScript and not about how it happens to be spelled.
	 */
	private function dailymotion_sdk_url( string $code ): string {
		$json = trim( substr( $code, (int) strpos( $code, '=' ) + 1 ) );
		$json = rtrim( $json, ';' );

		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'The published config is not decodable JSON: ' . $code );

		return (string) ( $decoded['sdk'] ?? '' );
	}

	/**
	 * Dailymotion sunset the attach-to-an-existing-iframe SDK, so the tracker now
	 * builds the player itself from the Player Embeds library. With no player ID
	 * configured that library is the ID-less one, which is what keeps the feature
	 * usable without a Dailymotion Studio account.
	 */
	public function test_dailymotion_publishes_the_idless_player_library_by_default(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_DAILYMOTION => true ) );
		$module->enqueue_scripts();

		$config_scripts = $this->dailymotion_config_scripts();
		$this->assertCount( 1, $config_scripts );

		[ $handle, $code, $position ] = $config_scripts[0];
		$this->assertSame( 'gtm4wp-dailymotion', $handle );
		// Before the bundle, so the URL exists when the tracker reads it.
		$this->assertSame( 'before', $position );
		$this->assertSame( 'https://geo.dailymotion.com/libs/player.js', $this->dailymotion_sdk_url( $code ) );
	}

	public function test_dailymotion_publishes_the_configured_player_library(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_DAILYMOTION          => true,
				GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID => '  xabcd  ',
			)
		);
		$module->enqueue_scripts();

		$config_scripts = $this->dailymotion_config_scripts();
		$this->assertCount( 1, $config_scripts );

		// Trimmed: a pasted ID routinely carries surrounding whitespace, and an
		// untrimmed one would be percent-encoded into a 404 rather than ignored.
		$this->assertSame(
			'https://geo.dailymotion.com/libs/player/xabcd.js',
			$this->dailymotion_sdk_url( $config_scripts[0][1] )
		);
	}

	/**
	 * The stored ID is spliced into a URL PATH, so it is url-encoded at the point
	 * of injection. Both directions (TS-2): the traversal must be gone AND the URL
	 * must still be the library URL it was supposed to be — asserting only the
	 * first would pass for an implementation that dropped the value entirely.
	 */
	public function test_dailymotion_player_id_cannot_escape_its_path_segment(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_DAILYMOTION          => true,
				GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID => '../../evil?x=1#f',
			)
		);
		$module->enqueue_scripts();

		$sdk = $this->dailymotion_sdk_url( $this->dailymotion_config_scripts()[0][1] );

		$this->assertStringStartsWith( 'https://geo.dailymotion.com/libs/player/', $sdk );
		$this->assertStringEndsWith( '.js', $sdk );

		// Everything that could change which URL this is, or which host it names.
		$segment = substr( $sdk, strlen( 'https://geo.dailymotion.com/libs/player/' ) );
		$this->assertStringNotContainsString( '/', $segment );
		$this->assertStringNotContainsString( '?', $segment );
		$this->assertStringNotContainsString( '#', $segment );
	}

	/**
	 * The URL is printed into an inline <script> body, so the hex flags are what
	 * stand between a stored ID and a closed script tag. Both directions again:
	 * the raw break-out must be absent AND the escaped form present, because an
	 * implementation that silently dropped the value would satisfy the first.
	 */
	public function test_dailymotion_player_id_cannot_break_out_of_the_script_block(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_DAILYMOTION          => true,
				GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID => '</script><script>alert(1)</script>',
			)
		);
		$module->enqueue_scripts();

		$code = $this->dailymotion_config_scripts()[0][1];

		$this->assertStringNotContainsString( '</script>', $code );
		$this->assertStringNotContainsString( '<script>', $code );
		// rawurlencode() already turned '<' into %3C; the hex flags are the second
		// layer, asserted on a character they alone can be responsible for.
		$this->assertStringContainsString( '%3C', $code );
	}

	/**
	 * The player ID field depends_on the Dailymotion checkbox, and Field's doc
	 * block is explicit that the admin UI only disables the control — the module
	 * owns ignoring the value. A stored ID from a since-disabled provider must not
	 * put anything on the page.
	 */
	public function test_dailymotion_player_id_is_ignored_when_the_provider_is_off(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_EVENTS_DAILYMOTION          => false,
				GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID => 'xabcd',
			)
		);
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-dailymotion', $this->enqueued );
		$this->assertCount( 0, $this->dailymotion_config_scripts() );
	}

	public function test_enable_youtube_js_api_adds_jsapi_params_to_a_youtube_embed(): void {
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->justReturn(
			array(
				'scheme' => 'https',
				'host'   => 'example.com',
			)
		);

		$module = $this->make_module( array() );

		$html   = '<iframe src="https://www.youtube.com/embed/abc?feature=oembed"></iframe>';
		$result = $module->enable_youtube_js_api( $html, 'https://youtu.be/abc', array() );

		$this->assertStringContainsString( 'enablejsapi=1&origin=https://example.com', $result );
		// The original oEmbed marker is rewritten in place, not left untouched.
		$this->assertStringNotContainsString( 'feature=oembed"', $result );
	}

	public function test_enable_youtube_js_api_passes_non_youtube_html_through_unchanged(): void {
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->justReturn(
			array(
				'scheme' => 'https',
				'host'   => 'example.com',
			)
		);

		$module = $this->make_module( array() );

		$html = '<iframe src="https://player.vimeo.com/video/123"></iframe>';
		$this->assertSame( $html, $module->enable_youtube_js_api( $html, 'https://vimeo.com/123', array() ) );
	}

	public function test_enable_youtube_js_api_passes_a_non_string_value_through_unchanged(): void {
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->justReturn(
			array(
				'scheme' => 'https',
				'host'   => 'example.com',
			)
		);

		$module = $this->make_module( array() );

		// A false (unsafe/blocked) oEmbed result must be returned as-is.
		$this->assertFalse( $module->enable_youtube_js_api( false, 'https://youtu.be/abc', array() ) );
	}

	/**
	 * Finding #112 (RI-17). The origin is spliced into markup the oEmbed handler
	 * has already escaped, so the splice runs AFTER that escaping finished and
	 * whatever it puts back is unescaped by definition. esc_url() at the point of
	 * injection is what keeps the iframe's src attribute intact.
	 *
	 * The site URL is A4-set and a real hostname cannot carry a quote, so this is
	 * hardening rather than a live break-out - which is precisely why the test has
	 * to model the real esc_url()'s character allow-list. Brain Monkey's own stub
	 * only rewrites & and ', so under it the hostile host survives intact and the
	 * assertion would be vacuous while the line still showed as covered (TS-1, the
	 * #92/#106 lesson).
	 */
	public function test_enable_youtube_js_api_escapes_the_injected_origin(): void {
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->justReturn(
			array(
				'scheme' => 'https',
				'host'   => 'example.com"onload=alert(1) x="',
			)
		);
		Functions\when( 'esc_url' )->alias(
			static function ( $url ) {
				// The allow-list is what makes esc_url() an escape rather than a
				// pass-through; everything outside it is dropped.
				return (string) preg_replace(
					'|[^a-z0-9\-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i',
					'',
					(string) $url
				);
			}
		);

		$module = $this->make_module( array() );

		$html   = '<iframe src="https://www.youtube.com/embed/abc?feature=oembed"></iframe>';
		$result = $module->enable_youtube_js_api( $html, 'https://youtu.be/abc', array() );

		$this->assertStringNotContainsString( '"onload', $result, 'No quote may survive into the src attribute.' );
		$this->assertStringNotContainsString( 'alert(1) x=', $result );
		$this->assertStringContainsString( 'origin=https://example.com', $result, 'The usable part of the origin still reaches the embed.' );
	}

	/**
	 * A site URL WordPress cannot resolve into a scheme and a host yields no usable
	 * origin, so the embed is returned exactly as the oEmbed handler produced it -
	 * rather than splicing in a half-built value, or reading array keys that are
	 * not there and raising a warning on every embed (RI-13's omit-don't-invent
	 * rule applied to markup).
	 *
	 * @param mixed $parsed What wp_parse_url() returns for the site URL.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_unusable_site_urls' )]
	public function test_enable_youtube_js_api_leaves_the_embed_alone_without_a_usable_origin( $parsed ): void {
		Functions\when( 'site_url' )->justReturn( 'nonsense' );
		Functions\when( 'wp_parse_url' )->justReturn( $parsed );
		Functions\when( 'esc_url' )->returnArg();

		// Warnings become failures, so an unguarded array read cannot pass silently.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only: promotes the reported PHP warning to a test failure; restored in finally.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception; the message is reported by PHPUnit, never rendered as HTML.
				throw new \ErrorException( $errstr, 0, $errno );
			},
			E_WARNING | E_NOTICE
		);

		try {
			$module = $this->make_module( array() );
			$html   = '<iframe src="https://www.youtube.com/embed/abc?feature=oembed"></iframe>';

			$this->assertSame( $html, $module->enable_youtube_js_api( $html, 'https://youtu.be/abc', array() ) );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Finding #120 (RI-17 read backwards). The scheme/host gate runs BEFORE the
	 * escaper, so it cannot see the escaper's own failure mode: esc_url()
	 * returns '' for a scheme outside wp_allowed_protocols(), and
	 * kses_allowed_protocols lets any plugin narrow that list. Without the
	 * second check the embed gets `origin=` with nothing after it - exactly the
	 * half-built value the first gate exists to prevent. A guard is only a guard
	 * for the steps that come after it.
	 *
	 * @return void
	 */
	public function test_enable_youtube_js_api_leaves_the_embed_alone_when_the_escaper_rejects_the_origin(): void {
		Functions\when( 'site_url' )->justReturn( 'gopher://example.com' );
		Functions\when( 'wp_parse_url' )->justReturn(
			array(
				'scheme' => 'gopher',
				'host'   => 'example.com',
			)
		);
		// Models a narrowed protocol allow-list: esc_url() drops the whole URL
		// rather than returning a partial one.
		Functions\when( 'esc_url' )->justReturn( '' );

		$module = $this->make_module( array() );

		$html = '<iframe src="https://www.youtube.com/embed/abc?feature=oembed"></iframe>';

		$result = $module->enable_youtube_js_api( $html, 'https://youtu.be/abc', array() );

		$this->assertSame( $html, $result, 'An origin the escaper rejected must leave the embed untouched.' );
		$this->assertStringNotContainsString( 'origin=', $result, 'No empty origin parameter may be spliced in.' );
	}

	/**
	 * Site URLs that cannot produce an origin.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_unusable_site_urls(): array {
		return array(
			'wp_parse_url returned false' => array( false ),
			'no scheme'                   => array( array( 'host' => 'example.com' ) ),
			'no host'                     => array( array( 'scheme' => 'https' ) ),
			'empty parts'                 => array( array() ),
		);
	}
}
