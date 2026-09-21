/**
 * Webpack configuration: the @wordpress/scripts defaults with dynamic entry
 * points (every js/frontend/ file is its own per-feature bundle, plus the
 * js/admin/ React app). Production mode minifies; combining bundles is left to
 * caching plugins on purpose, do not add a concatenation step (see "Script
 * loading & minification" in .claude/CLAUDE.md).
 */

const fs = require( 'fs' );
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const entry = {};

/**
 * External contract: the EasyPrivacy and AdGuard SpywareFilter block lists match
 * `/gtm4wp-$script` (easylist/easylist#25228, 2026-09-16), so every frontend
 * bundle must carry the prefix and no admin-loaded script may. A miss is silent
 * on both sides, hence the throw. Pinned from the PHP side by
 * tests/unit/ScriptNamingContractTest.php; rule text in .claude/CLAUDE.md.
 */
const FRONTEND_PREFIX = 'gtm4wp-';

const frontendDir = path.resolve( __dirname, 'js/frontend' );
if ( fs.existsSync( frontendDir ) ) {
	fs.readdirSync( frontendDir )
		.filter( ( file ) => file.endsWith( '.js' ) )
		.forEach( ( file ) => {
			if ( ! file.startsWith( FRONTEND_PREFIX ) ) {
				throw new Error(
					`js/frontend/${ file }: frontend bundles must be named ${ FRONTEND_PREFIX }*.js (block-list contract, see .claude/CLAUDE.md).`
				);
			}
			entry[ path.basename( file, '.js' ) ] = path.join(
				frontendDir,
				file
			);
		} );
}

const adminEntry = path.resolve( __dirname, 'js/admin/index.js' );
if ( fs.existsSync( adminEntry ) ) {
	entry.admin = adminEntry;
}

Object.keys( entry ).forEach( ( name ) => {
	if ( name !== 'admin' && ! name.startsWith( FRONTEND_PREFIX ) ) {
		throw new Error(
			`Entry "${ name }": only the admin bundle may lack the ${ FRONTEND_PREFIX } prefix (block-list contract, see .claude/CLAUDE.md).`
		);
	}
} );

module.exports = {
	...defaultConfig,
	entry,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
