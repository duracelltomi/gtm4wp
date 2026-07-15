/**
 * Unit tests for the generic GA4 e-commerce helper functions
 * (js/frontend/gtm4wp-ecommerce-generic.js). These are the public 1.x JS API
 * attached to window and consumed by gtm4wp-woocommerce.js and third parties.
 */

import '../gtm4wp-ecommerce-generic';

describe( 'gtm4wp-ecommerce-generic', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_clear_ecommerce = true;
	} );

	beforeEach( () => {
		window.dataLayer = [];
		global.gtm4wp_console_log = false;
		// Reset per test so the clear_ecommerce=false case below cannot leak into
		// the following test (TS-7).
		global.gtm4wp_clear_ecommerce = true;
	} );

	describe( 'gtm4wp_make_sure_is_float', () => {
		it( 'parses numeric strings to two-decimal floats', () => {
			expect( window.gtm4wp_make_sure_is_float( '19.999' ) ).toBe( 20 );
			expect( window.gtm4wp_make_sure_is_float( '10.5' ) ).toBe( 10.5 );
			expect( window.gtm4wp_make_sure_is_float( 7 ) ).toBe( 7 );
		} );

		it( 'returns 0 for values that cannot be parsed', () => {
			expect( window.gtm4wp_make_sure_is_float( 'abc' ) ).toBe( 0 );
			expect( window.gtm4wp_make_sure_is_float( null ) ).toBe( 0 );
			expect( window.gtm4wp_make_sure_is_float( {} ) ).toBe( 0 );
		} );
	} );

	describe( 'gtm4wp_read_from_json', () => {
		it( 'parses product JSON, coerces price and strips excluded keys', () => {
			const result = window.gtm4wp_read_from_json(
				JSON.stringify( {
					item_id: 123,
					price: '19.999',
					productlink: 'https://example.com/p',
					internal_id: 5,
				} )
			);

			expect( result.item_id ).toBe( 123 );
			expect( result.price ).toBe( 20 );
			expect( result.productlink ).toBeUndefined();
			expect( result.internal_id ).toBeUndefined();
		} );

		it( 'returns false for malformed JSON', () => {
			const spy = jest
				.spyOn( console, 'error' )
				.mockImplementation( () => {} );

			expect( window.gtm4wp_read_from_json( '{not valid json' ) ).toBe(
				false
			);

			spy.mockRestore();
		} );
	} );

	describe( 'gtm4wp_read_json_from_node', () => {
		it( 'reads and parses a data attribute from a node', () => {
			const el = document.createElement( 'span' );
			el.dataset.gtm4wpProductData = JSON.stringify( {
				item_id: 7,
				price: 5,
			} );

			const result = window.gtm4wp_read_json_from_node(
				el,
				'gtm4wpProductData'
			);

			expect( result.item_id ).toBe( 7 );
		} );

		it( 'returns false when the attribute is absent', () => {
			const el = document.createElement( 'span' );

			expect(
				window.gtm4wp_read_json_from_node( el, 'gtm4wpProductData' )
			).toBe( false );
		} );
	} );

	describe( 'gtm4wp_update_json_in_node', () => {
		it( 'merges a new key into the node JSON', () => {
			const el = document.createElement( 'span' );
			el.dataset.gtm4wpProductData = JSON.stringify( {
				item_id: 7,
				price: '5.00',
			} );

			const ok = window.gtm4wp_update_json_in_node(
				el,
				'gtm4wpProductData',
				'quantity',
				3
			);

			expect( ok ).toBe( true );
			expect( JSON.parse( el.dataset.gtm4wpProductData ).quantity ).toBe(
				3
			);
		} );

		it( 'returns false for a node without the attribute', () => {
			const el = document.createElement( 'span' );

			expect(
				window.gtm4wp_update_json_in_node( el, 'missing', 'k', 'v' )
			).toBe( false );
		} );
	} );

	describe( 'list attribution cookie (#405)', () => {
		beforeEach( () => {
			// Clear the cookie between tests (jsdom keeps it otherwise).
			document.cookie =
				'gtm4wp_item_list_attr=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
		} );

		it( 'round-trips a stored list attribution by product id', () => {
			window.gtm4wp_store_item_list_attribution(
				42,
				'Summer Sale',
				'summer-sale'
			);

			const map = window.gtm4wp_read_item_list_cookie();
			expect( map[ 42 ] ).toEqual( {
				item_list_name: 'Summer Sale',
				item_list_id: 'summer-sale',
			} );
		} );

		it( 'does not store without a product id or a list name', () => {
			window.gtm4wp_store_item_list_attribution( 0, 'X', 'x' );
			window.gtm4wp_store_item_list_attribution( 42, '', '' );

			expect( window.gtm4wp_read_item_list_cookie() ).toEqual( {} );
		} );

		it( 'merges the stored list onto an item by product id', () => {
			window.gtm4wp_store_item_list_attribution(
				42,
				'Summer Sale',
				'summer-sale'
			);

			const item = { item_id: 7 };
			window.gtm4wp_apply_stored_item_list( item, 42 );

			expect( item.item_list_name ).toBe( 'Summer Sale' );
			expect( item.item_list_id ).toBe( 'summer-sale' );
		} );

		it( 'does not overwrite an item that already has a list name', () => {
			window.gtm4wp_store_item_list_attribution( 42, 'Summer Sale' );

			const item = { item_id: 7, item_list_name: 'Related Products' };
			window.gtm4wp_apply_stored_item_list( item, 42 );

			expect( item.item_list_name ).toBe( 'Related Products' );
		} );

		it( 'leaves the item untouched when nothing is stored for the id', () => {
			const item = { item_id: 7 };
			window.gtm4wp_apply_stored_item_list( item, 999 );

			expect( item.item_list_name ).toBeUndefined();
		} );

		it( 'returns an empty map for a malformed cookie', () => {
			document.cookie = 'gtm4wp_item_list_attr=not-json%7B; path=/';

			expect( window.gtm4wp_read_item_list_cookie() ).toEqual( {} );
		} );

		it( 'caps the stored map at 20 entries, evicting the oldest', () => {
			// Store one more than the cap so the eviction loop must run.
			for ( let id = 1; id <= 21; id++ ) {
				window.gtm4wp_store_item_list_attribution(
					id,
					'List ' + id,
					'list-' + id
				);
			}

			const map = window.gtm4wp_read_item_list_cookie();

			// The cap holds: the map never grows past GTM4WP_LIST_ATTR_MAX_ENTRIES.
			expect( Object.keys( map ) ).toHaveLength( 20 );
			// The oldest id (1) was evicted; the newest (21) is present.
			expect( map[ 1 ] ).toBeUndefined();
			expect( map[ 21 ] ).toEqual( {
				item_list_name: 'List 21',
				item_list_id: 'list-21',
			} );
			// Only one entry was evicted: the second-oldest survives.
			expect( map[ 2 ] ).toBeDefined();
		} );
	} );

	describe( 'gtm4wp_push_ecommerce', () => {
		it( 'clears the previous ecommerce object then pushes the event', () => {
			window.gtm4wp_push_ecommerce( 'add_to_cart', [ { item_id: 1 } ], {
				currency: 'EUR',
			} );

			expect( window.dataLayer[ 0 ] ).toEqual( { ecommerce: null } );
			expect( window.dataLayer[ 1 ].event ).toBe( 'add_to_cart' );
			expect( window.dataLayer[ 1 ].ecommerce.currency ).toBe( 'EUR' );
			expect( window.dataLayer[ 1 ].ecommerce.items ).toEqual( [
				{ item_id: 1 },
			] );
		} );

		it( 'attaches the event callback and timeout when provided', () => {
			const callback = () => {};

			window.gtm4wp_push_ecommerce( 'purchase', [], {}, callback, 1500 );

			const last = window.dataLayer[ window.dataLayer.length - 1 ];
			expect( last.eventCallback ).toBe( callback );
			expect( last.eventTimeout ).toBe( 1500 );
		} );

		it( 'logs the pushed event when console logging is enabled', () => {
			global.gtm4wp_console_log = true;
			const spy = jest
				.spyOn( console, 'log' )
				.mockImplementation( () => {} );

			window.gtm4wp_push_ecommerce( 'view_item', [ { item_id: 9 } ], {} );

			expect( spy ).toHaveBeenCalledWith(
				expect.stringContaining( 'view_item' ),
				expect.objectContaining( { event: 'view_item' } )
			);

			spy.mockRestore();
		} );

		it( 'stays silent when console logging is disabled', () => {
			global.gtm4wp_console_log = false;
			const spy = jest
				.spyOn( console, 'log' )
				.mockImplementation( () => {} );

			window.gtm4wp_push_ecommerce( 'view_item', [ { item_id: 9 } ], {} );

			expect( spy ).not.toHaveBeenCalled();

			spy.mockRestore();
		} );

		it( 'does not prepend an ecommerce reset when clear_ecommerce is off', () => {
			global.gtm4wp_clear_ecommerce = false;

			window.gtm4wp_push_ecommerce( 'add_to_cart', [ { item_id: 1 } ], {
				currency: 'EUR',
			} );

			// Without the clear, the event itself is the very first push - there is
			// no { ecommerce: null } reset in front of it.
			expect( window.dataLayer ).toHaveLength( 1 );
			expect( window.dataLayer[ 0 ].event ).toBe( 'add_to_cart' );
			expect( window.dataLayer[ 0 ].ecommerce ).not.toBeNull();
		} );
	} );
} );
