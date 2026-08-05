/**
 * Tests for the left pane navigation.
 *
 * Mostly presentational, with two behaviours that are not: the search filter
 * (which delegates to moduleMatchesSearch and must not strand the admin with a
 * blank pane and no explanation) and the unsaved-changes marker.
 */

import { fireEvent, render, screen } from '@testing-library/react';

import Sidebar from '../components/Sidebar';

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

function renderSidebar( { search = '', dirtyModules = [] } = {} ) {
	const onSelect = jest.fn();
	const onSearch = jest.fn();

	render(
		<Sidebar
			modules={ MODULES }
			activeModuleId="container"
			onSelect={ onSelect }
			search={ search }
			onSearch={ onSearch }
			dirtyModules={ dirtyModules }
		/>
	);

	return { onSelect, onSearch };
}

describe( 'Sidebar listing', () => {
	it( 'lists every module', () => {
		renderSidebar();

		expect(
			screen.getByRole( 'button', { name: 'Container' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'WooCommerce' } )
		).toBeInTheDocument();
	} );

	it( 'marks the active module', () => {
		renderSidebar();

		expect(
			screen.getByRole( 'button', { name: 'Container' } )
		).toHaveClass( 'is-active' );
	} );

	it( 'marks an unavailable module', () => {
		renderSidebar();

		expect(
			screen.getByRole( 'button', { name: 'WooCommerce' } )
		).toHaveClass( 'is-unavailable' );
	} );

	it( 'reports the selected module id', () => {
		const { onSelect } = renderSidebar();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'WooCommerce' } )
		);

		expect( onSelect ).toHaveBeenCalledWith( 'woocommerce' );
	} );
} );

describe( 'Sidebar search', () => {
	it( 'reports what was typed', () => {
		const { onSearch } = renderSidebar();

		fireEvent.change( screen.getByRole( 'searchbox' ), {
			target: { value: 'woo' },
		} );

		expect( onSearch ).toHaveBeenCalledWith( 'woo' );
	} );

	it( 'narrows the list to matching modules', () => {
		renderSidebar( { search: 'woo' } );

		expect(
			screen.queryByRole( 'button', { name: 'Container' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'WooCommerce' } )
		).toBeInTheDocument();
	} );

	it( 'explains an empty result instead of showing a blank pane', () => {
		renderSidebar( { search: 'nothing matches this' } );

		expect(
			screen.getByText( 'No settings match your search.' )
		).toBeInTheDocument();
	} );
} );

describe( 'Sidebar unsaved marker', () => {
	it( 'marks only the modules reported dirty', () => {
		renderSidebar( { dirtyModules: [ 'woocommerce' ] } );

		const markers = screen.getAllByLabelText( 'Unsaved changes' );
		expect( markers ).toHaveLength( 1 );
		expect(
			screen.getByRole( 'button', { name: /WooCommerce/ } )
		).toContainElement( markers[ 0 ] );
	} );

	it( 'shows no marker when nothing is dirty', () => {
		renderSidebar();

		expect(
			screen.queryByLabelText( 'Unsaved changes' )
		).not.toBeInTheDocument();
	} );
} );
