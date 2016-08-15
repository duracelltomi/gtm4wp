jQuery( function() {
	jQuery( ".wpcf7" )
		.on( 'wpcf7:mailsent', function(e) {
			var gtm4wp_cf7formid = '(not set)';
			if ( e && e.target && e.target.id ) {
				gtm4wp_cf7formid = e.target.id;
			}
			
			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.contactForm7Submitted',
				'gtm4wp.cf7formid': gtm4wp_cf7formid
			});
		});
});
