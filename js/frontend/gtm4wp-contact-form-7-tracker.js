/**
 * GTM4WP Contact Form 7 tracker: mirrors the CF7 DOM events into the data
 * layer with the form metadata and (per the privacy mode) the inputs, and in
 * GA4 mode pushes form_start / form_submit / generate_lead. form_submit fires
 * on every attempt (GA4 Enhanced Measurement definition), so the submission
 * events carry form_status (mail_sent, validation_failed, spam, …).
 *
 * @see https://contactform7.com/dom-events/
 */

/**
 * Tracker configuration injected by the module as an inline script; defaults
 * (full inputs, no GA4 events) when it did not run.
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
 * The single CF7 event both GA4 submission events derive from: the last one
 * CF7 fires, for every outcome, which keeps form_submit before generate_lead.
 * NOT wpcf7mailsent, which fires BEFORE wpcf7submit and would reverse the pair.
 *
 * @constant
 * @type {string}
 */
const gtm4wp_ctf7_ga4_submit_event = 'wpcf7submit';

/**
 * Contact Form 7 submission status that counts as a generated lead.
 *
 * @constant
 * @type {string}
 */
const gtm4wp_ctf7_ga4_lead_status = 'mail_sent';

/**
 * Normalizes the CF7 inputs array per the privacy mode: 'full' (names and
 * values, the 1.x default), 'names', 'none'. A File becomes its file name.
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
 * CF7 <form>: the base shape of every GA4 event (form_start has no detail).
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
		form_destination: gtm4wp_cf7_form_destination( form ),
	};
}

/**
 * The form's action as an absolute URL, the current page when it has none.
 * The attribute, resolved against document.baseURI: a control named `action`
 * shadows the DOM property (#280).
 *
 * @param {Element} form The Contact Form 7 <form> element.
 * @return {string} Absolute URL.
 */
function gtm4wp_cf7_form_destination( form ) {
	const action =
		form && form.getAttribute ? form.getAttribute( 'action' ) || '' : '';

	if ( '' === action ) {
		return window.location.href;
	}

	try {
		return new URL( action, document.baseURI ).href;
	} catch ( e ) {
		return window.location.href;
	}
}

/**
 * Pushes a GA4 recommended form event; form_status is added when there is a
 * status (form_start has none, and the key is omitted, not emptied).
 *
 * @param {Object}  w      Window.
 * @param {string}  name   GA4 event name.
 * @param {Element} form   The Contact Form 7 <form> element.
 * @param {string}  status Contact Form 7 submission status, if any.
 * @return {void}
 */
function gtm4wp_cf7_push_ga4( w, name, form, status ) {
	const fields = gtm4wp_cf7_ga4_form_fields( form );

	if ( status ) {
		fields.form_status = status;
	}

	w[ gtm4wp_datalayer_name ] = w[ gtm4wp_datalayer_name ] || [];
	w[ gtm4wp_datalayer_name ].push( Object.assign( { event: name }, fields ) );
}

/**
 * Mirrors the CF7 DOM events into the data layer and, in GA4 mode, pushes
 * the GA4 recommended events.
 *
 * @param {Object} w Window.
 * @param {Object} d Document.
 * @param {Object} p CTF7 - GTM4WP event pairs.
 * @return {void}
 */
( function ( w, d, p ) {
	// Double-init guard: a re-injected bundle would double-push every event.
	if ( w.gtm4wp_cf7_inited ) {
		return;
	}
	w.gtm4wp_cf7_inited = true;

	for ( const ctf7event in p ) {
		d.addEventListener( ctf7event, function ( event ) {
			const cf7data = gtm4wp_prepare_cf7_data( event );

			w[ gtm4wp_datalayer_name ] = w[ gtm4wp_datalayer_name ] || [];
			w[ gtm4wp_datalayer_name ].push(
				Object.assign( { event: p[ event.type ] }, cf7data )
			);

			if (
				gtm4wp_cf7_config.ga4events &&
				gtm4wp_ctf7_ga4_submit_event === event.type
			) {
				gtm4wp_cf7_push_ga4(
					w,
					'form_submit',
					event.target,
					cf7data.status
				);

				if ( gtm4wp_ctf7_ga4_lead_status === cf7data.status ) {
					gtm4wp_cf7_push_ga4(
						w,
						'generate_lead',
						event.target,
						cf7data.status
					);
				}
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
