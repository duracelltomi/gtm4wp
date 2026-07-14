/**
 * GTM4WP Contact Form 7 tracker.
 *
 * Listens to the Contact Form 7 DOM events (wpcf7submit and friends) and
 * pushes a matching event into the data layer together with the form metadata
 * (form ID, name, unit tag, container post ID, locale, status) and, depending
 * on the configured privacy mode, the submitted inputs.
 *
 * When GA4 mode is enabled it additionally pushes the Google Analytics 4
 * recommended form events (form_start, form_submit, generate_lead).
 *
 * @see https://contactform7.com/dom-events/
 */

/**
 * Tracker configuration, injected by the module as an inline script. Falls back
 * to the defaults (full inputs, no GA4 events) when that inline script did not run.
 *
 * @constant
 * @type {Object}
 */
const gtm4wp_cf7_config = window.gtm4wp_cf7_config || {
	inputs: 'full',
	ga4events: false,
};

/**
 * Contact Form 7 DOM and Google Tag Manager for WordPress data layer event pairs.
 *
 * @see https://contactform7.com/dom-events/
 * @constant
 * @type {Object}
 */
const gtm4wp_ctf7_event_pairs = {
	wpcf7beforesubmit: 'gtm4wp.contactForm7BeforeSubmit',
	wpcf7invalid: 'gtm4wp.contactForm7InvalidInput',
	wpcf7unaccepted: 'gtm4wp.contactForm7Unaccepted',
	wpcf7spam: 'gtm4wp.contactForm7SpamDetected',
	wpcf7aborted: 'gtm4wp.contactForm7Aborted',
	wpcf7mailsent: 'gtm4wp.contactForm7MailSent',
	wpcf7mailfailed: 'gtm4wp.contactForm7MailFailed',
	wpcf7submit: 'gtm4wp.contactForm7Submitted',
};

/**
 * Contact Form 7 DOM event to GA4 recommended event map. Only the events that
 * have a meaningful GA4 counterpart are listed; form_start is handled separately
 * (on first field interaction).
 *
 * @constant
 * @type {Object}
 */
const gtm4wp_ctf7_ga4_event_pairs = {
	wpcf7submit: 'form_submit',
	wpcf7mailsent: 'generate_lead',
};

/**
 * Normalizes the Contact Form 7 inputs array according to the configured
 * privacy mode:
 *   - 'full'  : field names and submitted values (default, 1.x behavior)
 *   - 'names' : field names only, values dropped
 *   - 'none'  : no inputs at all
 *
 * File inputs are represented by their file name instead of the raw File
 * object so the value serializes cleanly in the data layer.
 *
 * @param {Array}  inputs Contact Form 7 detail.inputs array.
 * @param {string} mode   Configured inputs mode.
 * @return {Array} Normalized inputs array.
 */
function gtm4wp_cf7_normalize_inputs( inputs, mode ) {
	if ( 'none' === mode || ! Array.isArray( inputs ) ) {
		return [];
	}

	return inputs.map( function ( input ) {
		if ( 'names' === mode ) {
			return { name: input.name };
		}

		const value =
			typeof File !== 'undefined' && input.value instanceof File
				? input.value.name
				: input.value;

		return { name: input.name, value };
	} );
}

/**
 * Reads the human-readable form name injected server side as a data attribute
 * on the <form> element (the CF7 DOM events only carry the numeric form id).
 *
 * @param {Element} form The Contact Form 7 <form> element.
 * @return {string} Form name or an empty string.
 */
function gtm4wp_cf7_form_name( form ) {
	return form && form.getAttribute
		? form.getAttribute( 'data-gtm4wp-form-name' ) || ''
		: '';
}

/**
 * Extracts the form metadata and the submitted inputs from a Contact Form 7
 * DOM event.
 *
 * @param {CustomEvent} eventdata The event object dispatched by Contact Form 7.
 * @return {Object} Data-layer fields describing the form and its submission.
 */
function gtm4wp_prepare_cf7_data( eventdata ) {
	const detail = ( eventdata && eventdata.detail ) || {};
	const form = eventdata && eventdata.target;

	return {
		formid: detail.contactFormId || '(not set)',
		formname: gtm4wp_cf7_form_name( form ),
		unittag: detail.unitTag || '',
		containerpostid: detail.containerPostId || '',
		locale: detail.contactFormLocale || '',
		status: detail.status || '',
		inputs: gtm4wp_cf7_normalize_inputs(
			detail.inputs,
			gtm4wp_cf7_config.inputs
		),
	};
}

/**
 * Builds the GA4 form fields (form_id, form_name, form_destination) from a
 * Contact Form 7 <form> element. Used for events that lack a CF7 detail object
 * (form_start) as well as the submission events, so all GA4 events share one shape.
 *
 * @param {Element} form The Contact Form 7 <form> element.
 * @return {Object} GA4 form fields.
 */
function gtm4wp_cf7_ga4_form_fields( form ) {
	const idinput =
		form && form.querySelector
			? form.querySelector( 'input[name="_wpcf7"]' )
			: null;

	return {
		form_id: idinput ? idinput.value : '',
		form_name: gtm4wp_cf7_form_name( form ),
		form_destination:
			form && form.action ? form.action : window.location.href,
	};
}

/**
 * Pushes a GA4 recommended form event for the given form element.
 *
 * @param {Object}  w    Window.
 * @param {string}  name GA4 event name.
 * @param {Element} form The Contact Form 7 <form> element.
 * @return {void}
 */
function gtm4wp_cf7_push_ga4( w, name, form ) {
	w[ gtm4wp_datalayer_name ] = w[ gtm4wp_datalayer_name ] || [];
	w[ gtm4wp_datalayer_name ].push(
		Object.assign( { event: name }, gtm4wp_cf7_ga4_form_fields( form ) )
	);
}

/**
 * Handle Contact Form 7 DOM events.
 * When a CF7 event fires, push a matching data layer event with the form data,
 * and - when GA4 mode is on - the GA4 recommended events.
 *
 * @param {Object} w Window.
 * @param {Object} d Document.
 * @param {Object} p CTF7 - GTM4WP event pairs.
 * @return {void}
 */
( function ( w, d, p ) {
	for ( const ctf7event in p ) {
		d.addEventListener( ctf7event, function ( event ) {
			const cf7data = gtm4wp_prepare_cf7_data( event );

			w[ gtm4wp_datalayer_name ] = w[ gtm4wp_datalayer_name ] || [];
			w[ gtm4wp_datalayer_name ].push(
				Object.assign( { event: p[ event.type ] }, cf7data )
			);

			if (
				gtm4wp_cf7_config.ga4events &&
				gtm4wp_ctf7_ga4_event_pairs[ event.type ]
			) {
				gtm4wp_cf7_push_ga4(
					w,
					gtm4wp_ctf7_ga4_event_pairs[ event.type ],
					event.target
				);
			}
		} );
	}

	if ( gtm4wp_cf7_config.ga4events ) {
		const gtm4wp_cf7_started = new WeakSet();

		d.addEventListener( 'focusin', function ( event ) {
			const form =
				event.target && event.target.closest
					? event.target.closest( '.wpcf7 form, form.wpcf7-form' )
					: null;

			if ( ! form || gtm4wp_cf7_started.has( form ) ) {
				return;
			}

			gtm4wp_cf7_started.add( form );
			gtm4wp_cf7_push_ga4( w, 'form_start', form );
		} );
	}
} )( window, document, gtm4wp_ctf7_event_pairs );
