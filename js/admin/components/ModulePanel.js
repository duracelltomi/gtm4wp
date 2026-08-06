/**
 * Right pane of the GTM4WP admin app: one module rendered as tabbed groups.
 * A module with a single group skips the tab bar and renders its fields flat.
 */

import { Notice, TabPanel } from '@wordpress/components';
import { RawHTML, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import FieldControl from './FieldControl';

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
						<FieldControl
							field={ field }
							value={ values[ field.key ] }
							values={ values }
							error={ errors[ field.key ] }
							onChange={ ( next ) => onChange( field.key, next ) }
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
	focus,
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

	if ( ! module.available ) {
		return (
			<div className="gtm4wp-panel">
				<div className="gtm4wp-panel__head">
					<h2>{ module.title }</h2>
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

	const groupsWithFields = module.groups
		.map( ( group ) => ( {
			...group,
			fields: module.fields.filter(
				( field ) => field.group === group.id
			),
		} ) )
		.filter( ( group ) => group.fields.length > 0 );

	const hasTabs = groupsWithFields.length > 1;

	// Only honour a deep link that names a group still holding fields; an
	// initialTabName TabPanel cannot match would leave it with nothing selected.
	const focusFieldKey = focus ? focus.fieldKey : undefined;
	const initialTabName = groupsWithFields.some(
		( group ) => focus && group.id === focus.groupId
	)
		? focus.groupId
		: undefined;

	// Single group: render its fields flat. No matching group at all: fall
	// back to every field so nothing is silently dropped.
	const flatFields =
		1 === groupsWithFields.length
			? groupsWithFields[ 0 ].fields
			: module.fields;

	return (
		<div className="gtm4wp-panel">
			<div className="gtm4wp-panel__head">
				<h2>{ module.title }</h2>
				{ module.intro && (
					<RawHTML className="gtm4wp-panel__intro">
						{ module.intro }
					</RawHTML>
				) }
			</div>

			{ hasTabs ? (
				<TabPanel
					// Remount per module so the selected tab never carries over
					// to a module that has no such group.
					key={ module.id }
					className="gtm4wp-tabs"
					initialTabName={ initialTabName }
					tabs={ groupsWithFields.map( ( group ) => {
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
							className: hasError ? 'gtm4wp-tab--has-error' : '',
						};
					} ) }
				>
					{ ( tab ) => {
						const group = groupsWithFields.find(
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
