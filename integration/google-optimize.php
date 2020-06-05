<?php
function gtm4wp_go_pagehiding_snippet() {
	global $gtm4wp_options;

	$gtm4wp_goids                = explode( ',', $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS ] );
	$gtm4wp_goid_pagehiding_list = array();
	if ( count( $gtm4wp_goids ) > 0 ) {
		foreach ( $gtm4wp_goids as $gtm4wp_onegoid ) {
			$gtm4wp_goid_pagehiding_list[] = "'" . $gtm4wp_onegoid . "': true";
		}

		$gtm4wp_gotimeout = (int) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZETIMEOUT ];
		if ( 0 == $gtm4wp_gotimeout ) {
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
})(window,document.documentElement,'async-hide','dataLayer'," . $gtm4wp_gotimeout . ",{" . implode( ", ", $gtm4wp_goid_pagehiding_list ) . "});
</script>
<!-- GTM4WP: End of Google Optimize Page Hiding snippet -->";
	}
}

function gtm4wp_go_snippet( $content ) {
	global $gtm4wp_options;

	$gtm4wp_goids     = explode( ',', $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS ] );
	$gtm4wp_goid_list = array();
	if ( count( $gtm4wp_goids ) > 0 ) {
		foreach ( $gtm4wp_goids as $gtm4wp_onegoid ) {
			$gtm4wp_goid_list[] = '
<script src="https://www.googleoptimize.com/optimize.js?id=' . $gtm4wp_onegoid . '" onerror="dataLayer.hide.end && dataLayer.hide.end()"></script>';
		}

		$content .= "
<!-- GTM4WP: Load Google Optimize containers -->
" . implode("", $gtm4wp_goid_list) . "
<!-- GTM4WP: End of Load Google Optimize containers -->";
	}

	return $content;
}

add_action( 'wp_head', 'gtm4wp_go_pagehiding_snippet', 1 );
add_filter( GTM4WP_WPFILTER_AFTER_DATALAYER, 'gtm4wp_go_snippet' );
