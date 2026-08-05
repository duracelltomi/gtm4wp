/**
 * Tests for the settings export / import controls.
 *
 * The import file is NOT trusted here — the server re-runs every value through
 * its schema sanitizer (that end is covered by RestControllerTest's
 * hostile-payload tests). What this component owns is the three-way response
 * handling, and the distinction that actually matters to an admin: a partial
 * import must still adopt the values the server DID accept, or the screen shows
 * stale settings that disagree with what is stored.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';

import ImportExport from '../components/ImportExport';

function renderIO() {
	const onImported = jest.fn();
	const onNotice = jest.fn();

	render(
		<ImportExport
			exportPath="/gtm4wp/v2/export"
			importPath="/gtm4wp/v2/import"
			onImported={ onImported }
			onNotice={ onNotice }
		/>
	);

	return { onImported, onNotice };
}

/**
 * Drives the hidden file input with a file whose text() resolves to `payload`.
 *
 * @param {string} payload File contents.
 * @return {HTMLInputElement} The input that was changed.
 */
function chooseFile( payload ) {
	const input = document.querySelector( 'input[type="file"]' );
	const file = new File( [ payload ], 'settings.json', {
		type: 'application/json',
	} );

	// jsdom's File.text() is not implemented in every version; pin it so the
	// component reads exactly the payload this test intends.
	file.text = () => Promise.resolve( payload );

	Object.defineProperty( input, 'files', {
		value: [ file ],
		writable: true,
	} );
	fireEvent.change( input );

	return input;
}

beforeEach( () => {
	apiFetch.mockReset();
	window.URL.createObjectURL = jest.fn( () => 'blob:fake' );
	window.URL.revokeObjectURL = jest.fn();
} );

describe( 'ImportExport export', () => {
	it( 'downloads the settings returned by the export route', async () => {
		apiFetch.mockResolvedValue( { 'gtm-code': 'GTM-AAA' } );
		// Stubbed, not spied through: a real anchor click makes jsdom log an
		// unimplemented-navigation error, which the wp jest preset fails on.
		const clickSpy = jest
			.spyOn( window.HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( () => {} );

		renderIO();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Export settings' } )
		);

		await waitFor( () => expect( clickSpy ).toHaveBeenCalled() );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/gtm4wp/v2/export',
		} );
		expect( window.URL.createObjectURL ).toHaveBeenCalled();
		// The object URL is released again — a download must not leak it.
		expect( window.URL.revokeObjectURL ).toHaveBeenCalledWith(
			'blob:fake'
		);

		clickSpy.mockRestore();
	} );

	it( 'names the download with the dated export filename', async () => {
		apiFetch.mockResolvedValue( {} );
		let downloadName = '';
		const clickSpy = jest
			.spyOn( window.HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( function stubbedClick() {
				downloadName = this.download;
			} );

		renderIO();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Export settings' } )
		);

		await waitFor( () => expect( clickSpy ).toHaveBeenCalled() );
		expect( downloadName ).toMatch(
			/^gtm4wp-settings-\d{4}-\d{2}-\d{2}\.json$/
		);

		clickSpy.mockRestore();
	} );

	it( 'reports a failed export instead of downloading nothing silently', async () => {
		apiFetch.mockRejectedValue( new Error( 'Export exploded' ) );

		const { onNotice } = renderIO();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Export settings' } )
		);

		await waitFor( () =>
			expect( onNotice ).toHaveBeenCalledWith( 'Export exploded' )
		);
	} );
} );

describe( 'ImportExport import', () => {
	it( 'posts the raw file contents to the import route', async () => {
		apiFetch.mockResolvedValue( { imported: true, values: {} } );

		renderIO();
		chooseFile( '{"gtm-code":"GTM-AAA"}' );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/gtm4wp/v2/import',
			method: 'POST',
			data: { payload: '{"gtm-code":"GTM-AAA"}' },
		} );
	} );

	it( 'adopts the sanitized values the server stored on a clean import', async () => {
		apiFetch.mockResolvedValue( {
			imported: true,
			values: { 'gtm-code': 'GTM-CLEAN' },
		} );

		const { onImported, onNotice } = renderIO();
		chooseFile( '{}' );

		await waitFor( () =>
			expect( onImported ).toHaveBeenCalledWith( {
				'gtm-code': 'GTM-CLEAN',
			} )
		);
		expect( onNotice ).toHaveBeenCalledWith( 'Settings imported.' );
	} );

	it( 'still adopts the accepted values when the server rejected some', async () => {
		apiFetch.mockResolvedValue( {
			imported: false,
			values: { 'gtm-code': 'GTM-PARTIAL' },
		} );

		const { onImported, onNotice } = renderIO();
		chooseFile( '{}' );

		await waitFor( () =>
			expect( onImported ).toHaveBeenCalledWith( {
				'gtm-code': 'GTM-PARTIAL',
			} )
		);
		expect( onNotice ).toHaveBeenCalledWith(
			'Imported with errors — some values were rejected and left at their defaults.'
		);
	} );

	it( 'reports the partial-import notice without adopting anything when the server returned no values', async () => {
		apiFetch.mockResolvedValue( { imported: false } );

		const { onImported, onNotice } = renderIO();
		chooseFile( '{}' );

		await waitFor( () =>
			expect( onNotice ).toHaveBeenCalledWith(
				'Imported with errors — some values were rejected and left at their defaults.'
			)
		);
		expect( onImported ).not.toHaveBeenCalled();
	} );

	it( 'defaults to an empty value map when a clean import returns none', async () => {
		apiFetch.mockResolvedValue( { imported: true } );

		const { onImported } = renderIO();
		chooseFile( '{}' );

		await waitFor( () => expect( onImported ).toHaveBeenCalledWith( {} ) );
	} );

	it( 'reports a rejected import with the server message', async () => {
		apiFetch.mockRejectedValue( new Error( 'Not a GTM4WP export' ) );

		const { onImported, onNotice } = renderIO();
		chooseFile( 'this is not json' );

		await waitFor( () =>
			expect( onNotice ).toHaveBeenCalledWith( 'Not a GTM4WP export' )
		);
		expect( onImported ).not.toHaveBeenCalled();
	} );

	it( 'falls back to a generic message when the failure carries none', async () => {
		apiFetch.mockRejectedValue( new Error( '' ) );

		const { onNotice } = renderIO();
		chooseFile( '{}' );

		await waitFor( () =>
			expect( onNotice ).toHaveBeenCalledWith(
				'Import failed. Make sure the file is a GTM4WP settings export.'
			)
		);
	} );

	it( 'does nothing when the file dialog is dismissed without a selection', () => {
		renderIO();

		const input = document.querySelector( 'input[type="file"]' );
		Object.defineProperty( input, 'files', { value: [], writable: true } );
		fireEvent.change( input );

		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'clears the input so re-choosing the same file fires change again', async () => {
		apiFetch.mockResolvedValue( { imported: true, values: {} } );

		renderIO();
		const input = chooseFile( '{}' );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		expect( input.value ).toBe( '' );
	} );
} );
