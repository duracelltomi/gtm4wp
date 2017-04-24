<?php
function gtm4wp_go_pagehiding_snippet() {
	global $gtm4wp_options;
	
	$gtm4wp_goids = explode( ",", $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS ] );
	$gtm4wp_goid_list = array();
	if ( count( $gtm4wp_goids ) > 0 ) {
		foreach( $gtm4wp_goids as $gtm4wp_onegoid ) {
			$gtm4wp_goid_list[] = "'" . $gtm4wp_onegoid . "': true";
		}
		
		$gtm4wp_gotimeout = (int) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZETIMEOUT ];
		if ( 0 == $gtm4wp_gotimeout ) {
			$gtm4wp_gotimeout = 4000;
		}
		
		echo "<style>.google-optimize-hide { opacity: 0 !important} </style>
<script>
(function(a,s,y,n,c,h,i,d,e){s.className+=' '+y;h.start=1*new Date;
h.end=i=function(){s.className=s.className.replace(RegExp(' ?'+y),'')};
(a[n]=a[n]||[]).hide=h;setTimeout(function(){i();h.end=null},c);h.timeout=c;
})
(window,document.documentElement,'google-optimize-hide','dataLayer'," . $gtm4wp_gotimeout . ",{" . implode( ", ", $gtm4wp_goid_list ) . "});
</script>";
	}
}

add_action( "wp_head", "gtm4wp_go_pagehiding_snippet", 1 );
