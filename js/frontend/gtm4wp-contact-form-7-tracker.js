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
 * form_submit follows the GA4 Enhanced Measurement definition and fires on every
 * submission attempt, including one the server rejects. The submission events
 * therefore carry a form_status parameter holding the Contact Form 7 status
 * (mail_sent, validation_failed, spam and so on) so a container can tell an accepted
 * submission from a rejected one without reading the gtm4wp.contactForm7* pushes.
 *
 * Both submission events come from the same Contact Form 7 event (wpcf7submit), which
 * puts form_submit before the generate_lead it produced; see the constant below.
 *
 * @see https://contactform7.com/dom-events/
 */

import { gtm4wp_push_dl_event } from './lib/gtm4wp-datalayer';

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
 * The single Contact Form 7 DOM event both GA4 submission events are derived from.
 *
 * wpcf7submit is the last event Contact Form 7 fires and the only one it fires for
 * every outcome, so deriving form_submit AND generate_lead from it keeps them in
 * GA4's own order: the submission first, the lead it produced second. generate_lead
 * is deliberately NOT taken from wpcf7mailsent, which Contact Form 7 fires BEFORE
 * wpcf7submit and would therefore reverse the pair. form_start is handled separately
 * (on first field interaction).
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
 * (form_start) as well as the submission events, so all GA4 events share one base
 * shape; the submission events add form_status on top.
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
 * The Contact Form 7 status is added as form_status when there is one. form_start
 * fires on the first field interaction, long before a status exists, so the key is
 * omitted there rather than pushed as an empty placeholder.
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

	gtm4wp_push_dl_event( Object.assign( { event: name }, fields ) );
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
	// Guard against double registration: a re-injected bundle (AJAX navigation,
	// a page builder duplicating the handle) would otherwise attach the CF7
	// listeners twice and double-push every event. Mirrors the media trackers'
	// window.gtm4wp_<provider>_inited flag.
	if ( w.gtm4wp_cf7_inited ) {
		return;
	}
	w.gtm4wp_cf7_inited = true;

	for ( const ctf7event in p ) {
		d.addEventListener( ctf7event, function ( event ) {
			const cf7data = gtm4wp_prepare_cf7_data( event );

			gtm4wp_push_dl_event(
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
