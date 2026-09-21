/**
 * Tests for the Google service accounts panel.
 *
 * The server owns the parsing, encryption and every refusal (covered by
 * GoogleAuthRestControllerTest); what this component owns is the wire shape of
 * the five calls, the client-side size pre-check that mirrors the server cap,
 * the explicit two-step upload (choose, then Add - never submit-on-select),
 * the inline rename, the two-step delete, and the one property the whole
 * custody design rests on: nothing the panel renders can be the key. The
 * uploaded file is posted and forgotten - after an upload the DOM holds only
 * what the server answered.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';

import ServiceAccountsPanel from '../components/panels/ServiceAccountsPanel';

const REST_PATH = 'gtm4wp/v2/google/service-accounts';
const KEY_FILE_MAX_BYTES = 16384;

const DATA = {
	restPath: REST_PATH,
	keyFileMaxBytes: KEY_FILE_MAX_BYTES,
	labelMaxLength: 100,
};

const PRODUCTION = {
	id: 'sa_0123456789ab',
	label: 'Production',
	client_email: 'prod@example-project.iam.gserviceaccount.com',
	private_key_id: 'a1b2c3d4e5f6',
	uploaded_at: 1800000000,
	status: 'unverified',
	last_checked: 0,
	last_error: '',
};

const STAGING = {
	...PRODUCTION,
	id: 'sa_ba9876543210',
	label: 'Staging',
	client_email: 'staging@example-project.iam.gserviceaccount.com',
	private_key_id: 'ffeeddccbbaa',
};

// Shaped like a real key file. The "key" is a placeholder, never a
// credential; what matters is that this exact string must not reappear in
// the DOM once uploaded.
const KEY_FILE = JSON.stringify( {
	type: 'service_account',
	client_email: 'prod@example-project.iam.gserviceaccount.com',
	private_key:
		'-----BEGIN PRIVATE KEY-----\nNOT-A-REAL-KEY\n-----END PRIVATE KEY-----\n',
	private_key_id: 'a1b2c3d4e5f6',
	token_uri: 'https://oauth2.googleapis.com/token',
} );

function renderPanel( data = DATA ) {
	return render( <ServiceAccountsPanel data={ data } /> );
}

/**
 * Renders the panel with the given stored accounts and waits for the list.
 *
 * @param {Array} accounts Accounts the listing route answers with.
 */
async function renderLoaded( accounts ) {
	apiFetch.mockResolvedValueOnce( { accounts } );
	renderPanel();
	await waitFor( () =>
		expect( screen.queryByRole( 'progressbar' ) ).not.toBeInTheDocument()
	);
}

/**
 * Drives the hidden file input with a file whose text() resolves to `payload`.
 *
 * @param {string} payload File contents.
 * @param {number} [size]  Byte size to report; defaults to the payload length.
 * @return {HTMLInputElement} The input that was changed.
 */
function chooseFile( payload, size = payload.length ) {
	const input = document.querySelector( 'input[type="file"]' );
	const file = new File( [ payload ], 'project-key.json', {
		type: 'application/json',
	} );

	// jsdom's File.text() is not implemented in every version; pin it so the
	// component reads exactly the payload this test intends. `size` is pinned
	// for the same reason: it is what the pre-check reads.
	file.text = () => Promise.resolve( payload );
	Object.defineProperty( file, 'size', { value: size } );

	Object.defineProperty( input, 'files', {
		value: [ file ],
		writable: true,
	} );
	fireEvent.change( input );

	return input;
}

/**
 * Clicks the explicit submit button of the add form.
 */
function submitUpload() {
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Add service account' } )
	);
}

beforeEach( () => {
	// mockClear, not mockReset: reset would erase the stand-in's default
	// "unconfigured call rejects loudly" implementation for the rest of the
	// file (see the test-support header), silently downgrading every
	// unexpected call to a resolved undefined.
	apiFetch.mockClear();
} );

