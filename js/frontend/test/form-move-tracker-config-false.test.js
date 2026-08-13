/**
 * T50: the config state the plugin actually ships by default — the config
 * OBJECT present, with filledOnly false. Of the tracker's three load states
 * (no config at all / present-false / present-true), only the other two ran in
 * a JS realm: form-move-tracker.test.js covers the no-config fallback (a
 * tracker loaded standalone) and form-move-tracker-filled-only.test.js the
 * opt-in. UserEventsModule::enqueue_scripts() prints
 * `var gtm4wp_form_move_config = {"filledOnly":false};` on every default
 * install, so the `config && !config.filledOnly` reading path was the one
 * running on real sites and exercised by no test.
 *
 * Its own file for the same reason the filled-only suite has one: the tracker
 * reads the config once at load behind a window.gtm4wp_form_move_inited guard,
 * so one realm can only ever model one config state.
 */

describe( 'gtm4wp-form-move-tracker, config present with filledOnly false', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
		// The exact shape PHP prints for a default install - present, false.
		window.gtm4wp_form_move_config = { filledOnly: false };

		require( '../gtm4wp-form-move-tracker' );
	} );

	beforeEach( () => {
		window.dataLayer = [];
		document.body.innerHTML = '';
	} );

	function dispatchFocus( el, type ) {
		el.dispatchEvent( new window.Event( type, { bubbles: true } ) );
	}

	it( 'reports the leave of an untouched empty field, exactly like the no-config fallback', () => {
		// The discriminating case: filled-only mode stays silent here, so this
		// passing proves the false flag reaches the tracker as false rather
		// than the config's mere presence being read as the opt-in.
		document.body.innerHTML = '<form id="f"><input id="phone" /></form>';

		const el = document.getElementById( 'phone' );
		dispatchFocus( el, 'focusin' );
		dispatchFocus( el, 'focusout' );

		expect( window.dataLayer.map( ( p ) => p.event ) ).toEqual( [
			'gtm4wp.formElementEnter',
			'gtm4wp.formElementLeave',
		] );
	} );
} );
