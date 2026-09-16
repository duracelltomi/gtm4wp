<?php
/**
 * Optional contract for an admin schema that reports into Site Health.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Module;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Declares that a module has state worth showing under Tools -> Site Health ->
 * Info, in the plugin's own section there. The section is one table for the
 * whole plugin; each module that opts in contributes rows to it, and
 * Admin\SiteHealthInfo assembles them.
 *
 * Opt-in through a separate interface for the reason DocumentedSchemaInterface
 * gives: a method added to AdminSchemaInterface is a load-time fatal for every
 * third party schema written before it. The collector checks this one with
 * instanceof, so a schema that does not implement it simply has no rows.
 *
 * It lives on the admin schema rather than on the module because the rows
 * carry translated labels, and the module class is loaded on frontend requests
 * where no string may be translated.
 *
 * ⛔ **What may not appear in a row.** The Info tab is copied wholesale into
 * public support threads by people who have no way to review it first. Rows
 * carry option states, statuses, timestamps, counts and bare code names, and
 * nothing else: no credential or key material in any form, no account
 * addresses, no raw error text from a third party (it can quote fragments of
 * what was sent), no visitor data, and no identifier that is not already in
 * the site's public HTML. A row that would help you debug is not the test;
 * the test is whether a stranger may read it.
 */
interface SiteHealthInfoInterface {

	/**
	 * Rows for the plugin's Site Health Info section.
	 *
	 * Each entry is one row in the shape WordPress' `debug_information` filter
	 * takes for a field: a `label`, a `value` (a string, or an array of
	 * strings for a list), and optionally `private` (true keeps the row out of
	 * the copy-to-clipboard text) and `debug` (the machine-readable form used
	 * in that text instead of `value`).
	 *
	 * Keys need only be unique within the module: the collector prefixes each
	 * with the module id, so two modules naming a row `status` do not collide.
	 *
	 * Called only when the Site Health page is rendered, so a database read is
	 * affordable here - and no HTTP request is: a Site Health page load must
	 * not depend on any remote service being reachable.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<string, array<string, mixed>> Rows keyed by a module-local id; empty for nothing to report.
	 */
	public function site_health_info( Options $options ): array;
}
