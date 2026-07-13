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
 * Loads the YouTube, Vimeo and SoundCloud interaction tracking scripts.
 * Port of integration/youtube.php, integration/vimeo.php and
 * integration/soundcloud.php from 1.x.
 */
final class MediaEventsModule extends AbstractModule {

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
			GTM4WP_OPTION_EVENTS_YOUTUBE    => false,
			GTM4WP_OPTION_EVENTS_VIMEO      => false,
			GTM4WP_OPTION_EVENTS_SOUNDCLOUD => false,
			GTM4WP_OPTION_EVENTS_HTML5MEDIA => false,
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
	 * Loads the media tracking scripts based on the enabled options.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( $this->opt( GTM4WP_OPTION_EVENTS_YOUTUBE ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_youtube', true );

			if (
				isset( $GLOBALS['post'] )
				&& (
					has_block( 'core-embed/youtube', $GLOBALS['post'] )
					|| ( strpos( $GLOBALS['post']->post_content, '<iframe' ) !== false && strpos( $GLOBALS['post']->post_content, 'youtu' ) !== false )
				)
			) {
				$this->enqueue_script( 'gtm4wp-youtube', 'gtm4wp-youtube.js', array(), $in_footer );
			}
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_VIMEO ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_vimeo', true );

			wp_enqueue_script( 'gtm4wp-vimeo-api', 'https://player.vimeo.com/api/player.js', array(), '1.0', $in_footer );
			$this->enqueue_script( 'gtm4wp-vimeo', 'gtm4wp-vimeo.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_SOUNDCLOUD ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_soundcloud', true );

			wp_enqueue_script( 'gtm4wp-soundcloud-api', 'https://w.soundcloud.com/player/api.js', array(), '1.0', $in_footer );
			$this->enqueue_script( 'gtm4wp-soundcloud', 'gtm4wp-soundcloud.js', array(), $in_footer );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_HTML5MEDIA ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_html5media', true );

			// The tracker binds to <video>/<audio> elements through jQuery's
			// event system, so it declares a jquery dependency.
			$this->enqueue_script( 'gtm4wp-html5media', 'gtm4wp-html5media.js', array( 'jquery' ), $in_footer );
		}
	}
}
