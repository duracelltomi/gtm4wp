/**
 * Webpack configuration for GTM4WP.
 *
 * Extends the @wordpress/scripts default configuration with dynamic entry
 * points: every file in js/frontend/ becomes its own bundle (per-feature
 * scripts, enqueued only when the owning module is enabled), plus the React
 * admin app from js/admin/.
 *
 * `npm run build` runs webpack in production mode, so each bundle is already
 * minified. Combining bundles into one file is intentionally left to caching /
 * optimization plugins (WP Rocket, Autoptimize, LiteSpeed, …) — do not add a
 * concatenation step here. See "Script loading & minification" in .claude/CLAUDE.md.
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
