<?php
/**
 * Module admin schema contract.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Contract of a module's admin schema.
 *
 * The admin schema is the single source of truth for the admin UI
 * (left navigation entry = module, accordion sections = field groups),
 * the REST schema and save-time sanitization. All translated strings of
 * a module belong here. Instances are only ever created inside wp-admin
 * or during REST requests.
 */
interface AdminSchemaInterface {

	/**
	 * Translated module title shown in the left navigation and panel header.
	 *
	 * @return string
	 */
	public function title(): string;

	/**
	 * Translated introduction shown at the top of the module panel.
	 * May contain limited safe HTML.
	 *
	 * @return string
	 */
	public function intro(): string;

	/**
	 * Accordion groups of the module panel: group id => translated label.
	 * Fields reference these ids in their group property. Order defines
	 * the accordion order.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array;

	/**
	 * Field definitions of every option this module owns.
	 *
	 * @return \GTM4WP\Options\Field[]
	 */
	public function fields(): array;

	/**
	 * Translated explanation shown on the disabled module card when the
	 * module is unavailable (dependency plugin missing, version too old).
	 * Return an empty string for modules that are always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string;
}
