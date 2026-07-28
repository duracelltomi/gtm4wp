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
		delete window.gtm4wp_datalayer_push;
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

	it( 'pushes formElementLeave for an empty field while the filled-only option is off', () => {
		// The default is unchanged behavior, and this is the case the sub-option
		// exists to suppress. Without this assertion nothing here would fail if
		// the filled-only branch started running with no config present, since
		// every other case in this file uses a field that is empty at both ends
		// and would simply stop reporting (TS-2's two directions, applied to the
		// option gate rather than to escaping).
		document.body.innerHTML = '<form id="f"><input id="empty" /></form>';
		const el = document.getElementById( 'empty' );

		dispatchFocus( el, 'focusin' );
		dispatchFocus( el, 'focusout' );

		expect( window.dataLayer.map( ( push ) => push.event ) ).toEqual( [
			'gtm4wp.formElementEnter',
			'gtm4wp.formElementLeave',
		] );
	} );

	it( 'ignores focus on non-form elements', () => {
		document.body.innerHTML = '<div id="notinput">x</div>';

		dispatchFocus( document.getElementById( 'notinput' ), 'focusin' );

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	// -----------------------------------------------------------------------
	// GTM built-in Form variables (U108)
	// -----------------------------------------------------------------------

	it( 'fills the GTM built-in Form variables from the form element', () => {
		document.body.innerHTML =
			'<form id="signup" name="signupForm" class="fcls wide" action="/subscribe" target="_blank">' +
			'<input id="email" name="email" /></form>';

		dispatchFocus( document.getElementById( 'email' ), 'focusin' );

		expect( window.dataLayer[ 0 ] ).toMatchObject( {
			// Resolved to an absolute URL by the DOM, which is what GTM's
			// built-in Form URL reports on a real form submission.
			'gtm.elementUrl': 'http://localhost/subscribe',
			'gtm.elementId': 'signup',
			'gtm.elementClasses': 'fcls wide',
			'gtm.elementTarget': '_blank',
		} );
	} );

	it( 'fills them on formElementLeave as well', () => {
		document.body.innerHTML =
			'<form id="signup" class="fcls"><input id="email" /></form>';

		dispatchFocus( document.getElementById( 'email' ), 'focusout' );

		expect( window.dataLayer[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.formElementLeave',
			'gtm.elementId': 'signup',
			'gtm.elementClasses': 'fcls',
		} );
	} );

	it( 'sends empty strings, never the readable placeholders, to the built-ins', () => {
		// The two halves disagree on purpose. Our own keys keep the "(no form
		// ID)" placeholders they have always had, but a built-in variable must
		// not report a placeholder as though it were the form's real id, so the
		// gtm.* keys carry what GTM itself would send: an empty string.
		document.body.innerHTML = '<form><input id="email" /></form>';

		dispatchFocus( document.getElementById( 'email' ), 'focusin' );

		const push = window.dataLayer[ 0 ];

		expect( push.formID ).toBe( '(no form ID)' );
		expect( push[ 'gtm.elementId' ] ).toBe( '' );
		expect( push[ 'gtm.elementClasses' ] ).toBe( '' );
		expect( push[ 'gtm.elementTarget' ] ).toBe( '' );
		// A form with no action attribute posts to the current page, and that is
		// what the DOM and GTM both report.
		expect( push[ 'gtm.elementUrl' ] ).toBe( 'http://localhost/' );
	} );

	it( 'omits the built-in keys entirely for an element in no form', () => {
		// Absent, not empty: with no form there is no Form ID to report, and an
		// empty gtm.elementId would linger in GTM's data model and be read by a
		// later tag as though this event had described a form.
		document.body.innerHTML = '<input id="lonely" />';

		dispatchFocus( document.getElementById( 'lonely' ), 'focusin' );

		const push = window.dataLayer[ 0 ];

		expect( push ).not.toHaveProperty( 'gtm.elementId' );
		expect( push ).not.toHaveProperty( 'gtm.elementClasses' );
		expect( push ).not.toHaveProperty( 'gtm.elementUrl' );
		expect( push ).not.toHaveProperty( 'gtm.elementTarget' );
		expect( push.formID ).toBe( '(no form ID)' );
	} );

	it( 'sends no gtm.elementName, because GTM has no Form Name built-in', () => {
		// formName stays our own key. Guards against someone "completing the
		// set" later by inventing a key inside Google's namespace that no
		// built-in variable reads (U108).
		document.body.innerHTML =
			'<form id="f" name="signupForm"><input id="email" /></form>';

		dispatchFocus( document.getElementById( 'email' ), 'focusin' );

		expect( window.dataLayer[ 0 ].formName ).toBe( 'signupForm' );
		expect( window.dataLayer[ 0 ] ).not.toHaveProperty( 'gtm.elementName' );
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

	it( 'routes through the consent queue runtime when it is installed', () => {
		// Representative for every tracker converted to gtm4wp_push_dl_event:
		// with the queue runtime present the event goes to it, not straight
		// into the data layer.
		window.gtm4wp_datalayer_push = jest.fn();
		document.body.innerHTML = '<input id="routed" />';

		dispatchFocus( document.getElementById( 'routed' ), 'focusin' );

		expect( window.dataLayer ).toHaveLength( 0 );
		expect( window.gtm4wp_datalayer_push ).toHaveBeenCalledTimes( 1 );
		expect( window.gtm4wp_datalayer_push.mock.calls[ 0 ][ 0 ].event ).toBe(
			'gtm4wp.formElementEnter'
		);
	} );
} );
