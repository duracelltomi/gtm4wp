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
 * gtm4wp.com URLs for the settings screen's help links.
 *
 * The split is deliberate. A doc target has two halves and they age very
 * differently:
 *
 * - the **page path**, which lives on the Field (or on the schema's doc_url())
 *   and belongs to the website, so it can be renamed by someone editing a page;
 * - the **anchor**, which is always the option key and belongs to us. Option
 *   keys are frozen 1.x public API, so the fragment cannot drift, and it is
 *   never written down a second time next to the path it accompanies.
 *
 * A fragment the page does not define is harmless - the browser shows the page
 * top - which is what lets the anchors be added to gtm4wp.com page by page
 * without the plugin having to know how far that work has got.
 *
 * Admin-only, like the rest of src/Admin/: never loaded on a frontend request.
 */
final class Docs {

	/**
	 * Base URL every documentation path is resolved against.
	 *
	 * The single place the documentation domain is written. Registered as an
	 * external coupling in .upstream/upstream-review-checklist.md together with
	 * the paths in src/Modules/&#42;/AdminSchema.php.
	 */
	private const BASE = 'https://gtm4wp.com/';

	/**
	 * Builds the absolute documentation URL for a path, optionally deep linking
	 * to the section that documents a single option.
	 *
	 * @param string $path   Documentation path relative to self::BASE, without a leading
	 *                       slash and without a fragment. An empty path means "no
	 *                       documentation for this", and returns an empty string.
	 * @param string $anchor Fragment to append, always an option key. Empty for a
	 *                       module-level link, which points at the whole page.
	 * @return string Absolute URL, or '' when there is nothing to link to. Escape it at
	 *                the point of output.
	 */
	public static function url( string $path, string $anchor = '' ): string {
		if ( '' === $path ) {
			return '';
		}

		$url = self::BASE . ltrim( $path, '/' );

		if ( '' !== $anchor ) {
			$url .= '#' . rawurlencode( $anchor );
		}

		/**
		 * Filters the documentation URL of a module or an option.
		 *
		 * The extension point for third party modules registered through
		 * 'gtm4wp_register_modules': their schemas carry their own paths, and this
		 * filter lets them resolve against their own documentation site instead of
		 * gtm4wp.com. Returning an empty string removes the help link entirely.
		 *
		 * The result is validated after this filter runs - it ends up in an anchor
		 * href in the settings app - so only http and https URLs survive.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url    The absolute URL built from the path and the anchor.
		 * @param string $path   The documentation path as declared by the schema.
		 * @param string $anchor The fragment, an option key, or '' for a module link.
		 */
		$url = (string) apply_filters( 'gtm4wp_admin_doc_url', $url, $path, $anchor );

		// The filtered value reaches a React href, so a javascript: URL must not
		// get that far. esc_url_raw() returns '' for anything outside the list,
		// which is the same "no link" signal an empty path produces.
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}
}
