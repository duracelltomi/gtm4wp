/**
 * Jest configuration for GTM4WP.
 *
 * Extends @wordpress/scripts' default preset with module mappings for the
 * `@wordpress/*` packages.
 *
 * Those packages are **build-time externals**: the DependencyExtractionWebpackPlugin
 * rewrites every `@wordpress/x` import to `window.wp.x`, so the plugin never
 * bundles them and never pins a version — at runtime the admin app runs against
 * whatever WordPress itself ships, across the whole 6.3 → 7.x support range.
 * Installing one version as a devDependency would therefore not be "the real
 * thing" either; it would be one arbitrary point in that range, plus a heavy
 * dependency tree that has to be kept in step by hand.
 *
 * So the admin component tests run against small local stand-ins in
 * js/admin/test-support/. This is deliberate and is NOT the TS-13 smell (a double
 * more capable than the real collaborator): the stand-ins are strictly *less*
 * capable — plain accessible DOM with the documented prop contract (`onChange`
 * receives the value, `disabled`/`readOnly` reach the input) and nothing else. The
 * plugin's own guards are written not to rely on the library (see the comment on
 * TableControl.updateCell), and the tests assert those guards through their
 * observable effect, never through a library prop.
 *
 * The frontend tests in js/frontend/test/ import no `@wordpress/*` package and are
 * unaffected by these mappings.
 */

const preset = require( '@wordpress/jest-preset-default/jest-preset' );

module.exports = {
	...preset,
	setupFilesAfterEnv: [
		...( preset.setupFilesAfterEnv || [] ),
		require.resolve( '@testing-library/jest-dom' ),
	],
	// The admin app is JSX, and babel-jest needs to be told so. This is set here
	// rather than in a project-level babel.config.js on purpose: wp-scripts' own
	// webpack config applies `@wordpress/babel-preset-default` ONLY while the
	// project has no babel config of its own (`hasBabelConfig()`), so adding one
	// at the root would silently take over the production build. Configuring the
	// transform inline keeps that check false and leaves `npm run build` byte for
	// byte what it was, while using the very same preset here.
	transform: {
		'\\.[jt]sx?$': [
			require.resolve( 'babel-jest' ),
			{
				babelrc: false,
				configFile: false,
				presets: [
					require.resolve( '@wordpress/babel-preset-default' ),
				],
			},
		],
	},
	moduleNameMapper: {
		...preset.moduleNameMapper,
		'^@wordpress/components$':
			'<rootDir>/js/admin/test-support/wp-components.js',
		'^@wordpress/element$': '<rootDir>/js/admin/test-support/wp-element.js',
		'^@wordpress/i18n$': '<rootDir>/js/admin/test-support/wp-i18n.js',
		'^@wordpress/api-fetch$':
			'<rootDir>/js/admin/test-support/wp-api-fetch.js',
	},
};
