<?php
/**
 * Vimeo integration related codes.
 * Enabled JS API in YouTube embed codes and loads the interaction tracking script of GTM4WP.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

/**
 * Adds loading of the JS API of the YouTube player into the embed codes.
 *
 * @see https://developer.wordpress.org/reference/hooks/oembed_result/
 *
 * @param string|false $return The returned oEmbed HTML (false if unsafe).
 * @param string       $url URL of the content to be embedded.
 * @param string|array $data Additional arguments for retrieving embed HTML. See wp_oembed_get() for accepted arguments. Default empty.
 * @return string
 */
function gtm4wp_youtube( $return, $url, $data ) {
	$site_url       = site_url();
	$site_url_parts = wp_parse_url( $site_url );

	if ( false !== strpos( $return, 'youtube.com' ) ) {
		return str_replace( 'feature=oembed', 'feature=oembed&enablejsapi=1&origin=' . $site_url_parts['scheme'] . '://' . $site_url_parts['host'], $return );
	} else {
		return $return;
	}
}

add_filter( 'oembed_result', 'gtm4wp_youtube', 10, 3 );

if ( ! is_admin() ) {
	$in_footer = (bool) apply_filters( 'gtm4wp_youtube', true );
	wp_enqueue_script( 'gtm4wp-youtube', $gtp4wp_plugin_url . 'js/gtm4wp-youtube.js', array(), GTM4WP_VERSION, $in_footer );
}
