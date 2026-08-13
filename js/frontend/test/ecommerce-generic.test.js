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
			// toMatchObject: entries also carry the recency sequence eviction sorts
			// on (#68), which the PHP reader ignores.
			expect( map[ 42 ] ).toMatchObject( {
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
			// toMatchObject, not toEqual: each entry also carries the recency
			// sequence the eviction sorts on (#68), which is bookkeeping the PHP
			// reader ignores.
			expect( map[ 1 ] ).toBeUndefined();
			expect( map[ 21 ] ).toMatchObject( {
				item_list_name: 'List 21',
				item_list_id: 'list-21',
			} );
			// Only one entry was evicted: the second-oldest survives.
			expect( map[ 2 ] ).toBeDefined();
		} );

		// The size the browser measures: cookie name + percent-encoded value, in
		// bytes. Mirrors the reader's own parsing so the test sees exactly what was
		// handed to document.cookie, not the decoded JSON.
		const stored_cookie_bytes = () => {
			const parts = ( '; ' + document.cookie ).split(
				'; gtm4wp_item_list_attr='
			);
			if ( 2 !== parts.length ) {
				return 0;
			}
			return (
				'gtm4wp_item_list_attr'.length +
				parts.pop().split( ';' ).shift().length
			);
		};

		it( 'keeps the cookie within the browser byte limit when list names are long', () => {
			// item_list_name is not ours: a widget title, a long category name or a
			// translation all land in it. A name this length is unremarkable for a
			// widget title, and twenty of them serialize past 6 KB once
			// encodeURIComponent has tripled every JSON delimiter - well past what a
			// browser accepts, so without the size cap the write would be rejected
			// wholesale and the attribution would freeze until the TTL ran out.
			const long_name =
				'Handpicked Products From The Summer Sale Season Collection For Women And Men';
			for ( let id = 1; id <= 20; id++ ) {
				window.gtm4wp_store_item_list_attribution(
					id,
					long_name + ' ' + id,
					'handpicked-products-from-the-summer-sale-season-collection-for-women-and-men-' +
						id
				);
			}

			// Asserted against the browser's ~4096-byte name+value limit rather than
			// the plugin's own (lower) budget: the limit is the real contract, so
			// tuning the internal budget must not need a test edit, while eviction
			// breaking must fail here.
			expect( stored_cookie_bytes() ).toBeGreaterThan( 0 );
			expect( stored_cookie_bytes() ).toBeLessThanOrEqual( 4096 );

			const map = window.gtm4wp_read_item_list_cookie();
			// Size, not the entry cap, is what bound it here: fewer than 20 survive.
			expect( Object.keys( map ).length ).toBeLessThan( 20 );
			expect( Object.keys( map ).length ).toBeGreaterThan( 0 );
			// The just-clicked entry always survives - it is the freshest
			// attribution the visitor has - and the oldest went first.
			expect( map[ 20 ] ).toMatchObject( {
				item_list_name: long_name + ' 20',
			} );
			expect( map[ 1 ] ).toBeUndefined();
		} );

		it( 'measures the limit in encoded bytes, not characters: multibyte names evict too (T50)', () => {
			// The case above holds only because its ASCII names make
			// encoded-length and character-length coincide. An accented or CJK
			// widget title occupies up to nine encoded bytes per character
			// (encodeURIComponent of a 3-byte UTF-8 sequence), so a
			// "characters" reading of the budget would blow the real browser
			// limit while every test stayed green. Pin that the measurement is
			// post-encoding.
			const multibyte_name =
				'Nyári Válogatás – Kézműves Termékek Hölgyeknek És Uraknak 夏のセール';
			for ( let id = 1; id <= 20; id++ ) {
				window.gtm4wp_store_item_list_attribution(
					id,
					multibyte_name + ' ' + id,
					'nyari-valogatas-' + id
				);
			}

			expect( stored_cookie_bytes() ).toBeGreaterThan( 0 );
			expect( stored_cookie_bytes() ).toBeLessThanOrEqual( 4096 );

			const map = window.gtm4wp_read_item_list_cookie();
			// The multibyte names force earlier eviction than their character
			// count suggests - size in bytes, not entries and not characters,
			// is what bounds the cookie.
			expect( Object.keys( map ).length ).toBeLessThan( 20 );
			expect( Object.keys( map ).length ).toBeGreaterThan( 0 );
			expect( map[ 20 ] ).toMatchObject( {
				item_list_name: multibyte_name + ' 20',
			} );
			expect( map[ 1 ] ).toBeUndefined();
		} );

		it( 'leaves the previous cookie intact when one entry cannot fit at all', () => {
			window.gtm4wp_store_item_list_attribution(
				42,
				'Summer Sale',
				'summer-sale'
			);
			const before = stored_cookie_bytes();

			// A single list name longer than the whole budget. Writing it would be a
			// silent no-op in a real browser, so nothing is written at all and the
			// usable attribution already stored is not thrown away.
			window.gtm4wp_store_item_list_attribution(
				99,
				'X'.repeat( 4000 ),
				'x'
			);

			expect( stored_cookie_bytes() ).toBe( before );

			const map = window.gtm4wp_read_item_list_cookie();
			expect( map[ 99 ] ).toBeUndefined();
			expect( map[ 42 ] ).toMatchObject( {
				item_list_name: 'Summer Sale',
			} );
		} );

		it( 'evicts the least recently stored entry, not the lowest product id', () => {
			// #68: the sibling test above stores ids 1..21 ASCENDING, so insertion
			// order and numeric key order coincide and the old shift()-based
			// eviction looked correct. Product ids are integer-like keys, and the
			// spec makes Object.keys() return those in ascending NUMERIC order
			// whatever the insertion order - so the eviction actually dropped the
			// lowest id. Storing DESCENDING makes the two orders disagree: id 21 is
			// now the oldest entry and id 1 the newest, so the old code would have
			// evicted 1 (the newest) and kept 21 (the oldest) - inverted.
			for ( let id = 21; id >= 1; id-- ) {
				window.gtm4wp_store_item_list_attribution(
					id,
					'List ' + id,
					'list-' + id
				);
			}

			const map = window.gtm4wp_read_item_list_cookie();

			expect( Object.keys( map ) ).toHaveLength( 20 );
			// Stored first => least recently stored => evicted.
			expect( map[ 21 ] ).toBeUndefined();
			// Stored last => must survive.
			expect( map[ 1 ] ).toMatchObject( { item_list_name: 'List 1' } );
			// And the one stored second is still there: exactly one was evicted.
			expect( map[ 20 ] ).toBeDefined();
		} );

		describe( 'event-level enrichment of a server-rendered push', () => {
			// #405: a product page is full-page cacheable, so PHP must not merge the
			// visitor's list into that HTML. It emits the view_item push wrapped in a
			// call to this function instead, and the merge happens here, in the
			// browser. PHP resolves it off window with an identity fallback, so this
			// has to behave like one for every shape it is handed.

			it( 'merges the stored list onto the first item of the event', () => {
				window.gtm4wp_store_item_list_attribution(
					42,
					'Summer Sale',
					'summer-sale'
				);

				const event_object = {
					event: 'view_item',
					ecommerce: {
						currency: 'EUR',
						value: 10,
						items: [ { item_id: 'SKU-42' } ],
					},
				};

				const returned = window.gtm4wp_apply_stored_item_list_to_event(
					event_object,
					42
				);

				expect( returned ).toBe( event_object );
				expect( event_object.ecommerce.items[ 0 ] ).toMatchObject( {
					item_id: 'SKU-42',
					item_list_name: 'Summer Sale',
					item_list_id: 'summer-sale',
				} );
			} );

			it( 'leaves the event alone when nothing is stored for the id', () => {
				const event_object = {
					event: 'view_item',
					ecommerce: { items: [ { item_id: 'SKU-42' } ] },
				};

				window.gtm4wp_apply_stored_item_list_to_event(
					event_object,
					999
				);

				expect(
					event_object.ecommerce.items[ 0 ].item_list_name
				).toBeUndefined();
			} );

			it( 'does not overwrite a list the item already belongs to', () => {
				window.gtm4wp_store_item_list_attribution( 42, 'Summer Sale' );

				const event_object = {
					event: 'view_item',
					ecommerce: {
						items: [
							{
								item_id: 'SKU-42',
								item_list_name: 'Related Products',
							},
						],
					},
				};

				window.gtm4wp_apply_stored_item_list_to_event(
					event_object,
					42
				);

				expect( event_object.ecommerce.items[ 0 ].item_list_name ).toBe(
					'Related Products'
				);
			} );

			it( 'returns unexpected shapes untouched instead of throwing', () => {
				// The PHP side wraps the push in this call, so a throw here would take
				// the whole event with it - the one failure mode worse than a missing
				// item_list_name. Every shape that is not "an event with items" has to
				// come straight back out.
				window.gtm4wp_store_item_list_attribution( 42, 'Summer Sale' );

				const shapes = [
					{ event: 'view_item' },
					{ event: 'view_item', ecommerce: {} },
					{ event: 'view_item', ecommerce: { items: [] } },
					{
						event: 'view_item',
						ecommerce: { items: 'not-an-array' },
					},
					{ event: 'view_item', ecommerce: null },
					null,
					undefined,
				];

				shapes.forEach( ( shape ) => {
					expect( () =>
						window.gtm4wp_apply_stored_item_list_to_event(
							shape,
							42
						)
					).not.toThrow();

					expect(
						window.gtm4wp_apply_stored_item_list_to_event(
							shape,
							42
						)
					).toBe( shape );
				} );
			} );

			it( 'is exported under the exact name the PHP side emits', () => {
				// RI-14: PHP writes this identifier into a <script> body. A rename on
				// either side is silent - the identity fallback swallows it - so the
				// name itself is the contract. Its PHP half is
				// Helpers::LIST_ATTRIBUTION_JS_WRAPPER, pinned from the other
				// direction in PageDataLayerTest.
				expect(
					typeof window.gtm4wp_apply_stored_item_list_to_event
				).toBe( 'function' );
			} );
		} );

		it( 'treats an entry with no recency marker as the oldest', () => {
			// Entries written by an earlier version carry no sequence, so they must
			// drain out first rather than outliving freshly stored ones.
			const legacy = {};
			for ( let id = 100; id < 119; id++ ) {
				legacy[ id ] = { item_list_name: 'Legacy ' + id };
			}
			document.cookie =
				'gtm4wp_item_list_attr=' +
				encodeURIComponent( JSON.stringify( legacy ) );

			window.gtm4wp_store_item_list_attribution( 5, 'Fresh', 'fresh' );
			window.gtm4wp_store_item_list_attribution(
				6,
				'Fresh 6',
				'fresh-6'
			);

			const map = window.gtm4wp_read_item_list_cookie();

			expect( Object.keys( map ) ).toHaveLength( 20 );
			// Both fresh entries survive; a legacy one made way for the second.
			expect( map[ 5 ] ).toMatchObject( { item_list_name: 'Fresh' } );
			expect( map[ 6 ] ).toMatchObject( { item_list_name: 'Fresh 6' } );
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
