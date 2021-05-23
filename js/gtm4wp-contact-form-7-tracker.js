document.addEventListener('wpcf7submit', function(event) {
	var gtm4wp_cf7formid = '(not set)';
	if ( event && event.detail && event.detail.contactFormId ) {
		gtm4wp_cf7formid = event.detail.contactFormId;
	}

	var gtm4wp_cf7forminputs = [];
	if ( event && event.detail && event.detail.inputs ) {
		gtm4wp_cf7forminputs = event.detail.inputs;
	}

	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.contactForm7Submitted',
		'gtm4wp.cf7formid': gtm4wp_cf7formid,
		'gtm4wp.cf7inputs': gtm4wp_cf7forminputs
	});
});