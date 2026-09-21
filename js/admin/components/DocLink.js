/**
 * Help link to the gtm4wp.com documentation of a module or an option. The
 * URL arrives absolute from src/Admin/Docs.php; an empty one means no page
 * yet, and the icon is left out. Rendered as an anchor (Button with href)
 * and as a SIBLING of the control, never through its `label` prop: the
 * deep-link focus in ModulePanel skips anchors, and an interactive element
 * inside a <label> would toggle the setting.
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
			// A Dashicon slug (like TableControl): ships with wp-admin, adds no
			// package to the bundle.
			icon="editor-help"
			label={ label }
			target="_blank"
			// Spelled out: a security property, not left to the library.
			rel="noopener noreferrer"
		/>
	);
}
