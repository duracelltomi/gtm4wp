/**
 * Tests for the schema -> control mapping.
 *
 * FieldControl is the switch every settings field passes through, so the risk is
 * a field type silently landing on the wrong branch: an `integer` saved as a
 * string, a `multiselect` losing entries, or a new type falling through to the
 * text default without anyone noticing. Each branch is pinned by the value it
 * hands back to onChange, not by which component rendered.
 */

import { fireEvent, render, screen } from '@testing-library/react';

import FieldControl from '../components/FieldControl';

function renderField( field, { value, values = {}, error } = {} ) {
	const onChange = jest.fn();

	render(
		<FieldControl
			field={ { description: '', ...field } }
			value={ value }
			values={ values }
			error={ error }
			onChange={ onChange }
		/>
	);

	return { onChange };
}

describe( 'FieldControl type routing', () => {
	it( 'renders a checkbox field as a toggle and reports a boolean', () => {
		const { onChange } = renderField(
			{ key: 'k', type: 'checkbox', label: 'Enable' },
			{ value: false }
		);

		fireEvent.click( screen.getByRole( 'switch' ) );

		expect( onChange ).toHaveBeenCalledWith( true );
	} );

	it( 'coerces an integer field to a Number, not a string', () => {
		const { onChange } = renderField(
			{ key: 'k', type: 'integer', label: 'Count' },
			{ value: 1 }
		);

		fireEvent.change( screen.getByRole( 'spinbutton' ), {
			target: { value: '42' },
		} );

		expect( onChange ).toHaveBeenCalledWith( 42 );
	} );

	it( 'renders a select field from its choices map', () => {
		const { onChange } = renderField(
			{
				key: 'k',
				type: 'select',
				label: 'Mode',
				choices: { a: 'Alpha', b: 'Beta' },
			},
			{ value: 'a' }
		);

		expect(
			screen.getByRole( 'option', { name: 'Alpha' } )
		).toBeInTheDocument();

		fireEvent.change( screen.getByRole( 'combobox' ), {
			target: { value: 'b' },
		} );

		expect( onChange ).toHaveBeenCalledWith( 'b' );
	} );

	it( 'renders a textarea field', () => {
		const { onChange } = renderField(
			{ key: 'k', type: 'textarea', label: 'Notes' },
			{ value: 'before' }
		);

		fireEvent.change( screen.getByRole( 'textbox' ), {
			target: { value: 'after' },
		} );

		expect( onChange ).toHaveBeenCalledWith( 'after' );
	} );

	it( 'falls back to a text control for an unknown field type', () => {
		const { onChange } = renderField(
			{ key: 'k', type: 'something-new', label: 'Free text' },
			{ value: '' }
		);

		fireEvent.change( screen.getByRole( 'textbox' ), {
			target: { value: 'typed' },
		} );

		expect( onChange ).toHaveBeenCalledWith( 'typed' );
	} );

	it( 'renders a null value as an empty string rather than "null"', () => {
		renderField(
			{ key: 'k', type: 'text', label: 'Free text' },
			{ value: null }
		);

		expect( screen.getByRole( 'textbox' ) ).toHaveValue( '' );
	} );

	it( 'delegates a table field to the table control', () => {
		renderField(
			{
				key: 'k',
				type: 'table',
				label: 'Rows',
				columns: [ { key: 'id', label: 'ID' } ],
			},
			{ value: [] }
		);

		expect( screen.getByText( 'No rows added yet.' ) ).toBeInTheDocument();
	} );
} );

describe( 'FieldControl multiselect', () => {
	const FIELD = {
		key: 'k',
		type: 'multiselect',
		label: 'Entities',
		choices: { a: 'Alpha', b: 'Beta' },
	};

	it( 'adds a newly checked choice to the selection', () => {
		const { onChange } = renderField( FIELD, { value: [ 'a' ] } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Beta' } ) );

		expect( onChange ).toHaveBeenCalledWith( [ 'a', 'b' ] );
	} );

	it( 'removes an unchecked choice from the selection', () => {
		const { onChange } = renderField( FIELD, { value: [ 'a', 'b' ] } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Alpha' } ) );

		expect( onChange ).toHaveBeenCalledWith( [ 'b' ] );
	} );

	it( 'treats a non-array stored value as an empty selection', () => {
		const { onChange } = renderField( FIELD, { value: '' } );

		expect(
			screen.getByRole( 'checkbox', { name: 'Alpha' } )
		).not.toBeChecked();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Alpha' } ) );
		expect( onChange ).toHaveBeenCalledWith( [ 'a' ] );
	} );
} );

describe( 'FieldControl dependencies and annotations', () => {
	it( 'disables a control whose dependency is off', () => {
		renderField(
			{ key: 'k', type: 'text', label: 'Child', depends_on: 'parent' },
			{ value: '', values: { parent: false } }
		);

		expect( screen.getByRole( 'textbox' ) ).toBeDisabled();
	} );

	it( 'enables it once the dependency is on', () => {
		renderField(
			{ key: 'k', type: 'text', label: 'Child', depends_on: 'parent' },
			{ value: '', values: { parent: true } }
		);

		expect( screen.getByRole( 'textbox' ) ).toBeEnabled();
	} );

	it( 'shows the stored value of a disabled field rather than forcing it off', () => {
		// The display must never disagree with what would be saved.
		renderField(
			{ key: 'k', type: 'text', label: 'Child', depends_on: 'parent' },
			{ value: 'kept', values: { parent: false } }
		);

		expect( screen.getByRole( 'textbox' ) ).toHaveValue( 'kept' );
	} );

	it.each( [
		[ 'beta', 'Beta' ],
		[ 'experimental', 'Experimental' ],
		[ 'deprecated', 'Deprecated' ],
	] )( 'badges a %s field', ( phase, badge ) => {
		renderField( { key: 'k', type: 'text', label: 'Field', phase } );

		expect( screen.getByText( badge ) ).toBeInTheDocument();
	} );

	it( 'shows no badge for a stable field', () => {
		renderField( {
			key: 'k',
			type: 'text',
			label: 'Field',
			phase: 'stable',
		} );

		expect( screen.queryByText( 'Beta' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Experimental' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Deprecated' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a validation error alongside the field', () => {
		renderField(
			{ key: 'k', type: 'text', label: 'Field' },
			{ value: '', error: 'Invalid container ID.' }
		);

		expect(
			screen.getByText( 'Invalid container ID.' )
		).toBeInTheDocument();
	} );
} );
