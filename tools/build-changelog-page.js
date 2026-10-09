#!/usr/bin/env node
/**
 * Builds the gtm4wp.com changelog pages from CHANGELOG.md as WordPress block markup:
 * the current page (2.x and later) and the frozen 1.x archive. The output is pushed to
 * the site separately; this script only reads the repo and writes to --out.
 *
 * A released heading is `## X.Y.Z (YYYY-MM-DD)`, the date the wordpress.org SVN tag was
 * created. Only the unreleased headings at the top may carry no date, plus
 * NO_WPORG_RELEASE. Anything the parser does not recognise is an error, never a guess.
 *
 * Usage:
 *   node tools/build-changelog-page.js --out <dir> [--testing-tag 2.1.0-beta2]
 *
 * --testing-tag adds the "In testing" block: the top section of CHANGELOG.md as it was
 * at that git tag, dated by the tag.
 *
 * @package GTM4WP
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const SITE = 'https://gtm4wp.com';
const CURRENT_URL = `${ SITE }/changelog/`;
const ARCHIVE_URL = `${ SITE }/changelog/1-x/`;
const REPO_URL = 'https://github.com/duracelltomi/gtm4wp';
const CHANGELOG_URL = `${ REPO_URL }/blob/master/CHANGELOG.md`;

// Versions that never reached wordpress.org, so they have no release date.
const NO_WPORG_RELEASE = [ '1.17' ];

const MONTHS = [
	'January',
	'February',
	'March',
	'April',
	'May',
	'June',
	'July',
	'August',
	'September',
	'October',
	'November',
	'December',
];

const HEADING_RE = /^## (\d+(?:\.\d+)*)(?: \((\d{4}-\d{2}-\d{2})\))?$/;
const RELEASE_POST_RE = /^Release post: \[([^\]]+)\]\((https:\/\/[^\s)]+)\)$/;
const TESTING_TAG_RE = /^(\d+\.\d+\.\d+)-(?:beta|rc)\d+$/;

/**
 * Escapes text for HTML element content and attribute values.
 *
 * @param {string} text Raw text.
 * @return {string} Escaped text.
 */
function esc( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}

/**
 * Escapes text, then applies bold, italic and (optionally) issue links.
 *
 * @param {string}  text   Text that may contain placeholders.
 * @param {boolean} issues Whether to link `#123` issue references.
 * @return {string} HTML.
 */
