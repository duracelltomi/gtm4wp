/**
 * Tests for the Axeptio cookies-version field.
 *
 * This is the only admin control that talks to a THIRD-PARTY host
 * (client.axept.io) rather than to WordPress, so its untrusted boundary is the
 * shape of that response — the JS analogue of TC-11's "validate at the message
 * boundary". Four outcomes have to hold, and three of them are failure modes an
 * admin hits routinely (unpublished project, strict admin CSP, ad blocker,
 * offline wp-admin):
 *
 *   response ok + cookies[]  -> dropdown
 *   response ok + empty list -> manual entry + explanation
 *   response ok + junk shape -> manual entry (must not throw on a non-array)
 *   network / !ok            -> manual entry + explanation
 *
 * Plus the effect's `cancelled` guard: the Project ID field next to this one is
 * free text, so it changes on nearly every keystroke and slow responses arrive
 * out of order. A late response for an ABANDONED project id must not overwrite
 * the current one.
 */

import { act, render, screen, waitFor } from '@testing-library/react';

import AxeptioVersionControl from '../components/AxeptioVersionControl';

const PROJECT_ID_KEY = 'integrate-axeptio-projectid';

const MANUAL_ENTRY_AFTER_FAILURE =
	'The Axeptio project versions could not be loaded. Enter the cookies version manually below.';
const MANUAL_ENTRY_AFTER_EMPTY =
	'We were unable to find your Axeptio project, or it has not been published yet. Enter the cookies version manually below.';

function renderControl( { projectId = 'proj-1', value = '' } = {} ) {
	const onChange = jest.fn();

	const view = render(
		<AxeptioVersionControl
			value={ value }
			allValues={ { [ PROJECT_ID_KEY ]: projectId } }
			label="Cookies version"
			help={ null }
			onChange={ onChange }
		/>
	);

	return { onChange, ...view };
}

/**
 * Resolves to a fetch-like ok response carrying `body`.
 *
 * @param {Object} body Parsed JSON body the response resolves to.
 * @return {Promise} Fetch-like response promise.
 */
function okResponse( body ) {
	return Promise.resolve( {
		ok: true,
		json: () => Promise.resolve( body ),
	} );
}

beforeEach( () => {
	window.fetch = jest.fn();
} );

afterEach( () => {
	delete window.fetch;
	jest.clearAllMocks();
} );

