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
	 * Called only when the settings page itself is rendered - unlike fields(),
	 * which the settings REST controller walks on every REST request - so this
	 * is the one place a schema may afford a database read.
	 *
	 * Reserved key `columnChoices`: a map of option key => column key =>
	 * choices that SettingsPage merges into the matching table columns of this
	 * module's fields, for select columns whose choices only exist at
	 * page-render time (the Data Manager destinations' service-account list).
	 *
	 * Reserved key `panelGroup`: the id of the accordion group this panel
	 * belongs under. A panel is about one group's settings, so on a module with
	 * several tabs it renders inside that tab rather than below whichever one
	 * happens to be open. Omit it when the panel is the whole screen, or when
	 * the module has a single group.
	 *
	 * @return array<string, mixed>
	 */
	public function panel_data(): array;
}
