/**
 * Unit tests for the Easy Digital Downloads tracker
 * (js/frontend/gtm4wp-edd.js).
 *
 * Recipe (TC-9): set the bare globals the tracker reads, stub the jQuery +
 * Google Tag globals it touches, build a jsdom fixture, let the deferred
 * process_pages() run (fake timers), then dispatch bubbling events and assert
 * the gtm4wp_push_ecommerce calls. gtm4wp_datalayer_max_timeout is 0 so
 * select_item takes the no-redirect branch (jsdom cannot navigate).
 */

const GRID_ITEM = {
	item_id: 55,
	item_name: 'My eBook',
	price: 9.99,
	productlink: 'https://shop/downloads/my-ebook/',
	internal_id: 55,
	item_list_name: 'Downloads List',
	index: 1,
};

const PURCHASE_FORM_ITEM = {
	item_id: 55,
	item_name: 'My eBook',
	price: 9.99,
	internal_id: 55,
};

function grid_fixture() {
	return (
		'<div class="edd_download">' +
		'<span class="gtm4wp_edd_productdata" data-gtm4wp_product_data=\'' +
		JSON.stringify( GRID_ITEM ) +
		"'></span>" +
		'<a href="https://shop/downloads/my-ebook/">My eBook</a>' +
		'</div>'
	);
}

function purchase_form_fixture( item, price_options ) {
	const data = Object.assign( {}, item );
	if ( price_options ) {
		data.price_options = price_options;
	}

	return (
		'<form class="edd_download_purchase_form">' +
		( price_options
			? '<div class="edd_price_options">' +
			  '<input type="checkbox" name="edd_options[price_id][]" value="1" checked />' +
			  '<input type="checkbox" name="edd_options[price_id][]" value="2" checked />' +
			  '<input type="checkbox" name="edd_options[price_id][]" value="3" />' +
			  '</div>'
			: '' ) +
		"<input type='hidden' name='gtm4wp_product_data' value='" +
		JSON.stringify( data ) +
		"' />" +
		'<a class="edd-add-to-cart"><span class="edd-add-to-cart-label">Purchase</span></a>' +
		'</form>'
	);
}

function checkout_cart_fixture() {
	const cart_item = {
		item_id: 55,
		item_name: 'My eBook',
		price: 9.99,
		quantity: 2,
		cart_key: 0,
	};

	return (
		'<table><tbody>' +
		'<tr class="edd_cart_item">' +
		'<td class="edd_cart_item_name">My eBook' +
		'<span class="gtm4wp_edd_cartitemdata" data-gtm4wp_product_data=\'' +
		JSON.stringify( cart_item ) +
		"'></span></td>" +
		'<td><a class="edd_cart_remove_item_btn" href="#">Remove</a></td>' +
		'</tr>' +
		'</tbody></table>'
	);
}

