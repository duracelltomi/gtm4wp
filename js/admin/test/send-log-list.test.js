/**
 * Tests for the Data Manager "Recent sends" list.
 *
 * The server owns what a stored entry may contain (covered by
 * GoogleDataManagerSendLogTest); what this component owns is turning those
 * entries into something an admin can act on - above all the entries that
 * explain why nothing was sent, which are the whole reason the ring exists -
 * and never falling over on a shape it did not expect.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';

import SendLogList from '../components/panels/SendLogList';

const LOG_PATH = 'gtm4wp/v2/google/send-log';

function entry( overrides = {} ) {
	return {
		time: 1800000000,
		feature: 'refund',
		reference: 'woocommerce:12:34',
		destination: 'G-ABC123',
		outcome: 'accepted',
		attempt: 1,
		status: 200,
		request_id: 'req-42',
		reason: '',
		result: '',
		errors: 0,
		warnings: 0,
		tone: 'ok',
		...overrides,
	};
}

beforeEach( () => {
	// mockClear, not mockReset: reset would erase the stand-in's default
	// "unconfigured call rejects loudly" implementation for the rest of the
	// file. Every expected call is queued with a ...Once value for the same
	// reason - a persistent value would swallow an unexpected extra request.
	apiFetch.mockClear();
} );

describe( 'SendLogList loading', () => {
	it( 'reads the log from the path the server named', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		expect( apiFetch ).toHaveBeenCalledWith( { path: LOG_PATH } );
	} );

	it( 'renders nothing at all without a path', () => {
		const { container } = render( <SendLogList logPath={ undefined } /> );

		expect( container ).toBeEmptyDOMElement();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'says so when nothing has been sent yet', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect(
			await screen.findByText( 'Nothing has been sent yet.' )
		).toBeInTheDocument();
	} );

	it( 'reports a failed load instead of showing an empty list as fact', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'Forbidden' ) );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect( await screen.findByText( 'Forbidden' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Nothing has been sent yet.' )
		).not.toBeInTheDocument();
	} );

	it( 'treats a response that is not the envelope as no entries', async () => {
		apiFetch.mockResolvedValueOnce( 'not-the-envelope' );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect(
			await screen.findByText( 'Nothing has been sent yet.' )
		).toBeInTheDocument();
	} );

	it( 'reloads on demand', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );
		apiFetch.mockResolvedValueOnce( { entries: [ entry() ] } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		await screen.findByText( 'Nothing has been sent yet.' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Refresh' } ) );

		expect( await screen.findByText( 'Accepted' ) ).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'SendLogList entries', () => {
	it( 'shows an accepted send with what it was and where it went', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [ entry() ] } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect( await screen.findByText( 'Accepted' ) ).toBeInTheDocument();
		expect( screen.getByText( 'G-ABC123' ) ).toBeInTheDocument();
		expect( screen.getByText( /woocommerce:12:34/ ) ).toBeInTheDocument();
	} );

	it( 'explains in words why a send was skipped', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [
				entry( {
					outcome: 'skipped',
					reason: 'consent_denied',
					destination: '',
					status: 0,
					request_id: '',
				} ),
			],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect( await screen.findByText( 'Not sent' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'The buyer did not allow analytics storage.' )
		).toBeInTheDocument();
	} );

	it( 'distinguishes a missing client id from a refused one', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [
				entry( { outcome: 'skipped', reason: 'no_client_id' } ),
			],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect(
			await screen.findByText( /No Google Analytics client ID/ )
		).toBeInTheDocument();
	} );

	it( 'says a failed send is being tried again', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [ entry( { outcome: 'retrying', status: 503 } ) ],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect(
			await screen.findByText( 'Failed, trying again' )
		).toBeInTheDocument();
	} );

	it( 'reports what Google did with an accepted request once it knows', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [
				entry( {
					result: 'PARTIAL_SUCCESS',
					errors: 2,
					warnings: 1,
				} ),
			],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect(
			await screen.findByText(
				'PARTIAL_SUCCESS (2 with errors, 1 with warnings)'
			)
		).toBeInTheDocument();
	} );

	it( 'says a request is still being processed rather than showing the raw status', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [ entry( { result: 'PROCESSING' } ) ],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect(
			await screen.findByText( 'Google is still processing it' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'PROCESSING' ) ).not.toBeInTheDocument();
	} );

	it( 'shows an outcome it does not know rather than a blank cell', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [
				entry( { outcome: 'quarantined', reason: 'some_new_reason' } ),
			],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect( await screen.findByText( 'quarantined' ) ).toBeInTheDocument();
		expect( screen.getByText( 'some_new_reason' ) ).toBeInTheDocument();
	} );

	it( 'renders an entry with no destination without breaking the row', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [
				entry( {
					outcome: 'skipped',
					reason: 'no_destination',
					destination: '',
				} ),
			],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect( await screen.findByText( 'Not sent' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'No destination is configured to send to.' )
		).toBeInTheDocument();
	} );
} );

describe( 'SendLogList with every send lane off', () => {
	it( 'renders no block at all while the log is empty', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );

		const { container } = render(
			<SendLogList logPath={ LOG_PATH } hideWhenEmpty />
		);

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		expect( container ).toBeEmptyDOMElement();
		expect(
			screen.queryByText( 'Nothing has been sent yet.' )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'heading', { name: 'Recent sends' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Refresh' } )
		).not.toBeInTheDocument();
	} );

	it( 'still shows what was sent while a lane was on', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [ entry() ] } );

		render( <SendLogList logPath={ LOG_PATH } hideWhenEmpty /> );

		expect( await screen.findByText( 'Accepted' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Recent sends' } )
		).toBeInTheDocument();
	} );

	it( 'stays silent when the log cannot be loaded either', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'Forbidden' ) );

		const { container } = render(
			<SendLogList logPath={ LOG_PATH } hideWhenEmpty />
		);

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		expect( container ).toBeEmptyDOMElement();
		expect( screen.queryByText( 'Forbidden' ) ).not.toBeInTheDocument();
	} );

	it( 'says so when a lane is on and nothing has been sent yet', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );

		render( <SendLogList logPath={ LOG_PATH } hideWhenEmpty={ false } /> );

		expect(
			await screen.findByText( 'Nothing has been sent yet.' )
		).toBeInTheDocument();
	} );
} );

describe( 'SendLogList tones', () => {
	it( 'wears the tone the server decided, on the row', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [ entry( { tone: 'error', outcome: 'failed' } ) ],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		const row = ( await screen.findByText( 'Failed' ) ).closest( 'tr' );

		expect( row ).toHaveClass( 'gtm4wp-send-log__row--error' );
	} );

	it( 'keeps the words as well as the colour', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [
				entry( {
					tone: 'warn',
					outcome: 'skipped',
					reason: 'consent_denied',
					result: '',
				} ),
			],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		// A reader who cannot tell the colours apart still gets the whole story.
		expect( await screen.findByText( 'Not sent' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'The buyer did not allow analytics storage.' )
		).toBeInTheDocument();
	} );

	it( 'draws a tone it does not know as the neutral one, never as a success', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [ entry( { tone: 'catastrophic' } ) ],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		const row = ( await screen.findByText( 'Accepted' ) ).closest( 'tr' );

		expect( row ).toHaveClass( 'gtm4wp-send-log__row--pending' );
		expect( row ).not.toHaveClass( 'gtm4wp-send-log__row--ok' );
	} );

	it( 'falls back to the neutral tone for an entry from an older server', async () => {
		const without = entry();
		delete without.tone;

		apiFetch.mockResolvedValueOnce( { entries: [ without ] } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		const row = ( await screen.findByText( 'Accepted' ) ).closest( 'tr' );

		expect( row ).toHaveClass( 'gtm4wp-send-log__row--pending' );
	} );
} );

describe( 'SendLogList problems-only filter', () => {
	const mixed = [
		entry( {
			reference: 'woocommerce:1:2',
			tone: 'ok',
			result: 'SUCCESS',
		} ),
		entry( {
			reference: 'woocommerce:3:4',
			tone: 'warn',
			outcome: 'skipped',
			reason: 'no_client_id',
			result: '',
		} ),
		entry( {
			reference: 'woocommerce:5:6',
			tone: 'error',
			outcome: 'failed',
			result: '',
		} ),
	];

	it( 'counts what needs attention before anything is hidden', async () => {
		apiFetch.mockResolvedValueOnce( { entries: mixed } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		expect(
			await screen.findByLabelText( 'Only what needs attention (2)' )
		).toBeInTheDocument();
	} );

	it( 'leaves the accepted sends out once it is on', async () => {
		apiFetch.mockResolvedValueOnce( { entries: mixed } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		fireEvent.click(
			await screen.findByLabelText( 'Only what needs attention (2)' )
		);

		expect( screen.getByText( /woocommerce:3:4/ ) ).toBeInTheDocument();
		expect( screen.getByText( /woocommerce:5:6/ ) ).toBeInTheDocument();
		expect(
			screen.queryByText( /woocommerce:1:2/ )
		).not.toBeInTheDocument();
	} );

	it( 'shows everything again when it is switched back off', async () => {
		apiFetch.mockResolvedValueOnce( { entries: mixed } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		const toggle = await screen.findByLabelText(
			'Only what needs attention (2)'
		);

		fireEvent.click( toggle );
		fireEvent.click( toggle );

		expect( screen.getByText( /woocommerce:1:2/ ) ).toBeInTheDocument();
	} );

	it( 'says so rather than showing an empty table when nothing needs attention', async () => {
		apiFetch.mockResolvedValueOnce( {
			entries: [ entry( { tone: 'ok', result: 'SUCCESS' } ) ],
		} );

		render( <SendLogList logPath={ LOG_PATH } /> );

		fireEvent.click(
			await screen.findByLabelText( 'Only what needs attention (0)' )
		);

		expect(
			screen.getByText(
				'Nothing needs attention: every send in this list was accepted.'
			)
		).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	it( 'is not offered while the list is empty', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );

		render( <SendLogList logPath={ LOG_PATH } /> );

		await screen.findByText( 'Nothing has been sent yet.' );

		expect( screen.queryByRole( 'checkbox' ) ).not.toBeInTheDocument();
	} );
} );
