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
	 * Memoized result of the gtm4wp_media_sdk_blocked filter.
	 *
	 * The DECISION is made once per request - the filter runs a single time,
	 * so a third-party callback is not consulted once per tracker - while the
	 * flag it produces is PRINTED per tracker handle. See the note in
	 * enqueue_media_tracker() on why a page-wide fact must never ride a single
	 * handle's tag.
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
	 * Handle of the consent gate every SDK-FETCHING media tracker depends on.
	 *
	 * Public because *blocking* it - rewriting or removing its <script src> tag,
	 * which is what a consent manager does through script_loader_tag - is a
	 * documented way for a site to withhold every media provider's SDK request,
	 * so the name is part of the plugin's contract rather than an internal detail.
	 *
	 * Not every tracker: four of them fetch nothing (HTML5 media, Wistia, JW
	 * Player, VideoPress), so they neither enqueue the gate nor depend on it, and
	 * a site running only those never receives it at all - there would be no
	 * vendor request for it to withhold (#143).
	 *
	 * NOT dequeuing: see the note in enqueue_media_tracker(). Each SDK-fetching
	 * tracker declares this handle as a dependency, and WordPress re-adds a
	 * registered dependency to the print queue whether or not it was dequeued, so
	 * wp_dequeue_script() on this handle has no effect at all. The server-side
	 * switch is the gtm4wp_media_sdk_blocked filter.
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
	 * On the first call it also enqueues the consent gate every tracker depends on
	 * (see enqueue_gate()) and publishes the third-party SDK veto, when the site
	 * has set one - see sdk_blocked().
	 *
	 * @param string $handle      Script handle.
	 * @param string $file        File name inside the build directory.
	 * @param array  $deps        Script dependencies.
	 * @param bool   $in_footer   Whether to print the script in the footer.
	 * @param bool   $fetches_sdk Whether this tracker requests a third-party player
	 *                            SDK at runtime. False for the trackers that have
	 *                            nothing to fetch (see enqueue_scripts()); they get
	 *                            no consent gate, because there is no vendor request
	 *                            for one to withhold. Flip this the moment such a
	 *                            tracker gains an SDK.
	 * @return void
	 */
	private function enqueue_media_tracker( string $handle, string $file, array $deps, bool $in_footer, bool $fetches_sdk = true ): void {
		// The gate only has a job where a vendor request exists to refuse. Four of
		// the twelve trackers fetch nothing at all, so on a site running only those
		// - self-hosted HTML5 video is the ordinary case - the gate would be an
		// extra <script src> on every page that gates nothing, and "block
		// gtm4wp-media-gate to stop every SDK request" would read as a lever that
		// works while doing nothing at all (#143).
		if ( $fetches_sdk ) {
			$this->enqueue_gate( $in_footer );
		}

		// Declared as a dependency of every SDK-fetching tracker, not merely
		// enqueued beside them: WordPress then guarantees the gate is printed
		// first, so a tracker can never read the flag before the gate has had the
		// chance to set it. That ordering is load-bearing rather than defensive -
		// the bundles are enqueued with strategy 'defer', so at the moment a
		// tracker runs document.readyState is already 'interactive' and
		// gtm4wpOnReady() calls the init synchronously, reading the flag right
		// then.
		//
		// The 'defer' half of that was verified against core rather than assumed
		// (PA-16: name the WordPress function that carries the claim and read it).
		// WP_Scripts::filter_eligible_strategies() demotes a handle to blocking for
		// an inline script attached in the 'after' position only - "Handles with
		// inline scripts attached in the 'after' position cannot be delayed",
		// because an 'after' inline would run before the delayed script. Every
		// inline script this module attaches uses 'before', so the trackers keep
		// their deferred strategy and the reasoning above stands. Re-check this if
		// any of them ever moves to 'after'.
		//
		// The edge has a consequence worth stating where it is created, because
		// it is the opposite of what it looks like: it makes this handle
		// IMMUNE to wp_dequeue_script(). WP_Dependencies::dequeue() only unsets
		// from the queue, while all_deps() appends any still-REGISTERED
		// dependency to $to_do regardless - so a dequeued gate is printed anyway,
		// sets its flag, and every SDK loads. Do not document dequeuing this
		// handle as a lever, and do not reach for wp_deregister_script() instead:
		// a missing dependency makes all_deps() drop every tracker that names it
		// (and emits _doing_it_wrong on WP 6.9.1+). Blocking the tag works;
		// the gtm4wp_media_sdk_blocked filter works.
		if ( $fetches_sdk ) {
			$deps[] = self::GATE_HANDLE;
		}

		$this->enqueue_script( $handle, $file, $deps, $in_footer );

		// The "a gate was printed for this page" flag rides on every TRACKER
		// handle, never on the gate's own. It is what lets a tracker read a gate
		// that did not run as "refused" rather than "absent", so it has to
		// outlive the gate - and on the gate's handle it cannot:
		// WP_Scripts::do_item() builds $tag from the 'before' inline AND the src
		// tag and only then applies script_loader_tag, so both reach a consent
		// manager as ONE string. A blocker that rewrites the src in place leaves
		// the flag standing, but one that replaces or empties the whole string
		// takes the expectation with the gate, and the trackers then fetch as
		// though no gate existed - failing open in the one case the flag exists
		// for.
		//
		// Deliberately per tracker: blocking any single tracker must not take
		// the expectation away from the others. The assignment is idempotent,
		// so repeating it costs a few bytes and nothing else.
		//
		// It tracks the gate EXACTLY, which is why it sits under the same
		// condition: announcing an expectation no gate was enqueued for would make
		// an SDK-fetching tracker loaded some other way read "expected but never
		// ran" as a refusal, failing closed on a page where nothing was refused.
		if ( $fetches_sdk ) {
			wp_add_inline_script( $handle, 'window.gtm4wp_media_gate_expected = true;', 'before' );
		}

		// The same one-string mechanism makes these two page-wide flags
		// per-tracker as well. They used to be printed once, on whichever
		// tracker happened to enqueue first - so a consent manager blocking
		// exactly that tracker's handle (a documented per-provider lever)
		// deleted the flag together with the bundle it was riding on. For the
		// veto that failed OPEN: the site's server-side "no media SDKs at all"
		// decision vanished for every remaining tracker the moment one
		// provider's tag was refused. The observer opt-in is read by every
		// tracker, so it rides them all; the veto is only consulted on the SDK
		// fetch path, so it rides the SDK-fetching handles. The filter behind
		// sdk_blocked() still runs once per request (memoized) - only the
		// printing is repeated.
		if ( $this->opt( GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC ) ) {
			wp_add_inline_script( $handle, 'window.gtm4wp_media_observe_dynamic = true;', 'before' );
		}

		if ( $fetches_sdk && $this->sdk_blocked() ) {
			wp_add_inline_script( $handle, 'window.gtm4wp_media_sdk_blocked = true;', 'before' );
		}
	}

	/**
	 * Enqueues the consent gate once, and tells the trackers to expect it.
	 *
	 * Only reached from a tracker that actually fetches an SDK, so a site running
	 * only the four fetch-nothing trackers never pays for a gate with nothing to
	 * gate (#143). A tracker that gains an SDK must flip its $fetches_sdk argument.
	 *
	 * The gate (js/frontend/gtm4wp-media-gate.js) carries no logic. It exists to
	 * be a real, enqueued `<script src>` that a consent manager can refuse by
	 * rewriting or removing its tag through script_loader_tag - because the SDK
	 * requests themselves are made from JavaScript and therefore pass through no
	 * server-side control at all. Blocking the gate withholds every media SDK
	 * request while leaving the trackers running for the players already on the
	 * page. wp_dequeue_script() is NOT one of the ways to refuse it - see the
	 * note in enqueue_media_tracker() - and the server-side equivalent is the
	 * gtm4wp_media_sdk_blocked filter.
	 *
	 * Note what the gate is NOT. It is served from this site's own domain, so a
	 * consent manager's third-party-domain blocklist will never match it: pulling
	 * this lever takes a rule naming this handle, i.e. deliberate configuration.
	 * Zero-configuration blocking comes from somewhere else entirely and already
	 * works - every SDK-fetching tracker selects on `iframe[src*="<vendor>"]` and
	 * reaches ensureSdk() only once such an embed is found, so a consent manager
	 * that blocks the EMBED by domain (moving src to data-src, or swapping in a
	 * placeholder) leaves nothing for the selector to match and the vendor is
	 * never contacted. That property is the one to protect; this gate is the
	 * lever for a site that wants to act without relying on it.
	 *
	 * The companion inline flag is what makes a blocked gate distinguishable from
	 * no gate at all. It is attached to the gate's own handle with position
	 * 'before', so WordPress prints it in a SEPARATE <script> tag ahead of the
	 * gate's src tag: a consent manager that rewrites the src tag therefore
	 * suppresses the gate while leaving the expectation standing, which is
	 * exactly the state that has to mean "refused". Were the flag inside the gate
	 * file, blocking it would erase the evidence that it was ever expected, and
	 * the trackers would fetch as though no gate existed.
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
	 * Whether the site has vetoed every third-party media SDK request.
	 *
	 * The trackers fetch their provider SDK from JavaScript, only on a page that
	 * actually contains that provider's embed. That is a large privacy and
	 * performance win over enqueuing eight SDKs on every page, and it costs one
	 * thing: no <script src="https://vendor…"> tag is ever served, so the request
	 * never passes through `script_loader_tag` and a consent manager whose rule
	 * names the vendor's domain has nothing to match. This filter is that lever,
	 * given back.
	 *
	 * Per-provider control needs nothing new: each tracker is its own script
	 * handle, so `wp_dequeue_script( 'gtm4wp-vimeo' )` - or a consent manager
	 * blocking that handle - stops Vimeo's SDK request by stopping the bundle
	 * that would make it. This filter is the all-providers switch for the case
	 * where consent has not been given yet and the answer is not per provider.
	 *
	 * Returning true does not disable tracking of players the page already
	 * carries; it only withholds the vendor request, which is what a blocked
	 * <script> tag used to do.
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
			$this->enqueue_media_tracker( 'gtm4wp-html5media', 'gtm4wp-html5media.js', array(), $in_footer, false );
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

			// Nothing to fetch on demand either: Wistia's embed loads its own
			// player runtime and the tracker binds through the global
			// `window._wq` ready queue, so it works whether that runtime is
			// already present or arrives later.
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
