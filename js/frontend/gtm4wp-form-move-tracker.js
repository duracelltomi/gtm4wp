import { gtm4wp_push_dl_event } from './lib/gtm4wp-datalayer';

// Guard against double registration: a re-injected bundle (AJAX navigation, a page
// builder duplicating the handle) would otherwise attach these document-level
// listeners twice and double-push every form event. The media-tracker family and
// the CF7 tracker have carried this guard since #22 / #28; both times it was written
// as a media-tracker rule, so the bundles that attach document listeners without
// being media trackers were left behind (#71). Litmus: every js/frontend/*.js file
// with a module-scope addEventListener() needs one, whatever it tracks.
if ( ! window.gtm4wp_form_move_inited ) {
	window.gtm4wp_form_move_inited = true;

	// Printed by UserEventsModule::enqueue_scripts() as a `var`, which really is a
	// window property; a top-level `const` would bind lexically and never show up
	// here (RI-14). Defaulted rather than assumed, so the bundle still behaves when
	// something enqueues it without the inline config.
	const gtm4wp_form_move_config = window.gtm4wp_form_move_config || {
		filledOnly: false,
	};

	const gtm4wp_form_move_selector =
		'input,select,textarea,button,meter,progress';

	// Input types with no meaningful "filled in" state: a checkbox or a radio
	// carries its information equally when checked and unchecked, and a button can
	// only ever be clicked. These stay exempt from the filled-only option, so they
	// are reported the same way whether it is on or off.
	const gtm4wp_form_move_unfillable_types = [
		'checkbox',
		'radio',
		'button',
		'submit',
		'reset',
		'image',
	];

	// Was the field empty when the visitor entered it? Written on focusin and read
	// on focusout, because the option reports the empty -> filled transition rather
	// than the value on its own. A WeakMap, so a form removed from the DOM takes
	// its entries with it.
	const gtm4wp_form_move_empty_on_enter = new WeakMap();

	/**
	 * Whether "filled in" means anything for this element.
	 *
	 * @param {Element} elem The focused form element.
	 * @return {boolean} True when the element carries a value worth checking.
	 */
	function gtm4wp_form_move_has_value_state( elem ) {
		const tagname = elem.tagName ? elem.tagName.toLowerCase() : '';

		if ( 'textarea' === tagname || 'select' === tagname ) {
			return true;
		}

		if ( 'input' !== tagname ) {
			return false;
		}

		// The DOM property, not the attribute: it normalizes a missing or
		// unrecognized type attribute to "text", which is what the browser does
		// with the field itself.
		const type = String( elem.type || 'text' ).toLowerCase();

		return -1 === gtm4wp_form_move_unfillable_types.indexOf( type );
	}

	/**
	 * Whether the element currently holds no value. Whitespace only counts as
	 * empty, so a stray space bar press is not treated as filling the field in.
	 *
	 * @param {Element} elem The form element.
	 * @return {boolean} True when the element is empty.
	 */
	function gtm4wp_form_move_is_empty( elem ) {
		const value = elem.value;

		return '' === ( 'string' === typeof value ? value : '' ).trim();
	}

	/**
	 * Pushes one form interaction event with the identity of the element and of
	 * the form it belongs to.
	 *
	 * @param {Element} elem      The form element.
	 * @param {string}  eventname The data layer event name.
	 */
	function gtm4wp_form_move_push( elem, eventname ) {
		gtm4wp_push_dl_event( {
			event: eventname,

			inputID: elem.getAttribute( 'id' ) || '(no input ID)',
			inputName: elem.getAttribute( 'name' ) || '(no input name)',
			inputClass: elem.getAttribute( 'class' ) || '(no input class)',

			formID:
				( elem.form && elem.form.getAttribute( 'id' ) ) ||
				'(no form ID)',
			formName:
				( elem.form && elem.form.getAttribute( 'name' ) ) ||
				'(no form name)',
			formClass:
				( elem.form && elem.form.getAttribute( 'class' ) ) ||
				'(no form class)',

			...gtm4wp_form_move_native_params( elem.form ),
		} );
	}

	/**
	 * Builds the flat `gtm.element*` keys that populate GTM's built-in Form
	 * variables, ready to be spread into a data layer push.
	 *
	 * Google publishes no "Form Name" built-in, so the form's name attribute has
	 * no key here and stays available as our own `formName`. See U108.
	 *
	 * @param {HTMLFormElement|null} form The form the focused element belongs to.
	 * @return {Object} The `gtm.element*` keys, empty when there is no form.
	 */
	function gtm4wp_form_move_native_params( form ) {
		// Omitted rather than emptied when the element belongs to no form: the
		// six keys above fall back to "(no form ID)" style placeholders, and
		// feeding one of those to a built-in variable would have GTM report the
		// placeholder as though it were the form's real id.
		if ( ! form ) {
			return {};
		}

		// DOM properties, not getAttribute(): these have to match what GTM
		// itself pushes on a form submission, where an absent attribute reads as
		// an empty string and `action` resolves to an absolute URL (falling back
		// to the document URL when the attribute is missing).
		//
		// These are the same keys GTM's Clicks category reads, so on our events
		// Click ID and Click Classes resolve to the form as well. That is how
		// Google defines them; it is not something we can separate.
		return {
			'gtm.elementId': form.id || '',
			'gtm.elementClasses': form.className || '',
			'gtm.elementUrl': form.action || '',
			'gtm.elementTarget': form.target || '',
		};
	}

	/**
	 * Resolves the tracked form element a focus event happened in, if any.
	 *
	 * @param {Event} event The focusin / focusout event.
	 * @return {Element|null} The tracked element, or null.
	 */
	function gtm4wp_form_move_target( event ) {
		return (
			event &&
			event.target &&
			event.target.closest &&
			event.target.closest( gtm4wp_form_move_selector )
		);
	}

	document.addEventListener(
		'focusin',
		function ( event ) {
			const elem = gtm4wp_form_move_target( event );
			if ( ! elem ) {
				return;
			}

			// Recorded before the push, so that a data layer listener reacting to
			// the enter event cannot change what the leave handler compares to.
			if (
				gtm4wp_form_move_config.filledOnly &&
				gtm4wp_form_move_has_value_state( elem )
			) {
				gtm4wp_form_move_empty_on_enter.set(
					elem,
					gtm4wp_form_move_is_empty( elem )
				);
			}

			// The enter event is never filtered: it reports which fields the
			// visitor engaged with, which is exactly the half the leave event
			// stops reporting once the option is on.
			gtm4wp_form_move_push( elem, 'gtm4wp.formElementEnter' );
		},
		false
	);

	document.addEventListener(
		'focusout',
		function ( event ) {
			const elem = gtm4wp_form_move_target( event );
			if ( ! elem ) {
				return;
			}

			if (
				gtm4wp_form_move_config.filledOnly &&
				gtm4wp_form_move_has_value_state( elem )
			) {
				const wasempty = gtm4wp_form_move_empty_on_enter.get( elem );
				gtm4wp_form_move_empty_on_enter.delete( elem );

				// Report only the visit that actually filled the field in. A
				// field with no recorded entry state was focused before this
				// bundle attached its listeners, so the transition was never
				// observed; that stays silent rather than being guessed at.
				if ( true !== wasempty || gtm4wp_form_move_is_empty( elem ) ) {
					return;
				}
			}

			gtm4wp_form_move_push( elem, 'gtm4wp.formElementLeave' );
		},
		false
	);
}
