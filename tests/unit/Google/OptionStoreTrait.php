<?php
/**
 * Test support: an in-memory options table behind the WordPress option functions.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use Brain\Monkey\Functions;

/**
 * Stubs get_option() / add_option() / update_option() over an array so a test
 * can read back what the vault wrote, and records every write with its
 * autoload argument - the vault's "never autoload the key row" property is
 * only observable through that argument.
 *
 * As in WordPress, add_option() refuses an existing key and update_option()
 * creates a missing one; a stand-in more permissive than the real functions
 * would hide the vault's create-vs-update distinction (UC-3).
 */
trait OptionStoreTrait {

	/**
	 * The options table.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Every add_option()/update_option() call: function, key and autoload argument.
	 *
	 * @var array<int, array{fn: string, key: string, autoload: mixed}>
	 */
	private array $option_writes = array();

	/**
	 * Installs the stubs over an initial table.
	 *
	 * @param array<string, mixed> $initial Initial option rows.
	 * @return void
	 */
	private function stub_option_store( array $initial = array() ): void {
		$this->options       = $initial;
		$this->option_writes = array();

		Functions\when( 'get_option' )->alias(
			fn ( $key, $default_value = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default_value
		);
		Functions\when( 'add_option' )->alias(
			function ( $key, $value = '', $deprecated = '', $autoload = null ) {
				$this->option_writes[] = array(
					'fn'       => 'add_option',
					'key'      => $key,
					'autoload' => $autoload,
				);
				if ( array_key_exists( $key, $this->options ) ) {
					return false;
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				$this->option_writes[] = array(
					'fn'       => 'update_option',
					'key'      => $key,
					'autoload' => $autoload,
				);
				$this->options[ $key ] = $value;
				return true;
			}
		);
	}
}
