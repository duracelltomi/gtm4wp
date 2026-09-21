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
 * Declares that a module's settings screen is (also) a dedicated React panel
 * with its own REST routes. A separate opt-in interface for the reason
 * DocumentedSchemaInterface gives; SettingsPage checks it with instanceof and
 * a schema can mix a panel with fields.
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
	 * Data the panel component needs at boot (REST paths, limits, labels);
	 * scalars and arrays only, it travels as JSON. Called only when the settings
	 * page renders (unlike fields(), walked on every REST request), so this is
	 * the one place a schema may afford a database read.
	 *
	 * Reserved keys: `columnChoices` (option key => column key => choices,
	 * merged into table columns whose choices exist only at render time) and
	 * `panelGroup` (the accordion group this panel renders inside; omit when
	 * the panel is the whole screen or the module has one group).
	 *
	 * @return array<string, mixed>
	 */
	public function panel_data(): array;
}
