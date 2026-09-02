/**
 * Builds a distribution zip of GTM4WP.
 *
 * Runs the production asset build, stages only the files needed on a live
 * site into the WordPress-conventional top level plugin folder and zips
 * the result into release/. Everything development related (sources under
 * js/, tests, vendor, tooling configs, docs) stays out of the archive.
 *
 * Usage: npm run release
 */

const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const AdmZip = require( 'adm-zip' );

const ROOT = path.resolve( __dirname, '..' );
const SLUG = 'duracelltomi-google-tag-manager-for-wordpress';
const MAIN_FILE = path.join( ROOT, `${ SLUG }.php` );
const RELEASE_DIR = path.join( ROOT, 'release' );
const STAGE_DIR = path.join( RELEASE_DIR, SLUG );

/**
 * Everything that ships. Directories are copied recursively; missing
 * optional entries are skipped with a note.
 */
const DIST_FILES = [
	{ source: `${ SLUG }.php`, required: true },
	{ source: 'uninstall.php', required: true },
	{ source: 'readme.txt', required: true },
	{ source: 'license.txt', required: true },
	{ source: 'compat', required: true },
	{ source: 'src', required: true },
	{ source: 'build', required: true },
];

/**
 * Files the build must have produced; guards against zipping a stale or
 * partial build directory.
 */
const BUILD_SANITY_FILES = [
	'build/admin.js',
	'build/admin.asset.php',
	'build/style-admin.css',
	'build/gtm4wp-woocommerce.js',
	'build/gtm4wp-client-device-data.js',
];

/**
 * Files produced by a generator from an external source, each carrying a
 * `Generated: YYYY-MM-DD` stamp in its header.
 *
 * Adding one here is what makes its staleness break something. The alternative -
 * a note in a registry row saying "regenerate before a release" - is a comment,
 * and a comment does not fire.
 */
const GENERATED_DATA = [
	{
		file: 'src/Ecommerce/CountryPhoneData.php',
		command: 'composer generate:phone-table',
		what: 'per-country phone dialling rules (national numbering plans)',
	},
	{
		// Not shipped, but generated from the same parse as the table above, so a
		// stamp mismatch between the two means one of them was hand-edited.
		file: 'tests/unit/Modules/phone-corpus.php',
		command: 'composer generate:phone-table',
		what: 'phone normalization test corpus',
	},
];

const GENERATED_WARN_DAYS = 183;
const GENERATED_FAIL_DAYS = 365;

function fail( message ) {
	console.error( `\n✖ ${ message }` );
	process.exit( 1 );
}

/**
 * Refuses to package data mirrored from an external source that has not been
 * refreshed in a year, and says so out loud after six months.
 *
 * Deliberately NOT a regeneration. Regenerating here would need the network, so
 * packaging would stop being reproducible from a checkout; it would change what
 * ships after the test suite has run and after the changelog was written; and it
 * would produce a zip whose contents match no commit. Refreshing the data is a
 * code change and belongs in a commit with the suite green. This only refuses to
 * ship a copy old enough that nobody can say whether it is still true.
 *
 * Reads the stamp rather than the file mtime: a checkout, a copy or a rebase
 * rewrites mtimes and would report every fresh clone as newly generated.
 */
