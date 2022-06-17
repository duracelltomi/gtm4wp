<?php
/**
 * Google Optimize integration related codes.
 * Outputs the Google Optimize code and the anti flicker snippet on the fronend.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

/**
 * WordPress hook to add the anti flicker snippet into the <head> section of the page.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_head/
 *
 * @return void
 */
function gtm4wp_go_pagehiding_snippet() {
	global $gtm4wp_options;

	$gtm4wp_goids                = explode( ',', $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS ] );
	$gtm4wp_goid_pagehiding_list = array();
	if ( count( $gtm4wp_goids ) > 0 ) {
		foreach ( $gtm4wp_goids as $gtm4wp_onegoid ) {
			$gtm4wp_goid_pagehiding_list[ esc_attr( $gtm4wp_onegoid ) ] = true;
		}

		$gtm4wp_gotimeout = (int) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZETIMEOUT ];
		if ( 0 === $gtm4wp_gotimeout ) {
			$gtm4wp_gotimeout = 4000;
		}

		echo "
<!-- GTM4WP: Google Optimize Anti-flicker snippet -->
<style>
  .async-hide {
	  opacity: 0 !important
  }
</style>
<script>
(function(a,s,y,n,c,h,i,d,e) {
	s.className+=' '+y;
	h.start=1*new Date;
	h.end=i=function() {
		s.className=s.className.replace(RegExp(' ?'+y),'')
	};
	(a[n]=a[n]||[]).hide=h;
	setTimeout(function() {
		i();
		h.end=null
	},c);
	h.timeout=c;
})(window,document.documentElement,'async-hide','dataLayer'," . esc_js( $gtm4wp_gotimeout ) . ',' . wp_json_encode( $gtm4wp_goid_pagehiding_list, JSON_FORCE_OBJECT ) . ');
</script>
<!-- GTM4WP: End of Google Optimize Page Hiding snippet -->';
	}
}

/**
 * Add the Google Optimize snippet after the initial dataLayer.push() so that
 * Google Optimize can utilize content of the data layer.
 *
 * @param string $content The original content that can be extended by this hook.
 * @return void
 */
function gtm4wp_go_snippet( $content ) {
	global $gtm4wp_options;

	$gtm4wp_goids     = explode( ',', $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS ] );
	$gtm4wp_goid_list = array();
	if ( count( $gtm4wp_goids ) > 0 ) {
		foreach ( $gtm4wp_goids as $gtm4wp_onegoid ) {
			// phpcs ignore set due to wp_enqueue_script() can not handle custom script element attributes.
			$gtm4wp_goid_list[] = '
<script src="' . esc_url( 'https://www.googleoptimize.com/optimize.js?id=' . $gtm4wp_onegoid ) . '" onerror="dataLayer.hide.end && dataLayer.hide.end()"></script>'; // phpcs:ignore
		}

		$content .= '
<!-- GTM4WP: Load Google Optimize containers -->
' . implode( '', $gtm4wp_goid_list ) . '
<!-- GTM4WP: End of Load Google Optimize containers -->';
	}

	echo wp_kses(
		$content,
		array(
			'script' => array(
				'src'     => array(),
				'onerror' => array(),
			),
		)
	);
}

add_action( 'wp_head', 'gtm4wp_go_pagehiding_snippet', 1 );
add_action( GTM4WP_WPACTION_AFTER_DATALAYER, 'gtm4wp_go_snippet' );
