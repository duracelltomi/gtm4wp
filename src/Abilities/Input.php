<?php
/**
 * Reading ability input across the transports.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Before WordPress 7.1 the GET/DELETE REST transports pass every input as a
 * query string, validated but not coerced (#502); flags and lists are read
 * through these. A `confirm` stays strictly true.
 */
final class Input {

	/**
	 * A boolean input: rest_sanitize_boolean()'s reading of a query string.
	 *
	 * @param mixed $value The raw input value.
	 * @return bool
	 */
	public static function flag( $value ): bool {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false' ), true );
		}

		return (bool) $value;
	}

	/**
	 * A list of non-empty strings, from an array or a comma-separated string.
	 *
	 * @param mixed $value The raw input value.
	 * @return string[]
	 */
	public static function strings( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$strings[] = trim( $item );
			}
		}

		return array_values( array_unique( $strings ) );
	}
}