function plain( text, issues ) {
	let html = esc( text )
		.replace( /\*\*(?!\s)(.+?)(?<!\s)\*\*/g, '<strong>$1</strong>' )
		.replace(
			/(^|[^\w*])\*(?!\s)([^*]+?)(?<!\s)\*(?![\w*])/g,
			'$1<em>$2</em>'
		);

	if ( issues ) {
		html = html.replace(
			// "(#123)" and "issue #123" only; "checkout step #1" is not a reference.
			/(\(|\bissue )#(\d{1,5})(?=[\s),.;:]|$)/g,
			`$1<a href="${ REPO_URL }/issues/$2">#$2</a>`
		);
	}

	return html;
}

/**
 * Renders one line of changelog markdown to inline HTML. Code spans and links are
 * held in placeholders so that nothing inside them is reinterpreted.
 *
 * @param {string} src Markdown text.
 * @return {string} HTML.
 */
function renderInline( src ) {
	const slots = [];
	const hold = ( html ) => `\u0000${ slots.push( html ) - 1 }\u0000`;

	let text = String( src ).replace( /\u0000/g, '' );

	text = text.replace( /`([^`]+)`/g, ( m, code ) =>
		hold( `<code>${ esc( code ) }</code>` )
	);
	text = text.replace(
		/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
		( m, label, url ) =>
			hold( `<a href="${ esc( url ) }">${ plain( label, false ) }</a>` )
	);
	text = text.replace(
		/https?:\/\/[^\s<>()\u0000]*[^\s<>()\u0000.,;:!?'"]/g,
		( url ) => hold( `<a href="${ esc( url ) }">${ esc( url ) }</a>` )
	);

	let html = plain( text, true );

	// Link labels can hold code placeholders, so restore until none are left.
	while ( html.includes( '\u0000' ) ) {
		html = html.replace( /\u0000(\d+)\u0000/g, ( m, i ) => slots[ i ] );
	}

	return html;
}

/**
 * Formats an ISO date as "1 October 2026".
 *
 * @param {string} iso YYYY-MM-DD.
 * @return {string} Formatted date.
 */
function formatDate( iso ) {
	const [ year, month, day ] = iso.split( '-' ).map( Number );

	return `${ day } ${ MONTHS[ month - 1 ] } ${ year }`;
}

/**
 * Returns the anchor id of a version heading ("2.0.5" -> "v2-0-5").
 *
 * @param {string} version Version number.
 * @return {string} Anchor id.
 */
function anchorOf( version ) {
	return `v${ version.replace( /\./g, '-' ) }`;
}

/**
 * Splits CHANGELOG.md into version sections and validates the headings.
 *
 * @param {string} text CHANGELOG.md content.
 * @return {Array<{version: string, date: (string|null), released: boolean, body: string[]}>} Sections, file order.
 */
function parseChangelog( text ) {
	const lines = text.replace( /\r\n/g, '\n' ).split( '\n' );
	const sections = [];
	let seenDated = false;

	lines.forEach( ( line, i ) => {
		if ( line.startsWith( '## ' ) ) {
			const match = HEADING_RE.exec( line );

			if ( ! match ) {
				throw new Error(
					`CHANGELOG.md:${ i + 1 }: malformed heading "${ line }"`
				);
			}

			const [ , version, date = null ] = match;
			const exempt = NO_WPORG_RELEASE.includes( version );

			if ( ! date && seenDated && ! exempt ) {
				throw new Error(
					`CHANGELOG.md:${
						i + 1
					}: released version ${ version } has no wordpress.org date`
				);
			}

			seenDated = seenDated || null !== date;
			sections.push( {
				version,
				date,
				released: null !== date || exempt,
				body: [],
			} );
		} else if ( sections.length ) {
			sections[ sections.length - 1 ].body.push( line );
		}
	} );

	return sections;
}

/**
 * Wraps HTML in a paragraph block.
 *
 * @param {string} html Inline HTML.
 * @return {string} Block markup.
 */
function paragraphBlock( html ) {
	return `<!-- wp:paragraph -->\n<p>${ html }</p>\n<!-- /wp:paragraph -->`;
}

/**
 * Builds a heading block.
 *
 * @param {number}      level  2-4.
 * @param {string}      html   Inline HTML.
 * @param {string|null} anchor Optional id.
 * @return {string} Block markup.
 */
function headingBlock( level, html, anchor = null ) {
	const attrs = 2 === level ? '' : ` {"level":${ level }}`;
	const id = anchor ? ` id="${ anchor }"` : '';

	return (
		`<!-- wp:heading${ attrs } -->\n` +
		`<h${ level } class="wp-block-heading"${ id }>${ html }</h${ level }>\n` +
		'<!-- /wp:heading -->'
	);
}

/**
 * Builds a list block from items, each with optional nested items.
 *
 * @param {Array<{html: string, children: string[]}>} items List items.
 * @return {string} Block markup.
 */
function listBlock( items ) {
	const li = ( html, nested ) =>
		`<!-- wp:list-item -->\n<li>${ html }${ nested }</li>\n<!-- /wp:list-item -->`;
	const ul = ( inner ) =>
		`<!-- wp:list -->\n<ul class="wp-block-list">${ inner }</ul>\n<!-- /wp:list -->`;

	return ul(
		items
			.map( ( item ) =>
				li(
					item.html,
					item.children.length
						? ul(
								item.children
									.map( ( c ) => li( c, '' ) )
									.join( '' )
						  )
						: ''
				)
			)
			.join( '' )
	);
}

/**
 * Renders the body lines of one version section.
 *
 * @param {string[]} body    Lines under the version heading.
 * @param {string}   version Version, for error messages.
 * @return {string[]} Blocks.
 */
function renderBody( body, version ) {
	const blocks = [];
	let list = null;
	let para = null;

	const flushList = () => {
		if ( list ) {
			blocks.push( listBlock( list ) );
			list = null;
		}
	};
	const flushPara = () => {
		if ( para ) {
			blocks.push( paragraphBlock( para.join( '<br>' ) ) );
			para = null;
		}
	};

	body.forEach( ( line ) => {
		let match;

		if ( '' === line.trim() ) {
			flushPara();
		} else if ( ( match = /^(#{3,4}) (.+)$/.exec( line ) ) ) {
			flushPara();
			flushList();
			blocks.push(
				headingBlock( match[ 1 ].length, renderInline( match[ 2 ] ) )
			);
		} else if ( ( match = /^\* (.+)$/.exec( line ) ) ) {
			flushPara();
			list = list || [];
			list.push( { html: renderInline( match[ 1 ] ), children: [] } );
		} else if ( ( match = /^(?:\t| {2,4})\* (.+)$/.exec( line ) ) ) {
			if ( ! list ) {
				throw new Error(
					`${ version }: nested bullet without a parent: "${ line }"`
				);
			}
			list[ list.length - 1 ].children.push( renderInline( match[ 1 ] ) );
		} else if ( ( match = RELEASE_POST_RE.exec( line ) ) ) {
			flushPara();
			flushList();
			blocks.push(
				paragraphBlock(
					`Read more: <a href="${ esc( match[ 2 ] ) }">${ plain(
						match[ 1 ],
						false
					) }</a>`
				)
			);
		} else if ( /^(\s|#|```|>|\||- |\d+\. )/.test( line ) ) {
			throw new Error(
				`${ version }: unsupported markdown: "${ line }"`
			);
		} else {
			flushList();
			para = para || [];
			para.push( renderInline( line ) );
		}
	} );

	flushPara();
	flushList();

	return blocks;
}

