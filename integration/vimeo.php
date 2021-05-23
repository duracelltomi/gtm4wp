<?php
if ( ! is_admin() ) {
	$in_footer = apply_filters( 'gtm4wp_vimeo', true );

	wp_enqueue_script( 'gtm4wp-vimeo-api', 'https://player.vimeo.com/api/player.js', array(), '1.0', $in_footer );
	wp_enqueue_script( 'gtm4wp-vimeo', $gtp4wp_plugin_url . 'js/gtm4wp-vimeo.js', array(), GTM4WP_VERSION, $in_footer );
}
