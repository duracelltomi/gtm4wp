/**
 * Navigation of the GTM4WP admin app on a phone.
 *
 * The left pane the desktop screen navigates with is a column of twelve items:
 * laid out above the panel on a narrow screen it fills the viewport, so every
 * visit starts by scrolling past the whole module list to reach the settings.
 * This renders the same navigation as a search field and a dropdown, and it is
 * the one that gets pinned to the top of the screen - it stays roughly one
 * control tall, which the left pane never could.
 *
 * Rendered next to `Sidebar` rather than instead of it, with the stylesheet
 * showing exactly one of the two: which navigation fits is a question about the
 * viewport, and a media query answers it without this screen having to watch
 * the window and re-render on resize. Only one is ever in the accessibility
 * tree, because the other is `display: none`.
 */

import { SearchControl, SelectControl } from '@wordpress/components';
import { sprintf, __ } from '@wordpress/i18n';

import { moduleMatchesSearch } from '../utils';

export default function MobileNav( {
	modules,
	activeModuleId,
	onSelect,
	search,
	onSearch,
	dirtyModules,
} ) {
	const matching = modules.filter( ( module ) =>
		moduleMatchesSearch( module, search )
	);

	// The open module stays in the list even when the search term no longer
	// matches it. A `select` whose value is not among its options shows blank in
	// most browsers, so dropping it would leave the navigation claiming to be
	// nowhere while the panel below it is plainly showing a module.
	const options = modules.filter(
		( module ) =>
			module.id === activeModuleId ||
			matching.some( ( match ) => match.id === module.id )
	);

	return (
		<div
			className="gtm4wp-mobile-nav"
			role="navigation"
			aria-label={ __(
				'GTM4WP settings navigation',
				'duracelltomi-google-tag-manager'
			) }
		>
			<SearchControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __(
					'Search settings',
					'duracelltomi-google-tag-manager'
				) }
				value={ search }
				onChange={ onSearch }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				hideLabelFromVision
				label={ __(
					'Settings section',
					'duracelltomi-google-tag-manager'
				) }
				value={ activeModuleId }
				options={ options.map( ( module ) => ( {
					value: module.id,
					// The left pane marks unsaved changes with a coloured dot
					// and an `aria-label`; an option can hold neither, so the
					// marker has to be part of the text itself.
					label: dirtyModules.includes( module.id )
						? sprintf(
								/* translators: %s: name of a settings section. */
								__(
									'%s — unsaved changes',
									'duracelltomi-google-tag-manager'
								),
								module.title
						  )
						: module.title,
				} ) ) }
				onChange={ onSelect }
			/>
			{ 0 === matching.length && (
				<p className="gtm4wp-mobile-nav__empty">
					{ __(
						'No settings match your search.',
						'duracelltomi-google-tag-manager'
					) }
				</p>
			) }
		</div>
	);
}
