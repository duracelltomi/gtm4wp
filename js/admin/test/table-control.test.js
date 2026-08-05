/**
 * Tests for the `table` field control (container rows).
 *
 * The load-bearing behavior here is not the rendering, it is the pair of guards
 * the component enforces IN CODE rather than through the component library:
 *
 * - `updateCell()` rejects an edit to a locked cell, and `addRow()`/`removeRow()`
 *   reject when the row set is locked. A cell is locked because a
 *   `GTM4WP_HARDCODED_*` wp-config.php constant owns that part of the container
 *   setup, so the value must not be editable here. The component's own comment
 *   says why the `readOnly`/`disabled` props are not enough: they reach the input
 *   through @wordpress/components' prop pass-through, and this screen runs against
 *   every WordPress version the plugin supports.
 *
 * Asserting only that the input renders `readOnly` would therefore test the
 * component library, not the guard — so each guard is driven through its handler
 * and asserted by `onChange` NOT being called (TS-3: assert the effect).
 */

import { fireEvent, render, screen } from '@testing-library/react';

import TableControl from '../components/TableControl';

const COLUMNS = [
	{ key: 'id', label: 'Container ID' },
	{ key: 'domain', label: 'Domain' },
];

function renderTable( overrides = {} ) {
	const onChange = jest.fn();

	const props = {
		field: { columns: COLUMNS, ...( overrides.field || {} ) },
		value: overrides.value ?? [],
		label: 'Containers',
		help: null,
		disabled: overrides.disabled ?? false,
		onChange,
	};

	render( <TableControl { ...props } /> );

	return { onChange };
}

describe( 'TableControl rendering', () => {
	it( 'renders one input per column per row', () => {
		renderTable( {
			value: [
				{ id: 'GTM-AAA', domain: 'example.com' },
				{ id: 'GTM-BBB', domain: '' },
			],
		} );

		expect( screen.getByDisplayValue( 'GTM-AAA' ) ).toBeInTheDocument();
		expect( screen.getByDisplayValue( 'example.com' ) ).toBeInTheDocument();
		expect( screen.getByDisplayValue( 'GTM-BBB' ) ).toBeInTheDocument();
	} );

	it( 'shows the empty state when there are no rows', () => {
		renderTable( { value: [] } );

		expect( screen.getByText( 'No rows added yet.' ) ).toBeInTheDocument();
	} );

	it( 'treats a non-array value as no rows rather than throwing', () => {
		expect( () => renderTable( { value: null } ) ).not.toThrow();
		expect( screen.getByText( 'No rows added yet.' ) ).toBeInTheDocument();
	} );

	it( 'treats missing columns as an empty column set rather than throwing', () => {
		expect( () =>
			renderTable( { field: { columns: undefined }, value: [] } )
		).not.toThrow();
	} );
} );

describe( 'TableControl cell editing', () => {
	it( 'updates only the edited cell of the edited row', () => {
		const { onChange } = renderTable( {
			value: [
				{ id: 'GTM-AAA', domain: 'a.example' },
				{ id: 'GTM-BBB', domain: 'b.example' },
			],
		} );

		fireEvent.change( screen.getByDisplayValue( 'GTM-BBB' ), {
			target: { value: 'GTM-ZZZ' },
		} );

		expect( onChange ).toHaveBeenCalledTimes( 1 );
		expect( onChange ).toHaveBeenCalledWith( [
			{ id: 'GTM-AAA', domain: 'a.example' },
			{ id: 'GTM-ZZZ', domain: 'b.example' },
		] );
	} );

	it( 'renders a missing cell value as an empty string, not "undefined"', () => {
		renderTable( { value: [ { id: 'GTM-AAA' } ] } );

		const inputs = screen.getAllByRole( 'textbox' );
		expect( inputs.map( ( input ) => input.value ) ).toEqual( [
			'GTM-AAA',
			'',
		] );
	} );
} );

