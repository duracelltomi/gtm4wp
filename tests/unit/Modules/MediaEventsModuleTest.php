<?php
/**
 * Unit tests for the MediaEvents module PHP surface.
 *
 * Covers enqueue_scripts() — the conditional loading of the 12 tracker bundles,
 * with the YouTube branch's block/iframe-content detection — and
 * enable_youtube_js_api(), the oEmbed HTML rewrite that turns on the YouTube
 * JS API. The JS trackers themselves are tested under js/frontend/test/.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\MediaEvents\MediaEventsModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * The module must load a tracker bundle only when its option is enabled, and
 * the YouTube tracker only when the current post actually embeds a YouTube video.
 */
final class MediaEventsModuleTest extends TestCase {

	/**
	 * Handles passed to wp_enqueue_script() during a test.
	 *
	 * @var string[]
	 */
	private array $enqueued = array();

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
		$this->inline_scripts = array();
		$this->post_backup    = $GLOBALS['post'] ?? null;

		Functions\when( 'plugins_url' )->alias( static fn ( $path, $plugin ) => 'https://example.com/' . $path );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
				$this->enqueued[] = $handle;
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_scripts[] = array( $handle, $code, $position );
				return true;
			}
		);
		// Default: no YouTube block on the page unless a test says otherwise.
		Functions\when( 'has_block' )->justReturn( false );
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

	public function test_youtube_bundle_enqueued_when_post_has_youtube_block(): void {
		Functions\when( 'has_block' )->justReturn( true );
		$this->set_post_content( '' );

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_youtube_bundle_enqueued_when_content_has_a_youtube_iframe(): void {
		$this->set_post_content( '<p>Watch:</p><iframe src="https://www.youtube.com/embed/abc"></iframe>' );

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_youtube_bundle_not_enqueued_without_a_youtube_embed(): void {
		// has_block is false (setUp default); content has neither a youtube iframe.
		$this->set_post_content( '<p>Just some text, no embeds.</p>' );

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_youtube_iframe_alone_without_youtube_host_does_not_enqueue(): void {
		// A non-YouTube embed must not trigger the tracker: detection looks for a
		// youtube.com / youtu.be URL (or the legacy block), which a Vimeo iframe
		// does not contain.
		$this->set_post_content( '<iframe src="https://vimeo.com/embed/123"></iframe>' );

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_youtube_bundle_enqueued_for_a_modern_core_embed_block_url(): void {
		// A modern core/embed YouTube block stores only the bare URL in
		// post_content - no <iframe>, and has_block( 'core-embed/youtube' ) is
		// false (setUp default). The tracker must still enqueue on the
		// youtube.com URL. Before broadening detection this content was missed.
		$this->set_post_content( '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=abc</div></figure><!-- /wp:embed -->' );

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_youtube_bundle_enqueued_for_a_classic_youtu_be_autoembed(): void {
		// Classic-editor URL auto-embed: a bare youtu.be short URL on its own
		// line, with no block and no <iframe> in the stored content.
		$this->set_post_content( "Watch this:\nhttps://youtu.be/abc123\nThanks" );

		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_youtube_bundle_not_enqueued_when_option_disabled(): void {
		Functions\when( 'has_block' )->justReturn( true );
		$this->set_post_content( '<iframe src="https://www.youtube.com/embed/abc"></iframe>' );

		// Option off: the whole YouTube branch is skipped regardless of the embed.
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_YOUTUBE => false ) );
		$module->enqueue_scripts();

		$this->assertNotContains( 'gtm4wp-youtube', $this->enqueued );
	}

	public function test_vimeo_enqueues_both_the_sdk_and_the_tracker_when_enabled(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_VIMEO => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-vimeo-api', $this->enqueued );
		$this->assertContains( 'gtm4wp-vimeo', $this->enqueued );
	}

	public function test_twitch_enqueues_both_the_sdk_and_the_tracker_when_enabled(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_TWITCH => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-twitch-api', $this->enqueued );
		$this->assertContains( 'gtm4wp-twitch', $this->enqueued );
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

	public function test_dynamic_observer_flag_not_published_when_option_disabled(): void {
		// A tracker is enabled but the opt-in is off: the tracker still loads, yet
		// no observer flag is published, so the shared MutationObserver is never
		// created on the page.
		$module = $this->make_module( array( GTM4WP_OPTION_EVENTS_VIMEO => true ) );
		$module->enqueue_scripts();

		$this->assertContains( 'gtm4wp-vimeo', $this->enqueued );
		$this->assertCount( 0, $this->flag_inline_scripts() );
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
