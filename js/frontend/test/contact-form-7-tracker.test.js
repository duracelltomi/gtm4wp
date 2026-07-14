/**
 * Unit tests for the Contact Form 7 tracker
 * (js/frontend/gtm4wp-contact-form-7-tracker.js).
 *
 * The tracker attaches document-level listeners for the Contact Form 7 DOM
 * events and pushes a matching data layer event (plus, in GA4 mode, the GA4
 * recommended form events). The module reads gtm4wp_cf7_config at event time,
 * so it is required once (in beforeAll, after the config object is installed on
 * window) and the config is mutated per test - re-requiring per test would
 * accumulate document listeners across tests. The double-init test re-requires
 * explicitly to assert the guard prevents a second registration.
 */

/**
 * Dispatches a Contact Form 7 DOM event with the given detail on the target.
 *
 * @param {string}  type   CF7 event name (e.g. wpcf7submit).
 * @param {Element} target Event target (<form>) or document.
 * @param {Object}  detail CF7 event detail payload.
 * @return {void}
 */
function fireCf7( type, target, detail = {} ) {
	const event = new window.CustomEvent( type, { bubbles: true, detail } );
	target.dispatchEvent( event );
}

describe( 'gtm4wp-contact-form-7-tracker', () => {
	beforeAll( () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
		// Install a mutable config object BEFORE loading the module, so its
		// event-time reads (.inputs, per-event .ga4events) follow the same
		// reference the tests mutate. ga4events is true at load so the form_start
		// focusin listener is registered (harmless for the events we dispatch).
		window.gtm4wp_cf7_config = { inputs: 'full', ga4events: true };
		require( '../gtm4wp-contact-form-7-tracker' );
	} );

	beforeEach( () => {
		window.dataLayer = [];
		// Reset the shared config to a known baseline (mutate, never reassign -
		// the module holds a reference to this exact object).
		window.gtm4wp_cf7_config.inputs = 'full';
		window.gtm4wp_cf7_config.ga4events = false;
		document.body.innerHTML =
			'<form class="wpcf7-form" data-gtm4wp-form-name="Contact us" action="https://example.com/contact">' +
			'<input type="hidden" name="_wpcf7" value="42" />' +
			'<input type="text" name="your-name" />' +
			'</form>';
	} );

	const form = () => document.querySelector( 'form' );
	const submits = () =>
		window.dataLayer.filter(
			( entry ) => entry.event === 'gtm4wp.contactForm7Submitted'
		);

	it( 'pushes a matching data layer event with the form metadata on submit', () => {
		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			unitTag: 'wpcf7-f42-p1-o1',
			status: 'mail_sent',
			inputs: [ { name: 'your-name', value: 'Marks & Spencer' } ],
		} );

		expect( submits() ).toHaveLength( 1 );
		expect( submits()[ 0 ] ).toMatchObject( {
			event: 'gtm4wp.contactForm7Submitted',
			formid: 42,
			formname: 'Contact us',
			unittag: 'wpcf7-f42-p1-o1',
			status: 'mail_sent',
			// Value pushed verbatim (no HTML entity-encoding) - the downstream GTM
			// tag owns DOM escaping.
			inputs: [ { name: 'your-name', value: 'Marks & Spencer' } ],
		} );
	} );

	it( 'drops submitted values in "names" mode', () => {
		window.gtm4wp_cf7_config.inputs = 'names';

		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			inputs: [ { name: 'your-name', value: 'secret' } ],
		} );

		expect( submits()[ 0 ].inputs ).toEqual( [ { name: 'your-name' } ] );
	} );

	it( 'omits all inputs in "none" mode', () => {
		window.gtm4wp_cf7_config.inputs = 'none';

		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			inputs: [ { name: 'your-name', value: 'secret' } ],
		} );

		expect( submits()[ 0 ].inputs ).toEqual( [] );
	} );

	it( 'pushes GA4 recommended events only when ga4events is enabled', () => {
		window.gtm4wp_cf7_config.ga4events = true;

		fireCf7( 'wpcf7mailsent', form(), { contactFormId: 42 } );

		const lead = window.dataLayer.filter(
			( entry ) => entry.event === 'generate_lead'
		);
		expect( lead ).toHaveLength( 1 );
		expect( lead[ 0 ] ).toMatchObject( {
			form_id: '42',
			form_name: 'Contact us',
			form_destination: 'https://example.com/contact',
		} );
	} );

	it( 'does not re-register listeners when the bundle loads twice (regression: double-init)', () => {
		// The module was already loaded once in beforeAll, setting the
		// window.gtm4wp_cf7_inited guard. A second execution (e.g. re-injected by a
		// tag manager or page builder) must bail so each CF7 event is not pushed
		// twice; without the guard this re-require would add a second listener set.
		jest.isolateModules( () => {
			require( '../gtm4wp-contact-form-7-tracker' );
		} );

		fireCf7( 'wpcf7submit', form(), { contactFormId: 42 } );

		expect( submits() ).toHaveLength( 1 );
	} );
} );
