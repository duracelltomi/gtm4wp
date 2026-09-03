<?php
/**
 * Optional contract for an admin schema rendered by a custom panel.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Declares that a module's settings screen is not (only) a list of Field
 * controls but a dedicated React component with its own REST routes - the
 * service-accounts manager is the first one.
 *
 * Opt-in through a separate interface for the reason DocumentedSchemaInterface
 * gives: a method added to AdminSchemaInterface is a load-time fatal for every
 * third party schema written before it. SettingsPage checks this one with
 * instanceof and hands the panel id and its data to the app alongside the
 * fields, so a schema can mix both.
 */
interface PanelSchemaInterface {

	/**
	 * Id of the React panel component that renders this module, as known to
	 * the admin app. Unknown ids render nothing beyond the fields.
	 *
	 * @return string
	 */
	public function panel(): string;

	/**
	 * Data the panel component needs at boot: REST paths, limits, labels.
	 * Scalars and arrays only - it travels as JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function panel_data(): array;
}
