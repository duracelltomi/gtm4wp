/**
 * Tests for the phone navigation dropdown.
 *
 * The interesting behaviour is not that a `select` renders a list of modules,
 * it is the two places where a dropdown cannot do what the left pane does and
 * the component has to compensate:
 *
 * - a `select` whose value matches none of its options renders blank, so the
 *   open module has to survive a search term that does not match it. The left
 *   pane can simply drop it from the list, because the panel beside it still
 *   says which module is open; the dropdown IS the only thing saying so.
 * - an `option` holds text and nothing else, so the unsaved-changes marker the
 *   left pane draws as a dot with an `aria-label` has to be words instead.
 *
 * Both are invisible from the markup and would fail silently, so both are
 * asserted through what the visitor ends up seeing (TS-3).
 */

import { fireEvent, render, screen } from '@testing-library/react';

import MobileNav from '../components/MobileNav';

const MODULES = [
	{
		id: 'container',
		title: 'Container',
		available: true,
		fields: [ { label: 'Container ID', description: '' } ],
	},
	{
		id: 'woocommerce',
		title: 'WooCommerce',
		available: false,
		fields: [ { label: 'Track purchases', description: '' } ],
	},
];

function renderNav( {
	search = '',
	dirtyModules = [],
	activeModuleId = 'container',
} = {} ) {
	const onSelect = jest.fn();
	const onSearch = jest.fn();

	render(
		<MobileNav
			modules={ MODULES }
			activeModuleId={ activeModuleId }
			onSelect={ onSelect }
			search={ search }
			onSearch={ onSearch }
			dirtyModules={ dirtyModules }
		/>
	);

	return { onSelect, onSearch };
}

function optionLabels() {
	return Array.from( screen.getByRole( 'combobox' ).options ).map(
		( option ) => option.textContent
	);
}

describe( 'MobileNav listing', () => {
	it( 'offers every module as an option', () => {
		renderNav();

		expect( optionLabels() ).toEqual( [ 'Container', 'WooCommerce' ] );
	} );

	it( 'shows the active module as the selected one', () => {
		renderNav( { activeModuleId: 'woocommerce' } );

		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'woocommerce' );
	} );

	it( 'reports the chosen module id', () => {
		const { onSelect } = renderNav();

		fireEvent.change( screen.getByRole( 'combobox' ), {
			target: { value: 'woocommerce' },
		} );

		expect( onSelect ).toHaveBeenCalledWith( 'woocommerce' );
	} );
} );

describe( 'MobileNav search', () => {
	it( 'reports what was typed', () => {
		const { onSearch } = renderNav();

		fireEvent.change( screen.getByRole( 'searchbox' ), {
			target: { value: 'woo' },
		} );

		expect( onSearch ).toHaveBeenCalledWith( 'woo' );
	} );

	it( 'narrows the options to matching modules', () => {
		renderNav( { search: 'purchases', activeModuleId: 'woocommerce' } );

		expect( optionLabels() ).toEqual( [ 'WooCommerce' ] );
	} );

	it( 'keeps the open module selectable when the search excludes it', () => {
		// Matches WooCommerce only, but Container is the module on screen.
		renderNav( { search: 'purchases', activeModuleId: 'container' } );

		expect( optionLabels() ).toEqual( [ 'Container', 'WooCommerce' ] );
		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'container' );
	} );

	it( 'explains an empty result rather than offering the open module alone', () => {
		renderNav( { search: 'nothing matches this' } );

		expect(
			screen.getByText( 'No settings match your search.' )
		).toBeInTheDocument();
		// Still navigable: the open module is the one option left.
		expect( optionLabels() ).toEqual( [ 'Container' ] );
	} );
} );

describe( 'MobileNav unsaved marker', () => {
	it( 'names the unsaved modules in the option text', () => {
		renderNav( { dirtyModules: [ 'woocommerce' ] } );

		expect( optionLabels() ).toEqual( [
			'Container',
			'WooCommerce — unsaved changes',
		] );
	} );

	it( 'leaves the option text alone when nothing is dirty', () => {
		renderNav();

		expect( optionLabels() ).toEqual( [ 'Container', 'WooCommerce' ] );
	} );
} );
