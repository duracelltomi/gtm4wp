<?php
/**
 * GTM4WP options on the Events tab.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

$GLOBALS['gtm4wp_eventfieldtexts'] = array(
	GTM4WP_OPTION_EVENTS_FORMMOVE   => array(
		'label'       => esc_html__( 'Form fill events (gtm4wp.formElementEnter & gtm4wp.formElementLeave)', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include a Tag Manager event when a visitor moves between elements of a form (comment, contact, etc).', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_EVENTS_NEWUSERREG => array(
		'label'       => esc_html__( 'New user registration', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include a Tag Manager event when a new user registration has been completed on the frontend of your site (admin events not included)', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_EVENTS_USERLOGIN  => array(
		'label'       => esc_html__( 'User logged in', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include a Tag Manager event when an existing user has been logged in on the frontend of your site (admin events not included)', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_EVENTS_YOUTUBE    => array(
		'label'       => esc_html__( 'YouTube video events', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a YouTube video embeded on your site.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_EVENTS_VIMEO      => array(
		'label'       => esc_html__( 'Vimeo video events', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Vimeo video embeded on your site.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_EVENTS_SOUNDCLOUD => array(
		'label'       => esc_html__( 'Soundcloud events', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include a Tag Manager event when a visitor interacts with a Soundcloud media embeded on your site.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
);