describe( 'ServiceAccountsPanel listing', () => {
	it( 'loads the accounts from the module REST path and renders their public fields', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );

		expect( apiFetch ).toHaveBeenCalledWith( { path: REST_PATH } );

		const rows = screen.getAllByRole( 'row' ).slice( 1 );
		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ] ).toHaveTextContent( 'Production' );
		expect( rows[ 0 ] ).toHaveTextContent( PRODUCTION.client_email );
		expect( rows[ 0 ] ).toHaveTextContent( PRODUCTION.private_key_id );
		expect( rows[ 0 ] ).toHaveTextContent( 'Not tested yet' );
		expect( rows[ 1 ] ).toHaveTextContent( 'Staging' );
	} );

	it( 'says so when nothing is stored yet', async () => {
		await renderLoaded( [] );

		expect(
			screen.getByText( 'No service accounts stored yet.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the server status wording and the last error of a failed account', async () => {
		await renderLoaded( [
			{
				...PRODUCTION,
				status: 'error',
				last_error: 'invalid_grant: Invalid JWT Signature.',
			},
			{ ...STAGING, status: 'reupload-required' },
		] );

		expect( screen.getByText( 'Failed' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'invalid_grant: Invalid JWT Signature.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Key unreadable, upload the key file again' )
		).toBeInTheDocument();
	} );

	it( 'shows an unknown server status verbatim instead of hiding it', async () => {
		// The statusText() doc block declares this forward-compat behavior: a
		// status this map has not learned yet must stay visible.
		await renderLoaded( [ { ...PRODUCTION, status: 'quota-exceeded' } ] );

		expect( screen.getByText( 'quota-exceeded' ) ).toBeInTheDocument();
	} );

	it( 'renders a dash for a never-set date and a missing key id', async () => {
		await renderLoaded( [
			{ ...PRODUCTION, uploaded_at: 0, private_key_id: '' },
		] );

		const row = screen.getAllByRole( 'row' )[ 1 ];
		expect( row.textContent.match( /—/g ) ).toHaveLength( 2 );
	} );

	it( 'reports a failed load instead of spinning forever', async () => {
		apiFetch.mockRejectedValueOnce(
			new Error( 'Sorry, you are not allowed to do that.' )
		);

		renderPanel();

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Sorry, you are not allowed to do that.'
			)
		);
		expect( screen.queryByRole( 'progressbar' ) ).not.toBeInTheDocument();
		// The upload form is still there: a failed load must not lock the
		// admin out of adding the first account.
		expect(
			screen.getByRole( 'button', { name: 'Choose key file' } )
		).toBeInTheDocument();
	} );
} );

