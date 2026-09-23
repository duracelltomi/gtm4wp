// Double-init guard (#71): every frontend bundle with a module-scope
// addEventListener() needs one, or a re-injected bundle double-pushes.
if ( ! window.gtm4wp_form_move_inited ) {
	window.gtm4wp_form_move_inited = true;

	// Printed by UserEventsModule::enqueue_scripts() as a `var`, a real window
	// property (a top-level `const` would bind lexically, RI-14). Defaulted so
	// the bundle behaves without the inline config.
	const gtm4wp_form_move_config = window.gtm4wp_form_move_config || {
		filledOnly: false,
	};

	const gtm4wp_form_move_selector =
		'input,select,textarea,button,meter,progress';

	// Types with no meaningful "filled in" state, exempt from the filled-only
	// option.
	const gtm4wp_form_move_unfillable_types = [
		'checkbox',
		'radio',
		'button',
		'submit',
		'reset',
		'image',
	];

	// Was the field empty on focusin? The option reports the empty -> filled
	// transition. A WeakMap, so a removed form takes its entries with it.
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

		// The DOM property normalizes a missing/unknown type to "text".
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
		window[ gtm4wp_datalayer_name ].push( {
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
	 * Builds the flat `gtm.element*` keys of GTM's built-in Form variables.
	 * Google publishes no "Form Name" built-in, so the name stays our own
	 * `formName` (U108).
	 *
	 * @param {HTMLFormElement|null} form The form the focused element belongs to.
	 * @return {Object} The `gtm.element*` keys, empty when there is no form.
	 */
	function gtm4wp_form_move_native_params( form ) {
		// Omitted, not emptied: a "(no form ID)" placeholder would reach a
		// built-in variable as the form's real id.
		if ( ! form ) {
			return {};
		}

		// Attributes, the action resolved against document.baseURI: a control
		// named `action` (or id/class/target) shadows the DOM property (#280).
		return {
			'gtm.elementId': form.getAttribute( 'id' ) || '',
			'gtm.elementClasses': form.getAttribute( 'class' ) || '',
			'gtm.elementUrl': gtm4wp_form_move_action_url( form ),
			'gtm.elementTarget': form.getAttribute( 'target' ) || '',
		};
	}

	/**
	 * The form's action as the absolute URL GTM's Form URL built-in reports.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {string} The absolute URL, '' when the action does not parse.
	 */
	function gtm4wp_form_move_action_url( form ) {
		try {
			return new URL(
				form.getAttribute( 'action' ) || '',
				document.baseURI
			).href;
		} catch ( e ) {
			return '';
		}
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

			// Recorded before the push, so a data layer listener cannot change
			// what the leave handler compares to.
			if (
				gtm4wp_form_move_config.filledOnly &&
				gtm4wp_form_move_has_value_state( elem )
			) {
				gtm4wp_form_move_empty_on_enter.set(
					elem,
					gtm4wp_form_move_is_empty( elem )
				);
			}

			// Never filtered: it reports which fields the visitor engaged with.
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

				// Only the visit that filled the field in; no recorded entry
				// state (focused before the listeners attached) stays silent.
				if ( true !== wasempty || gtm4wp_form_move_is_empty( elem ) ) {
					return;
				}
			}

			gtm4wp_form_move_push( elem, 'gtm4wp.formElementLeave' );
		},
		false
	);
}
