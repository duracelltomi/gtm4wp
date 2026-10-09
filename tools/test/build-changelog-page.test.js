/**
 * Tests for tools/build-changelog-page.js: the heading rules, the escaping of
 * changelog text into page HTML, and the split into the current and 1.x pages.
 *
 * @package GTM4WP
 */

const {
	anchorOf,
	buildPages,
	formatDate,
	parseChangelog,
	renderBody,
	renderInline,
} = require( '../build-changelog-page' );

const SAMPLE = [
	'# Full changelog for GTM4WP',
	'',
	'## 2.1',
	'',
	'* Added: unreleased feature.',
	'',
	'## 2.0.5 (2026-10-01)',
	'',
	'* Fixed: a thing (#472).',
	'',
	'Release post: [GTM4WP 2.0.5 is out](https://gtm4wp.com/announcements/gtm4wp-2-0-5-is-out.html)',
	'',
	'## 2.0 (2026-09-01)',
	'',
	'### WooCommerce',
	'',
	'* Changed: grouped.',
	'\t* Nested detail.',
	'',
	'## 1.17',
	'',
	'* Never on wordpress.org.',
	'',
	'## 1.16.1 (2022-08-01)',
	'',
	'IMPORTANT!',
	'Read this.',
	'',
].join( '\n' );

describe( 'anchors and dates', () => {
	it( 'derives a stable anchor from the version', () => {
		expect( anchorOf( '2.0.5' ) ).toBe( 'v2-0-5' );
		expect( anchorOf( '2.0' ) ).toBe( 'v2-0' );
	} );

	it( 'formats ISO dates in English without a timezone shift', () => {
		expect( formatDate( '2026-10-01' ) ).toBe( '1 October 2026' );
		expect( formatDate( '2013-12-31' ) ).toBe( '31 December 2013' );
	} );
} );

describe( 'parseChangelog', () => {
	it( 'accepts dateless unreleased headings on top and the 1.17 exception', () => {
		const sections = parseChangelog( SAMPLE );

		expect(
			sections.map( ( s ) => [ s.version, s.date, s.released ] )
		).toEqual( [
			[ '2.1', null, false ],
			[ '2.0.5', '2026-10-01', true ],
			[ '2.0', '2026-09-01', true ],
			[ '1.17', null, true ],
			[ '1.16.1', '2022-08-01', true ],
		] );
	} );

	it( 'rejects a released heading without a date', () => {
		const text = '## 2.0.5 (2026-10-01)\n\n* x\n\n## 2.0.4\n\n* y\n';

		expect( () => parseChangelog( text ) ).toThrow(
			/2\.0\.4 has no wordpress\.org date/
		);
	} );

	it( 'rejects a malformed heading', () => {
		expect( () => parseChangelog( '## 1.16.1 = \n' ) ).toThrow(
			/malformed heading/
		);
		expect( () => parseChangelog( '## 2.0.5 (1 Oct 2026)\n' ) ).toThrow(
			/malformed heading/
		);
	} );
} );

describe( 'renderInline', () => {
	it( 'escapes raw HTML in changelog text', () => {
		const html = renderInline(
			'Added data-cfasync to all <script> elements & "quotes"'
		);

		expect( html ).toContain( '&lt;script&gt;' );
		expect( html ).toContain( '&amp; &quot;quotes&quot;' );
		expect( html ).not.toContain( '<script' );
		expect( html ).not.toContain( '"quotes"' );
	} );

	it( 'keeps a quote in a link target inside the href attribute', () => {
		const html = renderInline(
			'[x](https://example.com/"onmouseover="alert(1)) tail'
		);

		expect( html ).toContain(
			'href="https://example.com/&quot;onmouseover=&quot;alert(1"'
		);
		expect( html ).not.toContain( '"onmouseover="' );
	} );

	it( 'does not link non-http targets', () => {
		const html = renderInline( '[click](javascript:alert(1))' );

		expect( html ).not.toContain( '<a' );
		expect( html ).not.toContain( 'href' );
	} );

	it( 'renders code spans literally and escaped', () => {
		const html = renderInline( 'Use `**not bold** <b>` here and **bold**' );

		expect( html ).toBe(
			'Use <code>**not bold** &lt;b&gt;</code> here and <strong>bold</strong>'
		);
	} );

	it( 'links issue references but not other numbers', () => {
		expect( renderInline( 'Thanks (#145).' ) ).toBe(
			'Thanks (<a href="https://github.com/duracelltomi/gtm4wp/issues/145">#145</a>).'
		);
		expect( renderInline( 'checkout step #1 if' ) ).toBe(
			'checkout step #1 if'
		);
		expect( renderInline( 'dashes (`&#8217;`)' ) ).not.toContain(
			'issues/'
		);
	} );

	it( 'links bare URLs without trailing punctuation', () => {
		expect( renderInline( 'See https://gtm4wp.com/a?b=1&c=2.' ) ).toBe(
			'See <a href="https://gtm4wp.com/a?b=1&amp;c=2">https://gtm4wp.com/a?b=1&amp;c=2</a>.'
		);
	} );

	it( 'renders code inside a link label', () => {
		expect( renderInline( '[the `x` page](https://gtm4wp.com/x)' ) ).toBe(
			'<a href="https://gtm4wp.com/x">the <code>x</code> page</a>'
		);
	} );
} );