describe( 'ServiceAccountsPanel upload', () => {
	it( 'does not send anything when a file is merely chosen', async () => {
		await renderLoaded( [] );

		chooseFile( KEY_FILE );

		// Selecting the file only arms the Add button; the label stays
		// editable and nothing is posted until the admin submits.
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByText( 'project-key.json' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Add service account' } )
		).toBeEnabled();
	} );

	it( 'sends a label typed after the file was chosen', async () => {
		await renderLoaded( [] );
		apiFetch.mockResolvedValueOnce( {
			account: PRODUCTION,
			accounts: [ PRODUCTION ],
		} );

		// File first, label second - the order the report called out.
		chooseFile( KEY_FILE );
		fireEvent.change( screen.getByLabelText( 'Label' ), {
			target: { value: 'Production' },
		} );
		submitUpload();

		await waitFor( () =>
			expect( apiFetch ).toHaveBeenLastCalledWith( {
				path: REST_PATH,
				method: 'POST',
				data: { label: 'Production', key_file: KEY_FILE },
			} )
		);
	} );

	it( 'refuses to submit without a chosen file', async () => {
		await renderLoaded( [] );

		expect(
			screen.getByRole( 'button', { name: 'Add service account' } )
		).toBeDisabled();
		submitUpload();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'resets the label and the chosen file without sending anything', async () => {
		await renderLoaded( [] );

		fireEvent.change( screen.getByLabelText( 'Label' ), {
			target: { value: 'Production' },
		} );
		chooseFile( KEY_FILE );
		fireEvent.click( screen.getByRole( 'button', { name: 'Reset' } ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByLabelText( 'Label' ) ).toHaveValue( '' );
		expect(
			screen.queryByText( 'project-key.json' )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'No file selected yet.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Add service account' } )
		).toBeDisabled();
	} );

	it( 'posts the label and the raw file text to the collection route', async () => {
		await renderLoaded( [] );
		apiFetch.mockResolvedValueOnce( {
			account: PRODUCTION,
			accounts: [ PRODUCTION ],
		} );

		fireEvent.change( screen.getByLabelText( 'Label' ), {
			target: { value: 'Production' },
		} );
		chooseFile( KEY_FILE );
		submitUpload();

		await waitFor( () =>
			expect( apiFetch ).toHaveBeenLastCalledWith( {
				path: REST_PATH,
				method: 'POST',
				data: { label: 'Production', key_file: KEY_FILE },
			} )
		);
	} );

	it( 'adopts the returned list, clears the form and confirms the upload', async () => {
		await renderLoaded( [] );
		apiFetch.mockResolvedValueOnce( {
			account: PRODUCTION,
			accounts: [ PRODUCTION ],
		} );

		fireEvent.change( screen.getByLabelText( 'Label' ), {
			target: { value: 'Production' },
		} );
		chooseFile( KEY_FILE );
		submitUpload();

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Service account Production added. Use Test to check that it can reach Google.'
			)
		);
		expect( screen.getByRole( 'alert' ) ).toHaveAttribute(
			'data-status',
			'success'
		);
		expect( screen.getByLabelText( 'Label' ) ).toHaveValue( '' );
		expect(
			screen.getByText( 'No file selected yet.' )
		).toBeInTheDocument();
		expect( screen.getAllByRole( 'row' ).slice( 1 ) ).toHaveLength( 1 );
	} );

	it( 'never renders the uploaded key file, only what the server answered', async () => {
		await renderLoaded( [] );
		apiFetch.mockResolvedValueOnce( {
			account: PRODUCTION,
			accounts: [ PRODUCTION ],
		} );

		chooseFile( KEY_FILE );
		submitUpload();

		await waitFor( () =>
			expect( screen.getAllByRole( 'row' ).slice( 1 ) ).toHaveLength( 1 )
		);

		// The rendered document holds the public view and nothing of the file.
		expect( document.body.innerHTML ).not.toContain( 'BEGIN PRIVATE KEY' );
		expect( document.body.innerHTML ).not.toContain( 'NOT-A-REAL-KEY' );
		expect( document.body.innerHTML ).not.toContain( 'private_key' );
	} );

	it( 'confirms an upload even when the response carries no account', async () => {
		await renderLoaded( [] );
		apiFetch.mockResolvedValueOnce( { accounts: [ PRODUCTION ] } );

		chooseFile( KEY_FILE );
		submitUpload();

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Service account added. Use Test to check that it can reach Google.'
			)
		);
		expect( screen.getAllByRole( 'row' ).slice( 1 ) ).toHaveLength( 1 );
	} );

	it( 'refuses a file over the server cap at selection, before it is read or sent', async () => {
		await renderLoaded( [] );

		const file = chooseFile( KEY_FILE, KEY_FILE_MAX_BYTES + 1 );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'That file is too large to be a service account key file.'
		);
		// Only the initial listing call happened, and the refused file was
		// never kept: the submit button stays unarmed.
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( file.value ).toBe( '' );
		expect(
			screen.getByRole( 'button', { name: 'Add service account' } )
		).toBeDisabled();
	} );

	it( 'accepts a file exactly at the cap', async () => {
		await renderLoaded( [] );
		apiFetch.mockResolvedValueOnce( {
			account: PRODUCTION,
			accounts: [ PRODUCTION ],
		} );

		chooseFile( KEY_FILE, KEY_FILE_MAX_BYTES );
		submitUpload();

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
	} );

	it( 'shows the server refusal and keeps the list as it was', async () => {
		await renderLoaded( [ STAGING ] );
		apiFetch.mockRejectedValueOnce( {
			code: 'gtm4wp_google_key_invalid',
			message: 'This is not a Google Cloud service account key file.',
		} );

		chooseFile( '{"type":"authorized_user"}' );
		submitUpload();

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'This is not a Google Cloud service account key file.'
			)
		);
		expect( screen.getByRole( 'alert' ) ).toHaveAttribute(
			'data-status',
			'error'
		);
		expect( screen.getAllByRole( 'row' ).slice( 1 ) ).toHaveLength( 1 );
		expect( screen.getByText( 'Staging' ) ).toBeInTheDocument();
	} );

	it( 'falls back to a generic message when the failure carries none', async () => {
		await renderLoaded( [] );
		apiFetch.mockRejectedValueOnce( new Error( '' ) );

		chooseFile( KEY_FILE );
		submitUpload();

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'The key file could not be uploaded.'
			)
		);
	} );

	it( 'reports an unreadable file without sending anything', async () => {
		await renderLoaded( [] );

		const input = document.querySelector( 'input[type="file"]' );
		const file = new File( [ KEY_FILE ], 'project-key.json', {
			type: 'application/json',
		} );
		file.text = () => Promise.reject( new Error( '' ) );
		Object.defineProperty( input, 'files', {
			value: [ file ],
			writable: true,
		} );
		fireEvent.change( input );
		submitUpload();

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'The key file could not be uploaded.'
			)
		);
		// A read failure must never post a partial body.
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does nothing when the file dialog is dismissed without a selection', async () => {
		await renderLoaded( [] );

		const input = document.querySelector( 'input[type="file"]' );
		Object.defineProperty( input, 'files', { value: [], writable: true } );
		fireEvent.change( input );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'names the label limit the server enforces', async () => {
		await renderLoaded( [] );

		expect(
			screen.getByText(
				'Optional name shown instead of the account email, up to 100 characters.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'ServiceAccountsPanel test action', () => {
	it( 'posts to the account test route and adopts the returned account into its row only', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );
		apiFetch.mockResolvedValueOnce( {
			ok: true,
			message: 'Google accepted the key.',
			account: { ...PRODUCTION, status: 'ok', last_checked: 1800000100 },
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Google accepted the key.'
			)
		);
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: `${ REST_PATH }/${ PRODUCTION.id }/test`,
			method: 'POST',
		} );
		expect( screen.getByRole( 'alert' ) ).toHaveAttribute(
			'data-status',
			'success'
		);
		// Only the tested row adopts the response; an implementation mapping
		// every row onto response.account would show 'Working' twice.
		expect( screen.getAllByText( 'Working' ) ).toHaveLength( 1 );
		expect( screen.getByText( 'Not tested yet' ) ).toBeInTheDocument();
		expect( screen.getByText( STAGING.client_email ) ).toBeInTheDocument();
	} );

	it( 'keeps the list as it was when the response carries no account', async () => {
		await renderLoaded( [ PRODUCTION ] );
		apiFetch.mockResolvedValueOnce( {
			ok: true,
			message: 'Google accepted the key.',
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Google accepted the key.'
			)
		);
		expect( screen.getByText( 'Not tested yet' ) ).toBeInTheDocument();
	} );

	it( 'ignores further actions while a request is in flight', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );

		let finishTest;
		apiFetch.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					finishTest = resolve;
				} )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);
		// In flight: a second Test must not open a second request - the
		// handlers re-check isBusy because the rendered disabled prop cannot
		// be trusted across the supported WP range (TC-15).
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Staging' } )
		);

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );

		finishTest( {
			ok: true,
			message: 'Google accepted the key.',
			account: { ...PRODUCTION, status: 'ok' },
		} );
		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Google accepted the key.'
			)
		);
	} );

	it( 'shows a refusal as an error and the account as failed', async () => {
		await renderLoaded( [ PRODUCTION ] );
		apiFetch.mockResolvedValueOnce( {
			ok: false,
			message: 'invalid_grant: Invalid JWT Signature.',
			account: {
				...PRODUCTION,
				status: 'error',
				last_error: 'invalid_grant: Invalid JWT Signature.',
			},
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveAttribute(
				'data-status',
				'error'
			)
		);
		expect( screen.getByText( 'Failed' ) ).toBeInTheDocument();
	} );

	it( 'reports a failed request', async () => {
		await renderLoaded( [ PRODUCTION ] );
		apiFetch.mockRejectedValueOnce( new Error( '' ) );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'The service account could not be tested.'
			)
		);
		expect( screen.getByText( 'Not tested yet' ) ).toBeInTheDocument();
	} );
} );