function checkGeneratedData() {
	const today = new Date();
	const stamps = new Map();

	GENERATED_DATA.forEach( ( entry ) => {
		const full = path.join( ROOT, entry.file );

		if ( ! fs.existsSync( full ) ) {
			fail( `Generated data file is missing: ${ entry.file }\n  Run: ${ entry.command }` );
		}

		const match = fs
			.readFileSync( full, 'utf8' )
			.match( /^\s*\*\s*Generated:\s*(\d{4}-\d{2}-\d{2})\s*$/m );

		// A missing stamp is worse than a stale one: it means the file was edited
		// by hand, or the generator changed shape and this check silently stopped
		// checking anything.
		if ( ! match ) {
			fail(
				`No "Generated:" stamp in ${ entry.file }.\n` +
					`  Either it was edited by hand, or the generator no longer writes one.\n` +
					`  Run: ${ entry.command }`
			);
		}

		stamps.set( entry.file, match[ 1 ] );

		const ageDays = Math.floor(
			( today - new Date( `${ match[ 1 ] }T00:00:00Z` ) ) / 86400000
		);

		if ( ageDays > GENERATED_FAIL_DAYS ) {
			fail(
				`${ entry.what } were generated ${ ageDays } days ago (${ match[ 1 ] }).\n` +
					`  Too old to ship without checking. Run: ${ entry.command }\n` +
					`  Then run the test suite and commit the result; an empty diff means nothing moved.`
			);
		}

		if ( ageDays > GENERATED_WARN_DAYS ) {
			console.warn(
				`\n⚠ ${ entry.what }: generated ${ ageDays } days ago (${ match[ 1 ] }).\n` +
					`  Worth refreshing before this release: ${ entry.command }`
			);
		}
	} );

	// Generated together, so they date together. Different stamps mean one of
	// them was regenerated alone or edited, and the test corpus would then be
	// pinning a table it was not derived from.
	const distinct = new Set( stamps.values() );
	if ( distinct.size > 1 ) {
		fail(
			`Generated files disagree about when they were generated:\n` +
				[ ...stamps ].map( ( [ file, on ] ) => `    ${ on }  ${ file }` ).join( '\n' ) +
				`\n  They come from one parse, so regenerate them together.`
		);
	}
}

function readVersion() {
	const header = fs.readFileSync( MAIN_FILE, 'utf8' );
	const match = header.match( /^\s*\*\s*Version:\s*(.+)$/m );

	if ( ! match ) {
		fail( `Could not read the Version header from ${ MAIN_FILE }.` );
	}

	return match[ 1 ].trim();
}

function copyEntry( entry ) {
	const from = path.join( ROOT, entry.source );

	if ( ! fs.existsSync( from ) ) {
		if ( entry.required ) {
			fail( `Required distribution entry is missing: ${ entry.source }` );
		}
		console.log( `  - skipped (not present): ${ entry.source }` );
		return;
	}

	const to = path.join( STAGE_DIR, entry.source );
	fs.cpSync( from, to, { recursive: true } );
	console.log( `  + ${ entry.source }` );
}

function main() {
	const version = readVersion();

	console.log( `Building GTM4WP release ${ version }` );

	if ( /dev|alpha|beta|rc/i.test( version ) ) {
		console.warn(
			`\n⚠ Version "${ version }" looks like a pre-release. The zip will be built anyway — do not upload it to wordpress.org.\n`
		);
	}

	// Before the build rather than after: this needs no network and no toolchain,
	// so a stale mirror should not cost a webpack run to discover.
	checkGeneratedData();

	console.log( '\n1/3 Running production build…' );
	execSync( 'npm run build', { cwd: ROOT, stdio: 'inherit' } );

	BUILD_SANITY_FILES.forEach( ( file ) => {
		if ( ! fs.existsSync( path.join( ROOT, file ) ) ) {
			fail( `Build output is incomplete, missing: ${ file }` );
		}
	} );

	console.log( '\n2/3 Staging distribution files…' );
	fs.rmSync( STAGE_DIR, { recursive: true, force: true } );
	fs.mkdirSync( STAGE_DIR, { recursive: true } );
	DIST_FILES.forEach( copyEntry );

	console.log( '\n3/3 Creating zip…' );
	const zipFile = path.join( RELEASE_DIR, `${ SLUG }-${ version }.zip` );
	fs.rmSync( zipFile, { force: true } );

	const zip = new AdmZip();
	zip.addLocalFolder( STAGE_DIR, SLUG );
	zip.writeZip( zipFile );

	fs.rmSync( STAGE_DIR, { recursive: true, force: true } );

	const sizeKb = Math.round( fs.statSync( zipFile ).size / 1024 );
	console.log( `\n✔ ${ path.relative( ROOT, zipFile ) } (${ sizeKb } KB)` );
	console.log(
		`  Contains a single top level "${ SLUG }/" folder, ready for wordpress.org SVN or manual upload.`
	);
}

main();
