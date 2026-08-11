<?php
/**
 * Media events module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\MediaEvents;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the media events module, ported from the 1.x Events tab.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * Documentation hub of this module on gtm4wp.com. Every player has a page of
	 * its own below it, so this is the only module where the per-option link
	 * points somewhere genuinely different for each field.
	 */
	private const DOC_BASE = 'track-embedded-media-players-in-google-tag-manager';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_BASE;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Media events', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Fire tags in Google Tag Manager when visitors interact with embedded media players on your website.', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'players'  => __( 'Media players', 'duracelltomi-google-tag-manager' ),
			'advanced' => __( 'Advanced', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		return array(
			new Field(
				key: GTM4WP_OPTION_EVENTS_YOUTUBE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'YouTube video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a YouTube video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables (Video Status, Video URL, Video Title, Video Provider, Video Duration, Video Current Time, Video Percent). Deprecated: Google Tag Manager now ships its own native YouTube Video trigger, which is the recommended way to measure YouTube playback going forward.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_DEPRECATED,
				doc: self::DOC_BASE . '/youtube-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_VIMEO,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Vimeo video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Vimeo video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				doc: self::DOC_BASE . '/vimeo-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_SOUNDCLOUD,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Soundcloud events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Soundcloud media embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				doc: self::DOC_BASE . '/soundcloud-audio-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_HTML5MEDIA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'HTML5 video and audio events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a native HTML5 <video> or <audio> player embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables. Note: the tracker binds to the native media element, so a theme or player library that wraps or replaces it, or a live/streamed source with no fixed duration, can change which events fire and what they report.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/html5-video-and-audio-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_DAILYMOTION,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Dailymotion video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Dailymotion video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables. Note: Dailymotion no longer lets any script listen to a video it did not create itself, so tracking works by rebuilding each embed with Dailymotion\'s current player. The video keeps the size WordPress gave it and plays as before, but the player your visitors see is the one Dailymotion builds - see the player ID setting below. If the player library cannot be loaded, the embed is left exactly as it was and simply goes untracked.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/dailymotion-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_DAILYMOTION_PLAYERID,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Dailymotion player ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Optional. The ID of the player configuration to load, found in the Players tab of your Dailymotion Studio account. Leave it empty to use Dailymotion\'s default player, which needs no account. Filling it in is also what removes Dailymotion\'s "initialized without a player id" console warning. If an embed code already names a player of its own, that one is used for that embed regardless of this setting.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				depends_on: GTM4WP_OPTION_EVENTS_DAILYMOTION,
				doc: self::DOC_BASE . '/dailymotion-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_MIXCLOUD,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Mixcloud events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Mixcloud show embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/mixcloud-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Cloudflare Stream video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Cloudflare Stream video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/cloudflare-stream-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_WISTIA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Wistia video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Wistia video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/wistia-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_JWPLAYER,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'JW Player video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a JW Player video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/jw-player-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_VIDEOPRESS,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'VideoPress video events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a VideoPress (Jetpack/WordPress.com) video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/videopress-video-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_SPOTIFY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Spotify events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Spotify track, episode or playlist embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables. Note: the Spotify embed only reports periodic playback updates, so play, pause and finished states are derived from those updates, and it reports no title at all - the title is read from the embed on the page, and only when the embed carries none is it looked up once from Spotify\'s public oEmbed endpoint.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/spotify-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_TWITCH,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Twitch events', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Twitch stream or video embedded on your site. Each event also populates Google Tag Manager\'s built-in Video variables. Note: current time and duration are only available for videos (VODs), not live streams.', 'duracelltomi-google-tag-manager' ),
				group: 'players',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/twitch-tracking'
			),
			new Field(
				key: GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Track dynamically inserted players', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'By default the media trackers on the Media players tab only wire players that are present when the page loads. Check this option to also track players inserted after page load — e.g. opened in a popup/lightbox or loaded via AJAX — by watching the page for new embeds. This adds a single shared MutationObserver on the page, which has a small per-change cost, so leave it off unless your site injects media players at runtime.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_BASE . '/track-dynamically-inserted-media-players'
			),
		);
	}

	/**
	 * The media events module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
