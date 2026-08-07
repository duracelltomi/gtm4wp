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
			GTM4WP_OPTION_EVENTS_YOUTUBE              => false,
			GTM4WP_OPTION_EVENTS_VIMEO                => false,
			GTM4WP_OPTION_EVENTS_SOUNDCLOUD           => false,
			GTM4WP_OPTION_EVENTS_HTML5MEDIA           => false,
			GTM4WP_OPTION_EVENTS_DAILYMOTION          => false,
			GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID => '',
			GTM4WP_OPTION_EVENTS_MIXCLOUD             => false,
			GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM     => false,
			GTM4WP_OPTION_EVENTS_WISTIA               => false,
			GTM4WP_OPTION_EVENTS_JWPLAYER             => false,
			GTM4WP_OPTION_EVENTS_VIDEOPRESS           => false,
			GTM4WP_OPTION_EVENTS_SPOTIFY              => false,
			GTM4WP_OPTION_EVENTS_TWITCH               => false,
			GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC        => false,
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
		if ( ! is_string( $return_value ) || false === strpos( $return_value, 'youtube.com' ) ) {
			return $return_value;
		}

		$site_url_parts = wp_parse_url( site_url() );
		$site_url_parts = is_array( $site_url_parts ) ? $site_url_parts : array();

		$scheme = (string) ( $site_url_parts['scheme'] ?? '' );
		$host   = (string) ( $site_url_parts['host'] ?? '' );

		// A site URL WordPress cannot resolve into a scheme and a host cannot
		// produce a usable origin, and the YouTube JS API rejects a malformed one
		// anyway. Leave the embed exactly as the oEmbed handler returned it rather
		// than splicing in a half-built value (and rather than reading array keys
		// that are not there).
		if ( '' === $scheme || '' === $host ) {
			return $return_value;
		}

		// esc_url() AT the point of injection (RI-17). $return_value is markup the
		// oEmbed handler has already escaped, and this splice runs after that
		// escaping finished - so whatever is put back here is unescaped by
		// definition and the earlier escaping cannot defend the attribute. The
		// value is A4-set today (wp_parse_url over site_url()) and a hostname
		// cannot carry a quote, which is why nothing was exploitable; an escape
		// that is only correct because of where its value happens to come from is
		// not an escape.
		//
		// The separators stay as raw & rather than &#038;: browsers parse both
		// identically here, and 1.x emits this byte-for-byte.
		$origin = esc_url( $scheme . '://' . $host );

		// The scheme/host gate above runs BEFORE the escaper, so it cannot see the
		// escaper's own failure mode: esc_url() returns '' for a scheme outside
		// wp_allowed_protocols(), which the kses_allowed_protocols filter lets any
		// plugin narrow. Without this second check that would splice in the same
		// half-built `origin=` the first gate exists to prevent - a guard is only a
		// guard for the steps that come after it (RI-17, read backwards).
		if ( '' === $origin ) {
			return $return_value;
		}

		return str_replace(
			'feature=oembed',
			'feature=oembed&enablejsapi=1&origin=' . $origin,
			$return_value
		);
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
	 * Only the plugin's own tracker bundles are enqueued here. The provider SDKs
	 * deliberately are not: each tracker hands its SDK URL to
	 * gtm4wpObserveMedia() in js/frontend/lib/native-video-params.js, which
	 * fetches it only after finding a matching embed in the DOM.
	 *
	 * Enqueuing them from PHP meant every enabled provider's SDK was requested on
	 * every front-end page - 288 KB across the seven of them, measured 2026-08-07,
	 * of which Mixcloud alone is 190 KB - including pages with no player at all,
	 * which also handed the visitor's IP, User-Agent and Referer to seven third
	 * parties for nothing.
	 *
	 * PHP cannot make this decision. At wp_enqueue_scripts the page has not been
	 * rendered, so widget content, block templates, page-builder output and
	 * shortcodes are all still invisible; the DOM is the only place the answer
	 * exists, and the only place that stays correct for an embed inserted after
	 * load (GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC). That is also why the YouTube
	 * tracker no longer gates on $GLOBALS['post']->post_content: the gate dropped
	 * tracking for every YouTube embed that did not live in the main post body.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( $this->opt( GTM4WP_OPTION_EVENTS_YOUTUBE ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_youtube', true );

			$this->enqueue_media_tracker( 'gtm4wp-youtube', 'gtm4wp-youtube.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_VIMEO ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_vimeo', true );

			$this->enqueue_media_tracker( 'gtm4wp-vimeo', 'gtm4wp-vimeo.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_SOUNDCLOUD ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_soundcloud', true );

			$this->enqueue_media_tracker( 'gtm4wp-soundcloud', 'gtm4wp-soundcloud.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_HTML5MEDIA ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_html5media', true );

			// Vanilla tracker: it binds to <video>/<audio> elements with the
			// native addEventListener API and has no SDK to fetch at all.
			$this->enqueue_media_tracker( 'gtm4wp-html5media', 'gtm4wp-html5media.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_DAILYMOTION ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_dailymotion', true );

			$this->enqueue_media_tracker( 'gtm4wp-dailymotion', 'gtm4wp-dailymotion.js', array(), $in_footer );

			// Dailymotion is the one media tracker whose library URL is not a
			// fixed literal in the JS: a site can name the player configuration
			// its embeds use, and each player has its own generated library. So
			// the URL is built here and handed to the tracker, which passes it
			// straight to gtm4wpObserveMedia(). The Player ID itself never
			// reaches JavaScript and no URL is ever assembled client side.
			//
			// rawurlencode() AT the point of injection (RI-17), not a format
			// regex. The scheme and host are literals, so the configured value
			// can only ever land in ONE path segment, and rawurlencode() cannot
			// emit '/', ':', '?' or '#' - which makes a stored "../../evil" a 404
			// on geo.dailymotion.com rather than a different URL. A validating
			// regex is the wrong tool here: it would encode Dailymotion's CURRENT
			// Player ID grammar as a gate and reject their next one, with the
			// failure presenting as user error rather than a plugin bug (.upstream
			// UC-5). The encoder is the identity function for every id Dailymotion
			// actually issues, so it costs nothing on the legitimate path.
			$player_id = trim( (string) $this->opt( GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID ) );

			$config = array(
				'sdk' => ( '' === $player_id )
					? 'https://geo.dailymotion.com/libs/player.js'
					: 'https://geo.dailymotion.com/libs/player/' . rawurlencode( $player_id ) . '.js',
			);

			wp_add_inline_script(
				'gtm4wp-dailymotion',
				'var gtm4wp_dailymotion_config = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
				'before'
			);
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_MIXCLOUD ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_mixcloud', true );

			$this->enqueue_media_tracker( 'gtm4wp-mixcloud', 'gtm4wp-mixcloud.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_cloudflarestream', true );

			$this->enqueue_media_tracker( 'gtm4wp-cloudflarestream', 'gtm4wp-cloudflarestream.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_WISTIA ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_wistia', true );

			// Nothing to fetch on demand either: Wistia's embed loads its own
			// player runtime and the tracker binds through the global
			// `window._wq` ready queue, so it works whether that runtime is
			// already present or arrives later.
			$this->enqueue_media_tracker( 'gtm4wp-wistia', 'gtm4wp-wistia.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_JWPLAYER ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_jwplayer', true );

			// Nothing to fetch: the site already loads its own JW Player
			// library; the tracker only hooks the existing `jwplayer` global.
			$this->enqueue_media_tracker( 'gtm4wp-jwplayer', 'gtm4wp-jwplayer.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_VIDEOPRESS ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_videopress', true );

			// Nothing to fetch: VideoPress uses a postMessage API, so the
			// tracker listens for messages from the player iframes directly.
			$this->enqueue_media_tracker( 'gtm4wp-videopress', 'gtm4wp-videopress.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_SPOTIFY ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_spotify', true );

			$this->enqueue_media_tracker( 'gtm4wp-spotify', 'gtm4wp-spotify.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_TWITCH ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_twitch', true );

			$this->enqueue_media_tracker( 'gtm4wp-twitch', 'gtm4wp-twitch.js', array(), $in_footer );
		}
	}
}
