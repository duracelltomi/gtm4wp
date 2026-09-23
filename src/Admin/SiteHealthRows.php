<?php
/**
 * Row formatters shared by every module's Site Health Info rows.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds rows in the shape the debug_information filter takes: `label`,
 * `value` (translated, what the Info tab shows) and `debug` (English, what
 * the copy-to-clipboard text carries, so a paste reads the same on every
 * site). Core prints an empty value as "undefined", so no formatter ever
 * returns ''. One definition of the words every module would otherwise
 * translate on its own (UC-6).
 */
final class SiteHealthRows {

	/**
	 * A text row. Empty text becomes a dash.
	 *
	 * @param string $label The row label.
	 * @param string $value The translated value.
	 * @param string $debug The English value; the value itself when it needs no translation.
	 * @return array<string, string>
	 */
	public static function text( string $label, string $value, string $debug = '' ): array {
		$value = ( '' === $value ) ? '-' : $value;
		$debug = ( '' === $debug ) ? $value : $debug;

		return array(
			'label' => $label,
			'value' => $value,
			'debug' => $debug,
		);
	}

	/**
	 * A boolean option as on/off.
	 *
	 * @param string $label The row label.
	 * @param bool   $on    The option value.
	 * @return array<string, string>
	 */
	public static function on_off( string $label, bool $on ): array {
		return self::text( $label, self::word( $on ? 'on' : 'off' ), $on ? 'on' : 'off' );
	}

	/**
	 * A fact as yes/no.
	 *
	 * @param string $label The row label.
	 * @param bool   $yes   The fact.
	 * @return array<string, string>
	 */
	public static function yes_no( string $label, bool $yes ): array {
		return self::text( $label, self::word( $yes ? 'yes' : 'no' ), $yes ? 'yes' : 'no' );
	}

	/**
	 * A text option reported as set or empty - never by value.
	 *
	 * @param string $label The row label.
	 * @param string $value The option value.
	 * @return array<string, string>
	 */
	public static function set_or_empty( string $label, string $value ): array {
		$set = ( '' !== trim( $value ) );

		return self::text( $label, self::word( $set ? 'set' : 'empty' ), $set ? 'set' : 'empty' );
	}

	/**
	 * A count.
	 *
	 * @param string $label The row label.
	 * @param int    $count The count.
	 * @return array<string, string>
	 */
	public static function count( string $label, int $count ): array {
		return self::text( $label, (string) $count );
	}

	/**
	 * Several boolean options as one row: each key with its on/off state, the
	 * off ones included, because an off is as diagnostic as an on.
	 *
	 * @param string              $label    The row label.
	 * @param array<string, bool> $switches Option key => state.
	 * @return array<string, mixed>
	 */
	public static function group( string $label, array $switches ): array {
		if ( array() === $switches ) {
			return self::none( $label );
		}

		$value = array();
		$debug = array();

		foreach ( $switches as $key => $on ) {
			$value[ (string) $key ] = self::word( $on ? 'on' : 'off' );
			$debug[ (string) $key ] = $on ? 'on' : 'off';
		}

		return array(
			'label' => $label,
			'value' => $value,
			'debug' => $debug,
		);
	}

	/**
	 * The states of several boolean options, for group().
	 *
	 * @param Options  $options The plugin options service.
	 * @param string[] $keys    Option keys.
	 * @return array<string, bool>
	 */
	public static function states( Options $options, array $keys ): array {
		$states = array();

		foreach ( $keys as $key ) {
			$states[ $key ] = (bool) $options->get( $key );
		}

		return $states;
	}

	/**
	 * A host plugin as a module's StatusInfoInterface reports it: its version,
	 * or "not installed". A version under the module's floor says so.
	 *
	 * @param string               $label       The row label.
	 * @param array<string, mixed> $integration The `integration` entry: `active`, `version`.
	 * @param string               $floor       The module's minimum version, '' for none.
	 * @return array<string, string>
	 */
	public static function plugin( string $label, array $integration, string $floor = '' ): array {
		if ( empty( $integration['active'] ) ) {
			return self::text( $label, __( 'not installed', 'duracelltomi-google-tag-manager' ), 'not installed' );
		}

		$version = (string) ( $integration['version'] ?? '' );

		if ( ( '' === $floor ) || ( '' === $version ) || version_compare( $version, $floor, '>=' ) ) {
			return self::text( $label, $version );
		}

		return self::text(
			$label,
			/* translators: 1: installed version. 2: the lowest version the plugin supports. */
			sprintf( __( '%1$s (below the %2$s floor)', 'duracelltomi-google-tag-manager' ), $version, $floor ),
			sprintf( '%1$s (below the %2$s floor)', $version, $floor )
		);
	}

	/**
	 * A list of code names on one line, or "none".
	 *
	 * @param string   $label The row label.
	 * @param string[] $items The items, untranslated.
	 * @return array<string, string>
	 */
	public static function items( string $label, array $items ): array {
		$items = array_values( array_filter( array_map( 'strval', $items ), static fn ( string $item ): bool => '' !== $item ) );

		if ( array() === $items ) {
			return self::none( $label );
		}

		return self::text( $label, implode( ', ', $items ) );
	}

	/**
	 * Named sub-lines: core prints each as its own "key: value" line.
	 *
	 * @param string                $label The row label.
	 * @param array<string, string> $rows  Key => translated text.
	 * @param array<string, string> $debug Key => English text; the rows themselves when they need no translation.
	 * @return array<string, mixed>
	 */
	public static function assoc( string $label, array $rows, array $debug = array() ): array {
		if ( array() === $rows ) {
			return self::none( $label );
		}

		return array(
			'label' => $label,
			'value' => $rows,
			'debug' => ( array() === $debug ) ? $rows : $debug,
		);
	}

	/**
	 * The "none" row.
	 *
	 * @param string $label The row label.
	 * @return array<string, string>
	 */
	public static function none( string $label ): array {
		return self::text( $label, self::word( 'none' ), 'none' );
	}

	/**
	 * A stored Unix timestamp as a date, or a dash for never. Already English.
	 *
	 * @param int $timestamp Unix time, 0 for never.
	 * @return string
	 */
	public static function stamp( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '-';
		}

		return gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}

	/**
	 * One of the state words, translated.
	 *
	 * @param string $word The English word.
	 * @return string
	 */
	private static function word( string $word ): string {
		switch ( $word ) {
			case 'on':
				return __( 'on', 'duracelltomi-google-tag-manager' );
			case 'off':
				return __( 'off', 'duracelltomi-google-tag-manager' );
			case 'yes':
				return __( 'yes', 'duracelltomi-google-tag-manager' );
			case 'no':
				return __( 'no', 'duracelltomi-google-tag-manager' );
			case 'set':
				return __( 'set', 'duracelltomi-google-tag-manager' );
			case 'empty':
				return __( 'empty', 'duracelltomi-google-tag-manager' );
			default:
				return __( 'none', 'duracelltomi-google-tag-manager' );
		}
	}
}
