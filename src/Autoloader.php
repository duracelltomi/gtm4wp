<?php
/**
 * PSR-4 style class autoloader for the GTM4WP namespace.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP;

defined( 'ABSPATH' ) || exit;

/**
 * Maps GTM4WP\Sub\Namespace\ClassName to src/Sub/Namespace/ClassName.php.
 *
 * Shipping this tiny autoloader instead of the Composer one keeps the
 * distributed plugin free of a vendor directory. Classes are only parsed
 * when first referenced, which is the backbone of the frontend performance
 * guarantee: admin-only classes are never loaded on frontend requests.
 */
final class Autoloader {

	/**
	 * Registers the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Loads a single class file of the GTM4WP namespace.
	 *
	 * @param string $class_name Fully qualified class name being autoloaded.
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $path ) ) {
			require $path;
		}
	}
}
