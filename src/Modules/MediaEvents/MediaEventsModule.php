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

use GTM4WP\Frontend\ScriptTag;
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
	 * Memoized result of the gtm4wp_media_sdk_blocked filter: decided once per
	 * request, printed per tracker handle (see enqueue_media_tracker()).
	 *
	 * @var bool|null
	 */
	private ?bool $sdk_blocked = null;

	/**
	 * Whether the consent gate has been enqueued for this request.
	 *
	 * @var bool
	 */
	private bool $gate_enqueued = false;

	/**
	 * Handle of the consent gate every SDK-FETCHING tracker depends on. Public
	 * because BLOCKING its tag (what a consent manager does via
	 * script_loader_tag) is a documented lever, so the name is a contract. The
	 * four trackers that fetch nothing (HTML5, Wistia, JW Player, VideoPress)
	 * neither enqueue nor depend on it (#143). Dequeuing it does nothing:
	 * WordPress re-adds a registered dependency to the print queue; the
	 * server-side switch is the gtm4wp_media_sdk_blocked filter.
	 */
	public const GATE_HANDLE = 'gtm4wp-media-gate';

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

		// No usable origin: leave the embed as the oEmbed handler returned it.
		if ( '' === $scheme || '' === $host ) {
			return $return_value;
		}

		// esc_url() AT the point of injection (RI-17): the splice runs after the
		// oEmbed handler's escaping finished. Separators stay raw & (1.x bytes).
		$origin = esc_url( $scheme . '://' . $host );

		// esc_url() returns '' for a scheme a plugin narrowed out of
		// wp_allowed_protocols(); the gate above cannot see that.
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
	 * Enqueues a built media tracker script together with the inline flags it
	 * reads: the runtime-observer opt-in (GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC, read
	 * by gtm4wpObserveMedia in native-video-params.js), and for an SDK-fetching
	 * tracker the consent gate dependency, the gate-expected flag and the SDK veto.
	 *
	 * @param string $handle      Script handle.
	 * @param string $file        File name inside the build directory.
	 * @param array  $deps        Script dependencies.
	 * @param bool   $in_footer   Whether to print the script in the footer.
	 * @param bool   $fetches_sdk Whether this tracker requests a third-party SDK at
	 *                            runtime. False for the fetch-nothing trackers, which
	 *                            get no gate (#143); flip it the moment one gains an SDK.
	 * @return void
	 */
	private function enqueue_media_tracker( string $handle, string $file, array $deps, bool $in_footer, bool $fetches_sdk = true ): void {
		// The gate only has a job where a vendor request exists to refuse (#143).
		if ( $fetches_sdk ) {
			$this->enqueue_gate( $in_footer );
		}

		// A DEPENDENCY, not merely enqueued beside: WordPress then prints the gate
		// first, which is load-bearing because the deferred tracker reads the flag
		// synchronously in gtm4wpOnReady(). The trackers stay deferred only while
		// every inline script here uses 'before' (an 'after' inline demotes the
		// handle to blocking, WP_Scripts::filter_eligible_strategies(), PA-16).
		// The edge also makes the gate IMMUNE to wp_dequeue_script() (all_deps()
		// re-adds a registered dependency); never document dequeuing as a lever,
		// and never wp_deregister_script() it (a missing dependency drops every
		// tracker naming it). Blocking the tag and the filter both work.
		if ( $fetches_sdk ) {
			$deps[] = self::GATE_HANDLE;
		}

		$this->enqueue_script( $handle, $file, $deps, $in_footer );

		// The gate-expected flag rides every TRACKER handle, never the gate's own:
		// WP_Scripts::do_item() hands the 'before' inline and the src tag to
		// script_loader_tag as ONE string, so a blocker that empties the gate's
		// tag would take the expectation with it and the trackers would fail
		// open. Per tracker so blocking one tracker cannot remove it for the
		// others; under the same condition as the gate so a tracker loaded some
		// other way never reads "expected but never ran" as a refusal.
		if ( $fetches_sdk ) {
			wp_add_inline_script( $handle, 'window.gtm4wp_media_gate_expected = true;', 'before' );
		}

		// Same one-string mechanism: both page-wide flags ride every tracker they
		// apply to, or blocking the first-enqueued tracker's tag deleted them (the
		// veto failed OPEN). The filter itself still runs once (memoized).
		if ( $this->opt( GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC ) ) {
			wp_add_inline_script( $handle, 'window.gtm4wp_media_observe_dynamic = true;', 'before' );
		}

		if ( $fetches_sdk && $this->sdk_blocked() ) {
			wp_add_inline_script( $handle, 'window.gtm4wp_media_sdk_blocked = true;', 'before' );
		}
	}

	/**
	 * Enqueues the consent gate once (js/frontend/gtm4wp-media-gate.js, whose
	 * header documents the levers). It carries no logic: it exists to be a real
	 * `<script src>` a consent manager can refuse via script_loader_tag, since
	 * the SDK requests themselves are made from JavaScript and pass no
	 * server-side control. It is first-party, so a third-party-domain blocklist
	 * never matches it; the zero-configuration protection is that every tracker
	 * selects on `iframe[src*="<vendor>"]` and fetches only once such an embed
	 * exists - protect that property, never widen a selector to match a
	 * consent-blocked embed.
	 *
	 * @param bool $in_footer Whether to print the script in the footer.
	 * @return void
	 */
	private function enqueue_gate( bool $in_footer ): void {
		if ( $this->gate_enqueued ) {
			return;
		}

		$this->gate_enqueued = true;

		$this->enqueue_script( self::GATE_HANDLE, 'gtm4wp-media-gate.js', array(), $in_footer );
	}

	/**
	 * Whether the site has vetoed every third-party media SDK request: the
	 * server-side, all-providers lever (per provider, dequeue or block that
	 * tracker's own handle). Returning true withholds the vendor request only;
	 * players already on the page are still tracked.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	private function sdk_blocked(): bool {
		if ( null === $this->sdk_blocked ) {
			/**
			 * Filters whether GTM4WP may request a third-party media player SDK.
			 *
			 * @since 2.0.0
			 *
			 * @param bool $blocked Whether to withhold every media SDK request. Default false.
			 */
			$this->sdk_blocked = (bool) apply_filters( 'gtm4wp_media_sdk_blocked', false );
		}

		return $this->sdk_blocked;
	}

	/**
	 * Loads the media tracking scripts based on the enabled options. Only the
	 * tracker bundles are enqueued; each hands its SDK URL to gtm4wpObserveMedia()
	 * (native-video-params.js), which fetches it only after finding a matching
	 * embed in the DOM. Do NOT enqueue the SDKs from PHP: that requested ~288 KB
	 * and handed the visitor's IP to seven vendors on every page, and PHP cannot
	 * decide at wp_enqueue_scripts what the rendered DOM will contain (widgets,
	 * blocks, builders, embeds inserted after load).
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
			$this->enqueue_media_tracker( 'gtm4wp-html5media', 'gtm4wp-html5media.js', array(), $in_footer, false );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_DAILYMOTION ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_dailymotion', true );

			$this->enqueue_media_tracker( 'gtm4wp-dailymotion', 'gtm4wp-dailymotion.js', array(), $in_footer );

			// Dailymotion's library URL depends on the configured Player ID, so it
			// is built here and handed to the tracker; the id never reaches JS.
			// rawurlencode() AT the point of injection (RI-17), not a format regex
			// (which would encode Dailymotion's current id grammar and reject the
			// next one, UC-5): the value can only land in ONE path segment, so a
			// stored "../../evil" is a 404 on geo.dailymotion.com, never another URL.
			$player_id = trim( (string) $this->opt( GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID ) );

			$config = array(
				'sdk' => ( '' === $player_id )
					? 'https://geo.dailymotion.com/libs/player.js'
					: 'https://geo.dailymotion.com/libs/player/' . rawurlencode( $player_id ) . '.js',
			);

			wp_add_inline_script(
				'gtm4wp-dailymotion',
				'var gtm4wp_dailymotion_config = ' . ScriptTag::json_literal( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
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

			// Nothing to fetch: Wistia's embed loads its own runtime and the
			// tracker binds through the `window._wq` ready queue.
			$this->enqueue_media_tracker( 'gtm4wp-wistia', 'gtm4wp-wistia.js', array(), $in_footer, false );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_JWPLAYER ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_jwplayer', true );

			// Nothing to fetch: the site already loads its own JW Player
			// library; the tracker only hooks the existing `jwplayer` global.
			$this->enqueue_media_tracker( 'gtm4wp-jwplayer', 'gtm4wp-jwplayer.js', array(), $in_footer, false );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_VIDEOPRESS ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_videopress', true );

			// Nothing to fetch: VideoPress uses a postMessage API, so the
			// tracker listens for messages from the player iframes directly.
			$this->enqueue_media_tracker( 'gtm4wp-videopress', 'gtm4wp-videopress.js', array(), $in_footer, false );
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
