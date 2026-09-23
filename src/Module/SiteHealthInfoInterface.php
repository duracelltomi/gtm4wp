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
 * Declares that a module contributes rows to the plugin's section under Tools
 * -> Site Health -> Info (assembled by Admin\SiteHealthInfo). A separate
 * opt-in interface for the reason DocumentedSchemaInterface gives; on the
 * admin schema, not the module, because the rows carry translated labels.
 *
 * ⛔ The Info tab is pasted wholesale into public support threads. Rows carry
 * option states, statuses, timestamps, counts and bare code names, nothing
 * else: no credential or key material, no account addresses, no raw
 * third-party error text, no visitor data, no identifier not already in the
 * site's public HTML. The test is whether a stranger may read it.
 */
interface SiteHealthInfoInterface {

	/**
	 * Rows for the plugin's Site Health Info section, in the shape of the
	 * `debug_information` filter: `label`, `value` (a string, or key => string
	 * sub-lines), `debug` (the English twin of the value: the copied text
	 * prints the row KEY and this, so keys read as names and never '' - build
	 * rows with Admin\SiteHealthRows) and optionally `private`. Keys are
	 * prefixed with the module id by the collector. Called only when the page
	 * renders, so a database read is affordable; an HTTP request is not.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<string, array<string, mixed>> Rows keyed by a module-local id; empty for nothing to report.
	 */
	public function site_health_info( Options $options ): array;
}