describe( 'ServiceAccountsPanel rename', () => {
	const EDIT_FIELD = `New label for ${ PRODUCTION.client_email }`;

	it( 'opens the editor prefilled with the current label without sending anything', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Rename Production' } )
		);

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByLabelText( EDIT_FIELD ) ).toHaveValue(
			'Production'
		);
		// Only the edited row swaps its actions; the other row keeps its own.
		expect(
			screen.getByRole( 'button', { name: 'Rename Staging' } )
		).toBeInTheDocument();
	} );

	it( 'posts the new label to the account route and adopts the answer', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );
		const renamed = { ...PRODUCTION, label: 'Live site' };
		apiFetch.mockResolvedValueOnce( {
			account: renamed,
			accounts: [ renamed, STAGING ],
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Rename Production' } )
		);
		fireEvent.change( screen.getByLabelText( EDIT_FIELD ), {
			target: { value: 'Live site' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Save the new label of Production',
			} )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Service account renamed to Live site.'
			)
		);
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: `${ REST_PATH }/${ PRODUCTION.id }`,
			method: 'POST',
			data: { label: 'Live site' },
		} );
		expect( screen.getByText( 'Live site' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Production' ) ).not.toBeInTheDocument();
		// The editor is closed again.
		expect( screen.queryByLabelText( EDIT_FIELD ) ).not.toBeInTheDocument();
	} );

	it( 'ignores a second Save while the rename is in flight', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );

		let finishRename;
		apiFetch.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					finishRename = resolve;
				} )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Rename Production' } )
		);
		fireEvent.change( screen.getByLabelText( EDIT_FIELD ), {
			target: { value: 'Live site' },
		} );
		const save = screen.getByRole( 'button', {
			name: 'Save the new label of Production',
		} );
		fireEvent.click( save );
		// In flight: the prop layer disables the button (which is what jsdom
		// can see - a disabled button never delivers a click) and the handler
		// re-checks isBusy behind it, the recorded TC-15 r7 blind spot. Same
		// shape as the Test button's case above; the rename had no such pin.
		expect( save ).toBeDisabled();
		fireEvent.click( save );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 ); // the load + one rename

		const renamed = { ...PRODUCTION, label: 'Live site' };
		finishRename( { account: renamed, accounts: [ renamed, STAGING ] } );
		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Service account renamed to Live site.'
			)
		);
	} );

	it( 'can be cancelled without a request and keeps the stored label', async () => {
		await renderLoaded( [ PRODUCTION ] );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Rename Production' } )
		);
		fireEvent.change( screen.getByLabelText( EDIT_FIELD ), {
			target: { value: 'Scrapped edit' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByText( 'Production' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Rename Production' } )
		).toBeInTheDocument();
	} );

	it( 'keeps the editor open with the typed label when the rename fails', async () => {
		await renderLoaded( [ PRODUCTION ] );
		apiFetch.mockRejectedValueOnce( new Error( '' ) );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Rename Production' } )
		);
		fireEvent.change( screen.getByLabelText( EDIT_FIELD ), {
			target: { value: 'Live site' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Save the new label of Production',
			} )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'The service account could not be renamed.'
			)
		);
		// Still editable, so the admin can correct and retry.
		expect( screen.getByLabelText( EDIT_FIELD ) ).toHaveValue(
			'Live site'
		);
	} );
} );

