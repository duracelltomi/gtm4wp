<?php
/**
 * Optional contract for an admin schema that has a documentation page.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Declares where a module's documentation lives, rendered as a link in the
 * panel header. Separate from AdminSchemaInterface on purpose: a method added
 * there is a load-time fatal for every third party schema written before it,
 * so optional capabilities are separate interfaces checked with instanceof.
 * Per-option links travel on the Field's $doc.
 */
interface DocumentedSchemaInterface {

	/**
	 * Documentation path of the module, relative to the base URL in
	 * \GTM4WP\Admin\Docs, without a fragment. Empty for no page yet (no link
	 * rather than a broken one).
	 *
	 * @return string
	 */
	public function doc_url(): string;
}
