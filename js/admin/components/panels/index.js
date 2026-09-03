/**
 * Custom module panels, keyed by the `panel` name a module's PHP AdminSchema
 * declares (PanelSchemaInterface::PANEL). ModulePanel renders the matching
 * component in place of the option fields; a module naming a panel this map
 * does not know falls back to its fields, so a stale bundle degrades to an
 * empty panel rather than a blank screen.
 */

import ServiceAccountsPanel from './ServiceAccountsPanel';

const panels = {
	'google-service-accounts': ServiceAccountsPanel,
};

/**
 * @param {string} name Panel name from the module descriptor.
 * @return {Function|null} The panel component, or null when none is registered.
 */
export function panelComponent( name ) {
	return Object.prototype.hasOwnProperty.call( panels, name )
		? panels[ name ]
		: null;
}
