/**
 * Right pane of the GTM4WP admin app: one module rendered as tabbed groups.
 * A module with a single group skips the tab bar and renders its fields flat.
 */

import { Notice, TabPanel } from '@wordpress/components';
import { RawHTML, useCallback, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import DocLink from './DocLink';
import FieldControl from './FieldControl';
import { groupsWithFields } from '../utils';

/**
 * Accessible name of a help link. The target is named rather than left as a
 * bare "Help", because a screen reader user listing the links on this screen
 * would otherwise hear the same word up to thirty times in a row.
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
	// Brings the deep-linked field into view and hands it the keyboard focus, so
	// arriving from a notice ends on the control rather than merely on the tab
	// that holds it. A callback ref rather than an effect: it runs exactly when
	// the node appears, which is also the only moment the tab switch has settled.
	//
	// Once only. The tint stays for as long as the deep link is live, but leaving
	// the tab and coming back is the visitor moving around under their own steam
	// and it must not yank the focus out from under them a second time. The flag
	// lives here rather than in GroupFields, which is remounted per tab switch.
	const revealed = useRef( false );
	const revealFocused = useCallback( ( node ) => {
		if ( ! node || revealed.current ) {
			return;
		}

		revealed.current = true;

		// Guarded because jsdom has no layout engine and therefore no
		// scrollIntoView; the highlight and the focus below still assert.
		if ( 'function' === typeof node.scrollIntoView ) {
			node.scrollIntoView( { block: 'center' } );
		}

		const control = node.querySelector( 'input, textarea, select, button' );

		if ( control ) {
			control.focus( { preventScroll: true } );
		}
	}, [] );

	// Keeps the selected tab inside the visible part of the tab strip. A module
	// with more tabs than fit across the panel - Consent mode anywhere, any
	// multi-tab module on a phone - scrolls its strip sideways, so a tab opened
	// from a bookmark or an admin notice can start out beyond its edge: the
	// panel below shows the right settings while the strip still appears to be
	// on its first tab, with nothing marking where you actually are.
	//
	// The scroll offset is set directly rather than through scrollIntoView,
	// which would also scroll the page vertically and undo the deep-link reveal
	// above - that has just brought a field further down the page into view.
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

		// Centred where there is room; the browser clamps the first and last
		// tab back to the respective end of the strip.
		strip.scrollLeft +=
			tabBox.left - stripBox.left - ( stripBox.width - tabBox.width ) / 2;
	}, [ module.id, activeGroupId ] );

	// One definition for both branches below: an unavailable module is exactly
	// when its documentation is most worth reaching, because the panel says the
	// module cannot run and the page says what it needs.
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

	const groups = groupsWithFields( module );
	const hasTabs = groups.length > 1;

	// Only honour a requested tab that still holds fields; an initialTabName
	// TabPanel cannot match would leave it with nothing selected.
	const initialTabName = groups.some(
		( group ) => group.id === activeGroupId
	)
		? activeGroupId
		: undefined;

	// Single group: render its fields flat. No matching group at all: fall
	// back to every field so nothing is silently dropped.
	const flatFields = 1 === groups.length ? groups[ 0 ].fields : module.fields;

	return (
		<div className="gtm4wp-panel">
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

			{ hasTabs ? (
				<div ref={ tabStrip }>
					<TabPanel
						// Remount per module so the selected tab never carries over
						// to a module that has no such group.
						key={ module.id }
						className="gtm4wp-tabs"
						initialTabName={ initialTabName }
						onSelect={ onGroupSelect }
						tabs={ groups.map( ( group ) => {
							// Flag groups holding a rejected field so a hidden tab's
							// error stays visible (dot) and announced (SR text).
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
								<div className="gtm4wp-panel__body">
									<GroupFields
										fields={ group ? group.fields : [] }
										values={ values }
										errors={ errors }
										focusFieldKey={ focusFieldKey }
										revealFocused={ revealFocused }
										onChange={ onChange }
									/>
								</div>
							);
						} }
					</TabPanel>
				</div>
			) : (
				<div className="gtm4wp-panel__body">
					<GroupFields
						fields={ flatFields }
						values={ values }
						errors={ errors }
						focusFieldKey={ focusFieldKey }
						revealFocused={ revealFocused }
						onChange={ onChange }
					/>
				</div>
			) }
		</div>
	);
}
