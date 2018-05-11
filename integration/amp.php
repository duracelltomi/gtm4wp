<?php

function gtm4wp_amp_test(){
	global $gtm4wp_datalayer_data;

	echo "<pre>"
	print_r($gtm4wp_datalayer_data);
	echo "</pre>"
	die;


}

add_action( 'amp_post_template_head', 'gtm4wp_amp_test' );
