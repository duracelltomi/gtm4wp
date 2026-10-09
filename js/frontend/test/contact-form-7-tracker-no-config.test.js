/**
 * The Contact Form 7 tracker loaded WITHOUT its inline config (an optimiser that
 * drops or reorders inline scripts). It must fail closed like the client device
 * and visitor data trackers: events still fire, submitted values do not (#402).
 * A separate file because the module reads the config once, at load.
 */

describe( 'gtm4wp-contact-form-7-tracker without its inline config', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
		delete window.gtm4wp_cf7_config;
		require( '../gtm4wp-contact-form-7-tracker' );
	} );

	beforeEach( () => {
		window.dataLayer = [];
		document.body.innerHTML =
			'<form class="wpcf7-form" data-gtm4wp-form-name="Contact us">' +
			'<input type="text" name="your-email" />' +
			'</form>';
	} );

	it( 'still pushes the event but no submitted names or values', () => {
		document.querySelector( 'form' ).dispatchEvent(
			new window.CustomEvent( 'wpcf7mailsent', {
				bubbles: true,
				detail: {
					contactFormId: 42,
					inputs: [
						{ name: 'your-email', value: 'alice@example.com' },
					],
				},
			} )
		);

		const sent = window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.contactForm7MailSent'
		);
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].inputs ).toEqual( [] );
		expect( JSON.stringify( window.dataLayer ) ).not.toContain(
			'alice@example.com'
		);
	} );
} );
