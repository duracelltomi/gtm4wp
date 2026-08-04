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
	 * A forwarding header is only authentic when the request demonstrably arrived through
	 * infrastructure the site operator controls, which is what $trusted_proxies states.
	 * Only REMOTE_ADDR is observed by the server; everything else is a claim the client
	 * can make. The two header families fail differently, and both are handled here:
	 *
	 * - APPEND semantics (X-Forwarded-For): each proxy appends the address IT observed,
	 *   so the operator's own hops are on the RIGHT and whatever the client sent stays
	 *   on the left. The client address is the right-most entry that is not one of the
	 *   operator's proxies - found by walking from the right and stopping at the first
	 *   entry outside the trusted set. Everything left of that point is client-supplied
	 *   and must never be scanned for a "better looking" address, which is precisely the
	 *   spoof this ordering exists to defeat.
	 * - REPLACE semantics (CF-Connecting-IP, True-Client-IP, X-Real-IP): the proxy
	 *   OVERWRITES the header, so its value is authoritative - but only if the request
	 *   actually came through that proxy. On a request delivered straight to the origin,
	 *   the header is whatever the client typed.
	 *
	 * With no trusted proxies configured neither guarantee is available, so the header is
	 * read exactly as it was before this setting existed (left-to-right for the list form)
	 * and the value must be treated as visitor-supplied. That state is deliberate for
	 * backward compatibility, and the admin is warned about it rather than silently
	 * migrated - see Notices::show_notices().
	 *
	 * The function will translate the given custom header to a PHP server variable, no need to directly
	 * input the PHP form of the header. If the custom header is not found, the function will fall back
	 * to REMOTE_ADDR.
	 *
	 * @param string $use_custom_header A custom HTTP header to use instead of the default REMOTE_ADDR server variable.
	 * @param string $trusted_proxies   Whitespace/comma separated IP addresses and CIDR ranges of the proxies in front of this site.
	 * @return string IP address of the user if found, empty string otherwise.
	 */
	public static function get( string $use_custom_header = '', string $trusted_proxies = '' ): string {
		$custom_header = self::normalize_header_name( $use_custom_header );

		if ( '' !== $custom_header ) {
			$custom_header = 'HTTP_' . $custom_header;
		}

		if ( ( '' !== $custom_header ) && ( ! empty( $_SERVER[ $custom_header ] ) ) ) {
			$header_value = sanitize_text_field( wp_unslash( $_SERVER[ $custom_header ] ) );
			$trusted      = self::parse_trusted_proxies( $trusted_proxies );

			$ip = array() === $trusted
				? self::read_unverified_header( $custom_header, $header_value )
				: self::read_header_via_trusted_proxies( $custom_header, $header_value, $trusted );

			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return self::remote_addr();
	}

	/**
	 * Reads the configured header with no trusted-proxy information available.
	 *
	 * This is the pre-existing behavior, kept verbatim so configuring the header alone
	 * does not change any site's value: the first public entry of the list form, or the
	 * whole value for a single-address header. The result is NOT authenticated.
	 *
	 * @param string $header_name  The $_SERVER key being read.
	 * @param string $header_value The sanitized header value.
	 * @return string A public IP address, or an empty string when none was usable.
	 */
	private static function read_unverified_header( string $header_name, string $header_value ): string {
		if ( 'HTTP_X_FORWARDED_FOR' === $header_name ) {
			// X-Forwarded-For is a comma+space separated list of IPs, so each entry
			// has to be trimmed before it is validated: without that, every entry
			// after the first carries a leading space and fails filter_var().
			foreach ( explode( ',', $header_value ) as $entry ) {
				$entry = trim( $entry );

				if ( self::is_public_ip( $entry ) ) {
					return $entry;
				}
			}

			return '';
		}

		return self::is_public_ip( $header_value ) ? $header_value : '';
	}

	/**
	 * Reads the configured header against the operator's declared proxy set.
	 *
	 * @param string   $header_name  The $_SERVER key being read.
	 * @param string   $header_value The sanitized header value.
	 * @param string[] $trusted      Validated IP addresses / CIDR ranges of the site's own proxies.
	 * @return string A public IP address, or an empty string when the header cannot be vouched for.
	 */
	private static function read_header_via_trusted_proxies( string $header_name, string $header_value, array $trusted ): string {
		// The whole point of the trusted set: a header only means anything when the
		// request reached us through one of those hops. A request delivered straight to
		// the origin carries whatever headers its sender chose, list form or not.
		if ( ! self::ip_in_any_range( self::remote_addr_any(), $trusted ) ) {
			return '';
		}

		if ( 'HTTP_X_FORWARDED_FOR' === $header_name ) {
			$entries = array_map( 'trim', explode( ',', $header_value ) );

			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				if ( self::ip_in_any_range( $entries[ $i ], $trusted ) ) {
					// One of our own hops - keep walking left past it.
					continue;
				}

				// The first entry that is not ours IS the client. Stop here and return
				// it or nothing: continuing left would start reading addresses the
				// client themselves supplied, which is the spoof this guards against.
				return self::is_public_ip( $entries[ $i ] ) ? $entries[ $i ] : '';
			}

			// Every entry was one of our own proxies, so the list never carried a
			// client address (a purely internal request).
			return '';
		}

		return self::is_public_ip( $header_value ) ? $header_value : '';
	}

	/**
	 * Parses the operator-supplied trusted proxy list into validated entries.
	 *
	 * Accepts whitespace, newline and comma separation so the admin can paste a CDN's
	 * published range list unedited. Invalid entries are dropped rather than failing the
	 * whole list, which matches how the sanitizer stores it.
	 *
	 * @param string $raw The raw option value.
	 * @return string[] Validated IP addresses and CIDR ranges.
	 */
	private static function parse_trusted_proxies( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$entries = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_values( array_filter( $entries, array( self::class, 'is_valid_range' ) ) );
	}

	/**
	 * Normalizes a configured HTTP header name to its canonical $_SERVER form
	 * (without the HTTP_ prefix), or returns an empty string when it is not a
	 * usable header name.
	 *
	 * Public for the same reason as is_valid_range() below: the option's sanitizer
	 * must accept exactly what the reader will honor. Both ends used to carry their
	 * own copy of this pattern - #62 anchored the read end and #89 the save end,
	 * and the two literals then sat three files apart with a comment on one saying
	 * the other was "identical", which is a divergence waiting for the next
	 * tightening (PA-2).
	 *
	 * Anchored on purpose: the unanchored version this replaces matched any string
	 * CONTAINING one allowed character, so it accepted every input and validated
	 * nothing.
	 *
	 * @param string $header A header name as the admin typed it (X-Forwarded-For, x_forwarded_for, ...).
	 * @return string The canonical name (X_FORWARDED_FOR), or '' when invalid.
	 */
	public static function normalize_header_name( string $header ): string {
		if ( '' === $header ) {
			return '';
		}

		$name = strtoupper( str_replace( '-', '_', $header ) );

		return preg_match( '/^[A-Z0-9_]+$/', $name ) ? $name : '';
	}

	/**
	 * Whether an entry is a valid single IP address or CIDR range.
	 *
	 * Public so the option's sanitizer validates with exactly the same rule that the
	 * reader applies - a stored entry the reader would silently ignore is worse than a
	 * rejected one, because the admin believes the proxy is covered.
	 *
	 * @param string $entry A single list entry.
	 * @return bool
	 */
	public static function is_valid_range( string $entry ): bool {
		if ( false === strpos( $entry, '/' ) ) {
			return false !== filter_var( $entry, FILTER_VALIDATE_IP );
		}

		list( $subnet, $prefix ) = explode( '/', $entry, 2 );

		if ( false === filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( 1 !== preg_match( '/^\d{1,3}$/', $prefix ) ) {
			return false;
		}

		$prefix_length = (int) $prefix;

		// A /0 matches every address, so accepting it would declare the entire
		// internet a trusted proxy - which restores exactly the verbatim header
		// trust this option exists to remove, and does it silently: the admin
		// notice that warns about an unconfigured list keys on the list being
		// non-empty, so filling it with 0.0.0.0/0 also switches off the one signal
		// that would have told them.
		if ( $prefix_length < 1 ) {
			return false;
		}

		$max = ( false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) ? 128 : 32;

		return $prefix_length <= $max;
	}

	/**
	 * Whether an IP address falls inside any of the given ranges.
	 *
	 * @param string   $ip     The address to test.
	 * @param string[] $ranges Validated IP addresses / CIDR ranges.
	 * @return bool
	 */
	private static function ip_in_any_range( string $ip, array $ranges ): bool {
		if ( '' === $ip ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			if ( self::ip_in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IP address falls inside one IP address or CIDR range.
	 *
	 * Compares the packed binary forms, so IPv4 and IPv6 use one code path. Addresses of
	 * different families never match (their packed lengths differ), which is the correct
	 * answer rather than an error.
	 *
	 * @param string $ip    The address to test. Must already be a valid IP.
	 * @param string $range A validated IP address or CIDR range.
	 * @return bool
	 */
	private static function ip_in_range( string $ip, string $range ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$ip_bin = inet_pton( $ip );
		if ( false === $ip_bin ) {
			return false;
		}

		if ( false === strpos( $range, '/' ) ) {
			$range_bin = inet_pton( $range );

			return ( false !== $range_bin ) && ( $ip_bin === $range_bin );
		}

		list( $subnet, $prefix ) = explode( '/', $range, 2 );

		$subnet_bin = inet_pton( $subnet );
		if ( false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$prefix      = (int) $prefix;
		$whole_bytes = intdiv( $prefix, 8 );
		$rest_bits   = $prefix % 8;

		if ( $whole_bytes > 0 && 0 !== strncmp( $ip_bin, $subnet_bin, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $rest_bits ) {
			return true;
		}

		$mask = chr( ( 0xFF << ( 8 - $rest_bits ) ) & 0xFF );

		return ( $ip_bin[ $whole_bytes ] & $mask ) === ( $subnet_bin[ $whole_bytes ] & $mask );
	}

	/**
	 * Whether a string is an IP address outside the private and reserved ranges.
	 *
	 * @param string $ip The address to test.
	 * @return bool
	 */
	private static function is_public_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * REMOTE_ADDR as the reported visitor address: public ranges only, matching the
	 * value this function has always returned.
	 *
	 * @return string
	 */
	private static function remote_addr(): string {
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return '';
	}

	/**
	 * REMOTE_ADDR as the address of the machine that connected, in ANY range.
	 *
	 * Deliberately different from remote_addr(): the immediate peer is normally the
	 * operator's own load balancer or reverse proxy on a private address, so the
	 * public-only filter would reject exactly the value the trusted-proxy check needs.
	 *
	 * @return string
	 */
	private static function remote_addr_any(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );

		return ( false === $ip ) ? '' : (string) $ip;
	}
}
