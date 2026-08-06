/**
 * Unit tests of the pure helper functions of the admin app.
 */

import {
	axeptioVersionOptions,
	buildValueMap,
	changedValues,
	choiceSections,
	coerceValue,
	defaultGroupId,
	exportFilename,
	focusTarget,
	groupsWithFields,
	isCellLocked,
	isFieldDisabled,
	locationHash,
	locationTarget,
	moduleMatchesSearch,
	openingPosition,
	stripTags,
} from '../utils';

describe( 'choiceSections', () => {
	const FIELD = {
		choices: {
			html: 'Custom HTML',
			img: 'Custom Image',
			cl: 'Click',
			u: 'URL',
		},
		sections: [
			{ label: 'Tags', choices: [ 'html', 'img' ] },
			{ label: 'Triggers', choices: [ 'cl' ] },
			{ label: 'Variables', choices: [ 'u' ] },
		],
	};

	it( 'splits the choices into the declared sections, in order', () => {
		expect( choiceSections( FIELD ) ).toEqual( [
			{
				label: 'Tags',
				entries: [
					[ 'html', 'Custom HTML' ],
					[ 'img', 'Custom Image' ],
				],
			},
			{ label: 'Triggers', entries: [ [ 'cl', 'Click' ] ] },
			{ label: 'Variables', entries: [ [ 'u', 'URL' ] ] },
		] );
	} );

	it( 'returns one unlabelled section when the field declares none', () => {
		expect( choiceSections( { choices: { a: 'Alpha' } } ) ).toEqual( [
			{ label: '', entries: [ [ 'a', 'Alpha' ] ] },
		] );
	} );

	it( 'renders a choice no section claims rather than dropping it', () => {
		// An option that exists but cannot be seen is an option nobody can set,
		// and nothing would report it - so the leftovers get their own block.
		const sections = choiceSections( {
			choices: { ...FIELD.choices, brandNew: 'Brand new entity' },
			sections: FIELD.sections,
		} );

		expect( sections ).toHaveLength( 4 );
		expect( sections[ 3 ] ).toEqual( {
			label: '',
			entries: [ [ 'brandNew', 'Brand new entity' ] ],
		} );
	} );

	it( 'skips a section entry the choices do not define', () => {
		const sections = choiceSections( {
			choices: { html: 'Custom HTML' },
			sections: [ { label: 'Tags', choices: [ 'html', 'retired' ] } ],
		} );

		expect( sections ).toEqual( [
			{ label: 'Tags', entries: [ [ 'html', 'Custom HTML' ] ] },
		] );
	} );

	it( 'never renders the same choice twice', () => {
		const sections = choiceSections( {
			choices: { html: 'Custom HTML' },
			sections: [
				{ label: 'Tags', choices: [ 'html' ] },
				{ label: 'Triggers', choices: [ 'html' ] },
			],
		} );

		expect( sections ).toEqual( [
			{ label: 'Tags', entries: [ [ 'html', 'Custom HTML' ] ] },
		] );
	} );

	it( 'drops an empty section instead of showing an empty group', () => {
		expect(
			choiceSections( {
				choices: { html: 'Custom HTML' },
				sections: [
					{ label: 'Tags', choices: [ 'html' ] },
					{ label: 'Group classes', choices: [] },
				],
			} )
		).toEqual( [
			{ label: 'Tags', entries: [ [ 'html', 'Custom HTML' ] ] },
		] );
	} );

	it( 'survives a field with no choices at all', () => {
		expect( choiceSections( {} ) ).toEqual( [] );
		expect(
			choiceSections( { choices: {}, sections: FIELD.sections } )
		).toEqual( [] );
	} );
} );

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

describe( 'groupsWithFields / defaultGroupId', () => {
	const TABBED = {
		id: 'page-variables',
		groups: [
			{ id: 'post', label: 'Post data' },
			{ id: 'empty', label: 'Empty' },
			{ id: 'visitor', label: 'Visitor data' },
		],
		fields: [
			{ key: 'a', group: 'post' },
			{ key: 'b', group: 'visitor' },
		],
	};

	it( 'drops declared groups that hold no fields, keeping declared order', () => {
		expect( groupsWithFields( TABBED ).map( ( g ) => g.id ) ).toEqual( [
			'post',
			'visitor',
		] );
	} );

	it( 'attaches each group its own fields', () => {
		expect( groupsWithFields( TABBED )[ 1 ].fields ).toEqual( [
			{ key: 'b', group: 'visitor' },
		] );
	} );

	it( 'opens a tabbed module on its first populated group', () => {
		expect( defaultGroupId( TABBED ) ).toBe( 'post' );
	} );

	it( 'names no group for a module that renders without a tab bar', () => {
		// One populated group means the panel renders flat, so claiming a tab
		// in the URL would promise something the screen does not have.
		expect(
			defaultGroupId( {
				groups: [ { id: 'only' }, { id: 'empty' } ],
				fields: [ { key: 'a', group: 'only' } ],
			} )
		).toBeNull();
	} );

	it( 'survives a module with no groups or fields at all', () => {
		expect( groupsWithFields( {} ) ).toEqual( [] );
		expect( groupsWithFields( undefined ) ).toEqual( [] );
		expect( defaultGroupId( {} ) ).toBeNull();
	} );
} );

