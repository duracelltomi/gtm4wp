function gtm4wp_prepare_cf7_data( eventdata ) {
	let cf7data = {
		formid: '(not set)',
		inputs: []
	}

	if ( eventdata && eventdata.detail && eventdata.detail.contactFormId ) {
		cf7data.formid = eventdata.detail.contactFormId;
	}

	if ( event && event.detail && event.detail.inputs ) {
		cf7data.inputs = event.detail.inputs;
	}

	return cf7data;
}

/**
 * Contact Form 7 DOM and Google Tag Manager for WordPress data layer event pairs
 * @see https://contactform7.com/dom-events/
 * @const
 * @type {Object}
 */
const gtm4wp_ctf7_event_pairs = {
    wpcf7invalid: 'gtm4wp.contactForm7InvalidInput',
    wpcf7spam: 'gtm4wp.contactForm7SpamDetected',
    wpcf7mailsent: 'gtm4wp.contactForm7MailSent',
    wpcf7mailfailed: 'gtm4wp.contactForm7MailFailed',
    wpcf7submit: 'gtm4wp.contactForm7Submitted',
};

/**
 * Handle Contact Form 7 DOM events
 * If CTF7 event fired push a data layer event with form data(id, inputs)
 * @param {Object} w Window
 * @param {Object} d Document
 * @param {Object} p CTF7 - GTM4WP event pairs
 * @return void
 */
(function ( w, d, p ) {
    for ( let ctf7event in p ) {
        d.addEventListener( ctf7event, function( event ) {
            const cf7data = gtm4wp_prepare_cf7_data( event );
            w[ gtm4wp_datalayer_name ].push({
                'event': p[ ctf7event ],
                'formid': cf7data.formid,
                'inputs': cf7data.inputs
            });
        });
    }
}( window, document, gtm4wp_ctf7_event_pairs ));
