/**
 * Tests for the settings app shell.
 *
 * App owns the dirty/baseline bookkeeping, which is where a silent data-loss bug
 * would live. Two behaviours carry the weight:
 *
 * 1. Only CHANGED values are sent to the save route — the whole point of
 *    `changedValues()`. Posting the full map would let a stale field in an
 *    unopened panel overwrite a value someone else changed.
 * 2. After a partial save the accepted fields become the new baseline while the
 *    REJECTED ones stay dirty. Get that backwards and the screen reports
 *    "saved" for a value the server refused.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';

import App from '../App';

const MODULES = [
	{
		id: 'container',
		title: 'Container',
		available: true,
		intro: '',
		groups: [ { id: 'general', label: 'General' } ],
		fields: [
			{
				key: 'gtm-code',
				type: 'text',
				label: 'Container ID',
				group: 'general',
				description: '',
				value: 'GTM-AAA',
			},
			{
				key: 'datalayer-name',
				type: 'text',
				label: 'Data layer name',
				group: 'general',
				description: '',
				value: 'dataLayer',
			},
		],
	},
	{
		id: 'woocommerce',
		title: 'WooCommerce',
		available: true,
		intro: '',
		groups: [ { id: 'general', label: 'General' } ],
		fields: [
			{
				key: 'wc-enabled',
				type: 'checkbox',
				label: 'Track WooCommerce',
				group: 'general',
				description: '',
				value: false,
			},
		],
	},
];

const SETTINGS = {
	modules: MODULES,
	restPath: '/gtm4wp/v2/settings',
	exportPath: '/gtm4wp/v2/export',
	importPath: '/gtm4wp/v2/import',
};

function renderApp() {
	return render( <App settings={ SETTINGS } /> );
}

/**
 * Types `next` into the Container ID field.
 *
 * @param {string} next Value to type.
 */
function editContainerId( next ) {
	fireEvent.change( screen.getByDisplayValue( 'GTM-AAA' ), {
		target: { value: next },
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'App dirty tracking', () => {
	it( 'starts clean with saving disabled', () => {
		renderApp();

		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeDisabled();
	} );

	it( 'enables saving once a field changes', () => {
		renderApp();
		editContainerId( 'GTM-NEW' );

		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeEnabled();
	} );

	it( 'goes clean again when the value is typed back to its original', () => {
		renderApp();
		editContainerId( 'GTM-NEW' );
		fireEvent.change( screen.getByDisplayValue( 'GTM-NEW' ), {
			target: { value: 'GTM-AAA' },
		} );

		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeDisabled();
	} );

	it( 'marks only the module holding the changed field as dirty', () => {
		renderApp();
		editContainerId( 'GTM-NEW' );

		const dirtyMarkers = screen.getAllByLabelText( 'Unsaved changes' );
		expect( dirtyMarkers ).toHaveLength( 1 );
	} );
} );

describe( 'App saving', () => {
	it( 'posts only the changed values, not the whole settings map', async () => {
		apiFetch.mockResolvedValue( { saved: true, errors: {} } );

		renderApp();
		editContainerId( 'GTM-NEW' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/gtm4wp/v2/settings',
			method: 'POST',
			data: { values: { 'gtm-code': 'GTM-NEW' } },
		} );
	} );

	it( 'confirms a clean save and clears the dirty state', async () => {
		apiFetch.mockResolvedValue( { saved: true, errors: {} } );

		renderApp();
		editContainerId( 'GTM-NEW' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect(
			await screen.findByText( 'Settings saved.' )
		).toBeInTheDocument();
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Save changes' } )
			).toBeDisabled()
		);
	} );

	it( 'keeps a rejected field dirty and shows its error', async () => {
		apiFetch.mockResolvedValue( {
			saved: false,
			errors: { 'gtm-code': 'Invalid container ID.' },
		} );

		renderApp();
		editContainerId( 'not-a-container' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect(
			await screen.findByText(
				'Saved with errors — please review the highlighted fields.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Invalid container ID.' )
		).toBeInTheDocument();
		// The rejected value never became the baseline, so it is still unsaved.
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeEnabled();
	} );

	it( 'adopts the accepted field while the rejected one stays dirty', async () => {
		apiFetch.mockResolvedValue( {
			saved: false,
			errors: { 'gtm-code': 'Invalid container ID.' },
		} );

		renderApp();
		editContainerId( 'not-a-container' );
		fireEvent.change( screen.getByDisplayValue( 'dataLayer' ), {
			target: { value: 'myLayer' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await screen.findByText(
			'Saved with errors — please review the highlighted fields.'
		);

		// A second save must carry ONLY the still-rejected field: the accepted
		// data layer name is part of the baseline now.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/gtm4wp/v2/settings',
			method: 'POST',
			data: { values: { 'gtm-code': 'not-a-container' } },
		} );
	} );

	it( 'reports a failed save with the server message', async () => {
		apiFetch.mockRejectedValue( new Error( 'Permission denied' ) );

		renderApp();
		editContainerId( 'GTM-NEW' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect(
			await screen.findByText( 'Permission denied' )
		).toBeInTheDocument();
	} );

	it( 'falls back to a generic message when the failure carries none', async () => {
		apiFetch.mockRejectedValue( new Error( '' ) );

		renderApp();
		editContainerId( 'GTM-NEW' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect(
			await screen.findByText( 'Saving failed. Please try again.' )
		).toBeInTheDocument();
	} );

	it( 'leaves the change unsaved after a failure so it can be retried', async () => {
		apiFetch.mockRejectedValue( new Error( 'Permission denied' ) );

		renderApp();
		editContainerId( 'GTM-NEW' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await screen.findByText( 'Permission denied' );
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeEnabled();
	} );
} );

describe( 'App navigation', () => {
	it( 'shows the first module by default', () => {
		renderApp();

		expect(
			screen.getByRole( 'heading', { name: 'Container' } )
		).toBeInTheDocument();
	} );

	it( 'switches panel when another module is selected', () => {
		renderApp();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'WooCommerce' } )
		);

		expect(
			screen.getByRole( 'heading', { name: 'WooCommerce' } )
		).toBeInTheDocument();
		expect(
			screen.queryByDisplayValue( 'GTM-AAA' )
		).not.toBeInTheDocument();
	} );
} );

describe( 'App import', () => {
	it( 'adopts imported values as the new clean baseline', async () => {
		// Dirty the form first: an import replaces the baseline outright, so
		// nothing may be left showing as unsaved afterwards.
		renderApp();
		editContainerId( 'GTM-DIRTY' );
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeEnabled();

		apiFetch.mockResolvedValue( {
			imported: true,
			values: {
				'gtm-code': 'GTM-IMPORTED',
				'datalayer-name': 'dataLayer',
			},
		} );

		const fileInput = document.querySelector( 'input[type="file"]' );
		const file = new File( [ '{}' ], 'settings.json' );
		file.text = () => Promise.resolve( '{}' );
		Object.defineProperty( fileInput, 'files', {
			value: [ file ],
			writable: true,
		} );
		fireEvent.change( fileInput );

		expect(
			await screen.findByDisplayValue( 'GTM-IMPORTED' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeDisabled();
	} );
} );