describe( 'locationHash / locationTarget', () => {
	const MODULES = [
		{
			id: 'container',
			groups: [ { id: 'general' } ],
			fields: [ { key: 'gtm-containers', group: 'general' } ],
		},
		{
			id: 'page-variables',
			groups: [ { id: 'post' }, { id: 'visitor' }, { id: 'empty' } ],
			fields: [
				{ key: 'a', group: 'post' },
				{ key: 'b', group: 'visitor' },
			],
		},
	];

	it( 'writes module and tab into the fragment', () => {
		expect( locationHash( 'page-variables', 'visitor' ) ).toBe(
			'#page-variables/visitor'
		);
	} );

	it( 'writes the module alone when there is no tab', () => {
		expect( locationHash( 'container', null ) ).toBe( '#container' );
	} );

	it( 'writes nothing when there is no module to point at', () => {
		expect( locationHash( '', 'visitor' ) ).toBe( '' );
	} );

	it( 'round-trips a written fragment back to the same position', () => {
		// The property that makes a bookmark worth anything.
		expect(
			locationTarget(
				MODULES,
				locationHash( 'page-variables', 'visitor' )
			)
		).toEqual( { moduleId: 'page-variables', groupId: 'visitor' } );
	} );

	it( 'returns null for no fragment and for a module that is gone', () => {
		expect( locationTarget( MODULES, '' ) ).toBeNull();
		expect( locationTarget( MODULES, '#' ) ).toBeNull();
		expect( locationTarget( MODULES, '#removed-module/post' ) ).toBeNull();
	} );

	it( 'keeps the module when only the tab is stale', () => {
		// Half the bookmark is still good information, so an old tab id opens
		// the module on its own first tab rather than throwing the link away.
		expect( locationTarget( MODULES, '#page-variables/empty' ) ).toEqual( {
			moduleId: 'page-variables',
			groupId: 'post',
		} );
		expect( locationTarget( MODULES, '#page-variables' ) ).toEqual( {
			moduleId: 'page-variables',
			groupId: 'post',
		} );
	} );

	it( 'names no tab for a module rendered without a tab bar', () => {
		expect( locationTarget( MODULES, '#container/general' ) ).toEqual( {
			moduleId: 'container',
			groupId: null,
		} );
	} );

	it( 'never returns a value taken from the fragment', () => {
		// Same property as focusTarget: ids are matched against the schema and
		// the schema's own values come back, so a crafted URL selects a real
		// position or nothing.
		expect(
			locationTarget( MODULES, '#<script>/"onerror=alert(1)' )
		).toBeNull();
	} );
} );

describe( 'openingPosition', () => {
	const MODULES = [
		{
			id: 'container',
			groups: [ { id: 'general' } ],
			fields: [ { key: 'gtm-containers', group: 'general' } ],
		},
		{
			id: 'page-variables',
			groups: [ { id: 'post' }, { id: 'visitor' } ],
			fields: [
				{ key: 'a', group: 'post' },
				{ key: 'include-visitor-ip-proxies', group: 'visitor' },
			],
		},
	];

	const at = ( search, hash ) =>
		openingPosition( MODULES, { search, hash }, 'gtm4wp-focus' );

	it( 'opens the first module when the URL says nothing', () => {
		expect( at( '', '' ) ).toEqual( {
			moduleId: 'container',
			groupId: null,
			focusFieldKey: null,
		} );
	} );

	it( 'follows a bookmark', () => {
		expect( at( '', '#page-variables/visitor' ) ).toEqual( {
			moduleId: 'page-variables',
			groupId: 'visitor',
			focusFieldKey: null,
		} );
	} );

	it( 'follows a notice deep link, highlight and all', () => {
		expect( at( '?gtm4wp-focus=include-visitor-ip-proxies', '' ) ).toEqual(
			{
				moduleId: 'page-variables',
				groupId: 'visitor',
				focusFieldKey: 'include-visitor-ip-proxies',
			}
		);
	} );

	it( 'lets the notice win over a stale bookmark', () => {
		// The notice was clicked just now and says something about the site's
		// current state; the fragment only says where someone stood last time.
		expect(
			at( '?gtm4wp-focus=include-visitor-ip-proxies', '#container' )
		).toEqual( {
			moduleId: 'page-variables',
			groupId: 'visitor',
			focusFieldKey: 'include-visitor-ip-proxies',
		} );
	} );

	it( 'falls back to the bookmark when the deep link names an unknown option', () => {
		expect(
			at( '?gtm4wp-focus=removed', '#page-variables/visitor' )
		).toEqual( {
			moduleId: 'page-variables',
			groupId: 'visitor',
			focusFieldKey: null,
		} );
	} );

	it( 'survives an install with no modules', () => {
		expect( openingPosition( [], { search: '', hash: '' }, 'x' ) ).toEqual(
			{
				moduleId: '',
				groupId: null,
				focusFieldKey: null,
			}
		);
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
