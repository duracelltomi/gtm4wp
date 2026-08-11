/**
 * Help link to the gtm4wp.com documentation of a module or a single option.
 *
 * The URL is resolved server side (src/Admin/Docs.php) and arrives in the
 * bootstrap data already absolute, so there is no path building here: this
 * component only decides whether there is anything to link to and renders the
 * affordance. An empty url means the target has not been written yet, and the
 * icon is left out rather than pointing at a page that does not exist.
 *
 * Rendered as an anchor (Button does that as soon as it gets an href), which is
 * what keeps it out of the way of everything around it. Two things depend on
 * that and would break quietly if it became a button:
 *
 * - ModulePanel hands the keyboard focus to a deep-linked field by looking for
 *   the first input/textarea/select/button inside the row, and an anchor is
 *   deliberately none of those;
 * - the icon sits next to a control whose own label is a <label> element, so an
 *   interactive element placed inside that label would toggle the setting when
 *   clicked. It is rendered as a sibling of the control for that reason, never
 *   through a control's `label` prop.
 */

import { Button } from '@wordpress/components';

export default function DocLink( { url, label, className } ) {
	if ( ! url ) {
		return null;
	}

	return (
		<Button
			className={ className }
			href={ url }
			// A Dashicon slug rather than an @wordpress/icons import, matching
			// the two icon buttons TableControl already renders. Dashicons ship
			// with wp-admin and are a dependency of the wp-components stylesheet
			// this screen already enqueues, so this adds no package to the
			// bundle and no external contract to keep track of.
			icon="editor-help"
			label={ label }
			target="_blank"
			// Spelled out rather than left to the library: whether Button adds
			// it for a _blank target is its business and can change, and this
			// is the one prop here that is a security property.
			rel="noopener noreferrer"
		/>
	);
}
