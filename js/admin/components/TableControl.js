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

// A stored checkbox cell is the canonical string '1' (on) or '' (off); older
// or programmatic values may arrive as booleans, so normalize them all.
function isChecked( value ) {
	return value === true || value === '1' || value === 1;
}

// Repeats the column header inside the cell. It is hidden while the header row
// is on screen and takes over from it in the stacked mobile layout, where a
// six-column grid does not fit. Hidden from assistive tech either way: the
// control it labels already names its column and row in its `aria-label`, and
// the header row is what carries the column for a screen reader on desktop.
function CellLabel( { column } ) {
	return (
		<span className="gtm4wp-table__cell-label" aria-hidden="true">
			{ column.label }
		</span>
	);
}

// Whether a text cell breaks its column's `pattern` - an anchored regex source
// string the PHP schema builds from the same constant its save-time sanitizer
// enforces, so this marks a value while typing without a second copy of the
// rule. Matching is trimmed and case-insensitive because the sanitizers
// normalize whitespace and letter case before validating: everything accepted
// here is exactly what a save would accept. An empty cell is never marked
// (emptiness is the sanitizer's call - it may drop the row or refuse the
// save), and a pattern the browser cannot compile disables the hint rather
// than break the table - the server still validates.
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
 * Whether a row still holds only what a fresh one starts with.
 *
 * Such a row is the "Add row" state nobody typed into, and removing it throws
 * nothing away - so it needs no confirmation. The same rule the save-time
 * sanitizer uses when it drops an untouched row silently, applied here so the
 * two ends agree on what an empty row is.
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
		// A select column may name the value a fresh row starts with (a type
		// column with one meaningful choice); everything else starts empty.
		row[ column.key ] =
			'string' === typeof column.default ? column.default : '';
	} );

	return row;
}

// Options of a select column: its choices map plus a leading empty choice so
// an unset cell shows as "pick one" instead of silently displaying (and then
// saving) the first choice.
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

	// The row set is fixed outside this screen (a wp-config.php constant), so the
	// list cannot be added to or thinned out here — the field description names
	// the constant that decides it.
	const rowsLocked = Boolean( field.rows_locked );

	// A locked cell is rejected here as well, not only rendered read-only:
	// `readOnly` reaches the input through the component library's prop
	// pass-through, and this screen runs against every WordPress version the
	// plugin supports — the lock must not depend on that.
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

	// Index of the row whose removal is waiting for a second click, or null.
	// Only ever one: asking about a second row replaces the question rather
	// than stacking two of them.
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

	// A row someone filled in is a piece of configuration - a container id, a
	// destination and the service account it authenticates with - and the
	// trash icon sits at the end of the row the pointer is already travelling
	// along. Asking once costs a click; not asking costs whatever was typed,
	// with no undo on the screen. A row nobody typed into is removed straight
	// away: there is nothing to lose and confirming it would only train the
	// habit of dismissing the question.
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
										// A checkbox column can depend on another
										// cell (e.g. omitting the container ID
										// only makes sense with a custom path):
										// keep it disabled and unchecked until
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
