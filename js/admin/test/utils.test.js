/**
 * Unit tests of the pure helper functions of the admin app.
 */

import {
	changedValues,
	coerceValue,
	moduleMatchesSearch,
	stripTags,
} from '../utils';

describe( 'coerceValue', () => {
	it( 'coerces checkbox values to booleans', () => {
		const field = { type: 'checkbox' };

		expect( coerceValue( field, true ) ).toBe( true );
		expect( coerceValue( field, '1' ) ).toBe( true );
		expect( coerceValue( field, '' ) ).toBe( false );
		expect( coerceValue( field, false ) ).toBe( false );
	} );

	it( 'coerces integer values to numbers', () => {
		const field = { type: 'integer' };

		expect( coerceValue( field, '100' ) ).toBe( 100 );
		expect( coerceValue( field, 250 ) ).toBe( 250 );
		expect( coerceValue( field, 'not a number' ) ).toBe( 0 );
	} );

	it( 'coerces select values to strings', () => {
		const field = { type: 'select' };

		expect( coerceValue( field, 0 ) ).toBe( '0' );
		expect( coerceValue( field, 'retail' ) ).toBe( 'retail' );
		expect( coerceValue( field, null ) ).toBe( '' );
	} );

	it( 'splits multiselect comma strings into arrays', () => {
		const field = { type: 'multiselect' };

		expect( coerceValue( field, 'html,img,awct' ) ).toEqual( [
			'html',
			'img',
			'awct',
		] );
		expect( coerceValue( field, '' ) ).toEqual( [] );
		expect( coerceValue( field, [ 'html', '' ] ) ).toEqual( [ 'html' ] );
	} );
} );

describe( 'changedValues', () => {
	it( 'returns only changed entries', () => {
		const initial = { a: 1, b: 'x', c: [ 1, 2 ] };
		const current = { a: 1, b: 'y', c: [ 1, 2 ] };

		expect( changedValues( initial, current ) ).toEqual( { b: 'y' } );
	} );

	it( 'detects array changes by value', () => {
		const initial = { list: [ 'a' ] };
		const current = { list: [ 'a', 'b' ] };

		expect( changedValues( initial, current ) ).toEqual( {
			list: [ 'a', 'b' ],
		} );
	} );

	it( 'returns an empty object when nothing changed', () => {
		const state = { a: true, b: [ 'x' ] };

		expect( changedValues( state, { ...state } ) ).toEqual( {} );
	} );
} );

describe( 'moduleMatchesSearch', () => {
	const module = {
		title: 'WooCommerce',
		fields: [
			{
				label: 'Track e-commerce',
				description: 'Enable <strong>GA4</strong> tracking.',
			},
		],
	};

	it( 'matches on module title', () => {
		expect( moduleMatchesSearch( module, 'woo' ) ).toBe( true );
	} );

	it( 'matches on field label and description ignoring HTML', () => {
		expect( moduleMatchesSearch( module, 'e-commerce' ) ).toBe( true );
		expect( moduleMatchesSearch( module, 'ga4' ) ).toBe( true );
	} );

	it( 'does not match unrelated terms and passes empty searches', () => {
		expect( moduleMatchesSearch( module, 'scroll' ) ).toBe( false );
		expect( moduleMatchesSearch( module, '' ) ).toBe( true );
	} );
} );

describe( 'stripTags', () => {
	it( 'removes HTML tags', () => {
		expect( stripTags( 'a <a href="#">link</a> here' ) ).toBe(
			'a link here'
		);
		expect( stripTags( null ) ).toBe( '' );
	} );
} );
