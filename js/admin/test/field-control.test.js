/**
 * Tests for the schema -> control mapping.
 *
 * FieldControl is the switch every settings field passes through, so the risk is
 * a field type silently landing on the wrong branch: an `integer` saved as a
 * string, a `multiselect` losing entries, or a new type falling through to the
 * text default without anyone noticing. Each branch is pinned by the value it
 * hands back to onChange, not by which component rendered.
 */

import { cleanup, fireEvent, render, screen } from '@testing-library/react';

import FieldControl from '../components/FieldControl';

function renderField(
	field,
	{ value, values = {}, error, allFields = [] } = {}
) {
	const onChange = jest.fn();

	const { container } = render(
		<FieldControl
			field={ { description: '', ...field } }
			value={ value }
			values={ values }
			error={ error }
			allFields={ allFields }
			onChange={ onChange }
		/>
	);

	return { container, onChange };
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

describe( 'FieldControl multiselect sections', () => {
	// The tag restriction list: one stored option value, rendered as one group
	// per entity type.
	const FIELD = {
		key: 'blacklist-status',
		type: 'multiselect',
		label: 'Restricted entities',
		choices: { html: 'Custom HTML', img: 'Custom Image', u: 'URL' },
		sections: [
			{ label: 'Tags', choices: [ 'html', 'img' ] },
			{ label: 'Variables', choices: [ 'u' ] },
		],
	};

	it( 'renders every choice exactly once, under its own group heading', () => {
		renderField( FIELD, { value: [] } );

		expect( screen.getByText( 'Tags' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Variables' ) ).toBeInTheDocument();

		expect( screen.getAllByRole( 'checkbox' ) ).toHaveLength( 3 );
		expect(
			screen.getAllByRole( 'checkbox', { name: 'Custom HTML' } )
		).toHaveLength( 1 );
	} );

	it( 'keeps the selections of the other sections when one is toggled', () => {
		// The sections render ONE flat option value. A toggle that rebuilt the
		// selection from its own section would silently wipe every entity of the
		// other sections on the next save - and the screen would look right until
		// the page was reloaded.
		const { onChange } = renderField( FIELD, { value: [ 'html' ] } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'URL' } ) );

		expect( onChange ).toHaveBeenCalledWith( [ 'html', 'u' ] );
	} );

	it( 'unchecks across sections without touching the rest of the selection', () => {
		const { onChange } = renderField( FIELD, { value: [ 'html', 'u' ] } );

		fireEvent.click(
			screen.getByRole( 'checkbox', { name: 'Custom HTML' } )
		);

		expect( onChange ).toHaveBeenCalledWith( [ 'u' ] );
	} );

	it( 'counts the selected entries of each section', () => {
		renderField( FIELD, { value: [ 'html' ] } );

		expect( screen.getByText( '1 / 2' ) ).toBeInTheDocument();
		expect( screen.getByText( '0 / 1' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( '1 of 2 selected' )
		).toBeInTheDocument();
	} );

	it( 'leaves a collapsed section collapsed when the selection changes', () => {
		// The sections are uncontrolled `<details open>`: React writes the
		// attribute only when the prop CHANGES, so collapsing one survives the
		// re-render every checkbox click causes. If that ever stopped holding,
		// each click would spring every collapsed section back open - and a test
		// that only mounts once would never see it.
		const props = {
			field: { description: '', ...FIELD },
			values: {},
			onChange: jest.fn(),
		};

		const { container, rerender } = render(
			<FieldControl { ...props } value={ [] } />
		);

		container.querySelectorAll( 'details' )[ 0 ].open = false;

		rerender( <FieldControl { ...props } value={ [ 'u' ] } /> );

		expect( container.querySelectorAll( 'details' )[ 0 ].open ).toBe(
			false
		);
	} );

	it( 'still renders a choice that belongs to no section', () => {
		renderField(
			{
				...FIELD,
				choices: { ...FIELD.choices, sandboxedScripts: 'Sandboxed' },
			},
			{ value: [] }
		);

		expect(
			screen.getByRole( 'checkbox', { name: 'Sandboxed' } )
		).toBeInTheDocument();
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

	it( 'stays enabled while any one of several comma separated dependencies is on', () => {
		// #272: the trusted-proxies list serves two readers (visitor IP and the
		// Cloudflare country code); either one keeps it editable.
		const field = {
			key: 'k',
			type: 'text',
			label: 'Child',
			depends_on: 'first, second',
		};

		renderField( field, {
			value: '',
			values: { first: false, second: true },
		} );
		expect( screen.getByRole( 'textbox' ) ).toBeEnabled();

		cleanup();

		renderField( field, {
			value: '',
			values: { first: false, second: false },
		} );
		expect( screen.getByRole( 'textbox' ) ).toBeDisabled();
	} );

	/**
	 * The reported case, at the layer it was seen: a checkbox depending on a
	 * table stayed operable after the last row was deleted, because an empty
	 * array is truthy. Asserted on a checkbox rather than a text field because
	 * that is the shape the Data Manager's capture option has.
	 */
	it( 'disables a checkbox whose table dependency has been emptied', () => {
		renderField(
			{
				key: 'gdm-capture-attribution',
				type: 'checkbox',
				label: 'Store attribution data with each order',
				depends_on: 'gdm-destinations',
			},
			{ value: false, values: { 'gdm-destinations': [] } }
		);

		expect( screen.getByRole( 'switch' ) ).toBeDisabled();
	} );

	it( 'enables it again once the table holds a row', () => {
		renderField(
			{
				key: 'gdm-capture-attribution',
				type: 'checkbox',
				label: 'Store attribution data with each order',
				depends_on: 'gdm-destinations',
			},
			{
				value: false,
				values: {
					'gdm-destinations': [ { measurement_id: 'G-ABC123' } ],
				},
			}
		);

		expect( screen.getByRole( 'switch' ) ).toBeEnabled();
	} );

	/**
	 * A setting saved as ON that cannot run looks exactly like one that is
	 * running: the toggle reads on, and greying it out communicates "active,
	 * you just cannot change it here" - the opposite of the truth. Forcing the
	 * control off instead would misrepresent what is stored and make the
	 * setting appear to switch itself on when the dependency comes back, so the
	 * state is explained rather than hidden.
	 */
	it( 'says a setting stored as on is not in effect, naming what it needs', () => {
		renderField(
			{
				key: 'gdm-capture-attribution',
				type: 'checkbox',
				label: 'Store attribution data with each order',
				depends_on: 'gdm-destinations',
			},
			{
				value: true,
				values: { 'gdm-destinations': [] },
				allFields: [
					{
						key: 'gdm-destinations',
						label: 'Data Manager destinations',
					},
				],
			}
		);

		expect( screen.getByRole( 'switch' ) ).toBeDisabled();
		expect( screen.getByRole( 'switch' ) ).toBeChecked();
		expect(
			screen.getByText(
				'Saved as on, but not in effect: it needs Data Manager destinations.'
			)
		).toBeInTheDocument();
	} );

	it( 'tells an off setting what to set first', () => {
		renderField(
			{
				key: 'gdm-capture-attribution',
				type: 'checkbox',
				label: 'Store attribution data with each order',
				depends_on: 'gdm-destinations',
			},
			{
				value: false,
				values: { 'gdm-destinations': [] },
				allFields: [
					{
						key: 'gdm-destinations',
						label: 'Data Manager destinations',
					},
				],
			}
		);

		expect(
			screen.getByText(
				'Available once Data Manager destinations is set.'
			)
		).toBeInTheDocument();
	} );

	it( 'explains nothing while the dependency is satisfied', () => {
		renderField(
			{
				key: 'gdm-capture-attribution',
				type: 'checkbox',
				label: 'Store attribution data with each order',
				depends_on: 'gdm-destinations',
			},
			{
				value: true,
				values: { 'gdm-destinations': [ { measurement_id: 'G-A' } ] },
				allFields: [
					{
						key: 'gdm-destinations',
						label: 'Data Manager destinations',
					},
				],
			}
		);

		expect( screen.getByRole( 'switch' ) ).toBeEnabled();
		expect( screen.queryByText( /not in effect/ ) ).not.toBeInTheDocument();
		expect(
			screen.queryByText( /Available once/ )
		).not.toBeInTheDocument();
	} );

	/**
	 * The note names the dependency, so with no way to resolve its label there
	 * is nothing useful to say - and a sentence with a blank in it would be
	 * worse than the plain greying.
	 */
	it( 'stays silent when the dependency label cannot be resolved', () => {
		renderField(
			{
				key: 'child',
				type: 'checkbox',
				label: 'Child',
				depends_on: 'a-field-from-another-module',
			},
			{ value: true, values: {} }
		);

		expect( screen.getByRole( 'switch' ) ).toBeDisabled();
		expect( screen.queryByText( /not in effect/ ) ).not.toBeInTheDocument();
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

	it( 'renders the description markup of a field', () => {
		const { container } = renderField( {
			key: 'k',
			type: 'select',
			label: 'Field',
			choices: { a: 'Alpha' },
			description:
				'Read the <a href="https://gtm4wp.com/">docs</a>.<br />Use <code>gtm4wp</code>.',
		} );

		const helpNode = container.querySelector( '.gtm4wp-field-help' );

		expect( helpNode.querySelector( 'a' ) ).toHaveAttribute(
			'href',
			'https://gtm4wp.com/'
		);
		expect( helpNode.querySelector( 'code' ) ).toBeInTheDocument();
	} );

	it( 'keeps the description inside the phrasing content a help slot allows', () => {
		// Every @wordpress/components control renders `help` inside a <p>, so a
		// <div> wrapper there is invalid HTML: React logs a validateDOMNesting
		// error and the browser closes the paragraph early, dropping the styling
		// off the rest of the description. That is what RawHTML - which always
		// renders a <div> - did here. The stand-in reproduces the <p>, so the
		// nesting error alone would fail this test; the assertions pin the
		// wrapper element so the reason stays legible.
		const { container } = renderField( {
			key: 'k',
			type: 'select',
			label: 'Field',
			choices: { a: 'Alpha' },
			description: 'Plain description.',
		} );

		const helpNode = container.querySelector( '.gtm4wp-field-help' );

		expect( helpNode.tagName ).toBe( 'SPAN' );
		expect( helpNode.closest( 'p' ) ).not.toBeNull();
		expect( helpNode.querySelector( 'div' ) ).toBeNull();
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
