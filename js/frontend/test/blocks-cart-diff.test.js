/**
 * Unit tests for the pure WooCommerce block-tracker helpers
 * (js/frontend/lib/gtm4wp-blocks-cart-diff.js).
 */

import {
	gtm4wp_parse_block_item,
	gtm4wp_normalize_cart_items,
	gtm4wp_diff_cart_items,
	gtm4wp_normalize_crosssell_items,
	gtm4wp_selected_shipping_tier,
} from '../lib/gtm4wp-blocks-cart-diff';

/**
 * Builds a raw wc/store/cart line carrying a GA4 item at extensions.gtm4wp.item.
 *
 * @param {string}  key      Cart-item key.
 * @param {number}  quantity Line quantity.
 * @param {Object}  item     GA4 item.
 * @param {boolean} asString Expose the item as a JSON string (cart-item style).
 * @return {Object} The raw cart line.
 */
function cartLine( key, quantity, item, asString = false ) {
	return {
		key,
		quantity,
		extensions: {
			gtm4wp: { item: asString ? JSON.stringify( item ) : item },
		},
	};
}

describe( 'gtm4wp_parse_block_item', () => {
	it( 'passes an object through unchanged', () => {
		const obj = { item_id: 1 };
		expect( gtm4wp_parse_block_item( obj ) ).toBe( obj );
	} );

	it( 'parses a JSON string', () => {
		expect( gtm4wp_parse_block_item( '{"item_id":9}' ) ).toEqual( {
			item_id: 9,
		} );
	} );

	it( 'returns null for empty or malformed input', () => {
		expect( gtm4wp_parse_block_item( undefined ) ).toBeNull();
		expect( gtm4wp_parse_block_item( '' ) ).toBeNull();
		expect( gtm4wp_parse_block_item( '{not json' ) ).toBeNull();
	} );
} );

describe( 'gtm4wp_normalize_cart_items', () => {
	it( 'extracts the GA4 item (object or JSON string) and keeps key/quantity', () => {
		const normalized = gtm4wp_normalize_cart_items( [
			cartLine( 'A', 2, { item_id: 1, price: 5 } ),
			cartLine( 'B', 1, { item_id: 2, price: 3 }, true ),
		] );

		expect( normalized ).toEqual( [
			{ key: 'A', quantity: 2, item: { item_id: 1, price: 5 } },
			{ key: 'B', quantity: 1, item: { item_id: 2, price: 3 } },
		] );
	} );

	it( 'drops lines without GA4 item data and handles non-arrays', () => {
		expect(
			gtm4wp_normalize_cart_items( [
				{ key: 'X', quantity: 1 },
				cartLine( 'Y', 1, { item_id: 3 } ),
			] )
		).toEqual( [ { key: 'Y', quantity: 1, item: { item_id: 3 } } ] );

		expect( gtm4wp_normalize_cart_items( null ) ).toEqual( [] );
	} );
} );

describe( 'gtm4wp_diff_cart_items', () => {
	const a = { key: 'A', quantity: 1, item: { item_id: 1 } };
	const b = { key: 'B', quantity: 2, item: { item_id: 2 } };

	it( 'reports a newly added line', () => {
		const { added, removed } = gtm4wp_diff_cart_items( [ a ], [ a, b ] );
		expect( added ).toEqual( [ { item: { item_id: 2 }, quantity: 2 } ] );
		expect( removed ).toEqual( [] );
	} );

	it( 'reports a quantity increase and decrease as the net delta', () => {
		const a3 = { ...a, quantity: 3 };
		expect( gtm4wp_diff_cart_items( [ a ], [ a3 ] ).added ).toEqual( [
			{ item: { item_id: 1 }, quantity: 2 },
		] );
		expect( gtm4wp_diff_cart_items( [ a3 ], [ a ] ).removed ).toEqual( [
			{ item: { item_id: 1 }, quantity: 2 },
		] );
	} );

	it( 'reports a fully removed line', () => {
		const { added, removed } = gtm4wp_diff_cart_items( [ a, b ], [ a ] );
		expect( added ).toEqual( [] );
		expect( removed ).toEqual( [ { item: { item_id: 2 }, quantity: 2 } ] );
	} );

	it( 'reports nothing when the cart is unchanged', () => {
		const { added, removed } = gtm4wp_diff_cart_items( [ a, b ], [ a, b ] );
		expect( added ).toEqual( [] );
		expect( removed ).toEqual( [] );
	} );
} );

describe( 'gtm4wp_normalize_crosssell_items', () => {
	it( 'extracts the GA4 item (object or JSON string) and keeps id/permalink', () => {
		const normalized = gtm4wp_normalize_crosssell_items( [
			{
				id: 7,
				permalink: 'https://shop/p7',
				extensions: { gtm4wp: { item: { item_id: 7 } } },
			},
			{
				id: 8,
				permalink: 'https://shop/p8',
				extensions: {
					gtm4wp: { item: JSON.stringify( { item_id: 8 } ) },
				},
			},
		] );

		expect( normalized ).toEqual( [
			{ id: 7, permalink: 'https://shop/p7', item: { item_id: 7 } },
			{ id: 8, permalink: 'https://shop/p8', item: { item_id: 8 } },
		] );
	} );

	it( 'drops products without GA4 item data and handles non-arrays', () => {
		expect(
			gtm4wp_normalize_crosssell_items( [
				{ id: 1, permalink: 'https://shop/p1' },
				{
					id: 2,
					permalink: 'https://shop/p2',
					extensions: { gtm4wp: { item: { item_id: 2 } } },
				},
			] )
		).toEqual( [
			{ id: 2, permalink: 'https://shop/p2', item: { item_id: 2 } },
		] );

		expect( gtm4wp_normalize_crosssell_items( null ) ).toEqual( [] );
	} );
} );

describe( 'gtm4wp_selected_shipping_tier', () => {
	it( 'returns the name of the selected rate', () => {
		const cart = {
			shippingRates: [
				{
					shipping_rates: [
						{ name: 'Flat rate', selected: false },
						{ name: 'Free shipping', selected: true },
					],
				},
			],
		};
		expect( gtm4wp_selected_shipping_tier( cart ) ).toBe( 'Free shipping' );
	} );

	it( 'returns an empty string when nothing is selected', () => {
		expect( gtm4wp_selected_shipping_tier( { shippingRates: [] } ) ).toBe(
			''
		);
		expect( gtm4wp_selected_shipping_tier( {} ) ).toBe( '' );
	} );
} );
