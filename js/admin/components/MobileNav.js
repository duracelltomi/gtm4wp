/**
 * Navigation of the admin app on a phone: the left pane's module list as a
 * search field and a dropdown, pinned to the top. Rendered next to `Sidebar`
 * with the stylesheet showing exactly one (`display: none` keeps the other
 * out of the accessibility tree), so nothing watches the window for resizes.
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

	// The open module stays listed when the search no longer matches it: a
	// `select` whose value is not an option shows blank.
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
					// An option can hold no dot or `aria-label`: the marker is text.
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
