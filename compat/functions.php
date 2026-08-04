<?php
/**
 * Backward compatible public template and helper functions.
 *
 * These functions form the public integration surface of GTM4WP 1.x and are
 * kept as thin wrappers around the 2.x OOP services. Themes and third party
 * plugins call them directly, therefore their names, signatures and behavior
 * must stay unchanged.
 *
 * This file is only loaded on frontend requests, after the frontend services
 * have been constructed.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Frontend\VisitorIp;
use GTM4WP\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'gtm4wp_amp_running' ) ) {
	/**
	 * Returns whether an AMP page is currently being generated.
	 *
	 * @return bool
	 */
	function gtm4wp_amp_running() {
		return (bool) apply_filters( ContainerCode::FILTER_AMP_RUNNING, false );
	}
}

if ( ! function_exists( 'gtm4wp_get_user_ip' ) ) {
	/**
	 * Returns the IP address of the user either from the REMOTE_ADDR server variable
	 * or a custom HTTP header specified in the parameter of the function.
	 *
	 * The trusted proxy list configured on the settings screen is applied here too, so a
	 * 1.x caller that passes only a header name still gets the authenticated reading
	 * rather than the raw header. Pass the second argument to override it.
	 *
	 * @param string $use_custom_header A custom HTTP header to use instead of the default REMOTE_ADDR server variable.
	 * @param string $trusted_proxies   IP addresses / CIDR ranges of the proxies in front of this site. Defaults to the configured list.
	 * @return string IP address of the user if found, empty string otherwise.
	 */
	function gtm4wp_get_user_ip( $use_custom_header = '', $trusted_proxies = null ) {
		if ( null === $trusted_proxies ) {
			// options() is nullable by design (#64): it is built in boot(), and this is
			// a public function a theme could call earlier. Falling back to '' means an
			// early caller gets the same reading it got before this parameter existed,
			// never a fatal.
			$options         = Plugin::instance()->options();
			$trusted_proxies = ( null === $options )
				? ''
				: (string) $options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES, '' );
		}

		return VisitorIp::get( (string) $use_custom_header, (string) $trusted_proxies );
	}
}

if ( ! function_exists( 'gtm4wp_generate_script_opening_tag' ) ) {
	/**
	 * Generates an opening <script> tag that includes all the necessary attributes.
	 *
	 * @return string
	 */
	function gtm4wp_generate_script_opening_tag() {
		return Plugin::instance()->frontend()->script_tag()->opening_tag();
	}
}

if ( ! function_exists( 'gtm4wp_get_sanitize_script_block_rules' ) ) {
	/**
	 * Returns an array that can be used to sanitize a <script> block using wp_kses().
	 *
	 * @return array
	 */
	function gtm4wp_get_sanitize_script_block_rules() {
		return ScriptTag::sanitize_rules();
	}
}

if ( ! function_exists( 'gtm4wp_get_container_placement_string' ) ) {
	/**
	 * Helper function to translate the GTM container code placement value into a readable string.
	 *
	 * @return string Readable form of a GTM container code placement option.
	 */
	function gtm4wp_get_container_placement_string() {
		return Plugin::instance()->frontend()->container()->placement_string();
	}
}

if ( ! function_exists( 'gtm4wp_get_the_gtm_tag' ) ) {
	/**
	 * Returns a HTML code that includes the noscript/iframe part of the Google Tag Manager container.
	 * Can be used to manually place the snippet next to the opening body tag if the installed template
	 * does not support the wp_body_open hook.
	 *
	 * @return string The HTML code that includes the noscript/iframe part of the GTM container code.
	 */
	function gtm4wp_get_the_gtm_tag() {
		return Plugin::instance()->frontend()->container()->get_tag();
	}
}

if ( ! function_exists( 'gtm4wp_the_gtm_tag' ) ) {
	/**
	 * Outputs a HTML code that includes the noscript/iframe part of the Google Tag Manager container.
	 * Can be used to manually place the snippet next to the opening body tag if the installed template
	 * does not support the wp_body_open hook.
	 *
	 * @return void
	 */
	function gtm4wp_the_gtm_tag() {
		Plugin::instance()->frontend()->container()->the_tag();
	}
}

if ( ! function_exists( 'gtm4wp_get_consent_mode_flag' ) ) {
	/**
	 * Returns the value of the consent mode flag.
	 *
	 * @param string $flag The flag to be read.
	 * @return string The value of the flag (granted or denied).
	 */
	function gtm4wp_get_consent_mode_flag( $flag ) {
		return Plugin::instance()->frontend()->consent()->flag( (string) $flag );
	}
}

if ( ! function_exists( 'gtm4wp_datalayer_push' ) ) {
	/**
	 * Queues a data layer event to be fired after the main GTM container code.
	 *
	 * @param string $event_name The name of the GTM event.
	 * @param array  $event_data Additional event parameters to be passed after the event. Optional.
	 * @param string $js_before  Inline JS code to be added before the dataLayer.push() line.
	 * @param string $js_after   Inline JS code to be added after the dataLayer.push() line.
	 * @return bool Returns true when the data layer event was successfully queued. Returns false when function parameter types are invalid.
	 */
	function gtm4wp_datalayer_push( $event_name, $event_data = array(), $js_before = '', $js_after = '' ) {
		return Plugin::instance()->frontend()->datalayer()->queue_push( $event_name, $event_data, $js_before, $js_after );
	}
}
