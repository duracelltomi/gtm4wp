<?php
/**
 * Vimeo integration related codes.
 * Enqueues the official Vimeo JS API script and the interaction tracking script of GTM4WP.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

if ( ! is_admin() ) {
	$in_footer = (bool) apply_filters( 'gtm4wp_vimeo', true );

	wp_enqueue_script( 'gtm4wp-vimeo-api', 'https://player.vimeo.com/api/player.js', array(), '1.0', $in_footer );
	wp_enqueue_script( 'gtm4wp-vimeo', $gtp4wp_plugin_url . 'js/gtm4wp-vimeo.js', array(), GTM4WP_VERSION, $in_footer );
}
