/*
 * The globals the PHP side prints into the page as top-level `const` declarations,
 * via GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY and ContainerCode::header_top()
 * (WooCommerceModule::add_global_vars() is the only contributor). One list, used
 * twice below: to declare them, so `no-undef` catches a misspelled read, and to
 * forbid the `window.` spelling of the same names.
 *
 * A top-level `const` in a classic (non-module) script binds in the global LEXICAL
 * environment record and never becomes a property of `window`, so
 * `window.gtm4wp_list_attribution` is permanently undefined in a browser. A jsdom
 * unit test cannot catch that on its own - there `global === window`, so setting the
 * window property satisfies a bare read too - which is exactly how three of these
 * shipped dead. Hence the lint rule; js/frontend/test/inline-head-globals.test.js is
 * the runtime half of the same guard.
 *
 * gtm4wp_datalayer_name is deliberately NOT here: it is emitted as `var`, which DOES
 * create a window property, and several trackers read it that way.
 */
const inlineConstGlobals = [
	'gtm4wp_currency',
	'gtm4wp_clear_ecommerce',
	'gtm4wp_use_sku_instead',
	'gtm4wp_product_per_impression',
	'gtm4wp_console_log',
	'gtm4wp_remarketing_prod_id_prefix',
	'gtm4wp_list_attribution',
	'gtm4wp_datalayer_max_timeout',
	'gtm4wp_checkoutwc',
];

module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	overrides: [
		{
			// Jest unit tests of the admin app.
			files: [ 'js/**/test/**/*.js' ],
			extends: [ 'plugin:@wordpress/eslint-plugin/test-unit' ],
		},
		{
			/*
			 * Frontend trackers ported from 1.x. They run as plain browser
			 * scripts (no modules): they read inline globals printed by the
			 * PHP side and talk to each other through the window object,
			 * and their gtm4wp_* snake_case identifiers are the public JS
			 * API third party code relies on.
			 */
			files: [ 'js/frontend/**/*.js' ],
			env: {
				browser: true,
				jquery: true,
			},
			globals: {
				// Printed inline by ContainerCode::header_top() as `var`; this one
				// really is a window property.
				gtm4wp_datalayer_name: 'readonly',

				// Printed inline as top-level `const` (see the list above).
				...Object.fromEntries(
					inlineConstGlobals.map( ( name ) => [ name, 'readonly' ] )
				),

				// Shared helpers exposed on window by gtm4wp-ecommerce-generic.js.
				gtm4wp_make_sure_is_float: 'readonly',
				gtm4wp_push_ecommerce: 'readonly',
				gtm4wp_read_from_json: 'readonly',
				gtm4wp_read_json_from_node: 'readonly',
				gtm4wp_update_json_in_node: 'readonly',
				gtm4wp_read_item_list_cookie: 'readonly',
				gtm4wp_store_item_list_attribution: 'readonly',
				gtm4wp_apply_stored_item_list: 'readonly',

				// Cross-handler state assigned as window.* properties and
				// accessed as bare identifiers (1.x compatible behavior).
				gtm4wp_view_item_fired_during_pageload: 'writable',
				gtm4wp_checkout_step_fired: 'writable',
				productpage_window: 'writable',
				player: 'writable',

				// Third party media player APIs loaded at runtime.
				YT: 'readonly',
				SC: 'readonly',
				Vimeo: 'readonly',
				DM: 'readonly',
				Mixcloud: 'readonly',
				Stream: 'readonly',
				jwplayer: 'readonly',
				Twitch: 'readonly',
			},
			rules: {
				// gtm4wp_* snake_case identifiers are the public 1.x JS API.
				camelcase: 'off',
				// Console messages are a supported plugin feature, toggleable
				// with the "Do not use console.log() messages" option.
				'no-console': 'off',
				// The 1.x-identical trackers rely on loose comparisons;
				// converting them is not worth the regression risk.
				eqeqeq: 'off',
				'no-shadow': 'off',
				// The console && console.error && console.error() guard
				// pattern of 1.x.
				'no-unused-expressions': [
					'error',
					{ allowShortCircuit: true },
				],
				'no-unused-vars': [
					'error',
					{ args: 'none', caughtErrors: 'none' },
				],
			},
		},
		{
			/*
			 * Production trackers only. The unit tests deliberately set
			 * `window.<name>` - both as a stand-in for the real declaration
			 * (jsdom makes a window property visible to a bare read) and, in
			 * inline-head-globals.test.js, as the WRONG value a `window.` read
			 * would pick up.
			 */
			files: [ 'js/frontend/**/*.js' ],
			excludedFiles: [ 'js/frontend/test/**/*.js' ],
			rules: {
				'no-restricted-properties': [
					'error',
					...inlineConstGlobals.map( ( property ) => ( {
						object: 'window',
						property,
						message:
							'Printed by the PHP side as a top-level `const`, which binds lexically and is never a property of window. Read it as a bare identifier, with a `typeof` guard where the declaration may be absent.',
					} ) ),
				],
			},
		},
	],
};
