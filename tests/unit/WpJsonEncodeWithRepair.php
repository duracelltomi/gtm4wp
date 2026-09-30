<?php
/**
 * A wp_json_encode() double that models WordPress core's invalid-UTF-8 repair.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

/**
 * Port of wp_json_encode() / _wp_json_sanity_check() from wp-includes/functions.php.
 * The plain json_encode() stubs used across the suite stop at json_encode()'s
 * `false`, so they never run core's second pass, which walks arrays and objects
 * differently and can throw an Error rather than return false (#330, UC-3). Use
 * this double where a test needs to reach that second pass. The string
 * conversion is core's mbstring branch (PHPUnit requires ext-mbstring).
 */
final class WpJsonEncodeWithRepair {

	/**
	 * Mirrors wp_json_encode(): json_encode(), and on failure one repair pass
	 * whose Exception (never an Error) becomes `false`.
	 *
	 * @param mixed $value Value to encode.
	 * @param int   $flags json_encode() flags.
	 * @param int   $depth Maximum depth.
	 * @return string|false
	 */
	public static function encode( $value, $flags = 0, $depth = 512 ) {
		$json = json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		if ( false !== $json ) {
			return $json;
		}

		try {
			$value = self::sanity_check( $value, $depth );
		} catch ( \Exception $e ) {
			return false;
		}

		return json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Mirrors _wp_json_sanity_check(), including the `$output->$clean_id`
	 * assignment on the object branch.
	 *
	 * @param mixed $value Value to repair.
	 * @param int   $depth Remaining depth.
	 * @return mixed
	 * @throws \Exception When the depth limit is reached.
	 */
	private static function sanity_check( $value, $depth ) {
		if ( $depth < 0 ) {
			throw new \Exception( 'Reached depth limit' );
		}

		if ( is_array( $value ) ) {
			$output = array();
			foreach ( $value as $id => $el ) {
				$clean_id = is_string( $id ) ? self::convert_string( $id ) : $id;

				$output[ $clean_id ] = self::repair_element( $el, $depth );
			}

			return $output;
		}

		if ( is_object( $value ) ) {
			$output = new \stdClass();
			foreach ( $value as $id => $el ) {
				$clean_id = is_string( $id ) ? self::convert_string( $id ) : $id;

				$output->$clean_id = self::repair_element( $el, $depth );
			}

			return $output;
		}

		return is_string( $value ) ? self::convert_string( $value ) : $value;
	}

	/**
	 * The per-element half of the sanity check.
	 *
	 * @param mixed $el    Element to repair.
	 * @param int   $depth Depth of the containing value.
	 * @return mixed
	 */
	private static function repair_element( $el, $depth ) {
		if ( is_array( $el ) || is_object( $el ) ) {
			return self::sanity_check( $el, $depth - 1 );
		}

		return is_string( $el ) ? self::convert_string( $el ) : $el;
	}

	/**
	 * Mirrors _wp_json_convert_string()'s mbstring branch.
	 *
	 * @param string $input_string String to convert.
	 * @return string
	 */
	private static function convert_string( $input_string ) {
		$encoding = mb_detect_encoding( $input_string, mb_detect_order(), true );

		return mb_convert_encoding( $input_string, 'UTF-8', $encoding ? $encoding : 'UTF-8' );
	}
}
