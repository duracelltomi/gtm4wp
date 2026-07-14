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

const frontendDir = path.resolve( __dirname, 'js/frontend' );
if ( fs.existsSync( frontendDir ) ) {
	fs.readdirSync( frontendDir )
		.filter( ( file ) => file.endsWith( '.js' ) )
		.forEach( ( file ) => {
			entry[ path.basename( file, '.js' ) ] = path.join( frontendDir, file );
		} );
}

const adminEntry = path.resolve( __dirname, 'js/admin/index.js' );
if ( fs.existsSync( adminEntry ) ) {
	entry.admin = adminEntry;
}

module.exports = {
	...defaultConfig,
	entry,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
