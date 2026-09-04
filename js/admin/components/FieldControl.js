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
import { Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import AxeptioVersionControl from './AxeptioVersionControl';
import TableControl from './TableControl';
import { choiceSections, dependencyLabel, isFieldDisabled } from '../utils';

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
				<span
					className={ `gtm4wp-phase gtm4wp-phase--${ field.phase }` }
				>
					{ PHASE_LABELS[ field.phase ] }
				</span>
			) }
		</span>
	);
}

/**
 * Explains a control that is greyed out because of `depends_on`.
 *
 * The stored value is always shown as it is, so a setting saved as ON but
 * unable to run appears as a greyed-on control - which on its own reads as
 * "active, you just cannot change it here", the opposite of what is true. The
 * two states therefore get different sentences: one says the setting is not in
 * effect despite being on, the other says what to do first.
 *
 * @param {Object}  props           Component props.
 * @param {boolean} props.disabled  Whether the control is disabled by a dependency.
 * @param {*}       props.value     The stored value of the dependent field.
 * @param {string}  props.dependsOn Label of the field it depends on.
 * @return {JSX.Element|null} The note, or null when there is nothing to explain.
 */
function DependencyNote( { disabled, value, dependsOn } ) {
	if ( ! disabled || ! dependsOn ) {
		return null;
	}

	const isOn = Array.isArray( value ) ? 0 < value.length : Boolean( value );

	return (
		<span className="gtm4wp-field-dependency">
			{ isOn
				? sprintf(
						/* translators: %s: label of the setting this one depends on. */
						__(
							'Saved as on, but not in effect: it needs %s.',
							'duracelltomi-google-tag-manager'
						),
						dependsOn
				  )
				: sprintf(
						/* translators: %s: label of the setting this one depends on. */
						__(
							'Available once %s is set.',
							'duracelltomi-google-tag-manager'
						),
						dependsOn
				  ) }
		</span>
	);
}

function FieldHelp( { field, error, disabled, value, dependsOn } ) {
	return (
		<>
			{ error && <span className="gtm4wp-field-error">{ error }</span> }
			<DependencyNote
				disabled={ disabled }
				value={ value }
				dependsOn={ dependsOn }
			/>
			{ /*
			   Descriptions carry markup (links, <code>, <br />), so they go in as
			   HTML - trusted because every dynamic part is esc_html()'d in the
			   module's AdminSchema (PA-13). A <span> rather than RawHTML: every
			   @wordpress/components control renders its `help` slot inside a <p>,
			   and RawHTML always wraps in a <div>, which React rejects there.
			   Descriptions must stay phrasing content for the same reason.
			*/ }
			<span
				className="gtm4wp-field-help"
				dangerouslySetInnerHTML={ {
					__html: field.description ?? '',
				} }
			/>
		</>
	);
}

export default function FieldControl( {
	field,
	value,
	values,
	error,
	allFields,
	onChange,
} ) {
	// When a field declares `depends_on`, its control is disabled while the
	// field it points at is off/empty (e.g. "Include parent categories"
	// depends on "Category list"). The stored value is shown as-is (greyed),
	// never forced off, so the display never disagrees with what would be
	// saved, and re-satisfying the dependency does not appear to flip the
	// setting on by itself. The frontend module still guards the value
	// independently.
	//
	// Greying alone is not enough to be honest about it, though: a setting
	// saved as ON that cannot run looks identical to one that is running, so
	// the note below says which of the two it is.
	const disabled = isFieldDisabled( field, values );

	const help = (
		<FieldHelp
			field={ field }
			error={ error }
			disabled={ disabled }
			value={ value }
			dependsOn={ dependencyLabel( field, allFields ) }
		/>
	);
	const label = <FieldLabel field={ field } />;

	switch ( field.type ) {
		case 'axeptio-version':
			return (
				<AxeptioVersionControl
					value={ value }
					allValues={ values }
					label={ label }
					help={ help }
					disabled={ disabled }
					onChange={ ( next ) => onChange( next ) }
				/>
			);

		case 'checkbox':
			return (
				<ToggleControl
					__nextHasNoMarginBottom
					checked={ Boolean( value ) }
					disabled={ disabled }
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
					disabled={ disabled }
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
					disabled={ disabled }
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

			// Toggling always rewrites the WHOLE selection, so a choice in one
			// section can never drop the choices of another: the sections are a
			// rendering of one flat option value, not four values.
			const toggle = ( choiceValue, checked ) => {
				const next = checked
					? [ ...selected, choiceValue ]
					: selected.filter( ( entry ) => entry !== choiceValue );
				onChange( next );
			};

			const choices = ( entries ) => (
				<div className="gtm4wp-multiselect__choices">
					{ entries.map( ( [ choiceValue, choiceLabel ] ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ choiceValue }
							label={ choiceLabel }
							disabled={ disabled }
							checked={ selected.includes( choiceValue ) }
							onChange={ ( checked ) =>
								toggle( choiceValue, checked )
							}
						/>
					) ) }
				</div>
			);

			return (
				<fieldset className="gtm4wp-multiselect">
					<legend className="gtm4wp-field-label">{ label }</legend>
					{ help }
					{ choiceSections( field ).map( ( section, index ) => {
						// An unlabelled section is the whole list of a field that
						// declares no sections - render it bare, exactly as before.
						if ( '' === section.label ) {
							return (
								<Fragment key={ `section-${ index }` }>
									{ choices( section.entries ) }
								</Fragment>
							);
						}

						const checkedCount = section.entries.filter(
							( [ choiceValue ] ) =>
								selected.includes( choiceValue )
						).length;

						// Open by default: collapsing is there to let somebody put
						// a section away, not to hide options behind a click.
						return (
							<details
								className="gtm4wp-multiselect__section"
								key={ `section-${ index }` }
								open
							>
								<summary className="gtm4wp-multiselect__section-title">
									{ section.label }
									<span
										className="gtm4wp-multiselect__section-count"
										aria-label={ sprintf(
											/* translators: 1: number of selected entries, 2: number of entries in the group. */
											__(
												'%1$d of %2$d selected',
												'duracelltomi-google-tag-manager'
											),
											checkedCount,
											section.entries.length
										) }
									>
										{ `${ checkedCount } / ${ section.entries.length }` }
									</span>
								</summary>
								{ choices( section.entries ) }
							</details>
						);
					} ) }
				</fieldset>
			);
		}

		case 'table':
			return (
				<TableControl
					field={ field }
					value={ value }
					label={ label }
					help={ help }
					disabled={ disabled }
					onChange={ onChange }
				/>
			);

		case 'textarea':
			return (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ label }
					help={ help }
					disabled={ disabled }
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
					disabled={ disabled }
					value={ String( value ?? '' ) }
					onChange={ ( next ) => onChange( next ) }
				/>
			);
	}
}
