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

		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			status: 'mail_sent',
		} );

		const lead = window.dataLayer.filter(
			( entry ) => entry.event === 'generate_lead'
		);
		expect( lead ).toHaveLength( 1 );
		expect( lead[ 0 ] ).toMatchObject( {
			form_id: '42',
			form_name: 'Contact us',
			form_destination: 'https://example.com/contact',
			form_status: 'mail_sent',
		} );
	} );

	it( 'pushes form_submit before the generate_lead it produced', () => {
		// Both GA4 submission events are derived from wpcf7submit precisely so they
		// land in GA4's order. Firing the full CF7 sequence a successful send
		// produces: taking generate_lead from wpcf7mailsent instead - the event CF7
		// fires first - would reverse the pair and this test would fail.
		window.gtm4wp_cf7_config.ga4events = true;

		fireCf7( 'wpcf7beforesubmit', form(), { contactFormId: 42 } );
		fireCf7( 'wpcf7mailsent', form(), {
			contactFormId: 42,
			status: 'mail_sent',
		} );
		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			status: 'mail_sent',
		} );

		const ga4 = window.dataLayer
			.map( ( entry ) => entry.event )
			.filter( ( name ) => ! name.startsWith( 'gtm4wp.' ) );
		expect( ga4 ).toEqual( [ 'form_submit', 'generate_lead' ] );
	} );

	it( 'pushes no generate_lead when the mail could not be sent', () => {
		window.gtm4wp_cf7_config.ga4events = true;

		fireCf7( 'wpcf7mailfailed', form(), {
			contactFormId: 42,
			status: 'mail_failed',
		} );
		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			status: 'mail_failed',
		} );

		expect(
			window.dataLayer.filter(
				( entry ) => entry.event === 'generate_lead'
			)
		).toHaveLength( 0 );
		expect(
			window.dataLayer.find( ( entry ) => entry.event === 'form_submit' )
				.form_status
		).toBe( 'mail_failed' );
	} );

	it( 'does not push GA4 events at all when ga4events is disabled', () => {
		fireCf7( 'wpcf7mailsent', form(), {
			contactFormId: 42,
			status: 'mail_sent',
		} );
		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			status: 'mail_sent',
		} );

		expect(
			window.dataLayer.filter(
				( entry ) =>
					entry.event === 'generate_lead' ||
					entry.event === 'form_submit'
			)
		).toHaveLength( 0 );
	} );

	it( 'carries the rejecting status on form_submit when validation failed', () => {
		// form_submit follows the GA4 Enhanced Measurement definition and fires on
		// every attempt, so form_status is the only thing on the event that tells a
		// rejected submission from an accepted one. Asserting the value, not just
		// the key: an empty or hardcoded status would make the parameter useless.
		window.gtm4wp_cf7_config.ga4events = true;

		fireCf7( 'wpcf7invalid', form(), {
			contactFormId: 42,
			status: 'validation_failed',
		} );
		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			status: 'validation_failed',
		} );

		const ga4 = window.dataLayer.filter(
			( entry ) => entry.event === 'form_submit'
		);
		expect( ga4 ).toHaveLength( 1 );
		expect( ga4[ 0 ].form_status ).toBe( 'validation_failed' );
		// A failed validation never produced a lead.
		expect(
			window.dataLayer.filter(
				( entry ) => entry.event === 'generate_lead'
			)
		).toHaveLength( 0 );
	} );

	it( 'passes a custom Contact Form 7 status through to form_status', () => {
		// CF7 lets a third-party plugin return its own status string; the tracker
		// must forward whatever it gets instead of mapping against a known list.
		window.gtm4wp_cf7_config.ga4events = true;

		fireCf7( 'wpcf7submit', form(), {
			contactFormId: 42,
			status: 'payment_required',
		} );

		expect(
			window.dataLayer.find( ( entry ) => entry.event === 'form_submit' )
				.form_status
		).toBe( 'payment_required' );
	} );

	it( 'omits form_status on form_start, which has no submission status yet', () => {
		window.gtm4wp_cf7_config.ga4events = true;

		form().querySelector( 'input[name="your-name"]' ).focus();

		const start = window.dataLayer.filter(
			( entry ) => entry.event === 'form_start'
		);
		expect( start ).toHaveLength( 1 );
		// Omitted, not an empty-string placeholder: an empty form_status would
		// register as a value in GA4 and pollute the dimension.
		expect( start[ 0 ] ).not.toHaveProperty( 'form_status' );
	} );

	it( 'omits form_status when Contact Form 7 sends no status', () => {
		window.gtm4wp_cf7_config.ga4events = true;

		fireCf7( 'wpcf7submit', form(), { contactFormId: 42 } );

		expect(
			window.dataLayer.find( ( entry ) => entry.event === 'form_submit' )
		).not.toHaveProperty( 'form_status' );
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
