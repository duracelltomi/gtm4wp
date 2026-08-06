/**
 * Unit tests of the pure helper functions of the admin app.
 */

import {
	axeptioVersionOptions,
	buildValueMap,
	changedValues,
	coerceValue,
	exportFilename,
	focusTarget,
	isCellLocked,
	isFieldDisabled,
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

describe( 'buildValueMap', () => {
	const modules = [
		{
			fields: [
				{ key: 'flag', type: 'checkbox', value: true },
				{ key: 'count', type: 'integer', value: 5 },
			],
		},
		{
			fields: [ { key: 'name', type: 'text', value: 'default' } ],
		},
	];

	it( 'coerces each field value using its own value by default', () => {
		expect( buildValueMap( modules ) ).toEqual( {
			flag: true,
			count: 5,
			name: 'default',
		} );
	} );

	it( 'lets server overrides replace a field value (import path)', () => {
		expect(
			buildValueMap( modules, { name: 'imported', flag: '' } )
		).toEqual( {
			flag: false,
			count: 5,
			name: 'imported',
		} );
	} );
} );

describe( 'isFieldDisabled', () => {
	const dependent = {
		key: 'gtm4wp-options-include-parentcategories',
		depends_on: 'gtm4wp-options-include-categories',
	};

	it( 'disables a dependent field while its dependency is off', () => {
		expect(
			isFieldDisabled( dependent, {
				'gtm4wp-options-include-categories': false,
			} )
		).toBe( true );
	} );

	it( 'enables a dependent field once its dependency is on', () => {
		expect(
			isFieldDisabled( dependent, {
				'gtm4wp-options-include-categories': true,
			} )
		).toBe( false );
	} );

	it( 'treats a missing/empty dependency value as off', () => {
		expect( isFieldDisabled( dependent, {} ) ).toBe( true );
		expect(
			isFieldDisabled(
				{ key: 'child', depends_on: 'parent-path' },
				{ 'parent-path': '' }
			)
		).toBe( true );
		expect(
			isFieldDisabled(
				{ key: 'child', depends_on: 'parent-path' },
				{ 'parent-path': 'gtm.js' }
			)
		).toBe( false );
	} );

	it( 'never disables a field without a dependency', () => {
		expect( isFieldDisabled( { key: 'lonely' }, {} ) ).toBe( false );
		expect( isFieldDisabled( { key: 'lonely', depends_on: '' }, {} ) ).toBe(
			false
		);
	} );
} );

describe( 'isCellLocked', () => {
	const idColumn = { key: 'id' };
	const authColumn = { key: 'gtm_auth', readonly: true };

	it( 'locks a column a wp-config.php constant fixes', () => {
		expect( isCellLocked( { rows_locked: false }, authColumn ) ).toBe(
			true
		);
	} );

	it( 'leaves the other columns editable', () => {
		expect( isCellLocked( { rows_locked: false }, idColumn ) ).toBe(
			false
		);
	} );

	it( 'locks every cell once the row set is fixed', () => {
		// With the container list itself decided by wp-config.php there is no
		// row of the admin's own left for an edit to be saved into.
		expect( isCellLocked( { rows_locked: true }, idColumn ) ).toBe( true );
		expect( isCellLocked( { rows_locked: true }, authColumn ) ).toBe(
			true
		);
	} );

	it( 'treats a field without lock information as editable', () => {
		expect( isCellLocked( {}, {} ) ).toBe( false );
		expect( isCellLocked( undefined, undefined ) ).toBe( false );
	} );
} );

describe( 'exportFilename', () => {
	it( 'stamps the given date into the file name', () => {
		expect( exportFilename( new Date( '2026-07-15T10:20:30Z' ) ) ).toBe(
			'gtm4wp-settings-2026-07-15.json'
		);
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

describe( 'focusTarget', () => {
	const MODULES = [
		{
			id: 'container',
			fields: [ { key: 'gtm-containers', group: 'general' } ],
		},
		{
			id: 'page-variables',
			fields: [
				{ key: 'include-visitor-ip', group: 'visitor' },
				{ key: 'include-visitor-ip-proxies', group: 'visitor' },
			],
		},
	];

	it( 'resolves an option key to the module and group that hold it', () => {
		// The whole point of the feature: the link carries the option key only,
		// and where it currently lives is looked up here.
		expect(
			focusTarget(
				MODULES,
				'?page=gtm4wp-settings&gtm4wp-focus=include-visitor-ip-proxies',
				'gtm4wp-focus'
			)
		).toEqual( {
			moduleId: 'page-variables',
			groupId: 'visitor',
			fieldKey: 'include-visitor-ip-proxies',
		} );
	} );

	it( 'resolves a key held by the first module too', () => {
		expect(
			focusTarget(
				MODULES,
				'?gtm4wp-focus=gtm-containers',
				'gtm4wp-focus'
			)
		).toEqual( {
			moduleId: 'container',
			groupId: 'general',
			fieldKey: 'gtm-containers',
		} );
	} );

	it( 'returns null when the query string carries no deep link', () => {
		expect(
			focusTarget( MODULES, '?page=gtm4wp-settings', 'gtm4wp-focus' )
		).toBeNull();
		expect( focusTarget( MODULES, '', 'gtm4wp-focus' ) ).toBeNull();
	} );

	it( 'returns null for an option this install does not have', () => {
		// A removed option, or a hand-edited URL: the page must open normally
		// rather than end up with nothing selected.
		expect(
			focusTarget(
				MODULES,
				'?gtm4wp-focus=removed-option',
				'gtm4wp-focus'
			)
		).toBeNull();
	} );

	it( 'ignores a deep link when the server named no query argument', () => {
		// Bootstrap data from a version that predates `focusArg`.
		expect(
			focusTarget( MODULES, '?gtm4wp-focus=gtm-containers', undefined )
		).toBeNull();
	} );

	it( 'reads the query argument the server named, not a hardcoded one', () => {
		// Pins the single-definition contract: rename the constant in PHP and
		// the app follows, because the name arrives in the bootstrap data.
		expect(
			focusTarget(
				MODULES,
				'?somewhere-else=gtm-containers',
				'somewhere-else'
			)
		).toEqual( {
			moduleId: 'container',
			groupId: 'general',
			fieldKey: 'gtm-containers',
		} );
	} );

	it( 'never returns a value taken from the URL', () => {
		// The key is matched against the schema and the FIELD's own values come
		// back, so a crafted URL cannot smuggle a class name or a selector in.
		expect(
			focusTarget(
				MODULES,
				'?gtm4wp-focus=' +
					encodeURIComponent( '"><script>alert(1)</script>' ),
				'gtm4wp-focus'
			)
		).toBeNull();
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
