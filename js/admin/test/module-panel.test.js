/**
 * Tests for the right-hand settings panel.
 *
 * The branch worth guarding is the group/tab arithmetic: groups are declared
 * independently of fields, so a group can end up empty and a field can name a
 * group that no longer exists. The component is written so neither case loses a
 * field — an empty group is dropped, and a field with no matching group still
 * renders. That "nothing is silently dropped" property is what these pin.
 */

import { fireEvent, render, screen } from '@testing-library/react';

import ModulePanel from '../components/ModulePanel';

function field( key, group, label = key ) {
	return { key, group, label, type: 'text', description: '' };
}

function renderPanel(
	module,
	{ values = {}, errors = {}, focus = null } = {}
) {
	const onChange = jest.fn();

	render(
		<ModulePanel
			module={ {
				id: 'm',
				title: 'Module',
				available: true,
				intro: '',
				...module,
			} }
			values={ values }
			errors={ errors }
			focus={ focus }
			onChange={ onChange }
		/>
	);

	return { onChange };
}

describe( 'ModulePanel availability', () => {
	it( 'replaces the fields with the module-specific reason when unavailable', () => {
		renderPanel( {
			available: false,
			unavailableMessage: 'WooCommerce is not active.',
			groups: [ { id: 'g', label: 'General' } ],
			fields: [ field( 'a', 'g' ) ],
		} );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'WooCommerce is not active.'
		);
		expect( screen.queryByRole( 'textbox' ) ).not.toBeInTheDocument();
	} );

	it( 'falls back to a generic reason when the module supplies none', () => {
		renderPanel( {
			available: false,
			groups: [],
			fields: [],
		} );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'This module is not available in your current environment.'
		);
	} );
} );

