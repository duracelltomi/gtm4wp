<?php
/*
Altering query parameters no longer needed

function gtm4wp_soundcloud( $return, $url, $data ) {
  if ( false !== strpos( $return, "soundcloud.com" ) ) {
	  if ( false === strpos( $return, ' id="' ) ) {
	    if ( preg_match('/src="([^\"]+?)"/i', $return, $r) ) {
				$_urlquery = parse_url( $r[1], PHP_URL_QUERY );
				if ( false !== $_urlquery ) {
					parse_str( $_urlquery, $_urlparts );

					if ( isset( $_urlparts[ "url" ] ) ) {
						$_urlpartsid = explode( "/", $_urlparts[ "url" ] );
						$_playerid = "soundcloudplayer_" . $_urlpartsid[ count( $_urlpartsid )-1 ];
					  $return = str_replace( '<iframe ', '<iframe id="' . $_playerid . '" ', $return);
					}
				}
			}
		}
	}

  return $return;
}

add_filter( "oembed_result", "gtm4wp_soundcloud", 10, 3 );
*/

if ( ! is_admin() ) {
	$in_footer = apply_filters( 'gtm4wp_soundcloud', false);
	wp_enqueue_script( "gtm4wp-soundcloud-api", "https://w.soundcloud.com/player/api.js", array(), "1.0", $in_footer );
	wp_enqueue_script( "gtm4wp-soundcloud", $gtp4wp_plugin_url . "js/gtm4wp-soundcloud.js", array( "jquery" ), GTM4WP_VERSION, $in_footer );
}
