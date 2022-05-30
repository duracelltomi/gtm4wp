<?php
/**
 * Soundcloud integration related codes.
 * Enqueues the official Soundcloud JS API script and the interaction tracking script of GTM4WP.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

if ( ! is_admin() ) {
	$in_footer = (bool) apply_filters( 'gtm4wp_soundcloud', true );
	wp_enqueue_script( 'gtm4wp-soundcloud-api', 'https://w.soundcloud.com/player/api.js', array(), '1.0', $in_footer );
	wp_enqueue_script( 'gtm4wp-soundcloud', $gtp4wp_plugin_url . 'js/gtm4wp-soundcloud.js', array(), GTM4WP_VERSION, $in_footer );
}
