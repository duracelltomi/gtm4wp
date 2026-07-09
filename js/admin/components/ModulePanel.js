/**
 * Right pane of the GTM4WP admin app: one module with its accordion groups.
 */

import { Notice, Panel, PanelBody } from '@wordpress/components';
import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import FieldControl from './FieldControl';

export default function ModulePanel( { module, values, errors, onChange } ) {
	if ( ! module.available ) {
		return (
			<div className="gtm4wp-panel">
				<h2>{ module.title }</h2>
				<Notice status="warning" isDismissible={ false }>
					{ module.unavailableMessage ||
						__(
							'This module is not available in your current environment.',
							'duracelltomi-google-tag-manager'
						) }
				</Notice>
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

	return (
		<div className="gtm4wp-panel">
			<h2>{ module.title }</h2>
			{ module.intro && (
				<RawHTML className="gtm4wp-panel__intro">
					{ module.intro }
				</RawHTML>
			) }
			<Panel>
				{ groupsWithFields.map( ( group, index ) => (
					<PanelBody
						key={ group.id }
						title={ group.label }
						initialOpen={ 0 === index }
					>
						{ group.fields.map( ( field ) => (
							<div
								className={ `gtm4wp-field gtm4wp-field--${ field.type }` }
								key={ field.key }
							>
								<FieldControl
									field={ field }
									value={ values[ field.key ] }
									error={ errors[ field.key ] }
									onChange={ ( next ) =>
										onChange( field.key, next )
									}
								/>
							</div>
						) ) }
					</PanelBody>
				) ) }
			</Panel>
		</div>
	);
}
