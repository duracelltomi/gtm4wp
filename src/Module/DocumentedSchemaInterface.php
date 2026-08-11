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
 * Declares where the documentation of a whole module lives, which the settings
 * screen renders as a link in the module panel header.
 *
 * Separate from AdminSchemaInterface on purpose. Adding a method to that
 * interface would be a fatal at class-load time for any third party schema
 * written against an earlier build - a class that implements an interface
 * without one of its methods does not fail when it is called, it fails when it
 * is loaded. SettingsPage checks for this one with instanceof, so an existing
 * schema keeps working untouched and opts in when it wants a header link.
 *
 * The per-option links need nothing here: they travel on the Field's $doc.
 */
interface DocumentedSchemaInterface {

	/**
	 * Documentation path of the module, relative to the base URL in
	 * \GTM4WP\Admin\Docs and without a fragment - a module link points at the
	 * whole page, not at one option's section.
	 *
	 * Return an empty string for a module that has no page yet; the panel then
	 * shows no header link rather than a broken one.
	 *
	 * @return string
	 */
	public function doc_url(): string;
}
