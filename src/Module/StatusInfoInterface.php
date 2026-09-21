<?php
/**
 * Optional contract for an admin schema that reports into the plugin status.
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
 * Declares that a module has a master switch and/or integrates another
 * plugin, for the per-module rows of the gtm4wp/get-status ability
 * (assembled by Abilities\StatusAbilities, which walks the module registry
 * with instanceof). A separate opt-in interface for the reason
 * DocumentedSchemaInterface gives; on the admin schema, not the module,
 * like the other opt-ins. Without it a module's row reports `enabled` and
 * `integration` as null: unknown, not off.
 *
 * The facts belong to the module: which of its options is the one switch
 * that turns the whole feature on, and which plugin it integrates. A
 * plugin-wide class listing them per module id is the deviation this
 * interface replaced, and it left every third-party module unable to report
 * either.
 *
 * ⛔ The answer ends up in an AI assistant's transcript. Option states and
 * a version string only: nothing else about the host plugin, its
 * configuration or its data.
 */
interface StatusInfoInterface {

	/**
	 * The module's status facts, any of:
	 * - `enabled` (bool): the state of the module's master switch. Omit
	 *   when the module has no single option that turns it on.
	 * - `integration` (array{active: bool, version: string|null}): whether
	 *   the plugin this module integrates is present, and its version. Omit
	 *   when the module integrates nothing. "Present" is not "usable": the
	 *   module's is_available() enforces its version floor.
	 *
	 * Called only when the ability runs, so a database read is affordable.
	 *
	 * @param Options $options The plugin options service, read fresh for the call.
	 * @return array<string, mixed>
	 */
	public function status_info( Options $options ): array;
}
