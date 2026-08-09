/**
 * Unit tests for the "Only report fields the visitor filled in" sub-option of
 * the form interaction tracker (js/frontend/gtm4wp-form-move-tracker.js).
 *
 * This lives in its own file rather than in form-move-tracker.test.js on
 * purpose. The tracker attaches its listeners to `document` behind a
 * window.gtm4wp_form_move_inited guard, and both survive for the life of one
 * jsdom environment. Loading the bundle a second time in the same file with a
 * different config would either be refused by the guard or leave the default
 * mode listeners attached alongside the new ones, so every assertion here would
 * be measuring two trackers at once. Jest gives each test file its own module
 * registry and its own jsdom, which is exactly the isolation this needs.
 *
 * The config stand-in below is the shape UserEventsModule::enqueue_scripts()
 * really prints - `var gtm4wp_form_move_config = {"filledOnly":true};` - and
 * nothing more permissive, so the suite cannot pass because the double accepts
 * something the plugin never emits (TC-15/UC-3).
 */

describe( 'gtm4wp-form-move-tracker, filled-only mode', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
		// Set before the bundle is required: it reads the config once, at load.
		// require(), not a top-level import, because imports are hoisted above
		// this assignment and the tracker would see no config at all.
		window.gtm4wp_form_move_config = { filledOnly: true };

		require( '../gtm4wp-form-move-tracker' );
	} );

	beforeEach( () => {
		window.dataLayer = [];
		document.body.innerHTML = '';
	} );

	function dispatchFocus( el, type ) {
		el.dispatchEvent( new window.Event( type, { bubbles: true } ) );
	}

	/**
	 * Focuses the element, optionally changes its value, then blurs it, and
	 * returns the event names pushed along the way.
	 *
	 * @param {Element} el       The element to visit.
	 * @param {*}       newvalue Value to assign while focused; undefined leaves
	 *                           the element untouched.
	 * @return {string[]} The data layer event names, in order.
	 */
	function visit( el, newvalue ) {
		dispatchFocus( el, 'focusin' );

		if ( undefined !== newvalue ) {
			el.value = newvalue;
		}

		dispatchFocus( el, 'focusout' );

		return window.dataLayer.map( ( push ) => push.event );
	}

	// -----------------------------------------------------------------------
	// The transition the option reports
	// -----------------------------------------------------------------------

	it( 'reports a field that was empty on enter and holds a value on leave', () => {
		document.body.innerHTML = '<form id="f"><input id="email" /></form>';

		expect( visit( document.getElementById( 'email' ), 'a@b.c' ) ).toEqual(
			[ 'gtm4wp.formElementEnter', 'gtm4wp.formElementLeave' ]
		);
	} );

	it( 'stays silent on leave for a field the visitor tabbed through empty', () => {
		// The reason the option exists: a keyboard user moving through a form
		// used to emit a leave event for every untouched field.
		document.body.innerHTML = '<form id="f"><input id="phone" /></form>';

		expect( visit( document.getElementById( 'phone' ) ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );
	} );

	it( 'keeps firing formElementEnter for every field, filled or not', () => {
		// The enter half is deliberately never filtered, so "fields touched"
		// stays measurable next to "fields filled in".
		document.body.innerHTML =
			'<form id="f"><input id="a" /><input id="b" /></form>';

		dispatchFocus( document.getElementById( 'a' ), 'focusin' );
		dispatchFocus( document.getElementById( 'b' ), 'focusin' );

		expect( window.dataLayer.map( ( p ) => p.event ) ).toEqual( [
			'gtm4wp.formElementEnter',
			'gtm4wp.formElementEnter',
		] );
	} );

	it( 'stays silent for a field that was already filled in on enter', () => {
		// A field the theme pre-fills was not filled in by this visitor, so the
		// transition never happens and the leave event never fires.
		document.body.innerHTML =
			'<form id="f"><input id="email" value="prefilled@example.com" /></form>';

		expect( visit( document.getElementById( 'email' ) ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );
	} );

	it( 'stays silent when the visitor clears an already filled field', () => {
		document.body.innerHTML =
			'<form id="f"><input id="email" value="old@example.com" /></form>';

		expect( visit( document.getElementById( 'email' ), '' ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );
	} );

	it( 'treats a whitespace only value as empty', () => {
		document.body.innerHTML =
			'<form id="f"><textarea id="msg"></textarea></form>';

		expect( visit( document.getElementById( 'msg' ), '   ' ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );
	} );

	it( 'reports a textarea the visitor typed into', () => {
		document.body.innerHTML =
			'<form id="f"><textarea id="msg"></textarea></form>';

		expect( visit( document.getElementById( 'msg' ), 'hello' ) ).toEqual( [
			'gtm4wp.formElementEnter',
			'gtm4wp.formElementLeave',
		] );
	} );

	it( 'stays silent on a focusout it never saw the matching focusin for', () => {
		// A field focused before the deferred bundle attached its listeners has
		// no recorded entry state, so the transition was never observed. The
		// tracker reports nothing rather than guessing it was a fill.
		document.body.innerHTML =
			'<form id="f"><input id="early" value="typed" /></form>';

		dispatchFocus( document.getElementById( 'early' ), 'focusout' );

		expect( window.dataLayer ).toHaveLength( 0 );
	} );

	// -----------------------------------------------------------------------
	// select
	// -----------------------------------------------------------------------

	it( 'reports a placeholder select the visitor made a choice in', () => {
		document.body.innerHTML =
			'<form id="f"><select id="country">' +
			'<option value="">Choose...</option>' +
			'<option value="hu">Hungary</option>' +
			'</select></form>';

		expect( visit( document.getElementById( 'country' ), 'hu' ) ).toEqual( [
			'gtm4wp.formElementEnter',
			'gtm4wp.formElementLeave',
		] );
	} );

	it( 'stays silent for a placeholder select left on its empty option', () => {
		document.body.innerHTML =
			'<form id="f"><select id="country">' +
			'<option value="">Choose...</option>' +
			'<option value="hu">Hungary</option>' +
			'</select></form>';

		expect( visit( document.getElementById( 'country' ) ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );
	} );

	it( 'stays silent for a select whose options all carry a value', () => {
		// Documented consequence: such a select is never empty, so it can never
		// make the empty -> filled transition and only ever reports the enter.
		document.body.innerHTML =
			'<form id="f"><select id="lang">' +
			'<option value="en">English</option>' +
			'<option value="hu">Hungarian</option>' +
			'</select></form>';

		expect( visit( document.getElementById( 'lang' ) ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );
	} );

	// -----------------------------------------------------------------------
	// Element types with no "empty" state - always reported
	// -----------------------------------------------------------------------

	/**
	 * Each case carries an explicit empty value attribute, so the element really
	 * is empty by the value test. Without the type exemption every one of these
	 * would be suppressed, which is what makes the assertion meaningful rather
	 * than accidentally green (a checkbox with no value attribute reports "on"
	 * and would pass either way).
	 */
	it.each( [
		[ 'checkbox', '<input type="checkbox" id="t" value="" />' ],
		[ 'radio', '<input type="radio" id="t" value="" />' ],
		[ 'submit', '<input type="submit" id="t" value="" />' ],
		[ 'reset', '<input type="reset" id="t" value="" />' ],
		[ 'image', '<input type="image" id="t" value="" />' ],
		[ 'button element', '<button id="t"></button>' ],
		[ 'meter', '<meter id="t"></meter>' ],
		[ 'progress', '<progress id="t"></progress>' ],
	] )( 'always reports %s, which has no empty state', ( _label, markup ) => {
		document.body.innerHTML = '<form id="f">' + markup + '</form>';

		expect( visit( document.getElementById( 't' ) ) ).toEqual( [
			'gtm4wp.formElementEnter',
			'gtm4wp.formElementLeave',
		] );
	} );

	it( 'checks an input whose type attribute is missing or unrecognized', () => {
		// elem.type normalizes both to "text", so they are value checked like any
		// other text field rather than falling through as exempt.
		document.body.innerHTML =
			'<form id="f"><input id="notype" /><input id="bogus" type="wat" /></form>';

		expect( visit( document.getElementById( 'notype' ) ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );

		window.dataLayer = [];

		expect( visit( document.getElementById( 'bogus' ) ) ).toEqual( [
			'gtm4wp.formElementEnter',
		] );
	} );

	it( 'still carries the full identity payload on a reported leave', () => {
		// The option decides whether the event fires, never what it contains.
		document.body.innerHTML =
			'<form id="signup" name="signupForm" class="fcls">' +
			'<input id="email" name="email" class="icls" /></form>';

		visit( document.getElementById( 'email' ), 'a@b.c' );

		expect( window.dataLayer[ 1 ] ).toMatchObject( {
			event: 'gtm4wp.formElementLeave',
			inputID: 'email',
			inputName: 'email',
			inputClass: 'icls',
			formID: 'signup',
			formName: 'signupForm',
			formClass: 'fcls',
		} );
	} );
} );
