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
	 * Snapshot of $GLOBALS['post'] so a test that sets it cannot leak (TS-7).
	 *
	 * @var mixed
	 */
	private $post_backup;

	protected function setUp(): void {
		parent::setUp();

		$this->enqueued    = array();
		$this->post_backup = $GLOBALS['post'] ?? null;

		Functions\when( 'plugins_url' )->alias( static fn ( $path, $plugin ) => 'https://example.com/' . $path );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
				$this->enqueued[] = $handle;
				return true;
			}
		);
		// Default: no YouTube block on the page unless a test says otherwise.
		Functions\when( 'has_block' )->justReturn( false );
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
}
