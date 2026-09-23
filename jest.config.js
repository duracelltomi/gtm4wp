/**
 * Jest configuration: the @wordpress/scripts preset plus module mappings for the
 * `@wordpress/*` packages. Those are build-time externals (rewritten to
 * `window.wp.x`, never bundled or pinned; the admin app runs against whatever WP
 * 6.3-7.x ships), so the admin tests use the small stand-ins in
 * js/admin/test-support/. Not the TS-13 smell: the stand-ins are strictly LESS
 * capable (plain DOM with the documented prop contract), and the tests assert the
 * plugin's own guards through their observable effect, never a library prop.
 * js/frontend/test/ imports no `@wordpress/*` package.
 */

const preset = require( '@wordpress/jest-preset-default/jest-preset' );

module.exports = {
	...preset,
	// A git worktree checked out under .claude/worktrees/ (the Agent tool's
	// isolation location) carries a full copy of this suite; without this a
	// root run collects and counts it too (#311).
	testPathIgnorePatterns: [
		...( preset.testPathIgnorePatterns || [] ),
		'<rootDir>/.claude/worktrees/',
	],
	setupFilesAfterEnv: [
		...( preset.setupFilesAfterEnv || [] ),
		require.resolve( '@testing-library/jest-dom' ),
	],
	// JSX transform configured inline, NOT in a root babel.config.js: wp-scripts
	// applies its preset only while `hasBabelConfig()` is false, so a root config
	// would silently take over the production build.
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
