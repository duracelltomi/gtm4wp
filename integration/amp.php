<?php

// Check if we are running
function gtm4wp_amp_running(){
	if (function_exists( 'is_amp_endpoint' ) && is_amp_endpoint()) {
		return true;
	}
	return false;
}

// Generate AMP GTM code
function gtm4wp_amp_gtmcode(){
	global $gtm4wp_options;

	// Checks to make sure we can only run once.
	if($gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_AMPCODE_GTM ]){
		return false;

	}else{
		// Run once
		$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_AMPCODE_GTM ] = true;

		// Documentation for schema: https://developers.google.com/analytics/devguides/collection/amp-analytics/
		$gtm4wp_ampids = explode( ",", $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_AMPID ] );
		$gtm4wp_ampid_list = array();
			
		if ( count( $gtm4wp_ampids ) > 0 ) {

			foreach( $gtm4wp_ampids as $gtm4wp_oneampid ) {

				// Based on examples from https://www.simoahava.com/analytics/accelerated-mobile-pages-via-google-tag-manager/#creating-custom-amp-variables
				echo '<!-- Google Tag Manager --><amp-analytics config="https://www.googletagmanager.com/amp.json?id='.$gtm4wp_oneampid.'&gtm.url=SOURCE_URL" data-credentials="include">'.gtm4wp_amp_gtmvariables().'</amp-analytics>';
			}

		}//Finished GTM Container loading		
	}
}

// Map the gtm4wp variables to AMP compatible variables
function gtm4wp_amp_gtmvariables(){
	global $gtm4wp_options;
	return '{"vars":{'.$gtm4wp_options[GTM4WP_OPTION_INTEGRATE_AMPCODE_DATALAYER]."} }";
}

// Check AMP Analytics has not been included already
// https://github.com/Automattic/amp-wp/blob/develop/includes/amp-post-template-actions.php
function gtm4wo_amp_gtmampcode_check($data){
	if ( ! empty( $data['amp_analytics'] ) && !$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_AMPCODE_AMP] ) {
		$data['amp_component_scripts']['amp-analytics'] = 'https://cdn.ampproject.org/v0/amp-analytics-0.1.js';
		define('GTM4WP_PLACEMENT_AMP_TAG', true);
	}
	return $data;
}

// Some injection for AMP code
add_action( 'amp_post_template_data', 'gtm4wo_amp_gtmampcode_check' );

// Load up the gtm4wp plugin when AMP loads
add_action( 'amp_post_template_head', 'gtm4wp_wp_header_begin');
add_action( 'amp_post_template_head', 'gtm4wp_wp_header_top', 1 );

// Inject the Google Tag Manager to the page
// Try amp_post_template_body (https://github.com/Automattic/amp-wp/pull/1143)
add_action( 'amp_post_template_body', 'gtm4wp_amp_gtmcode');
add_action( 'amp_post_template_footer', 'gtm4wp_amp_gtmcode');
