/**
 * Right pane of the GTM4WP admin app: one module rendered as tabbed groups.
 * A module with a single group skips the tab bar and renders its fields flat.
 */

import { Notice, TabPanel } from '@wordpress/components';
import { RawHTML, useCallback, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import DocLink from './DocLink';
import FieldControl from './FieldControl';
import { panelComponent } from './panels';
import { groupsWithFields } from '../utils';

/**
 * Accessible name of a help link: the target is named, so a screen reader
 * listing the links does not hear "Help" thirty times.
 *
 * @param {string} name Label of the option, or title of the module.
 * @return {string} Translated link text.
 */
function docLabel( name ) {
	return sprintf(
		/* translators: %s: name of the option or module the documentation link points at. */
		__(
			'Documentation: %s (opens in a new tab)',
			'duracelltomi-google-tag-manager'
		),
		name
	);
}

function GroupFields( {
	fields,
	allFields,
	values,
	errors,
	focusFieldKey,
	revealFocused,
	onChange,
} ) {
	return (
		<>
			{ fields.map( ( field ) => {
				const isFocused = field.key === focusFieldKey;

				return (
					<div
						className={ [
							'gtm4wp-field',
							`gtm4wp-field--${ field.type }`,
							isFocused ? 'is-focused' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						key={ field.key }
						ref={ isFocused ? revealFocused : undefined }
					>
						{ /*
						   The control is wrapped rather than made a flex item
						   directly, so the row can put the help icon beside it
						   without any control having to know it is in a row.
						   min-width:0 lives on this wrapper in the stylesheet -
						   without it the table control refuses to shrink and
						   pushes the icon off the panel.
						*/ }
						<div className="gtm4wp-field__control">
							<FieldControl
								field={ field }
								value={ values[ field.key ] }
								values={ values }
								error={ errors[ field.key ] }
								allFields={ allFields }
								onChange={ ( next ) =>
									onChange( field.key, next )
								}
							/>
						</div>

						{ /*
						   After the control in the DOM, so tabbing through a
						   panel reaches the setting before its help, and so the
						   deep-link reveal keeps finding the control first.
						*/ }
						<DocLink
							className="gtm4wp-field__doc"
							url={ field.doc }
							label={ docLabel( field.label ) }
						/>
					</div>
				);
			} ) }
		</>
	);
}

export default function ModulePanel( {
	module,
	values,
	errors,
	activeGroupId,
	focusFieldKey,
	onGroupSelect,
	onChange,
} ) {
	// Reveals and focuses the deep-linked field. A callback ref, not an
	// effect: it runs exactly when the node appears, after the tab switch has
	// settled. Once only (the flag lives here, GroupFields is remounted per
	// tab): coming back to the tab must not yank the focus again.
	const revealed = useRef( false );
	const revealFocused = useCallback( ( node ) => {
		if ( ! node || revealed.current ) {
			return;
		}

		revealed.current = true;

		// jsdom has no scrollIntoView.
		if ( 'function' === typeof node.scrollIntoView ) {
			node.scrollIntoView( { block: 'center' } );
		}

		const control = node.querySelector( 'input, textarea, select, button' );

		if ( control ) {
			control.focus( { preventScroll: true } );
		}
	}, [] );

	// Keeps the selected tab inside the visible part of a sideways-scrolling
	// strip (a tab opened from a bookmark can start beyond its edge). The
	// offset is set directly: scrollIntoView would also scroll vertically and
	// undo the deep-link reveal above.
	const tabStrip = useRef( null );

	useEffect( () => {
		const strip = tabStrip.current
			? tabStrip.current.querySelector( '.components-tab-panel__tabs' )
			: null;
		const selected = strip
			? strip.querySelector( '[aria-selected="true"]' )
			: null;

		if ( ! strip || ! selected ) {
			return;
		}

		const stripBox = strip.getBoundingClientRect();
		const tabBox = selected.getBoundingClientRect();

		// Centred; the browser clamps the ends.
		strip.scrollLeft +=
			tabBox.left - stripBox.left - ( stripBox.width - tabBox.width ) / 2;
	}, [ module.id, activeGroupId ] );

	// Shared by both branches: an unavailable module's docs matter most.
	const moduleDoc = (
		<DocLink
			className="gtm4wp-panel__doc"
			url={ module.docUrl }
			label={ docLabel( module.title ) }
		/>
	);

	if ( ! module.available ) {
		return (
			<div className="gtm4wp-panel">
				<div className="gtm4wp-panel__head">
					<div className="gtm4wp-panel__heading">
						<h2>{ module.title }</h2>
						{ moduleDoc }
					</div>
				</div>
				<div className="gtm4wp-panel__body">
					<Notice status="warning" isDismissible={ false }>
						{ module.unavailableMessage ||
							__(
								'This module is not available in your current environment.',
								'duracelltomi-google-tag-manager'
							) }
					</Notice>
				</div>
			</div>
		);
	}

	const head = (
		<div className="gtm4wp-panel__head">
			<div className="gtm4wp-panel__heading">
				<h2>{ module.title }</h2>
				{ moduleDoc }
			</div>
			{ module.intro && (
				<RawHTML className="gtm4wp-panel__intro">
					{ module.intro }
				</RawHTML>
			) }
		</div>
	);

	// A custom panel (service-account custody, the destinations test panel)
	// renders in place of the fields when there are none, below them
	// otherwise. With tabs, the schema names the panel's group so it travels
	// with its tab instead of sitting under whatever tab is open.
	const CustomPanel = module.panel ? panelComponent( module.panel ) : null;
	const hasFields =
		Array.isArray( module.fields ) && 0 < module.fields.length;
	const panelGroup = module.panelGroup || '';

	const customPanel = CustomPanel ? (
		<div className="gtm4wp-panel__body">
			<CustomPanel
				// Remount per module: a panel's own state never
				// carries over.
				key={ module.id }
				data={ module.panelData || {} }
				// Unsaved editor state, for the per-row Test button.
				values={ values }
			/>
		</div>
	) : null;

	if ( CustomPanel && ! hasFields ) {
		return (
			<div className="gtm4wp-panel">
				{ head }
				{ customPanel }
			</div>
		);
	}

	const groups = groupsWithFields( module );
	const hasTabs = groups.length > 1;

	// Inside a tab only when that tab is rendered (groupsWithFields() drops
	// an empty group); otherwise it falls back to sitting below.
	const panelInTab =
		hasTabs && groups.some( ( group ) => group.id === panelGroup );

	// An initialTabName TabPanel cannot match leaves nothing selected.
	const initialTabName = groups.some(
		( group ) => group.id === activeGroupId
	)
		? activeGroupId
		: undefined;

	// Single group: flat. No matching group: every field, nothing dropped.
	const flatFields = 1 === groups.length ? groups[ 0 ].fields : module.fields;

	return (
		<div className="gtm4wp-panel">
			{ head }

			{ hasTabs ? (
				<div ref={ tabStrip }>
					<TabPanel
						// Remount per module: the selected tab never carries over.
						key={ module.id }
						className="gtm4wp-tabs"
						initialTabName={ initialTabName }
						onSelect={ onGroupSelect }
						tabs={ groups.map( ( group ) => {
							// A hidden tab's error stays visible (dot) and announced.
							const hasError = group.fields.some( ( field ) =>
								Boolean( errors[ field.key ] )
							);

							return {
								name: group.id,
								title: hasError ? (
									<>
										{ group.label }
										<span className="screen-reader-text">
											{ __(
												'(has errors)',
												'duracelltomi-google-tag-manager'
											) }
										</span>
									</>
								) : (
									group.label
								),
								className: hasError
									? 'gtm4wp-tab--has-error'
									: '',
							};
						} ) }
					>
						{ ( tab ) => {
							const group = groups.find(
								( candidate ) => candidate.id === tab.name
							);

							return (
								<>
									<div className="gtm4wp-panel__body">
										<GroupFields
											fields={ group ? group.fields : [] }
											allFields={ module.fields }
											values={ values }
											errors={ errors }
											focusFieldKey={ focusFieldKey }
											revealFocused={ revealFocused }
											onChange={ onChange }
										/>
									</div>
									{ panelInTab &&
										panelGroup === tab.name &&
										customPanel }
								</>
							);
						} }
					</TabPanel>
				</div>
			) : (
				<div className="gtm4wp-panel__body">
					<GroupFields
						fields={ flatFields }
						allFields={ module.fields }
						values={ values }
						errors={ errors }
						focusFieldKey={ focusFieldKey }
						revealFocused={ revealFocused }
						onChange={ onChange }
					/>
				</div>
			) }

			{ /* Below everything when the panel names no rendered tab, or when
			     there are none - a module with one group renders flat. */ }
			{ ! panelInTab && customPanel }
		</div>
	);
}
