<?php
/**
 * Optional contract for an admin schema that registers abilities.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Module;

use GTM4WP\Abilities\ProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Declares that a module contributes abilities to the plugin's `gtm4wp`
 * category (collected by Abilities\Registrar inside wp_abilities_api_init).
 * The SiteHealthInfoInterface collector-plus-opt-in shape (PA-21), so a
 * third-party module lands under the same category and off switches; on the
 * admin schema, not the module, because an ability's label and description
 * are translated strings.
 *
 * The abilities live in a module class implementing
 * Abilities\ProviderInterface. What every ability must keep - the settings
 * capability as permission callback, a strict defaulted input schema, the
 * meta triple from Abilities\Meta - is pinned by Abilities\ContractTest.
 */
interface AbilitiesInterface {

	/**
	 * The provider whose register() the Registrar calls. Called only inside
	 * wp_abilities_api_init, which core fires lazily on the first ability
	 * lookup of a request, so building the provider may afford a database
	 * read (the same cost class as PanelSchemaInterface::panel_data());
	 * fields() stays database-free.
	 *
	 * @return ProviderInterface
	 */
	public function abilities(): ProviderInterface;
}
