/**
 * Editable data table control for `table` type fields: one row per entry,
 * one text input per schema-defined column, with add/remove row actions.
 */

import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { sprintf, __ } from '@wordpress/i18n';

import { isCellLocked } from '../utils';

// A stored checkbox cell is '1' or ''; booleans may arrive too.
function isChecked( value ) {
	return value === true || value === '1' || value === 1;
}

// Repeats the column header inside the cell for the stacked mobile layout;
// hidden from assistive tech (the control's `aria-label` already names it).
function CellLabel( { column } ) {
	return (
		<span className="gtm4wp-table__cell-label" aria-hidden="true">
			{ column.label }
		</span>
	);
}

// Whether a text cell breaks its column's `pattern`, the anchored regex the
// PHP schema builds from the same constant its sanitizer enforces (no second
// copy of the rule). Trimmed and case-insensitive like the sanitizers. An
// empty cell is never marked (the sanitizer's call); an uncompilable pattern
// disables the hint, the server still validates.
function violatesPattern( column, value ) {
	const trimmed = value.trim();

	if ( ! column.pattern || '' === trimmed ) {
		return false;
	}

	try {
		return ! new RegExp( column.pattern, 'i' ).test( trimmed );
	} catch ( error ) {
		return false;
	}
}

/**
 * Whether a row still holds only what a fresh one starts with (removing it
 * needs no confirmation); the same rule the sanitizer uses to drop one.
 *
 * @param {Object} row     The row to judge.
 * @param {Array}  columns Column definitions.
 * @return {boolean} Whether the row carries nothing the user entered.
 */
function isUntouched( row, columns ) {
	const fresh = emptyRow( columns );

	return columns.every(
		( column ) => ( row?.[ column.key ] ?? '' ) === fresh[ column.key ]
	);
}

function emptyRow( columns ) {
	const row = {};

	columns.forEach( ( column ) => {
		// A select column may name a fresh row's starting value.
		row[ column.key ] =
			'string' === typeof column.default ? column.default : '';
	} );

	return row;
}

// Choices plus a leading empty option, so an unset cell is not silently
// shown (and saved) as the first choice.
function selectOptions( column ) {
	const choices = column.choices || {};

	return [
		{
			value: '',
			label: __( '— Select —', 'duracelltomi-google-tag-manager' ),
		},
		...Object.keys( choices ).map( ( value ) => ( {
			value,
			label: String( choices[ value ] ),
		} ) ),
	];
}