describe( 'renderBody', () => {
	it( 'nests indented bullets under their parent item', () => {
		const html = renderBody( [ '* Parent', '\t* Child' ], '2.0' ).join(
			''
		);

		expect( html ).toMatch(
			/<li>Parent<!-- wp:list -->\n<ul class="wp-block-list"><!-- wp:list-item -->\n<li>Child<\/li>/
		);
	} );

	it( 'turns a release post line into a read-more link', () => {
		const html = renderBody(
			[
				'Release post: [GTM4WP 2.0.5 is out](https://gtm4wp.com/x.html)',
			],
			'2.0.5'
		).join( '' );

		expect( html ).toContain(
			'Read more: <a href="https://gtm4wp.com/x.html">GTM4WP 2.0.5 is out</a>'
		);
	} );

	it( 'joins consecutive text lines into one paragraph', () => {
		expect( renderBody( [ 'IMPORTANT!', 'Read this.' ], '1.0' ) ).toEqual( [
			'<!-- wp:paragraph -->\n<p>IMPORTANT!<br>Read this.</p>\n<!-- /wp:paragraph -->',
		] );
	} );

	it.each( [
		[ '- dash bullet' ],
		[ '```' ],
		[ '> quote' ],
		[ '| table |' ],
		[ '1. numbered' ],
	] )( 'rejects unsupported markdown: %s', ( line ) => {
		expect( () => renderBody( [ line ], '2.0' ) ).toThrow(
			/unsupported markdown/
		);
	} );

	it( 'rejects a nested bullet without a parent', () => {
		expect( () => renderBody( [ '\t* orphan' ], '2.0' ) ).toThrow(
			/without a parent/
		);
	} );
} );

describe( 'buildPages', () => {
	const sections = parseChangelog( SAMPLE );

	it( 'splits 2.x and 1.x and leaves unreleased sections out', () => {
		const pages = buildPages( sections );

		expect( pages.current ).toContain(
			'id="v2-0-5">2.0.5 · 1 October 2026</h2>'
		);
		expect( pages.current ).toContain(
			'id="v2-0">2.0 · 1 September 2026</h2>'
		);
		expect( pages.current ).not.toContain( 'unreleased feature' );
		expect( pages.current ).not.toContain( 'v1-16-1' );
		expect( pages.archive ).toContain( 'id="v1-17">1.17</h2>' );
		expect( pages.archive ).toContain(
			'id="v1-16-1">1.16.1 · 1 August 2022</h2>'
		);
		expect( pages.archive ).not.toContain( 'v2-0' );
		expect( pages.anchors ).toEqual( [
			'v2-0-5',
			'v2-0',
			'v1-17',
			'v1-16-1',
		] );
	} );

	it( 'dates the current page by its newest release', () => {
		expect( buildPages( sections ).current ).toContain(
			'Last updated: 1 October 2026'
		);
	} );

	it( 'adds the in-testing block and dates the page by the pre-release', () => {
		const testing = {
			tag: '2.1.0-beta2',
			date: '2026-10-05',
			section: {
				version: '2.1',
				body: [ '* Added: a <b>beta</b> feature.' ],
			},
		};
		const pages = buildPages( sections, testing );

		expect( pages.current ).toContain(
			'id="in-testing">In testing: 2.1.0-beta2</h2>'
		);
		expect( pages.current ).toContain( 'releases/tag/2.1.0-beta2' );
		expect( pages.current ).toContain(
			'a &lt;b&gt;beta&lt;/b&gt; feature'
		);
		expect( pages.current ).toContain( 'Last updated: 5 October 2026' );
		expect( pages.current.indexOf( 'id="in-testing"' ) ).toBeLessThan(
			pages.current.indexOf( 'id="v2-0-5"' )
		);
	} );

	it( 'refuses an in-testing block for a version already released', () => {
		const testing = {
			tag: '2.0.0-rc1',
			date: '2026-08-17',
			section: { version: '2.0', body: [] },
		};

		expect( () => buildPages( sections, testing ) ).toThrow(
			/already released/
		);
	} );

	it( 'is deterministic', () => {
		expect( buildPages( sections ) ).toEqual( buildPages( sections ) );
	} );
} );
