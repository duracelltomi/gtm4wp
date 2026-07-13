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
				// Printed inline by ContainerCode::header_top() and the
				// GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY filter (PHP side).
				gtm4wp_datalayer_name: 'readonly',
				gtm4wp_currency: 'readonly',
				gtm4wp_clear_ecommerce: 'readonly',
				gtm4wp_use_sku_instead: 'readonly',
				gtm4wp_product_per_impression: 'readonly',

				// Shared helpers exposed on window by gtm4wp-ecommerce-generic.js.
				gtm4wp_make_sure_is_float: 'readonly',
				gtm4wp_push_ecommerce: 'readonly',
				gtm4wp_read_from_json: 'readonly',
				gtm4wp_read_json_from_node: 'readonly',
				gtm4wp_update_json_in_node: 'readonly',

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
	],
};
