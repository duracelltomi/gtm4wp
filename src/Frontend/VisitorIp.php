<?php
/**
 * Visitor IP detection.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Port of gtm4wp_get_user_ip() from 1.x (public/frontend.php).
 */
final class VisitorIp {

	/**
	 * Returns the IP address of the user either from the REMOTE_ADDR server variable
	 * or a custom HTTP header specified in the parameter of the function.
	 *
	 * Originally this function iterated through many commonly used custom headers however since they are
	 * unprotected, one could send a bogus IP address for tracking purposes. Therefore the function only uses
	 * the safe server variable and a user option to allow one specific custom HTTP header.
	 *
	 * The function will translate the given custom header to a PHP server variable, no need to directly
	 * input the PHP form of the header. If the custom header is not found, the function will fall back
	 * to REMOTE_ADDR.
	 *
	 * @param string $use_custom_header A custom HTTP header to use instead of the default REMOTE_ADDR server variable.
	 * @return string IP address of the user if found, empty string otherwise.
	 */
	public static function get( string $use_custom_header = '' ): string {
		$custom_header = '';

		if ( '' !== $use_custom_header ) {
			$custom_header = strtoupper( str_replace( '-', '_', $use_custom_header ) );
			if ( preg_match( '/[A-Z0-9_]+/', $custom_header ) ) {
				$custom_header = 'HTTP_' . $custom_header;
			} else {
				$custom_header = '';
			}
		}

		if ( ( '' !== $custom_header ) && ( ! empty( $_SERVER[ $custom_header ] ) ) ) {
			if ( 'HTTP_X_FORWARDED_FOR' === $custom_header ) {
				// X-Forwarded-For is a comma+space separated list of IPs.
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $custom_header ] ) ) ) as $ip ) {
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
						return $ip;
					}
				}
			} else {
				$ip = filter_var( wp_unslash( $_SERVER[ $custom_header ] ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
				if ( false !== $ip ) {
					return $ip;
				}
			}
		}

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return '';
	}
}
