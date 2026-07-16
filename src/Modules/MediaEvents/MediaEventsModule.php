<?php
/**
 * Media events module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\MediaEvents;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the embedded media player interaction tracking scripts.
 *
 * YouTube, Vimeo, SoundCloud and native HTML5 media are ports of the 1.x
 * integration/*.php trackers. Dailymotion, Mixcloud, Cloudflare Stream, Wistia,
 * JW Player, VideoPress, Spotify and Twitch are 2.0 additions; every tracker
 * pushes the same gtm4wp.media* data layer shape and populates GTM's built-in
 * Video variables via js/frontend/lib/native-video-params.js.
 */
final class MediaEventsModule extends AbstractModule {

	/**
	 * Whether the runtime-observer opt-in flag has been published to the page.
	 *
	 * Published at most once per request (on the first media tracker enqueued),
	 * so every tracker's shared helper reads a single boolean.
	 *
	 * @var bool
	 */
	private bool $dynamic_flag_published = false;

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'media-events';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_EVENTS_YOUTUBE          => false,
			GTM4WP_OPTION_EVENTS_VIMEO            => false,
			GTM4WP_OPTION_EVENTS_SOUNDCLOUD       => false,
			GTM4WP_OPTION_EVENTS_HTML5MEDIA       => false,
			GTM4WP_OPTION_EVENTS_DAILYMOTION      => false,
			GTM4WP_OPTION_EVENTS_MIXCLOUD         => false,
			GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM => false,
			GTM4WP_OPTION_EVENTS_WISTIA           => false,
			GTM4WP_OPTION_EVENTS_JWPLAYER         => false,
			GTM4WP_OPTION_EVENTS_VIDEOPRESS       => false,
			GTM4WP_OPTION_EVENTS_SPOTIFY          => false,
			GTM4WP_OPTION_EVENTS_TWITCH           => false,
			GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC    => false,
		);
	}

	/**
	 * Registers the frontend hooks.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( $this->opt( GTM4WP_OPTION_EVENTS_YOUTUBE ) ) {
			add_filter( 'oembed_result', array( $this, 'enable_youtube_js_api' ), 10, 3 );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}

	/**
	 * Adds loading of the JS API of the YouTube player into the embed codes.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/oembed_result/
	 *
	 * @param string|false $return_value The returned oEmbed HTML (false if unsafe).
	 * @param string       $url URL of the content to be embedded.
	 * @param string|array $data Additional arguments for retrieving embed HTML.
	 * @return string|false
	 */
	public function enable_youtube_js_api( $return_value, $url, $data ) {
		$site_url       = site_url();
		$site_url_parts = wp_parse_url( $site_url );

		if ( is_string( $return_value ) && false !== strpos( $return_value, 'youtube.com' ) ) {
			return str_replace( 'feature=oembed', 'feature=oembed&enablejsapi=1&origin=' . $site_url_parts['scheme'] . '://' . $site_url_parts['host'], $return_value );
		}

		return $return_value;
	}

	/**
	 * Enqueues a built media tracker script and, on the first call, publishes the
	 * runtime-observer opt-in flag.
	 *
	 * The flag is a single boolean read by js/frontend/lib/native-video-params.js
	 * (gtm4wpObserveMedia): when true, every enabled tracker also watches
	 * document.body for players inserted after page load (popups/AJAX). It is off
	 * unless the site enabled GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC, so the shared
	 * MutationObserver is never created on sites that do not need it.
	 *
	 * @param string $handle    Script handle.
	 * @param string $file      File name inside the build directory.
	 * @param array  $deps      Script dependencies.
	 * @param bool   $in_footer Whether to print the script in the footer.
	 * @return void
	 */
	private function enqueue_media_tracker( string $handle, string $file, array $deps, bool $in_footer ): void {
		$this->enqueue_script( $handle, $file, $deps, $in_footer );

		if ( ! $this->dynamic_flag_published && $this->opt( GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC ) ) {
			wp_add_inline_script( $handle, 'window.gtm4wp_media_observe_dynamic = true;', 'before' );
			$this->dynamic_flag_published = true;
		}
	}

	/**
	 * Loads the media tracking scripts based on the enabled options.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( $this->opt( GTM4WP_OPTION_EVENTS_YOUTUBE ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_youtube', true );

			if ( isset( $GLOBALS['post'] ) ) {
				$post_content = (string) $GLOBALS['post']->post_content;

				// Enqueue the YouTube tracker whenever the post can render a
				// YouTube embed. Besides the legacy core-embed/youtube block,
				// this covers the modern core/embed block and classic-editor URL
				// auto-embeds (whose stored content holds only the bare URL,
				// never an <iframe>), plus manually pasted iframes - all of which
				// carry a youtube.com / youtu.be URL. The tracker bails harmlessly
				// if the rendered page contains no YouTube player iframe.
				$has_youtube_embed = (
					has_block( 'core-embed/youtube', $GLOBALS['post'] )
					|| false !== strpos( $post_content, 'youtube.com' )
					|| false !== strpos( $post_content, 'youtu.be' )
				);

				if ( $has_youtube_embed ) {
					$this->enqueue_media_tracker( 'gtm4wp-youtube', 'gtm4wp-youtube.js', array(), $in_footer );
				}
			}
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_VIMEO ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_vimeo', true );

			wp_enqueue_script( 'gtm4wp-vimeo-api', 'https://player.vimeo.com/api/player.js', array(), '1.0', $in_footer );
			// Depend on the Vimeo Player SDK handle so WordPress always prints
			// it before the tracker, guaranteeing the `Vimeo` global exists.
			$this->enqueue_media_tracker( 'gtm4wp-vimeo', 'gtm4wp-vimeo.js', array( 'gtm4wp-vimeo-api' ), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_SOUNDCLOUD ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_soundcloud', true );

			wp_enqueue_script( 'gtm4wp-soundcloud-api', 'https://w.soundcloud.com/player/api.js', array(), '1.0', $in_footer );
			$this->enqueue_media_tracker( 'gtm4wp-soundcloud', 'gtm4wp-soundcloud.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_HTML5MEDIA ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_html5media', true );

			// Vanilla tracker: it binds to <video>/<audio> elements with the
			// native addEventListener API and needs no external dependency.
			$this->enqueue_media_tracker( 'gtm4wp-html5media', 'gtm4wp-html5media.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_DAILYMOTION ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_dailymotion', true );

			// Dailymotion JS SDK: exposes the `DM` global used to wrap each
			// dailymotion.com/dai.ly iframe embedded via WordPress oEmbed.
			wp_enqueue_script( 'gtm4wp-dailymotion-api', 'https://api.dmcdn.net/all.js', array(), '1.0', $in_footer );
			$this->enqueue_media_tracker( 'gtm4wp-dailymotion', 'gtm4wp-dailymotion.js', array( 'gtm4wp-dailymotion-api' ), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_MIXCLOUD ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_mixcloud', true );

			// Mixcloud Widget API: exposes the `Mixcloud` global used to build a
			// PlayerWidget for each mixcloud.com iframe (audio, like SoundCloud).
			wp_enqueue_script( 'gtm4wp-mixcloud-api', 'https://widget.mixcloud.com/media/js/widgetApi.js', array(), '1.0', $in_footer );
			$this->enqueue_media_tracker( 'gtm4wp-mixcloud', 'gtm4wp-mixcloud.js', array( 'gtm4wp-mixcloud-api' ), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_cloudflarestream', true );

			// Cloudflare Stream Player SDK: exposes the `Stream` global used to
			// wrap each cloudflarestream.com/videodelivery.net iframe.
			wp_enqueue_script( 'gtm4wp-cloudflarestream-api', 'https://embed.cloudflarestream.com/embed/sdk.latest.js', array(), '1.0', $in_footer );
			$this->enqueue_media_tracker( 'gtm4wp-cloudflarestream', 'gtm4wp-cloudflarestream.js', array( 'gtm4wp-cloudflarestream-api' ), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_WISTIA ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_wistia', true );

			// No SDK is enqueued: Wistia's embed loads its own player runtime and
			// the tracker binds through the global `window._wq` ready queue, so it
			// works whether the embed script is already present or loads later.
			$this->enqueue_media_tracker( 'gtm4wp-wistia', 'gtm4wp-wistia.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_JWPLAYER ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_jwplayer', true );

			// No SDK is enqueued: the site already loads its own JW Player
			// library; the tracker only hooks the existing `jwplayer` global.
			$this->enqueue_media_tracker( 'gtm4wp-jwplayer', 'gtm4wp-jwplayer.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_VIDEOPRESS ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_videopress', true );

			// No SDK is enqueued: VideoPress uses a postMessage API, so the
			// tracker listens for messages from the player iframes directly.
			$this->enqueue_media_tracker( 'gtm4wp-videopress', 'gtm4wp-videopress.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_SPOTIFY ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_spotify', true );

			// Spotify iFrame API: calls the global `onSpotifyIframeApiReady`
			// callback with the IFrameAPI used to control each open.spotify.com
			// embed. The tracker defines that callback, so it must run before the
			// SDK: the SDK depends on the tracker handle and is loaded with the
			// same defer strategy so both execute in dependency order.
			$this->enqueue_media_tracker( 'gtm4wp-spotify', 'gtm4wp-spotify.js', array(), $in_footer );
			wp_enqueue_script(
				'gtm4wp-spotify-api',
				'https://open.spotify.com/embed/iframe-api/v1',
				array( 'gtm4wp-spotify' ),
				'1.0',
				array(
					'in_footer' => $in_footer,
					'strategy'  => 'defer',
				)
			);
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_TWITCH ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_twitch', true );

			// Twitch Embed API: exposes the `Twitch` global. The tracker upgrades
			// each Twitch embed container into a Twitch.Embed so it can subscribe
			// to player events (a plain iframe cannot be wrapped after the fact).
			wp_enqueue_script( 'gtm4wp-twitch-api', 'https://embed.twitch.tv/embed/v1.js', array(), '1.0', $in_footer );
			$this->enqueue_media_tracker( 'gtm4wp-twitch', 'gtm4wp-twitch.js', array( 'gtm4wp-twitch-api' ), $in_footer );
		}
	}
}