describe( 'ModulePanel group layout', () => {
	it( 'renders a single group flat, with no tab bar', () => {
		renderPanel( {
			groups: [ { id: 'g', label: 'General' } ],
			fields: [ field( 'a', 'g' ), field( 'b', 'g' ) ],
		} );

		expect( screen.queryByRole( 'tablist' ) ).not.toBeInTheDocument();
		expect( screen.getAllByRole( 'textbox' ) ).toHaveLength( 2 );
	} );

	it( 'renders tabs when more than one group holds fields', () => {
		renderPanel( {
			groups: [
				{ id: 'one', label: 'One' },
				{ id: 'two', label: 'Two' },
			],
			fields: [ field( 'a', 'one' ), field( 'b', 'two' ) ],
		} );

		expect( screen.getAllByRole( 'tab' ) ).toHaveLength( 2 );
	} );

	it( 'drops a declared group that has no fields rather than showing an empty tab', () => {
		renderPanel( {
			groups: [
				{ id: 'one', label: 'One' },
				{ id: 'empty', label: 'Empty' },
			],
			fields: [ field( 'a', 'one' ) ],
		} );

		expect( screen.queryByRole( 'tablist' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'tab', { name: 'Empty' } )
		).not.toBeInTheDocument();
	} );

	it( 'still renders fields whose group does not exist', () => {
		// No group matches, so the panel must fall back to every field instead
		// of rendering an empty body.
		renderPanel( {
			groups: [ { id: 'declared', label: 'Declared' } ],
			fields: [ field( 'orphan', 'missing-group' ) ],
		} );

		expect( screen.getAllByRole( 'textbox' ) ).toHaveLength( 1 );
	} );

	it( 'switches the rendered fields with the selected tab', () => {
		renderPanel( {
			groups: [
				{ id: 'one', label: 'One' },
				{ id: 'two', label: 'Two' },
			],
			fields: [
				field( 'a', 'one', 'First field' ),
				field( 'b', 'two', 'Second field' ),
			],
		} );

		expect( screen.getByText( 'First field' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Second field' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'tab', { name: /Two/ } ) );

		expect( screen.getByText( 'Second field' ) ).toBeInTheDocument();
	} );
} );

describe( 'ModulePanel error surfacing', () => {
	it( 'flags the tab holding a rejected field so a hidden error stays visible', () => {
		renderPanel(
			{
				groups: [
					{ id: 'one', label: 'One' },
					{ id: 'two', label: 'Two' },
				],
				fields: [ field( 'a', 'one' ), field( 'b', 'two' ) ],
			},
			{ errors: { b: 'Rejected.' } }
		);

		// The error is on the second, currently hidden tab.
		const flagged = screen.getByRole( 'tab', { name: /Two/ } );
		expect( flagged ).toHaveClass( 'gtm4wp-tab--has-error' );
		expect( flagged ).toHaveTextContent( '(has errors)' );
	} );

	it( 'leaves a tab unflagged when none of its fields were rejected', () => {
		renderPanel(
			{
				groups: [
					{ id: 'one', label: 'One' },
					{ id: 'two', label: 'Two' },
				],
				fields: [ field( 'a', 'one' ), field( 'b', 'two' ) ],
			},
			{ errors: { b: 'Rejected.' } }
		);

		expect( screen.getByRole( 'tab', { name: /One/ } ) ).not.toHaveClass(
			'gtm4wp-tab--has-error'
		);
	} );
} );

describe( 'ModulePanel deep linking', () => {
	const TWO_GROUPS = {
		groups: [
			{ id: 'one', label: 'One' },
			{ id: 'two', label: 'Two' },
		],
		fields: [
			field( 'a', 'one', 'First field' ),
			field( 'b', 'two', 'Second field' ),
		],
	};

	it( 'opens the tab holding the linked field instead of the first one', () => {
		// Without this the notice lands the admin on tab one and the setting it
		// was talking about is behind a tab they have to guess.
		renderPanel( TWO_GROUPS, {
			focus: { moduleId: 'm', groupId: 'two', fieldKey: 'b' },
		} );

		expect( screen.getByRole( 'tab', { name: /Two/ } ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( screen.getByText( 'Second field' ) ).toBeInTheDocument();
	} );

	it( 'marks the linked field and hands it the keyboard focus', () => {
		renderPanel( TWO_GROUPS, {
			focus: { moduleId: 'm', groupId: 'two', fieldKey: 'b' },
		} );

		const control = screen.getByRole( 'textbox' );
		expect( control.closest( '.gtm4wp-field' ) ).toHaveClass(
			'is-focused'
		);
		expect( control ).toHaveFocus();
	} );

	it( 'does not grab the focus again when the tab is revisited', () => {
		// The tint stays for as long as the link is live, but moving between
		// tabs is the visitor steering; yanking the caret back into the field
		// each time they return would fight them.
		renderPanel( TWO_GROUPS, {
			focus: { moduleId: 'm', groupId: 'two', fieldKey: 'b' },
		} );

		fireEvent.click( screen.getByRole( 'tab', { name: /One/ } ) );
		fireEvent.click( screen.getByRole( 'tab', { name: /Two/ } ) );

		const control = screen.getByRole( 'textbox' );
		expect( control.closest( '.gtm4wp-field' ) ).toHaveClass(
			'is-focused'
		);
		expect( control ).not.toHaveFocus();
	} );

	it( 'marks only the linked field', () => {
		renderPanel(
			{
				groups: [ { id: 'one', label: 'One' } ],
				fields: [
					field( 'a', 'one', 'First field' ),
					field( 'b', 'one', 'Second field' ),
				],
			},
			{ focus: { moduleId: 'm', groupId: 'one', fieldKey: 'b' } }
		);

		expect( document.querySelectorAll( '.is-focused' ) ).toHaveLength( 1 );
		expect(
			screen.getByText( 'Second field' ).closest( '.gtm4wp-field' )
		).toHaveClass( 'is-focused' );
	} );

	it( 'falls back to the first tab when the linked group holds no fields', () => {
		// An empty group is dropped from the tab bar, so pinning the tab to it
		// would leave the panel with nothing selected.
		renderPanel(
			{
				groups: [
					{ id: 'one', label: 'One' },
					{ id: 'two', label: 'Two' },
					{ id: 'empty', label: 'Empty' },
				],
				fields: [ field( 'a', 'one' ), field( 'b', 'two' ) ],
			},
			{ focus: { moduleId: 'm', groupId: 'empty', fieldKey: 'gone' } }
		);

		expect( screen.getByRole( 'tab', { name: /One/ } ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
	} );

	it( 'leaves the first tab selected and nothing marked without a deep link', () => {
		renderPanel( TWO_GROUPS );

		expect( screen.getByRole( 'tab', { name: /One/ } ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( document.querySelectorAll( '.is-focused' ) ).toHaveLength( 0 );
	} );
} );

describe( 'ModulePanel field wiring', () => {
	it( 'reports a field edit with that field key', () => {
		const { onChange } = renderPanel(
			{
				groups: [ { id: 'g', label: 'General' } ],
				fields: [ field( 'gtm-code', 'g' ) ],
			},
			{ values: { 'gtm-code': 'GTM-AAA' } }
		);

		fireEvent.change( screen.getByDisplayValue( 'GTM-AAA' ), {
			target: { value: 'GTM-BBB' },
		} );

		expect( onChange ).toHaveBeenCalledWith( 'gtm-code', 'GTM-BBB' );
	} );
} );
