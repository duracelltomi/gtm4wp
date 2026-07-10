/**
 * Editable data table control for `table` type fields: one row per entry,
 * one text input per schema-defined column, with add/remove row actions.
 */

import { Button, CheckboxControl, TextControl } from '@wordpress/components';
import { sprintf, __ } from '@wordpress/i18n';

// A stored checkbox cell is the canonical string '1' (on) or '' (off); older
// or programmatic values may arrive as booleans, so normalize them all.
function isChecked( value ) {
	return value === true || value === '1' || value === 1;
}

function emptyRow( columns ) {
	const row = {};

	columns.forEach( ( column ) => {
		row[ column.key ] = '';
	} );

	return row;
}

export default function TableControl( {
	field,
	value,
	label,
	help,
	onChange,
} ) {
	const columns = Array.isArray( field.columns ) ? field.columns : [];
	const rows = Array.isArray( value ) ? value : [];

	const updateCell = ( rowIndex, columnKey, next ) => {
		onChange(
			rows.map( ( row, index ) =>
				index === rowIndex ? { ...row, [ columnKey ]: next } : row
			)
		);
	};

	const addRow = () => {
		onChange( [ ...rows, emptyRow( columns ) ] );
	};

	const removeRow = ( rowIndex ) => {
		onChange( rows.filter( ( row, index ) => index !== rowIndex ) );
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
												<CheckboxControl
													__nextHasNoMarginBottom
													aria-label={ cellLabel }
													disabled={ ! enabled }
													checked={
														enabled &&
														isChecked(
															row[ column.key ]
														)
													}
													onChange={ ( next ) =>
														updateCell(
															rowIndex,
															column.key,
															next ? '1' : ''
														)
													}
												/>
											</td>
										);
									}

									return (
										<td key={ column.key }>
											<TextControl
												__next40pxDefaultSize
												__nextHasNoMarginBottom
												hideLabelFromVision
												label={ cellLabel }
												placeholder={
													column.placeholder
												}
												value={ String(
													row[ column.key ] ?? ''
												) }
												onChange={ ( next ) =>
													updateCell(
														rowIndex,
														column.key,
														next
													)
												}
											/>
										</td>
									);
								} ) }
								<td className="gtm4wp-table__actions">
									<Button
										icon="trash"
										isDestructive
										label={ sprintf(
											/* translators: %d: row number. */
											__(
												'Remove row %d',
												'duracelltomi-google-tag-manager'
											),
											rowIndex + 1
										) }
										onClick={ () => removeRow( rowIndex ) }
									/>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<Button icon="plus" variant="secondary" onClick={ addRow }>
				{ __( 'Add row', 'duracelltomi-google-tag-manager' ) }
			</Button>
		</fieldset>
	);
}
