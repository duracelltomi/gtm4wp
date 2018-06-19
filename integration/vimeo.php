<?php
/*
Altering query parameters no longer needed with new Vimeo JS API

function gtm4wp_vimeo( $return, $url, $data ) {
  if ( false !== strpos( $return, "vimeo.com" ) ) {
	  if ( false === strpos( $return, ' id="' ) ) {
			$_urlparts = explode( "/", $url );
			$_playerid = "vimeoplayer_" . $_urlparts[ count( $_urlparts )-1 ];
			
		  $return = str_replace( '<iframe ', '<iframe id="' . $_playerid . '" ', $return);
	  	$return = str_replace( $url, $url . "?api=1&origin=" . site_url() . "&player_id=" . $_playerid, $return);
	  } else {
		  $return = str_replace( $url, $url . "?api=1&origin=" . site_url(), $return);
		}
	}

  return $return;
}

add_filter( "oembed_result", "gtm4wp_vimeo", 10, 3 );
*/
if ( ! is_admin() ) {
	$in_footer = apply_filters( 'gtm4wp_vimeo', false);
//	wp_enqueue_script( "gtm4wp-vimeo-froogaloop", $gtp4wp_plugin_url . "js/froogaloop.js", array(), "2.0", $in_footer );
	wp_enqueue_script( "gtm4wp-vimeo-api", "https://player.vimeo.com/api/player.js", array(), "1.0", $in_footer );
	wp_enqueue_script( "gtm4wp-vimeo", $gtp4wp_plugin_url . "js/gtm4wp-vimeo.js", array( "jquery" ), GTM4WP_VERSION, $in_footer );
}
