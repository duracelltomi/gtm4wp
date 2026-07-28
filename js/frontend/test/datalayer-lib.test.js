/**
 * Unit tests for the shared data layer push helper
 * (js/frontend/lib/gtm4wp-datalayer.js).
 */

import { gtm4wp_push_dl_event } from '../lib/gtm4wp-datalayer';

describe( 'gtm4wp_push_dl_event', () => {
	beforeEach( () => {
		window.gtm4wp_datalayer_name = 'dataLayer';
		window.dataLayer = [];
		delete window.gtm4wp_datalayer_push;
		delete window.customDL;
	} );

	it( 'pushes directly to the data layer when the queue runtime is absent', () => {
		gtm4wp_push_dl_event( { event: 'direct' } );

		expect( window.dataLayer ).toEqual( [ { event: 'direct' } ] );
	} );

	it( 'delegates to the queue runtime when it is installed', () => {
		window.gtm4wp_datalayer_push = jest.fn();

		gtm4wp_push_dl_event( { event: 'routed' } );

		expect( window.gtm4wp_datalayer_push ).toHaveBeenCalledWith( {
			event: 'routed',
		} );
		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'honors a custom data layer variable name on the direct path', () => {
		window.gtm4wp_datalayer_name = 'customDL';

		gtm4wp_push_dl_event( { event: 'renamed' } );

		expect( window.customDL ).toEqual( [ { event: 'renamed' } ] );
		expect( window.dataLayer ).toHaveLength( 0 );

		window.gtm4wp_datalayer_name = 'dataLayer';
	} );

	it( 'creates the data layer array when it does not exist yet', () => {
		delete window.dataLayer;

		gtm4wp_push_dl_event( { event: 'first' } );

		expect( window.dataLayer ).toEqual( [ { event: 'first' } ] );
	} );
} );
