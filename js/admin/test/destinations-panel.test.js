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
	// the file (see the test-support header).
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
		apiFetch.mockResolvedValue( { ok: true, message: 'Accepted.' } );

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
	} );

	it( 'reports a refusal next to the row that failed', async () => {
		apiFetch.mockResolvedValue( {
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
	} );

	it( 'falls back to its own wording when the request itself fails', async () => {
		apiFetch.mockRejectedValue( {} );

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
} );

describe( 'DestinationsPanel result identity', () => {
	// A probe result is fingerprinted with the tested cells and shown only
	// while the row at its index still matches, so an edited or re-indexed
	// row never wears another probe's verdict.

	it( 'clears the banner when a tested cell of the row is edited', async () => {
		apiFetch.mockResolvedValue( { ok: true, message: 'Accepted.' } );

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
		apiFetch.mockResolvedValue( { ok: true, message: 'Accepted.' } );

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
		apiFetch.mockResolvedValue( {
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
		apiFetch.mockResolvedValue( { ok: true, message: 'Accepted.' } );

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
