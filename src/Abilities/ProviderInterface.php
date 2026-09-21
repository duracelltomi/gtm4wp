<?php
/**
 * Contract of a class that registers abilities.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * One provider per feature area. The Registrar calls register() on each of
 * them inside the wp_abilities_api_init action, where wp_register_ability()
 * is allowed to be called.
 */
interface ProviderInterface {

	/**
	 * Registers this provider's abilities with wp_register_ability().
	 *
	 * @return void
	 */
	public function register(): void;
}
