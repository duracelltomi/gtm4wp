<?php
/**
 * GTM4WP options on the Advanced tab.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

$GLOBALS['gtm4wp_advancedfieldtexts'] = array(
	GTM4WP_OPTION_DATALAYER_NAME   => array(
		'label'       => esc_html__( 'dataLayer variable name', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'In some cases you need to rename the dataLayer variable. You can enter your name here. Leave black for default name: dataLayer', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_ENV_GTM_AUTH     => array(
		'label'       => esc_html__( 'Environment gtm_auth parameter', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enter the gtm_auth parameter of the Google Tag Manager environment that has to be activated on this site. Both gtm_auth and gtm_preview parameters are required to activate the desired environment.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_ENV_GTM_PREVIEW  => array(
		'label'       => esc_html__( 'Environment gtm_preview parameter', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enter the gtm_auth parameter of the Google Tag Manager environment that has to be activated on this site. Both gtm_auth and gtm_preview parameters are required to activate the desired environment.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_DONOTTRACK       => array(
		'label'       => esc_html__( "Include browser 'Do not track' setting", 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Add into the data layer whether the user has asked not to track any website interaction. You may want to respect this and disable all tags if this variable is set in the data layer.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_DEPRECATED,
	),
	GTM4WP_OPTION_LOADEARLY        => array(
		'label'       => esc_html__( 'Load GTM container as early as possible', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Turning on this option will load your Google Tag Manager container as early as possible during page load. This can cause issues if you are using jQuery in your custom HTML tags that fire on \'Page View\' events.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_GTMDOMAIN        => array(
		'label'       => esc_html__( 'Container domain name', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enter your custom domain name if you are using a server side GTM container for tracking. Do not include https:// prefix. Leave this blank to use www.googletagmanager.com', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_NOGTMFORLOGGEDIN => array(
		'label'       => esc_html__( 'User roles to exclude', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Do not load GTM container on the frontend if role of the logged in user is any of this', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
);