describe( 'AxeptioVersionControl without a Project ID', () => {
	it( 'does not call the Axeptio API at all', () => {
		renderControl( { projectId: '' } );

		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'disables the dropdown and explains what to do first', () => {
		renderControl( { projectId: '' } );

		expect( screen.getByRole( 'combobox' ) ).toBeDisabled();
		expect(
			screen.getByRole( 'option', {
				name: 'Enter your Project ID above to load versions',
			} )
		).toBeInTheDocument();
	} );

	it( 'treats a whitespace-only Project ID as absent', () => {
		renderControl( { projectId: '   ' } );

		expect( window.fetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'AxeptioVersionControl with published versions', () => {
	it( 'requests the project JSON with the Project ID URL-encoded', async () => {
		window.fetch.mockReturnValue( okResponse( { cookies: [] } ) );

		renderControl( { projectId: 'a/b c' } );

		await waitFor( () => expect( window.fetch ).toHaveBeenCalled() );

		const url = window.fetch.mock.calls[ 0 ][ 0 ];
		expect( url ).toContain( 'https://client.axept.io/a%2Fb%20c.json' );
	} );

	it( 'offers every published version as an option', async () => {
		window.fetch.mockReturnValue(
			okResponse( {
				cookies: [
					{ name: 'v1', title: 'Version one' },
					{ name: 'v2', title: 'Version two' },
				],
			} )
		);

		renderControl();

		expect(
			await screen.findByRole( 'option', { name: 'Version one' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'option', { name: 'Version two' } )
		).toBeInTheDocument();
		// Still a dropdown, not the manual-entry fallback.
		expect( screen.getByRole( 'combobox' ) ).toBeInTheDocument();
	} );

	it( 'keeps a saved version selectable even when the project no longer lists it', async () => {
		window.fetch.mockReturnValue(
			okResponse( { cookies: [ { name: 'v2', title: 'Version two' } ] } )
		);

		renderControl( { value: 'retired-version' } );

		expect(
			await screen.findByRole( 'option', { name: 'retired-version' } )
		).toBeInTheDocument();
	} );
} );

describe( 'AxeptioVersionControl degradation to manual entry', () => {
	it( 'falls back to a text field when the project publishes no versions', async () => {
		window.fetch.mockReturnValue( okResponse( { cookies: [] } ) );

		renderControl();

		expect(
			await screen.findByText( MANUAL_ENTRY_AFTER_EMPTY )
		).toBeInTheDocument();
		expect( screen.getByRole( 'textbox' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'combobox' ) ).not.toBeInTheDocument();
	} );

	it( 'falls back when the response body is not the expected shape', async () => {
		// A non-array `cookies` is the shape a proxy/error page can produce.
		window.fetch.mockReturnValue( okResponse( { cookies: 'nope' } ) );

		renderControl();

		expect(
			await screen.findByText( MANUAL_ENTRY_AFTER_EMPTY )
		).toBeInTheDocument();
	} );

	it( 'falls back when the response body has no cookies key at all', async () => {
		window.fetch.mockReturnValue( okResponse( {} ) );

		renderControl();

		expect(
			await screen.findByText( MANUAL_ENTRY_AFTER_EMPTY )
		).toBeInTheDocument();
	} );

	it( 'falls back on a non-ok HTTP status', async () => {
		window.fetch.mockReturnValue( Promise.resolve( { ok: false } ) );

		renderControl();

		expect(
			await screen.findByText( MANUAL_ENTRY_AFTER_FAILURE )
		).toBeInTheDocument();
	} );

	it( 'falls back when the request is blocked or the network fails', async () => {
		window.fetch.mockReturnValue(
			Promise.reject( new Error( 'blocked' ) )
		);

		renderControl();

		expect(
			await screen.findByText( MANUAL_ENTRY_AFTER_FAILURE )
		).toBeInTheDocument();
	} );

	it( 'keeps the saved value editable in the manual field', async () => {
		window.fetch.mockReturnValue(
			Promise.reject( new Error( 'blocked' ) )
		);

		renderControl( { value: 'saved-version' } );

		await screen.findByText( MANUAL_ENTRY_AFTER_FAILURE );
		expect(
			screen.getByDisplayValue( 'saved-version' )
		).toBeInTheDocument();
	} );
} );

describe( 'AxeptioVersionControl stale-response guard', () => {
	it( 'ignores a response for a Project ID the admin has already moved on from', async () => {
		let resolveFirst;
		const firstResponse = new Promise( ( resolve ) => {
			resolveFirst = resolve;
		} );

		window.fetch.mockReturnValueOnce( firstResponse ).mockReturnValueOnce(
			okResponse( {
				cookies: [ { name: 'current', title: 'Current project' } ],
			} )
		);

		const { rerender } = renderControl( { projectId: 'old-project' } );

		// The admin keeps typing: a second Project ID supersedes the first.
		rerender(
			<AxeptioVersionControl
				value=""
				allValues={ { [ PROJECT_ID_KEY ]: 'new-project' } }
				label="Cookies version"
				help={ null }
				onChange={ jest.fn() }
			/>
		);

		expect(
			await screen.findByRole( 'option', { name: 'Current project' } )
		).toBeInTheDocument();

		// The abandoned request now finishes. Its result must be discarded.
		await act( async () => {
			resolveFirst( {
				ok: true,
				json: () =>
					Promise.resolve( {
						cookies: [ { name: 'stale', title: 'Stale project' } ],
					} ),
			} );
			await firstResponse;
		} );

		expect(
			screen.queryByRole( 'option', { name: 'Stale project' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'option', { name: 'Current project' } )
		).toBeInTheDocument();
	} );
} );
