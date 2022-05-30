<?php
/**
 * GTM4WP options on the Scroll tracking tab.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

$GLOBALS['gtm4wp_scrollerfieldtexts'] = array(
	GTM4WP_OPTION_SCROLLER_ENABLED      => array(
		'label'       => esc_html__( 'Enabled', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enable scroll tracker script on your website.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_SCROLLER_DEBUGMODE    => array(
		'label'       => esc_html__( 'Debug mode', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Fire console.log() commands instead of dataLayer events.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_SCROLLER_CALLBACKTIME => array(
		'label'       => esc_html__( 'Time delay before location check', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enter the number of milliseconds after the script checks the current location. It prevents too many events being fired while scrolling.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_SCROLLER_DISTANCE     => array(
		'label'       => esc_html__( 'Minimum distance', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'The minimum amount of pixels that a visitor has to scroll before we treat the move as scrolling.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_SCROLLER_CONTENTID    => array(
		'label'       => esc_html__( 'Content ID', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enter the DOM ID of the content element in your template. Leave it empty for default(content). Do not include the # sign.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_SCROLLER_READERTIME   => array(
		'label'       => esc_html__( 'Scroller time', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enter the number of seconds after the the scroller user is being treated as a reader, someone who really reads the content, not just scrolls through it.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
);
