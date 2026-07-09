/**
 * Right pane of the GTM4WP admin app: one module rendered as tabbed groups.
 * A module with a single group skips the tab bar and renders its fields flat.
 */

import { Notice, TabPanel } from '@wordpress/components';
import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import FieldControl from './FieldControl';

function GroupFields( { fields, values, errors, onChange } ) {
	return (
		<>
			{ fields.map( ( field ) => (
				<div
					className={ `gtm4wp-field gtm4wp-field--${ field.type }` }
					key={ field.key }
				>
					<FieldControl
						field={ field }
						value={ values[ field.key ] }
						error={ errors[ field.key ] }
						onChange={ ( next ) => onChange( field.key, next ) }
					/>
				</div>
			) ) }
		</>
	);
}

export default function ModulePanel( { module, values, errors, onChange } ) {
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
						onChange={ onChange }
					/>
				</div>
			) }
		</div>
	);
}