describe( 'gtm4wp-edd tracker', () => {
	let body_event_handlers;

	beforeEach( () => {
		document.body.className = '';
		document.body.innerHTML = '';

		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 10;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0;
		window.google_tag_manager = { 'GTM-TEST': {} };
		delete window.gtm4wp_checkout_products;
		delete window.gtm4wp_checkout_value;
		delete window.gtm4wp_edd_variable_view_item;

		global.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_make_sure_is_float = ( value ) => {
			const parsed = parseFloat( value );
			return isNaN( parsed ) ? 0 : parseFloat( parsed.toFixed( 2 ) );
		};
		global.gtm4wp_read_from_json = (
			raw,
			exclude = [ 'productlink', 'internal_id' ]
		) => {
			try {
				const parsed = JSON.parse( raw );
				exclude.forEach( ( k ) => delete parsed[ k ] );
				return parsed;
			} catch ( e ) {
				return false;
			}
		};
		global.gtm4wp_read_json_from_node = (
			el,
			key,
			exclude = [ 'productlink', 'internal_id' ]
		) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			return global.gtm4wp_read_from_json( raw, exclude );
		};

		// jQuery stub capturing body event bindings so tests can fire
		// edd_gateway_loaded the way EDD core does.
		body_event_handlers = {};
		const jq = {
			on: ( event, handler ) => {
				body_event_handlers[ event ] = handler;
				return jq;
			},
		};
		global.jQuery = jest.fn( () => jq );
		window.jQuery = global.jQuery;

		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
		delete window.jQuery;
	} );

	function boot_tracker() {
		jest.isolateModules( () => require( '../gtm4wp-edd' ) );
		jest.runAllTimers();
	}

	it( 'fires chunked view_item_list impressions for grid spans', () => {
		document.body.innerHTML = grid_fixture() + grid_fixture();
		global.gtm4wp_product_per_impression = 1;

		boot_tracker();

		const impression_calls = global.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === 'view_item_list'
		);
		expect( impression_calls ).toHaveLength( 2 );
		expect( impression_calls[ 0 ][ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 55, index: 1 } )
		);
	} );

	it( 'fires select_item when a grid product link is clicked', () => {
		document.body.innerHTML = grid_fixture();

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_download a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'select_item'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 55 } )
		);
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'productlink' );
	} );

	it( 'fires add_to_cart with the form data on a buy button click', () => {
		document.body.innerHTML = purchase_form_fixture( PURCHASE_FORM_ITEM );

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd-add-to-cart-label' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ] ).toHaveLength( 1 );
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 55, quantity: 1 } )
		);
		expect( call[ 2 ].value ).toBe( 9.99 );
	} );

	it( 'fires one add_to_cart item per checked variable price option', () => {
		document.body.innerHTML = purchase_form_fixture( PURCHASE_FORM_ITEM, {
			1: { name: 'Personal', price: 5 },
			2: { name: 'Professional', price: 15 },
			3: { name: 'Agency', price: 50 },
		} );

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd-add-to-cart' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		// Only the two CHECKED options count; the price option name becomes
		// the GA4 item_variant and price_options never reaches the push.
		expect( call[ 1 ] ).toHaveLength( 2 );
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				item_variant: 'Personal',
				price: 5,
			} )
		);
		expect( call[ 1 ][ 1 ] ).toEqual(
			expect.objectContaining( {
				item_variant: 'Professional',
				price: 15,
			} )
		);
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'price_options' );
		expect( call[ 2 ].value ).toBe( 20 );
	} );

	it( 're-fires view_item with the picked price options on the download page', () => {
		window.gtm4wp_edd_variable_view_item = true;
		document.body.innerHTML = purchase_form_fixture( PURCHASE_FORM_ITEM, {
			1: { name: 'Personal', price: 5 },
			2: { name: 'Professional', price: 15 },
			3: { name: 'Agency', price: 50 },
		} );

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_price_options input' )
			.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'view_item'
		);
		expect( call ).toBeDefined();
		// Both CHECKED options are reported, each with quantity 1, the option
		// name as item_variant and without the price_options map.
		expect( call[ 1 ] ).toHaveLength( 2 );
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				item_variant: 'Personal',
				price: 5,
				quantity: 1,
			} )
		);
		expect( call[ 1 ][ 1 ] ).toEqual(
			expect.objectContaining( {
				item_variant: 'Professional',
				price: 15,
				quantity: 1,
			} )
		);
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'price_options' );
		expect( call[ 2 ].value ).toBe( 20 );
	} );

	it( 'does not re-fire view_item without the download page flag', () => {
		document.body.innerHTML = purchase_form_fixture( PURCHASE_FORM_ITEM, {
			1: { name: 'Personal', price: 5 },
		} );

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_price_options input' )
			.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'does not re-fire view_item for forms inside download grid items', () => {
		window.gtm4wp_edd_variable_view_item = true;
		document.body.innerHTML =
			'<div class="edd_download">' +
			purchase_form_fixture( PURCHASE_FORM_ITEM, {
				1: { name: 'Personal', price: 5 },
			} ) +
			'</div>';

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_price_options input' )
			.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'does not re-fire view_item when no price option is checked', () => {
		window.gtm4wp_edd_variable_view_item = true;
		document.body.innerHTML = purchase_form_fixture( PURCHASE_FORM_ITEM, {
			1: { name: 'Personal', price: 5 },
			2: { name: 'Professional', price: 15 },
		} );
		document
			.querySelectorAll( '.edd_price_options input' )
			.forEach( ( input ) => {
				input.checked = false;
			} );

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_price_options input' )
			.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'fires remove_from_cart from the checkout cart row data', () => {
		document.body.innerHTML = checkout_cart_fixture();

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_cart_remove_item_btn' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'remove_from_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 55, quantity: 2 } )
		);
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'cart_key' );
		expect( call[ 2 ].value ).toBeCloseTo( 19.98 );
	} );

	it( 'skips the initial gateway load and reports later gateway picks once', () => {
		window.gtm4wp_checkout_products = [ { item_id: 55 } ];
		window.gtm4wp_checkout_value = 9.99;

		boot_tracker();

		const gateway_handler = body_event_handlers.edd_gateway_loaded;
		expect( gateway_handler ).toBeDefined();

		// The first firing is EDD initializing the default gateway on page
		// load - not a buyer decision.
		gateway_handler( {}, 'stripe' );
		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();

		// An actual switch reports add_payment_info once per gateway.
		gateway_handler( {}, 'paypal' );
		gateway_handler( {}, 'paypal' );

		const payment_calls = global.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === 'add_payment_info'
		);
		expect( payment_calls ).toHaveLength( 1 );
		expect( payment_calls[ 0 ][ 2 ] ).toEqual(
			expect.objectContaining( { payment_type: 'paypal', value: 9.99 } )
		);
	} );

	it( 'reports the payment info at the latest on purchase submit', () => {
		window.gtm4wp_checkout_products = [ { item_id: 55 } ];
		window.gtm4wp_checkout_value = 9.99;
		document.body.innerHTML =
			'<label class="edd-gateway-option">' +
			'<input type="radio" name="payment-mode" value="stripe" checked />' +
			'</label>' +
			'<input type="submit" id="edd-purchase-button" value="Purchase" />';

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '#edd-purchase-button' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_payment_info'
		);
		expect( call ).toBeDefined();
		expect( call[ 2 ] ).toEqual(
			expect.objectContaining( { payment_type: 'stripe' } )
		);
	} );
} );