export default function TableControl( {
	field,
	value,
	label,
	help,
	disabled = false,
	onChange,
} ) {
	const columns = Array.isArray( field.columns ) ? field.columns : [];
	const rows = Array.isArray( value ) ? value : [];

	// The row set is fixed by a wp-config.php constant (named in the field
	// description).
	const rowsLocked = Boolean( field.rows_locked );

	// Rejected here too, not only rendered read-only: the `readOnly` prop
	// pass-through cannot be trusted across the supported WordPress range.
	const updateCell = ( rowIndex, column, next ) => {
		if ( isCellLocked( field, column ) ) {
			return;
		}

		onChange(
			rows.map( ( row, index ) =>
				index === rowIndex ? { ...row, [ column.key ]: next } : row
			)
		);
	};

	// Row whose removal awaits a second click, or null; only ever one.
	const [ pendingRemoval, setPendingRemoval ] = useState( null );

	const addRow = () => {
		if ( rowsLocked ) {
			return;
		}

		onChange( [ ...rows, emptyRow( columns ) ] );
	};

	const removeRow = ( rowIndex ) => {
		if ( rowsLocked ) {
			return;
		}

		setPendingRemoval( null );
		onChange( rows.filter( ( row, index ) => index !== rowIndex ) );
	};

	// A filled row is configuration with no undo, so ask once; an untouched
	// row is removed straight away (confirming it would train dismissal).
	const requestRemoval = ( rowIndex ) => {
		if ( rowsLocked ) {
			return;
		}

		if ( isUntouched( rows[ rowIndex ], columns ) ) {
			removeRow( rowIndex );

			return;
		}

		setPendingRemoval( rowIndex );
	};

	return (
		<fieldset className="gtm4wp-table">
			<legend className="gtm4wp-field-label">{ label }</legend>
			{ help }
			<div className="gtm4wp-table__scroll">
				<table className="gtm4wp-table__table">
					<thead>
						<tr>
							{ columns.map( ( column ) => (
								<th key={ column.key } scope="col">
									{ column.label }
								</th>
							) ) }
							<th scope="col">
								<span className="screen-reader-text">
									{ __(
										'Actions',
										'duracelltomi-google-tag-manager'
									) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ 0 === rows.length && (
							<tr>
								<td
									className="gtm4wp-table__empty"
									colSpan={ columns.length + 1 }
								>
									{ __(
										'No rows added yet.',
										'duracelltomi-google-tag-manager'
									) }
								</td>
							</tr>
						) }
						{ rows.map( ( row, rowIndex ) => (
							<tr key={ rowIndex }>
								{ columns.map( ( column ) => {
									const cellLabel = sprintf(
										/* translators: 1: column label. 2: row number. */
										__(
											'%1$s, row %2$d',
											'duracelltomi-google-tag-manager'
										),
										column.label,
										rowIndex + 1
									);

									const locked = isCellLocked(
										field,
										column
									);

									if ( 'checkbox' === column.type ) {
										// A checkbox column depending on another
										// cell stays disabled and unchecked until
										// that cell is filled in.
										const enabled =
											! column.depends_on ||
											'' !==
												String(
													row[ column.depends_on ] ??
														''
												);

										return (
											<td key={ column.key }>
												<CellLabel column={ column } />
												<CheckboxControl
													__nextHasNoMarginBottom
													aria-label={ cellLabel }
													disabled={
														disabled ||
														locked ||
														! enabled
													}
													checked={
														enabled &&
														isChecked(
															row[ column.key ]
														)
													}
													onChange={ ( next ) =>
														updateCell(
															rowIndex,
															column,
															next ? '1' : ''
														)
													}
												/>
											</td>
										);
									}

									if ( 'select' === column.type ) {
										return (
											<td key={ column.key }>
												<CellLabel column={ column } />
												<SelectControl
													__next40pxDefaultSize
													__nextHasNoMarginBottom
													hideLabelFromVision
													label={ cellLabel }
													disabled={
														disabled || locked
													}
													options={ selectOptions(
														column
													) }
													value={ String(
														row[ column.key ] ?? ''
													) }
													onChange={ ( next ) =>
														updateCell(
															rowIndex,
															column,
															next
														)
													}
												/>
											</td>
										);
									}

									const cellValue = String(
										row[ column.key ] ?? ''
									);
									const invalid =
										! locked &&
										violatesPattern( column, cellValue );

									let cellClass;
									if ( locked ) {
										cellClass =
											'gtm4wp-table__cell--locked';
									} else if ( invalid ) {
										cellClass =
											'gtm4wp-table__cell--invalid';
									}

									return (
										<td key={ column.key }>
											<CellLabel column={ column } />
											<TextControl
												__next40pxDefaultSize
												__nextHasNoMarginBottom
												hideLabelFromVision
												label={ cellLabel }
												className={ cellClass }
												disabled={ disabled }
												readOnly={ locked }
												placeholder={
													column.placeholder
												}
												help={
													invalid
														? column.invalid_message ||
														  __(
																'This value is not valid.',
																'duracelltomi-google-tag-manager'
														  )
														: undefined
												}
												value={ cellValue }
												onChange={ ( next ) =>
													updateCell(
														rowIndex,
														column,
														next
													)
												}
											/>
										</td>
									);
								} ) }
								<td className="gtm4wp-table__actions">
									{ pendingRemoval === rowIndex ? (
										<>
											<Button
												variant="primary"
												isDestructive
												disabled={
													disabled || rowsLocked
												}
												label={ sprintf(
													/* translators: %d: row number. */
													__(
														'Confirm removing row %d',
														'duracelltomi-google-tag-manager'
													),
													rowIndex + 1
												) }
												onClick={ () =>
													removeRow( rowIndex )
												}
											>
												{ __(
													'Remove',
													'duracelltomi-google-tag-manager'
												) }
											</Button>
											<Button
												variant="tertiary"
												disabled={
													disabled || rowsLocked
												}
												label={ sprintf(
													/* translators: %d: row number. */
													__(
														'Keep row %d',
														'duracelltomi-google-tag-manager'
													),
													rowIndex + 1
												) }
												onClick={ () =>
													setPendingRemoval( null )
												}
											>
												{ __(
													'Cancel',
													'duracelltomi-google-tag-manager'
												) }
											</Button>
										</>
									) : (
										<Button
											icon="trash"
											isDestructive
											disabled={ disabled || rowsLocked }
											label={ sprintf(
												/* translators: %d: row number. */
												__(
													'Remove row %d',
													'duracelltomi-google-tag-manager'
												),
												rowIndex + 1
											) }
											onClick={ () =>
												requestRemoval( rowIndex )
											}
										/>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<Button
				icon="plus"
				variant="secondary"
				disabled={ disabled || rowsLocked }
				onClick={ addRow }
			>
				{ __( 'Add row', 'duracelltomi-google-tag-manager' ) }
			</Button>
		</fieldset>
	);
}
