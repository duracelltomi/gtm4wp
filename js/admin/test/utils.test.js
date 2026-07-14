/**
 * Unit tests of the pure helper functions of the admin app.
 */

import {
	axeptioVersionOptions,
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

	it( 'coerces table rows to objects with every column as string', () => {
		const field = {
			type: 'table',
			columns: [ { key: 'id' }, { key: 'gtm_auth' } ],
		};

		expect(
			coerceValue( field, [
				{ id: 'GTM-AAA111', gtm_auth: 'token', ignored: 'x' },
				{ id: 'GTM-BBB222' },
			] )
		).toEqual( [
			{ id: 'GTM-AAA111', gtm_auth: 'token' },
			{ id: 'GTM-BBB222', gtm_auth: '' },
		] );

		expect( coerceValue( field, 'not-an-array' ) ).toEqual( [] );
		expect( coerceValue( field, [ null ] ) ).toEqual( [
			{ id: '', gtm_auth: '' },
		] );
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

describe( 'axeptioVersionOptions', () => {
	const cookies = [
		{ name: 'proj-v1', title: 'Project v1' },
		{ name: 'proj-v2', title: 'Project v2' },
	];

	it( 'maps fetched versions to value/label options, using name as fallback label', () => {
		const options = axeptioVersionOptions(
			[ { name: 'proj-v1', title: 'Project v1' }, { name: 'proj-v2' } ],
			'proj-v1',
			'placeholder'
		);

		expect( options ).toEqual( [
			{ value: 'proj-v1', label: 'Project v1' },
			{ value: 'proj-v2', label: 'proj-v2' },
		] );
	} );

	it( 'prepends a placeholder option only when no version is selected', () => {
		const options = axeptioVersionOptions( cookies, '', 'Select…' );

		expect( options[ 0 ] ).toEqual( { value: '', label: 'Select…' } );
		expect( options ).toHaveLength( 3 );
	} );

	it( 'preserves a saved value that is no longer published so a save never drops it', () => {
		const options = axeptioVersionOptions( cookies, 'removed-v0', '' );

		expect( options ).toContainEqual( {
			value: 'removed-v0',
			label: 'removed-v0',
		} );
		// The saved value is not duplicated as a placeholder (it is non-empty).
		expect(
			options.filter( ( option ) => option.value === '' )
		).toHaveLength( 0 );
	} );

	it( 'does not re-add the saved value when it is still published', () => {
		const options = axeptioVersionOptions( cookies, 'proj-v2', '' );

		expect(
			options.filter( ( option ) => option.value === 'proj-v2' )
		).toHaveLength( 1 );
	} );

	it( 'skips entries without a name and dedupes repeated names', () => {
		const options = axeptioVersionOptions(
			[
				{ title: 'No name' },
				{ name: 'dup', title: 'First' },
				{ name: 'dup', title: 'Second' },
			],
			'dup',
			''
		);

		expect( options ).toEqual( [ { value: 'dup', label: 'First' } ] );
	} );

	it( 'tolerates a non-array cookies argument', () => {
		expect( axeptioVersionOptions( undefined, '', 'ph' ) ).toEqual( [
			{ value: '', label: 'ph' },
		] );
	} );
} );