describe( 'TableControl lock guards', () => {
	it( 'rejects an edit to a cell locked by a readonly column', () => {
		const { onChange } = renderTable( {
			field: {
				columns: [
					{ key: 'id', label: 'Container ID', readonly: true },
					{ key: 'domain', label: 'Domain' },
				],
			},
			value: [ { id: 'GTM-LOCKED', domain: 'example.com' } ],
		} );

		fireEvent.change( screen.getByDisplayValue( 'GTM-LOCKED' ), {
			target: { value: 'GTM-TAMPERED' },
		} );

		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'still allows editing an unlocked column of the same table', () => {
		const { onChange } = renderTable( {
			field: {
				columns: [
					{ key: 'id', label: 'Container ID', readonly: true },
					{ key: 'domain', label: 'Domain' },
				],
			},
			value: [ { id: 'GTM-LOCKED', domain: 'example.com' } ],
		} );

		fireEvent.change( screen.getByDisplayValue( 'example.com' ), {
			target: { value: 'changed.example' },
		} );

		expect( onChange ).toHaveBeenCalledWith( [
			{ id: 'GTM-LOCKED', domain: 'changed.example' },
		] );
	} );

	it( 'rejects an edit to any cell when the whole row set is locked', () => {
		const { onChange } = renderTable( {
			field: { columns: COLUMNS, rows_locked: true },
			value: [ { id: 'GTM-AAA', domain: 'example.com' } ],
		} );

		fireEvent.change( screen.getByDisplayValue( 'example.com' ), {
			target: { value: 'changed.example' },
		} );

		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'does not add a row when the row set is locked', () => {
		const { onChange } = renderTable( {
			field: { columns: COLUMNS, rows_locked: true },
			value: [ { id: 'GTM-AAA', domain: 'example.com' } ],
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add row' } ) );

		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'does not remove a row when the row set is locked', () => {
		const { onChange } = renderTable( {
			field: { columns: COLUMNS, rows_locked: true },
			value: [ { id: 'GTM-AAA', domain: 'example.com' } ],
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove row 1' } )
		);

		expect( onChange ).not.toHaveBeenCalled();
	} );
} );

describe( 'TableControl row actions', () => {
	it( 'appends a row with every column key initialised to an empty string', () => {
		const { onChange } = renderTable( {
			value: [ { id: 'GTM-AAA', domain: 'example.com' } ],
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add row' } ) );

		expect( onChange ).toHaveBeenCalledWith( [
			{ id: 'GTM-AAA', domain: 'example.com' },
			{ id: '', domain: '' },
		] );
	} );

	it( 'removes the row at the clicked index, not the first one', () => {
		const { onChange } = renderTable( {
			value: [
				{ id: 'GTM-AAA', domain: 'a.example' },
				{ id: 'GTM-BBB', domain: 'b.example' },
				{ id: 'GTM-CCC', domain: 'c.example' },
			],
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove row 2' } )
		);

		expect( onChange ).toHaveBeenCalledWith( [
			{ id: 'GTM-AAA', domain: 'a.example' },
			{ id: 'GTM-CCC', domain: 'c.example' },
		] );
	} );
} );

describe( 'TableControl checkbox columns', () => {
	const CHECKBOX_COLUMNS = [
		{ key: 'path', label: 'Custom path' },
		{
			key: 'noid',
			label: 'Omit container ID',
			type: 'checkbox',
			depends_on: 'path',
		},
	];

	it.each( [
		[ 'the canonical stored string', '1' ],
		[ 'a boolean true', true ],
		[ 'a numeric 1', 1 ],
	] )( 'renders as checked for %s', ( _label, stored ) => {
		renderTable( {
			field: { columns: CHECKBOX_COLUMNS },
			value: [ { path: '/gtm.js', noid: stored } ],
		} );

		expect( screen.getByRole( 'checkbox' ) ).toBeChecked();
	} );

	it( 'stores the canonical "1" / "" strings rather than booleans', () => {
		const { onChange } = renderTable( {
			field: { columns: CHECKBOX_COLUMNS },
			value: [ { path: '/gtm.js', noid: '' } ],
		} );

		fireEvent.click( screen.getByRole( 'checkbox' ) );

		expect( onChange ).toHaveBeenCalledWith( [
			{ path: '/gtm.js', noid: '1' },
		] );
	} );

	it( 'disables and unchecks a dependent checkbox while its source cell is empty', () => {
		renderTable( {
			field: { columns: CHECKBOX_COLUMNS },
			// Stored as on, but the cell it depends on is empty.
			value: [ { path: '', noid: '1' } ],
		} );

		const checkbox = screen.getByRole( 'checkbox' );
		expect( checkbox ).toBeDisabled();
		expect( checkbox ).not.toBeChecked();
	} );

	it( 'enables the dependent checkbox once its source cell is filled in', () => {
		renderTable( {
			field: { columns: CHECKBOX_COLUMNS },
			value: [ { path: '/gtm.js', noid: '' } ],
		} );

		expect( screen.getByRole( 'checkbox' ) ).toBeEnabled();
	} );
} );
