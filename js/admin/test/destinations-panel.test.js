/**
 * Tests for the Data Manager destinations test panel.
 *
 * The server owns the row validation and the validateOnly probe (covered by
 * GoogleDataManagerRestControllerTest); what this component owns is which
 * rows get a Test button (the CURRENT editor rows, unsaved edits included),
 * the wire shape of the test call, the per-row result reporting, and the
 * stored-health readout for a destination whose real sends keep failing.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';

import DestinationsPanel from '../components/panels/DestinationsPanel';

const TEST_PATH = 'gtm4wp/v2/google/destinations/test';
const OPTION_KEY = 'gdm-destinations';

const ROW = {
	label: 'Production',
	service_account: 'sa_0123456789ab',
	type: 'ga4',
	property_id: '123456789',
	measurement_id: 'G-ABC123',
};

function panelData( overrides = {} ) {
	return {
		testPath: TEST_PATH,
		optionKey: OPTION_KEY,
		health: {},
		threshold: 3,
		columnChoices: {
			[ OPTION_KEY ]: {
				service_account: { sa_0123456789ab: 'Production SA' },
			},
		},
		...overrides,
	};
}

function renderPanel( { data = panelData(), rows = [ ROW ] } = {} ) {
	return render(
		<DestinationsPanel data={ data } values={ { [ OPTION_KEY ]: rows } } />
	);
}

beforeEach( () => {
	// mockClear, not mockReset: reset would erase the stand-in's default
	// "unconfigured call rejects loudly" implementation for the rest of
	// the file (see the test-support header). For the same reason every
	// expected call is queued with a ...Once value - a persistent
	// mockResolvedValue would replace that default for the rest of the
	// file, silently downgrading any unexpected extra request.
	apiFetch.mockClear();
} );

describe( 'DestinationsPanel guidance and row list', () => {
	it( 'tells the admin to upload a service account first while none exists', () => {
		renderPanel( {
			data: panelData( {
				columnChoices: { [ OPTION_KEY ]: { service_account: {} } },
			} ),
		} );

		expect(
			screen.getByText( /Upload a Google service account first/ )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: /Test/ } )
		).not.toBeInTheDocument();
	} );

	it( 'renders nothing while no row is complete enough to probe', () => {
		const { container } = renderPanel( {
			rows: [ { ...ROW, measurement_id: '' } ],
		} );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'lists a Test button per complete row, named by label or measurement id', () => {
		renderPanel( {
			rows: [
				ROW,
				{ ...ROW, label: '', measurement_id: 'G-SECOND1' },
				{ ...ROW, measurement_id: '' }, // incomplete: no button
			],
		} );

		expect(
			screen.getByRole( 'button', { name: 'Test Production' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Test G-SECOND1' } )
		).toBeInTheDocument();
		expect( screen.getAllByRole( 'button' ) ).toHaveLength( 2 );
	} );

	it( 'tolerates missing values without throwing', () => {
		expect( () =>
			render(
				<DestinationsPanel data={ panelData() } values={ undefined } />
			)
		).not.toThrow();
	} );
} );

describe( 'DestinationsPanel probing', () => {
	it( 'POSTs the row as the editor holds it now, unsaved edits included', async () => {
		apiFetch.mockResolvedValueOnce( { ok: true, message: 'Accepted.' } );

		renderPanel( {
			rows: [ { ...ROW, property_id: '987654321' } ],
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect( screen.getByText( 'Accepted.' ) ).toBeInTheDocument()
		);
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: TEST_PATH,
			method: 'POST',
			data: {
				service_account: 'sa_0123456789ab',
				type: 'ga4',
				property_id: '987654321',
				measurement_id: 'G-ABC123',
			},
		} );
		// The verdict is rendered AS a success - an inverted or hardcoded
		// `ok` flag differs only in this class (T80).
		expect( screen.getByText( 'Accepted.' ) ).toHaveClass(
			'gtm4wp-destinations__result--ok'
		);
	} );

	it( 'defaults an unselected type to ga4 in the probe payload', async () => {
		// The select column's leading empty option makes type:'' storable, so
		// the `|| 'ga4'` default is a real branch, not dead code (T80).
		apiFetch.mockResolvedValueOnce( { ok: true, message: 'Accepted.' } );

		renderPanel( { rows: [ { ...ROW, type: '' } ] } );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect( screen.getByText( 'Accepted.' ) ).toBeInTheDocument()
		);
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.type ).toBe( 'ga4' );
	} );

	it( 'reports a refusal next to the row that failed', async () => {
		apiFetch.mockResolvedValueOnce( {
			ok: false,
			message: 'PERMISSION_DENIED: no access to the property.',
		} );

		renderPanel();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect(
				screen.getByText(
					'PERMISSION_DENIED: no access to the property.'
				)
			).toBeInTheDocument()
		);
		// Rendered AS an error, not just as text (T80).
		expect(
			screen.getByText( 'PERMISSION_DENIED: no access to the property.' )
		).toHaveClass( 'gtm4wp-destinations__result--error' );
	} );

	it( 'shows the message a rejected request carries', async () => {
		// The WP_Error body path: apiFetch rejects with a message-bearing
		// error object, whose text beats the generic fallback (T80).
		apiFetch.mockRejectedValueOnce( {
			message: 'rest_forbidden: you cannot do that.',
		} );

		renderPanel();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'rest_forbidden: you cannot do that.' )
			).toBeInTheDocument()
		);
	} );

	it( 'falls back to its own wording when the request itself fails', async () => {
		apiFetch.mockRejectedValueOnce( {} );

		renderPanel();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'The destination could not be tested.' )
			).toBeInTheDocument()
		);
	} );

	it( 'treats a malformed response as a failure instead of throwing', async () => {
		// A proxy or an exhausted PHP worker can hand apiFetch something that
		// is not the route's envelope; reading `.ok` off undefined lands in
		// the catch, which must still report, not crash the panel (T80).
		apiFetch.mockResolvedValueOnce( undefined );

		renderPanel();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'The destination could not be tested.' )
			).toBeInTheDocument()
		);
	} );

	it( 'ignores further Test clicks while a probe is in flight', async () => {
		// The sibling ServiceAccountsPanel test, ported (T76). Per TC-15
		// rule 7 this pins the disabled-prop layer only: jsdom never
		// delivers a click to a disabled button, so the handler-level
		// busyIndex re-check stays the documented in-harness blind spot,
		// guarded by code review.
		let finishProbe;
		apiFetch.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					finishProbe = resolve;
				} )
		);

		renderPanel( {
			rows: [ ROW, { ...ROW, label: '', measurement_id: 'G-SECOND1' } ],
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test G-SECOND1' } )
		);

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		finishProbe( { ok: true, message: 'Accepted.' } );
		await waitFor( () =>
			expect( screen.getByText( 'Accepted.' ) ).toBeInTheDocument()
		);

		// The verdict lands on the probed row, not the one clicked in flight.
		const items = screen.getAllByRole( 'listitem' );
		expect( items[ 0 ] ).toHaveTextContent( 'Accepted.' );
		expect( items[ 1 ] ).not.toHaveTextContent( 'Accepted.' );
	} );
} );

describe( 'DestinationsPanel result identity', () => {
	// A probe result is fingerprinted with the tested cells and shown only
	// while the row at its index still matches, so an edited or re-indexed
	// row never wears another probe's verdict.

	it( 'clears the banner when a tested cell of the row is edited', async () => {
		apiFetch.mockResolvedValueOnce( { ok: true, message: 'Accepted.' } );

		const { rerender } = renderPanel();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);
		await waitFor( () =>
			expect( screen.getByText( 'Accepted.' ) ).toBeInTheDocument()
		);

		rerender(
			<DestinationsPanel
				data={ panelData() }
				values={ {
					[ OPTION_KEY ]: [ { ...ROW, property_id: '987654321' } ],
				} }
			/>
		);

		expect( screen.queryByText( 'Accepted.' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the banner across a label-only edit', async () => {
		// Green by design before and after the fingerprint fix: the label is
		// display-only and deliberately not part of the result identity.
		apiFetch.mockResolvedValueOnce( { ok: true, message: 'Accepted.' } );

		const { rerender } = renderPanel();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);
		await waitFor( () =>
			expect( screen.getByText( 'Accepted.' ) ).toBeInTheDocument()
		);

		rerender(
			<DestinationsPanel
				data={ panelData() }
				values={ {
					[ OPTION_KEY ]: [ { ...ROW, label: 'Renamed' } ],
				} }
			/>
		);

		expect( screen.getByText( 'Accepted.' ) ).toBeInTheDocument();
	} );

	it( 'does not migrate the banner onto the row shifted into the index by a removal above', async () => {
		apiFetch.mockResolvedValueOnce( {
			ok: false,
			message: 'DENIED-FOR-B',
		} );

		const rowB = { ...ROW, label: 'B', measurement_id: 'G-SECOND1' };
		const rowC = { ...ROW, label: 'C', measurement_id: 'G-THIRD11' };
		const { rerender } = renderPanel( { rows: [ ROW, rowB, rowC ] } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Test B' } ) );
		await waitFor( () =>
			expect( screen.getByText( 'DENIED-FOR-B' ) ).toBeInTheDocument()
		);

		// Remove row A: B and C shift down one index each.
		rerender(
			<DestinationsPanel
				data={ panelData() }
				values={ { [ OPTION_KEY ]: [ rowB, rowC ] } }
			/>
		);

		expect( screen.queryByText( 'DENIED-FOR-B' ) ).not.toBeInTheDocument();
	} );

	it( 'no longer hides a failing-health warning behind a stale migrated banner', async () => {
		apiFetch.mockResolvedValueOnce( { ok: true, message: 'Accepted.' } );

		const rowB = { ...ROW, label: 'B', measurement_id: 'G-SECOND1' };
		const data = panelData( {
			health: {
				'G-SECOND1': {
					last_success: 0,
					last_failure: 1800000000,
					consecutive_failures: 4,
					last_error: 'PERMISSION_DENIED',
				},
			},
		} );

		const { rerender } = render(
			<DestinationsPanel
				data={ data }
				values={ { [ OPTION_KEY ]: [ ROW, rowB ] } }
			/>
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Test Production' } )
		);
		await waitFor( () =>
			expect( screen.getByText( 'Accepted.' ) ).toBeInTheDocument()
		);

		// Remove row A: B shifts into the tested index. Its own failing
		// record must show instead of A's stale success banner.
		rerender(
			<DestinationsPanel
				data={ data }
				values={ { [ OPTION_KEY ]: [ rowB ] } }
			/>
		);

		expect( screen.queryByText( 'Accepted.' ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( /The last 4 sends to this destination failed/ )
		).toBeInTheDocument();
	} );
} );

describe( 'DestinationsPanel stored health', () => {
	it( 'shows the failing record of a destination past the threshold', () => {
		renderPanel( {
			data: panelData( {
				health: {
					'G-ABC123': {
						last_success: 0,
						last_failure: 1800000000,
						consecutive_failures: 4,
						last_error: 'PERMISSION_DENIED',
					},
				},
			} ),
		} );

		expect(
			screen.getByText( /The last 4 sends to this destination failed/ )
		).toBeInTheDocument();
		expect( screen.getByText( /PERMISSION_DENIED/ ) ).toBeInTheDocument();
	} );

	it( 'warns exactly at the threshold, naming "unknown" when no error is stored', () => {
		// The boundary is >=, and the last_error fallback is a real branch:
		// a record can predate the error field (T80).
		renderPanel( {
			data: panelData( {
				health: {
					'G-ABC123': {
						last_success: 0,
						last_failure: 1800000000,
						consecutive_failures: 3,
						last_error: '',
					},
				},
			} ),
		} );

		expect(
			screen.getByText(
				'The last 3 sends to this destination failed. Last error: unknown'
			)
		).toBeInTheDocument();
	} );

	it( 'shows nothing for a record below the threshold', () => {
		renderPanel( {
			data: panelData( {
				health: {
					'G-ABC123': {
						last_success: 1800000000,
						last_failure: 1800000100,
						consecutive_failures: 1,
						last_error: 'blip',
					},
				},
			} ),
		} );

		expect( screen.queryByText( /blip/ ) ).not.toBeInTheDocument();
	} );
} );

describe( 'DestinationsPanel send log', () => {
	it( 'renders the recent-sends list when the server names its path', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );

		renderPanel( {
			data: panelData( { logPath: 'gtm4wp/v2/google/send-log' } ),
		} );

		expect(
			await screen.findByText( 'Nothing has been sent yet.' )
		).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: 'gtm4wp/v2/google/send-log',
		} );
	} );

	it( 'still shows the log when no row is complete enough to test', async () => {
		apiFetch.mockResolvedValueOnce( { entries: [] } );

		renderPanel( {
			data: panelData( { logPath: 'gtm4wp/v2/google/send-log' } ),
			rows: [ { ...ROW, measurement_id: '' } ],
		} );

		// The most useful entries are the ones explaining why nothing was
		// sent, and "no destination configured" is one of them.
		expect(
			await screen.findByText( 'Nothing has been sent yet.' )
		).toBeInTheDocument();
	} );

	it( 'does not reach the network when the server named no path', () => {
		renderPanel();

		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
