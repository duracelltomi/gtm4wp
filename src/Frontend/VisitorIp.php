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
	 * NOTE - the custom-header value is NOT authenticated (RI-18). Narrowing the choice to one
	 * operator-configured header removes the guesswork an attacker had with a header list, but the
	 * header itself is still client-supplied: only REMOTE_ADDR is observed by the server. For
	 * X-Forwarded-For specifically, the list below is scanned left-to-right, and proxies that append
	 * (nginx proxy_add_x_forwarded_for, AWS ALB, Cloudflare) put the address THEY observed on the
	 * right - so the entry returned here is the one the client chose, not the one the proxy vouched
	 * for. Treat the result as analytics data, never as an input to an access decision; a caller that
	 * needs a trustworthy address has to skip a configured number of trusted proxy hops from the right.
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
			// Anchored on purpose: the unanchored version this replaces matched any
			// string CONTAINING one allowed character, so it accepted every input and
			// validated nothing.
			if ( preg_match( '/^[A-Z0-9_]+$/', $custom_header ) ) {
				$custom_header = 'HTTP_' . $custom_header;
			} else {
				$custom_header = '';
			}
		}

		if ( ( '' !== $custom_header ) && ( ! empty( $_SERVER[ $custom_header ] ) ) ) {
			if ( 'HTTP_X_FORWARDED_FOR' === $custom_header ) {
				// X-Forwarded-For is a comma+space separated list of IPs, so each entry
				// has to be trimmed before it is validated: without that, every entry
				// after the first carries a leading space and fails filter_var().
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $custom_header ] ) ) ) as $ip ) {
					$ip = trim( $ip );

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
