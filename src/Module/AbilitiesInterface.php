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
 * category of the WordPress Abilities API (collected by Abilities\Registrar,
 * which walks the module registry inside wp_abilities_api_init). A separate
 * opt-in interface for the reason DocumentedSchemaInterface gives; on the
 * admin schema, not the module, because an ability's label and description
 * are translated strings and the module stays free of them. The same
 * collector-plus-opt-in shape as SiteHealthInfoInterface, so a third-party
 * module registered through 'gtm4wp_register_modules' lands under the same
 * category and the same off switches as the built-in ones.
 *
 * The abilities themselves live in a class of the module implementing
 * Abilities\ProviderInterface; this method only hands it over. What every
 * ability must keep - the settings capability as its permission callback, a
 * strict and defaulted input schema, the meta triple from Abilities\Meta - is
 * pinned for every registered ability by tests/unit/Abilities/ContractTest.php.
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