describe( 'ServiceAccountsPanel delete', () => {
	it( 'asks for confirmation before sending anything', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );

		// The delete control is the same destructive trash-icon button the
		// container and destination tables render, named for screen readers.
		const deleteButton = screen.getByRole( 'button', {
			name: 'Delete Production',
		} );
		expect( deleteButton ).toHaveAttribute( 'data-icon', 'trash' );
		expect( deleteButton ).toHaveAttribute( 'data-destructive', 'true' );

		fireEvent.click( deleteButton );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'button', {
				name: 'Confirm deleting Production',
			} )
		).toBeInTheDocument();
		// Only the row asked about switches to its confirmation.
		expect(
			screen.getByRole( 'button', { name: 'Delete Staging' } )
		).toBeInTheDocument();
	} );

	it( 'can be cancelled', async () => {
		await renderLoaded( [ PRODUCTION ] );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Delete Production' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'button', { name: 'Delete Production' } )
		).toBeInTheDocument();
	} );

	it( 'sends DELETE to the account route on confirmation and adopts the returned list', async () => {
		await renderLoaded( [ PRODUCTION, STAGING ] );
		apiFetch.mockResolvedValueOnce( { accounts: [ STAGING ] } );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Delete Production' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Confirm deleting Production',
			} )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Service account Production deleted.'
			)
		);
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: `${ REST_PATH }/${ PRODUCTION.id }`,
			method: 'DELETE',
		} );
		expect( screen.queryByText( 'Production' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Staging' ) ).toBeInTheDocument();
	} );

	it( 'keeps an account the server refuses to delete', async () => {
		await renderLoaded( [ PRODUCTION ] );
		apiFetch.mockRejectedValueOnce( {
			code: 'gtm4wp_google_account_in_use',
			message: 'This service account is still in use.',
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Delete Production' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Confirm deleting Production',
			} )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'This service account is still in use.'
			)
		);
		expect( screen.getByText( 'Production' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Delete Production' } )
		).toBeInTheDocument();
	} );
} );