/**
 * Renders a version section with its heading.
 *
 * @param {{version: string, date: (string|null), body: string[]}} section Section.
 * @return {string[]} Blocks.
 */
function renderSection( section ) {
	const title = section.date
		? `${ section.version } · ${ formatDate( section.date ) }`
		: section.version;

	return [
		headingBlock( 2, esc( title ), anchorOf( section.version ) ),
		...renderBody( section.body, section.version ),
	];
}

/**
 * Builds the "Versions:" jump list: one link per minor line, to its newest version.
 *
 * @param {Array<{version: string}>} sections Sections on the page, newest first.
 * @return {string} Block markup.
 */
function jumpList( sections ) {
	const seen = new Map();

	sections.forEach( ( s ) => {
		const line = s.version.split( '.' ).slice( 0, 2 ).join( '.' );

		if ( ! seen.has( line ) ) {
			seen.set( line, s.version );
		}
	} );

	const links = [ ...seen ].map(
		( [ line, newest ] ) =>
			`<a href="#${ anchorOf( newest ) }">${ esc( line ) }</a>`
	);

	return paragraphBlock( `Versions: ${ links.join( ' · ' ) }` );
}

/**
 * Builds both pages.
 *
 * @param {Array}       sections Parsed sections.
 * @param {Object|null} testing  { tag, date, section } for the "In testing" block.
 * @return {{current: string, archive: string, anchors: string[]}} Block markup per page.
 */
function buildPages( sections, testing = null ) {
	const released = sections.filter( ( s ) => s.released );
	const current = released.filter(
		( s ) => Number( s.version.split( '.' )[ 0 ] ) >= 2
	);
	const archive = released.filter(
		( s ) => Number( s.version.split( '.' )[ 0 ] ) < 2
	);

	if ( testing ) {
		const base = TESTING_TAG_RE.exec( testing.tag )[ 1 ];
		const shipped = released.find(
			( s ) => base === s.version || base === `${ s.version }.0`
		);

		if ( shipped ) {
			throw new Error(
				`${ testing.tag }: ${ shipped.version } is already released, drop --testing-tag`
			);
		}
	}

	const dates = current
		.map( ( s ) => s.date )
		.concat( testing ? [ testing.date ] : [] );
	const lastUpdated = dates.filter( Boolean ).sort().pop();

	const currentBlocks = [
		paragraphBlock(
			'Every change in GTM4WP 2.x, newest first. The date next to each version is the day it became available on wordpress.org.'
		),
		paragraphBlock(
			`Changes in 1.x and earlier versions are on the <a href="${ ARCHIVE_URL }">GTM4WP 1.x changelog</a> page. The same list is also kept in <a href="${ CHANGELOG_URL }">CHANGELOG.md on GitHub</a>.`
		),
		paragraphBlock( `Last updated: ${ formatDate( lastUpdated ) }` ),
		jumpList( current ),
	];

	if ( testing ) {
		currentBlocks.push(
			headingBlock(
				2,
				esc( `In testing: ${ testing.tag }` ),
				'in-testing'
			),
			paragraphBlock(
				`Pre-release published on GitHub on ${ formatDate(
					testing.date
				) }. Download it from the <a href="${ REPO_URL }/releases/tag/${ esc(
					testing.tag
				) }">GitHub release page</a> and test it on a staging site, not on a live site.`
			),
			...renderBody( testing.section.body, testing.tag )
		);
	}

	current.forEach( ( s ) => currentBlocks.push( ...renderSection( s ) ) );

	const archiveBlocks = [
		paragraphBlock(
			'Every change in GTM4WP 1.x and earlier versions, newest first. The date next to each version is the day it became available on wordpress.org.'
		),
		paragraphBlock(
			`For GTM4WP 2.x, see the <a href="${ CURRENT_URL }">GTM4WP changelog</a>.`
		),
		jumpList( archive ),
	];

	archive.forEach( ( s ) => archiveBlocks.push( ...renderSection( s ) ) );

	return {
		current: currentBlocks.join( '\n\n' ) + '\n',
		archive: archiveBlocks.join( '\n\n' ) + '\n',
		anchors: released.map( ( s ) => anchorOf( s.version ) ),
	};
}

