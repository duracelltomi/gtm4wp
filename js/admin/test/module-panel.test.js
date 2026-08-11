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
	{
		values = {},
		errors = {},
		activeGroupId = null,
		focusFieldKey = null,
	} = {}
) {
	const onChange = jest.fn();
	const onGroupSelect = jest.fn();

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
			activeGroupId={ activeGroupId }
			focusFieldKey={ focusFieldKey }
			onGroupSelect={ onGroupSelect }
			onChange={ onChange }
		/>
	);

	return { onChange, onGroupSelect };
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

	it( 'opens the requested tab instead of the first one', () => {
		// Without this the notice lands the admin on tab one and the setting it
		// was talking about is behind a tab they have to guess.
		renderPanel( TWO_GROUPS, {
			activeGroupId: 'two',
			focusFieldKey: 'b',
		} );

		expect( screen.getByRole( 'tab', { name: /Two/ } ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( screen.getByText( 'Second field' ) ).toBeInTheDocument();
	} );

	it( 'marks the linked field and hands it the keyboard focus', () => {
		renderPanel( TWO_GROUPS, {
			activeGroupId: 'two',
			focusFieldKey: 'b',
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
			activeGroupId: 'two',
			focusFieldKey: 'b',
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
			{ activeGroupId: 'one', focusFieldKey: 'b' }
		);

		expect( document.querySelectorAll( '.is-focused' ) ).toHaveLength( 1 );
		expect(
			screen.getByText( 'Second field' ).closest( '.gtm4wp-field' )
		).toHaveClass( 'is-focused' );
	} );

	it( 'falls back to the first tab when the requested group holds no fields', () => {
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
			{ activeGroupId: 'empty', focusFieldKey: 'gone' }
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

describe( 'ModulePanel tab reporting', () => {
	// The panel is the only thing that knows which tab is showing, so if it does
	// not report a switch upwards the URL cannot follow the visitor.
	it( 'reports the tab the visitor switches to', () => {
		const { onGroupSelect } = renderPanel( {
			groups: [
				{ id: 'one', label: 'One' },
				{ id: 'two', label: 'Two' },
			],
			fields: [ field( 'a', 'one' ), field( 'b', 'two' ) ],
		} );

		onGroupSelect.mockClear();
		fireEvent.click( screen.getByRole( 'tab', { name: /Two/ } ) );

		expect( onGroupSelect ).toHaveBeenCalledWith( 'two' );
	} );

	it( 'reports nothing for a module rendered without a tab bar', () => {
		// A single populated group renders flat, so there is no tab to name and
		// the URL must not claim one.
		const { onGroupSelect } = renderPanel( {
			groups: [ { id: 'only', label: 'Only' } ],
			fields: [ field( 'a', 'only' ) ],
		} );

		expect( onGroupSelect ).not.toHaveBeenCalled();
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

describe( 'ModulePanel documentation links', () => {
	const DOC = 'https://gtm4wp.com/some-page#gtm-code';

	it( 'links the panel header to the module documentation', () => {
		renderPanel( {
			title: 'WooCommerce',
			docUrl: 'https://gtm4wp.com/google-tag-manager-for-woocommerce',
			groups: [ { id: 'g', label: 'General' } ],
			fields: [ field( 'a', 'g' ) ],
		} );

		const link = screen.getByRole( 'link', {
			name: 'Documentation: WooCommerce (opens in a new tab)',
		} );

		expect( link ).toHaveAttribute(
			'href',
			'https://gtm4wp.com/google-tag-manager-for-woocommerce'
		);
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute( 'rel', 'noopener noreferrer' );
	} );

	it( 'links the header of an unavailable module too', () => {
		// The module cannot run, which is precisely when the page explaining
		// what it needs is worth reaching.
		renderPanel( {
			title: 'WooCommerce',
			available: false,
			unavailableMessage: 'WooCommerce is not active.',
			docUrl: 'https://gtm4wp.com/google-tag-manager-for-woocommerce',
			groups: [],
			fields: [],
		} );

		expect(
			screen.getByRole( 'link', {
				name: 'Documentation: WooCommerce (opens in a new tab)',
			} )
		).toBeInTheDocument();
	} );

	it( 'names the option, not just "help", on a field link', () => {
		// Thirty identical "Help" links on one panel is what this avoids: the
		// accessible name has to say which setting it belongs to.
		renderPanel( {
			groups: [ { id: 'g', label: 'General' } ],
			fields: [
				{ ...field( 'gtm-code', 'g', 'Container ID' ), doc: DOC },
			],
		} );

		expect(
			screen.getByRole( 'link', {
				name: 'Documentation: Container ID (opens in a new tab)',
			} )
		).toHaveAttribute( 'href', DOC );
	} );

	it( 'renders no icon for an option that has no page yet', () => {
		renderPanel( {
			docUrl: '',
			groups: [ { id: 'g', label: 'General' } ],
			fields: [ { ...field( 'gtm-code', 'g' ), doc: '' } ],
		} );

		expect( screen.queryByRole( 'link' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the help link out of the control label', () => {
		// The load-bearing one. Every control renders its label as a <label>
		// element, and an anchor placed inside one would toggle the setting on
		// click as well as opening the page. The link is a sibling of the
		// control for that reason, which is only observable as behaviour.
		const { onChange } = renderPanel( {
			groups: [ { id: 'g', label: 'General' } ],
			fields: [
				{
					key: 'load-early',
					group: 'g',
					label: 'Load early',
					type: 'checkbox',
					description: '',
					doc: DOC,
				},
			],
		} );

		fireEvent.click( screen.getByRole( 'link' ) );

		expect( onChange ).not.toHaveBeenCalled();
		expect( screen.getByRole( 'switch' ) ).not.toBeChecked();
	} );

	it( 'leaves the deep-link focus on the control, not on the help link', () => {
		// ModulePanel focuses the first input/textarea/select/button it finds in
		// the revealed row. An anchor is none of those on purpose, so arriving
		// from a notice still lands on the setting itself.
		renderPanel(
			{
				groups: [ { id: 'g', label: 'General' } ],
				fields: [
					{ ...field( 'gtm-code', 'g', 'Container ID' ), doc: DOC },
				],
			},
			{ focusFieldKey: 'gtm-code' }
		);

		expect( screen.getByRole( 'textbox' ) ).toHaveFocus();
	} );
} );
