/**
 * Maps a GTM4WP field definition to a @wordpress/components control.
 */

import {
	CheckboxControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const PHASE_LABELS = {
	beta: __( 'Beta', 'duracelltomi-google-tag-manager' ),
	experimental: __( 'Experimental', 'duracelltomi-google-tag-manager' ),
	deprecated: __( 'Deprecated', 'duracelltomi-google-tag-manager' ),
};

function FieldLabel( { field } ) {
	return (
		<span className="gtm4wp-field-label">
			{ field.label }
			{ PHASE_LABELS[ field.phase ] && (
				<span className={ `gtm4wp-phase gtm4wp-phase--${ field.phase }` }>
					{ PHASE_LABELS[ field.phase ] }
				</span>
			) }
		</span>
	);
}

function FieldHelp( { field, error } ) {
	return (
		<>
			{ error && <span className="gtm4wp-field-error">{ error }</span> }
			<RawHTML className="gtm4wp-field-help">{ field.description }</RawHTML>
		</>
	);
}

export default function FieldControl( { field, value, error, onChange } ) {
	const help = <FieldHelp field={ field } error={ error } />;
	const label = <FieldLabel field={ field } />;

	switch ( field.type ) {
		case 'checkbox':
			return (
				<ToggleControl
					__nextHasNoMarginBottom
					checked={ Boolean( value ) }
					label={ label }
					help={ help }
					onChange={ ( next ) => onChange( next ) }
				/>
			);

		case 'integer':
			return (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					label={ label }
					help={ help }
					value={ String( value ?? '' ) }
					onChange={ ( next ) => onChange( Number( next ) ) }
				/>
			);

		case 'select':
			return (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					help={ help }
					value={ String( value ?? '' ) }
					options={ Object.entries( field.choices ).map(
						( [ choiceValue, choiceLabel ] ) => ( {
							value: choiceValue,
							label: choiceLabel,
						} )
					) }
					onChange={ ( next ) => onChange( next ) }
				/>
			);

		case 'multiselect': {
			const selected = Array.isArray( value ) ? value : [];
			const toggle = ( choiceValue, checked ) => {
				const next = checked
					? [ ...selected, choiceValue ]
					: selected.filter( ( entry ) => entry !== choiceValue );
				onChange( next );
			};

			return (
				<fieldset className="gtm4wp-multiselect">
					<legend className="gtm4wp-field-label">{ label }</legend>
					{ help }
					<div className="gtm4wp-multiselect__choices">
						{ Object.entries( field.choices ).map(
							( [ choiceValue, choiceLabel ] ) => (
								<CheckboxControl
									__nextHasNoMarginBottom
									key={ choiceValue }
									label={ choiceLabel }
									checked={ selected.includes( choiceValue ) }
									onChange={ ( checked ) =>
										toggle( choiceValue, checked )
									}
								/>
							)
						) }
					</div>
				</fieldset>
			);
		}

		case 'textarea':
			return (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ label }
					help={ help }
					value={ String( value ?? '' ) }
					onChange={ ( next ) => onChange( next ) }
				/>
			);

		default:
			return (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					help={ help }
					value={ String( value ?? '' ) }
					onChange={ ( next ) => onChange( next ) }
				/>
			);
	}
}
