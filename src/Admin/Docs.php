<?php
/**
 * Documentation link builder for the settings screen.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the documentation paths carried by the admin schemas into absolute
 * gtm4wp.com URLs. The page path belongs to the website and can be renamed;
 * the anchor is always the option key (frozen 1.x public API), so it cannot
 * drift. A fragment the page does not define is harmless, which lets the
 * anchors be added to gtm4wp.com page by page. Admin-only.
 */
final class Docs {

	/**
	 * Base URL every path resolves against: the single place the documentation
	 * domain is written (registered in the upstream checklist).
	 */
	private const BASE = 'https://gtm4wp.com/';

	/**
	 * Builds the absolute documentation URL for a path, optionally deep linking
	 * to the section documenting one option.
	 *
	 * @param string $path   Path relative to self::BASE, no leading slash, no fragment; '' means no
	 *                       documentation. An absolute http(s) URL is used as given.
	 * @param string $anchor Fragment to append, always an option key; '' for a module-level link.
	 * @return string Absolute URL, or '' when there is nothing to link to. Escape at output.
	 */
	public static function url( string $path, string $anchor = '' ): string {
		if ( '' === $path ) {
			return '';
		}

		// A third party schema may name its own site with an absolute URL;
		// resolving it against BASE would go nowhere. The built-in schemas are
		// held to the path form by ModuleConsistencyTest, so BASE stays the one
		// place the domain is written.
		$absolute = 1 === preg_match( '#^https?://#i', $path );

		$url = $absolute
			? $path
			: self::BASE . ltrim( $path, '/' );

		// An absolute URL carrying its own fragment keeps it: a browser resolves
		// the FIRST fragment, so appending the key would land on the wrong section.
		if ( '' !== $anchor && ! ( $absolute && str_contains( $path, '#' ) ) ) {
			$url .= '#' . rawurlencode( $anchor );
		}

		/**
		 * Filters the documentation URL of a module or an option, so a third
		 * party module can resolve against its own site. Returning '' removes
		 * the help link. Validated afterwards: only http(s) survives.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url    The absolute URL built from the path and the anchor.
		 * @param string $path   The documentation path as declared by the schema.
		 * @param string $anchor The fragment, an option key, or '' for a module link.
		 */
		$url = (string) apply_filters( 'gtm4wp_admin_doc_url', $url, $path, $anchor );

		// Reaches a React href: a javascript: URL must not get that far.
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}
}
