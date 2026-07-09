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
	{ source: 'languages', required: false },
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

function fail( message ) {
	console.error( `\n✖ ${ message }` );
	process.exit( 1 );
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
