<?php
/**
 * Module contract.
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
 * Contract of a GTM4WP feature module.
 *
 * Implementations must stay lean and frontend-safe: no translated strings,
 * no field metadata, no admin logic. Everything admin-shaped lives in the
 * sibling AdminSchema class which is referenced by class name only via
 * admin_schema() so it is never autoloaded on frontend requests.
 */
interface ModuleInterface {

	/**
	 * Unique module id, used as the REST/admin navigation key.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Default values of every option this module owns.
	 *
	 * Keys are the 1.x compatible option keys (GTM4WP_OPTION_* constant
	 * values). A registry unit test asserts these keys exactly match the
	 * module's AdminSchema field keys.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array;

	/**
	 * Whether the module can operate in the current environment
	 * (required plugin active, minimum dependency version, etc.).
	 *
	 * Called before frontend() and used by the admin UI to render
	 * unavailable modules as disabled cards.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Registers all frontend hooks of the module.
	 *
	 * Only called on frontend requests when the module is available.
	 * Implementations should check their own enabling option(s) and
	 * return early without registering anything when disabled.
	 *
	 * @param Options $options The plugin options service.
	 * @return void
	 */
	public function frontend( Options $options ): void;

	/**
	 * Fully qualified class name of the module's AdminSchema.
	 *
	 * Returned as a string on purpose: the class is only autoloaded by
	 * admin/REST code paths, never on the frontend.
	 *
	 * @return string Class name implementing AdminSchemaInterface.
	 */
	public function admin_schema(): string;
}
