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

document.addEventListener('wpcf7submit', function(event) {
	const cf7data = gtm4wp_prepare_cf7_data( event );

	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.contactForm7Submitted',
		'gtm4wp.cf7formid': cf7data.formid,
		'gtm4wp.cf7inputs': cf7data.inputs
	});
});

document.addEventListener('wpcf7mailsent', function(event) {
	const cf7data = gtm4wp_prepare_cf7_data( event );

	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.contactForm7MailSent',
		'gtm4wp.cf7formid': cf7data.formid,
		'gtm4wp.cf7inputs': cf7data.inputs
	});
});

document.addEventListener('wpcf7mailfailed', function(event) {
	const cf7data = gtm4wp_prepare_cf7_data( event );

	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.contactForm7MailFailed',
		'gtm4wp.cf7formid': cf7data.formid,
		'gtm4wp.cf7inputs': cf7data.inputs
	});
});

document.addEventListener('wpcf7spam', function(event) {
	const cf7data = gtm4wp_prepare_cf7_data( event );

	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.contactForm7SpamDetected',
		'gtm4wp.cf7formid': cf7data.formid,
		'gtm4wp.cf7inputs': cf7data.inputs
	});
});

document.addEventListener('wpcf7invalid', function(event) {
	const cf7data = gtm4wp_prepare_cf7_data( event );

	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.contactForm7InvalidInput',
		'gtm4wp.cf7formid': cf7data.formid,
		'gtm4wp.cf7inputs': cf7data.inputs
	});
});
