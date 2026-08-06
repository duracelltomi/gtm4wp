/**
 * Left pane navigation of the GTM4WP admin app.
 */

import { SearchControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { moduleMatchesSearch } from '../utils';

export default function Sidebar( {
	modules,
	activeModuleId,
	onSelect,
	search,
	onSearch,
	dirtyModules,
} ) {
	const visibleModules = modules.filter( ( module ) =>
		moduleMatchesSearch( module, search )
	);

	return (
		<nav
			className="gtm4wp-sidebar"
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
			<ul>
				{ visibleModules.map( ( module ) => (
					<li key={ module.id }>
						<button
							type="button"
							className={ [
								'gtm4wp-sidebar__item',
								module.id === activeModuleId ? 'is-active' : '',
								module.available ? '' : 'is-unavailable',
							]
								.filter( Boolean )
								.join( ' ' ) }
							onClick={ () => onSelect( module.id ) }
						>
							<span>{ module.title }</span>
							{ dirtyModules.includes( module.id ) && (
								<span
									className="gtm4wp-sidebar__dirty"
									aria-label={ __(
										'Unsaved changes',
										'duracelltomi-google-tag-manager'
									) }
								>
									●
								</span>
							) }
						</button>
					</li>
				) ) }
			</ul>
			{ 0 === visibleModules.length && (
				<p className="gtm4wp-sidebar__empty">
					{ __(
						'No settings match your search.',
						'duracelltomi-google-tag-manager'
					) }
				</p>
			) }
		</nav>
	);
}