/**
 * Runs git and returns stdout, or throws.
 *
 * @param {string[]} args Arguments passed to git.
 * @return {string} stdout.
 */
function git( args ) {
	const res = spawnSync( 'git', args, { encoding: 'utf8' } );

	if ( res.error || 0 !== res.status ) {
		throw new Error(
			`git ${ args.join( ' ' ) } failed: ${ res.stderr || res.error }`
		);
	}

	return res.stdout;
}

/**
 * Reads the "In testing" data for a pre-release tag.
 *
 * @param {string} tag Pre-release tag, e.g. 2.1.0-beta2.
 * @return {{tag: string, date: string, section: Object}} Testing data.
 */
function readTesting( tag ) {
	const match = TESTING_TAG_RE.exec( tag );

	if ( ! match ) {
		throw new Error(
			`--testing-tag "${ tag }" is not a pre-release tag like 2.1.0-beta2`
		);
	}

	const ref = `refs/tags/${ tag }`;
	const date = git( [
		'for-each-ref',
		'--format=%(creatordate:short)',
		ref,
	] ).trim();

	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
		throw new Error( `tag ${ tag } not found` );
	}

	// Only this one section: older headings at a tag may predate the date format.
	const lines = git( [ 'show', `${ ref }:CHANGELOG.md` ] )
		.replace( /\r\n/g, '\n' )
		.split( '\n' );
	const base = match[ 1 ];
	const start = lines.findIndex( ( l ) => {
		const h = HEADING_RE.exec( l );

		return h && ( base === h[ 1 ] || base === `${ h[ 1 ] }.0` );
	} );

	if ( -1 === start ) {
		throw new Error(
			`CHANGELOG.md at ${ tag } has no section for ${ base }`
		);
	}

	const end = lines.findIndex(
		( l, i ) => i > start && l.startsWith( '## ' )
	);
	const body = lines.slice( start + 1, -1 === end ? lines.length : end );

	return {
		tag,
		date,
		section: { version: base, date: null, released: false, body },
	};
}

function main() {
	const args = process.argv.slice( 2 );
	const opt = ( name ) => {
		const i = args.indexOf( name );

		return -1 === i ? null : args[ i + 1 ];
	};
	const out = opt( '--out' );
	const tag = opt( '--testing-tag' );

	if ( ! out ) {
		console.error(
			'Usage: node tools/build-changelog-page.js --out <dir> [--testing-tag 2.1.0-beta2]'
		);
		process.exit( 2 );
	}

	try {
		const text = fs.readFileSync(
			path.join( __dirname, '..', 'CHANGELOG.md' ),
			'utf8'
		);
		const pages = buildPages(
			parseChangelog( text ),
			tag ? readTesting( tag ) : null
		);

		fs.mkdirSync( out, { recursive: true } );
		fs.writeFileSync( path.join( out, 'changelog.html' ), pages.current );
		fs.writeFileSync(
			path.join( out, 'changelog-1-x.html' ),
			pages.archive
		);

		console.log(
			`Wrote changelog.html (${ pages.current.length } bytes) and changelog-1-x.html (${ pages.archive.length } bytes) to ${ out }`
		);
		console.log(
			`Anchors: ${ pages.anchors.length }, newest #${ pages.anchors[ 0 ] }`
		);
	} catch ( e ) {
		console.error( `build-changelog-page: ${ e.message }` );
		process.exit( 1 );
	}
}

if ( require.main === module ) {
	main();
}

module.exports = {
	anchorOf,
	buildPages,
	formatDate,
	parseChangelog,
	renderBody,
	renderInline,
};
