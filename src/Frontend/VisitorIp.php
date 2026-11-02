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
	 * Returns the visitor's IP address from REMOTE_ADDR or one configured custom
	 * HTTP header (translated to its $_SERVER form here), falling back to
	 * REMOTE_ADDR. Only REMOTE_ADDR is observed by the server; a header is a
	 * claim, authentic only when the request arrived through a proxy listed in
	 * $trusted_proxies (RI-18). Two header families: APPEND (X-Forwarded-For -
	 * the operator's hops are on the RIGHT, the client is the right-most entry
	 * outside the trusted set, and nothing left of it may be scanned) and
	 * REPLACE (CF-Connecting-IP, True-Client-IP, X-Real-IP - authoritative only
	 * when the request came through that proxy). With no trusted proxies the
	 * header is read as before this setting existed, unauthenticated; the admin
	 * is warned (Notices::show_notices()) rather than silently migrated.
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
	 * Reads the configured header with no trusted-proxy information: the
	 * pre-existing behavior (first public entry of the list form, or the whole
	 * value), kept verbatim. The result is NOT authenticated.
	 *
	 * @param string $header_name  The $_SERVER key being read.
	 * @param string $header_value The sanitized header value.
	 * @return string A public IP address, or an empty string when none was usable.
	 */
	private static function read_unverified_header( string $header_name, string $header_value ): string {
		if ( 'HTTP_X_FORWARDED_FOR' === $header_name ) {
			// Comma+space separated: trim, or every entry after the first fails filter_var().
			foreach ( explode( ',', $header_value ) as $entry ) {
				$entry = self::unmap( trim( $entry ) );

				if ( self::is_public_ip( $entry ) ) {
					return $entry;
				}
			}

			return '';
		}

		$header_value = self::unmap( $header_value );

		return self::is_public_ip( $header_value ) ? $header_value : '';
	}

	/**
	 * Whether this request reached the site through one of the given proxies -
	 * the gate every replace-style proxy header (an IP, a country) needs before
	 * its value means anything (RI-18).
	 *
	 * @param string[] $trusted Validated IP addresses / CIDR ranges from parse_trusted_proxies().
	 * @return bool
	 */
	public static function request_via_trusted_proxy( array $trusted ): bool {
		return self::ip_in_any_range( self::remote_addr_any(), $trusted );
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
		// A header only means anything when the request reached us through one of
		// the trusted hops; delivered straight to the origin, it is whatever the
		// sender chose.
		if ( ! self::ip_in_any_range( self::remote_addr_any(), $trusted ) ) {
			return '';
		}

		if ( 'HTTP_X_FORWARDED_FOR' === $header_name ) {
			$entries = array_map( static fn ( $entry ) => self::unmap( trim( $entry ) ), explode( ',', $header_value ) );

			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				if ( self::ip_in_any_range( $entries[ $i ], $trusted ) ) {
					continue;
				}

				// The first entry that is not ours IS the client: return it or
				// nothing. Continuing left would read client-supplied addresses.
				return self::is_public_ip( $entries[ $i ] ) ? $entries[ $i ] : '';
			}

			// Every entry was one of our own proxies (a purely internal request).
			return '';
		}

		$header_value = self::unmap( $header_value );

		return self::is_public_ip( $header_value ) ? $header_value : '';
	}

	/**
	 * Parses the trusted proxy list into validated entries. Whitespace, newline
	 * and comma separated so a CDN's published list pastes unedited; invalid
	 * entries are dropped. Public so the option's sanitizer stores exactly what
	 * this reader honours - one split rule, one place (PA-2).
	 *
	 * @param string $raw The raw option value.
	 * @return string[] Validated IP addresses and CIDR ranges.
	 */
	public static function parse_trusted_proxies( string $raw ): array {
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
	 * (without HTTP_), or '' when unusable. Public so the option's sanitizer
	 * accepts exactly what the reader honours (PA-2, #62/#89). The pattern is
	 * anchored on purpose: unanchored, it validated nothing.
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
	 * Whether an entry is a valid single IP address or CIDR range. Public so the
	 * sanitizer validates with the reader's rule: a stored entry the reader
	 * silently ignores makes the admin believe the proxy is covered.
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

		// A /0 would declare the entire internet a trusted proxy, silently: the
		// unconfigured-list notice keys on the list being non-empty.
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
	 * An IPv4-mapped IPv6 address (::ffff:a.b.c.d, any notation) as the IPv4
	 * address it is (#657): a dual-stack listener reports IPv4 peers that way.
	 * Anything else is returned unchanged.
	 *
	 * @param string $ip A candidate address.
	 * @return string
	 */
	private static function unmap( string $ip ): string {
		$bin = ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) ? inet_pton( $ip ) : false;

		if ( is_string( $bin ) && 16 === strlen( $bin ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
			return (string) inet_ntop( substr( $bin, 12 ) );
		}

		return $ip;
	}

	/**
	 * A trusted range listed in IPv4-mapped form as its IPv4 equivalent, so it
	 * keeps matching a peer unmap() has already folded (prefix 96+ only).
	 *
	 * @param string $range A validated IP address or CIDR range.
	 * @return string
	 */
	private static function unmap_range( string $range ): string {
		if ( false === strpos( $range, '/' ) ) {
			return self::unmap( $range );
		}

		list( $subnet, $prefix ) = explode( '/', $range, 2 );
		$folded                  = self::unmap( $subnet );

		return ( $folded !== $subnet && (int) $prefix >= 96 ) ? $folded . '/' . ( (int) $prefix - 96 ) : $range;
	}

	/**
	 * Whether an IP address falls inside one IP address or CIDR range. Compares
	 * packed binary forms after folding IPv4-mapped addresses to IPv4 on both
	 * sides (otherwise different families never match).
	 *
	 * @param string $ip    The address to test. Must already be a valid IP.
	 * @param string $range A validated IP address or CIDR range.
	 * @return bool
	 */
	private static function ip_in_range( string $ip, string $range ): bool {
		$ip    = self::unmap( $ip );
		$range = self::unmap_range( $range );

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
		$ip = self::remote_addr_any();

		return self::is_public_ip( $ip ) ? $ip : '';
	}

	/**
	 * REMOTE_ADDR in ANY range, unlike remote_addr(): the immediate peer is
	 * normally the operator's own proxy on a private address, which is exactly
	 * what the trusted-proxy check needs.
	 *
	 * @return string
	 */
	private static function remote_addr_any(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );

		return ( false === $ip ) ? '' : self::unmap( (string) $ip );
	}
}
