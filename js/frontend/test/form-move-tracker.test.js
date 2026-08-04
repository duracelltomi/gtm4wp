/**
 * Unit tests for the form interaction tracker
 * (js/frontend/gtm4wp-form-move-tracker.js). The script attaches focusin /
 * focusout listeners on document that push identity events to the data layer.
 */

import '../gtm4wp-form-move-tracker';

describe( 'gtm4wp-form-move-tracker', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
	} );

	beforeEach( () => {
		window.dataLayer = [];
		document.body.innerHTML = '';
	} );

	function dispatchFocus( el, type ) {
		el.dispatchEvent( new window.Event( type, { bubbles: true } ) );
	}

	it( 'pushes formElementEnter on focusin with input and form identity', () => {
		document.body.innerHTML =
			'<form id="signup" name="signupForm" class="fcls">' +
			'<input id="email" name="email" class="icls" /></form>';

		dispatchFocus( document.getElementById( 'email' ), 'focusin' );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.formElementEnter',
			inputID: 'email',
			inputName: 'email',
			inputClass: 'icls',
			formID: 'signup',
			formName: 'signupForm',
			formClass: 'fcls',
		} );
	} );

	it( 'pushes formElementLeave on focusout with fallbacks for missing identity', () => {
		document.body.innerHTML = '<input id="lonely" />';

		dispatchFocus( document.getElementById( 'lonely' ), 'focusout' );

		expect( window.dataLayer ).toHaveLength( 1 );
		expect( window.dataLayer[ 0 ].event ).toBe( 'gtm4wp.formElementLeave' );
		expect( window.dataLayer[ 0 ].inputName ).toBe( '(no input name)' );
		expect( window.dataLayer[ 0 ].formID ).toBe( '(no form ID)' );
	} );

	it( 'ignores focus on non-form elements', () => {
		document.body.innerHTML = '<div id="notinput">x</div>';

		dispatchFocus( document.getElementById( 'notinput' ), 'focusin' );

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	it( 'does not re-register its listeners when the bundle loads twice (#71)', () => {
		// The import at the top of this file already booted the tracker once, so
		// executing the module again here is exactly what a re-injected bundle does
		// (AJAX navigation, a page builder duplicating the handle). Without the
		// window.gtm4wp_form_move_inited guard the second copy attaches its own
		// focusin listener and every form interaction is pushed twice.
		document.body.innerHTML =
			'<form id="f"><input id="dbl" name="dbl" /></form>';

		jest.isolateModules( () => require( '../gtm4wp-form-move-tracker' ) );

		dispatchFocus( document.getElementById( 'dbl' ), 'focusin' );

		expect( window.dataLayer ).toHaveLength( 1 );
	} );
} );
