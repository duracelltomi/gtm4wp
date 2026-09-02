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

// Every document-level listener the tracker attaches is captured file-wide and
// detached after each test (the T33 shape from woocommerce-tracker.test.js):
// the shared jsdom document never resets between tests, so without this the
// listeners accumulate, exact counts become order-dependent and an intra-boot
// duplicate push would be invisible behind find()/delta assertions.
let capturedDocListeners = [];
let originalDocAdd = null;

beforeEach( () => {
	capturedDocListeners = [];
	originalDocAdd = document.addEventListener;
	document.addEventListener = function ( type, fn, opts ) {
		capturedDocListeners.push( { type, fn, opts } );
		return originalDocAdd.call( this, type, fn, opts );
	};
} );

afterEach( () => {
	if ( originalDocAdd ) {
		document.addEventListener = originalDocAdd;
		originalDocAdd = null;
	}

	capturedDocListeners.forEach( ( { type, fn, opts } ) =>
		document.removeEventListener( type, fn, opts )
	);
	capturedDocListeners = [];
} );

// item_id mirrors production markup: the PHP side always emits it as a STRING
// (commit 8023129); internal_id stays numeric, exactly as DownloadData builds it.
const GRID_ITEM = {
	item_id: '55',
	item_name: 'My eBook',
	price: 9.99,
	productlink: 'https://shop/downloads/my-ebook/',
	internal_id: 55,
	item_list_name: 'Downloads List',
	index: 1,
};

const PURCHASE_FORM_ITEM = {
	item_id: '55',
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
		item_id: '55',
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
		delete global.gtm4wp_list_attribution;
		// The double-init guard (#218) lives on window and persists across tests
		// in the shared jsdom document; clear it so every boot_tracker() actually
		// boots (TS-8) - otherwise every test after the first no-ops.
		delete window.gtm4wp_edd_inited;
		// The shared cross-bundle container id is window state too (#222); clear
		// it so the init-guard tests see a fresh realm.
		delete window.gtm4wp_first_container_id;

		global.gtm4wp_store_item_list_attribution = jest.fn();
		global.gtm4wp_apply_stored_item_list = jest.fn( ( item ) => item );

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
			expect.objectContaining( { item_id: '55', index: 1 } )
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
			expect.objectContaining( { item_id: '55' } )
		);
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'productlink' );
	} );

	it( 'fires select_item for links in the EDD downloads block grid', () => {
		document.body.innerHTML =
			'<article class="edd-blocks__download">' +
			'<span class="gtm4wp_edd_productdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( GRID_ITEM ) +
			"'></span>" +
			'<a href="https://shop/downloads/my-ebook/">My eBook</a>' +
			'</article>';

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd-blocks__download a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'select_item'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: '55' } )
		);
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
			expect.objectContaining( { item_id: '55', quantity: 1 } )
		);
		expect( call[ 2 ].value ).toBe( 9.99 );
	} );

	it( 'does not re-register listeners when the bundle loads twice (regression: double-init #218)', () => {
		document.body.innerHTML = purchase_form_fixture( PURCHASE_FORM_ITEM );

		const add_to_cart_count = () => {
			document
				.querySelector( '.edd-add-to-cart-label' )
				.dispatchEvent(
					new window.MouseEvent( 'click', { bubbles: true } )
				);
			return global.gtm4wp_push_ecommerce.mock.calls.filter(
				( c ) => c[ 0 ] === 'add_to_cart'
			).length;
		};

		// A re-injected bundle (AJAX navigation, a page builder duplicating the
		// handle) re-executes the module against the same document. Measured as a
		// delta so the count is robust to document listeners the shared jsdom has
		// accumulated from earlier tests: the SECOND boot must add no new
		// listener, so one click pushes the same number after two boots as after
		// one. Without the window.gtm4wp_edd_inited guard the second boot adds a
		// duplicate listener and the second click count is higher.
		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();
		const after_one_boot = add_to_cart_count();

		global.gtm4wp_push_ecommerce.mockClear();
		boot_tracker();
		const after_two_boots = add_to_cart_count();

		expect( after_two_boots ).toBe( after_one_boot );
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

	it( 'stores and applies list attribution only when the flag is on', () => {
		global.gtm4wp_list_attribution = 1;
		document.body.innerHTML =
			grid_fixture() + purchase_form_fixture( PURCHASE_FORM_ITEM );

		boot_tracker();

		// A select_item list click persists the clicked list, keyed by the
		// download id read straight from the node (internal_id is excluded
		// from the event payload itself).
		document
			.querySelector( '.edd_download a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		expect(
			global.gtm4wp_store_item_list_attribution
		).toHaveBeenCalledWith( 55, 'Downloads List', undefined );

		// An add_to_cart click merges the stored list onto its items.
		document
			.querySelector( '.edd-add-to-cart' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		expect( global.gtm4wp_apply_stored_item_list ).toHaveBeenCalledWith(
			expect.objectContaining( { item_id: '55' } ),
			55
		);
	} );

	it( 'never touches list attribution while the flag is off', () => {
		document.body.innerHTML =
			grid_fixture() + purchase_form_fixture( PURCHASE_FORM_ITEM );

		boot_tracker();

		document
			.querySelector( '.edd_download a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		document
			.querySelector( '.edd-add-to-cart' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		expect(
			global.gtm4wp_store_item_list_attribution
		).not.toHaveBeenCalled();
		expect( global.gtm4wp_apply_stored_item_list ).not.toHaveBeenCalled();
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
			expect.objectContaining( { item_id: '55', quantity: 2 } )
		);
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'cart_key' );
		expect( call[ 2 ].value ).toBeCloseTo( 19.98 );
	} );

	it( 'fires remove_from_cart from the cart block div rows', () => {
		const cart_item = {
			item_id: '55',
			item_name: 'My eBook',
			price: 9.99,
			quantity: 1,
			cart_key: 0,
		};

		// The cart block renders div rows (not tr) and AJAX remove links
		// with the edd-remove-from-cart class instead of
		// edd_cart_remove_item_btn.
		document.body.innerHTML =
			'<div class="edd-blocks-cart__row edd-blocks-cart__row-item edd_cart_item">' +
			'<div class="edd_cart_item_name">My eBook' +
			'<span class="gtm4wp_edd_cartitemdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( cart_item ) +
			"'></span></div>" +
			'<a class="edd-blocks-cart__action-remove edd-remove-from-cart" href="#">Remove</a>' +
			'</div>';

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd-remove-from-cart' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'remove_from_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: '55' } )
		);
		expect( call[ 2 ].value ).toBeCloseTo( 9.99 );
	} );

	it( 'reports checkout quantity edits as add/remove deltas', () => {
		const cart_item = {
			item_id: '55',
			item_name: 'My eBook',
			price: 9.99,
			quantity: 2,
			cart_key: 0,
		};

		document.body.innerHTML =
			'<table><tbody>' +
			'<tr class="edd_cart_item">' +
			'<td>My eBook' +
			'<span class="gtm4wp_edd_cartitemdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( cart_item ) +
			"'></span>" +
			'<input type="number" class="edd-input edd-item-quantity" value="2" /></td>' +
			'</tr>' +
			'</tbody></table>';

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		const qty_el = document.querySelector( '.edd-item-quantity' );

		// 2 -> 5: three units added.
		qty_el.value = '5';
		qty_el.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		const add_call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( add_call ).toBeDefined();
		expect( add_call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: '55', quantity: 3 } )
		);
		expect( add_call[ 2 ].value ).toBeCloseTo( 29.97 );

		// 5 -> 4: the next edit diffs against the already-reported 5, not the
		// server-rendered 2.
		global.gtm4wp_push_ecommerce.mockClear();
		qty_el.value = '4';
		qty_el.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		const remove_call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'remove_from_cart'
		);
		expect( remove_call ).toBeDefined();
		expect( remove_call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: '55', quantity: 1 } )
		);
		expect( remove_call[ 2 ].value ).toBeCloseTo( 9.99 );

		// An unchanged or invalid value reports nothing.
		global.gtm4wp_push_ecommerce.mockClear();
		qty_el.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		qty_el.value = '0';
		qty_el.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'skips the initial gateway load and reports later gateway picks once', () => {
		window.gtm4wp_checkout_products = [ { item_id: '55' } ];
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
		window.gtm4wp_checkout_products = [ { item_id: '55' } ];
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

	it( 'keeps a container index of 0 set by another bundle (regression: #222)', () => {
		// gtm4wp_first_container_id is shared across the WC and EDD bundles, and
		// 0 is a legitimate container index. The typeof guard must leave a
		// defined falsy value alone - the x = x || '' idiom this replaces would
		// clobber it (the same pin the WC bundle carries).
		window.gtm4wp_first_container_id = 0;

		boot_tracker();

		expect( window.gtm4wp_first_container_id ).toBe( 0 );
	} );

	it( 'initializes the shared container id to an empty string when undefined', () => {
		boot_tracker();

		expect( window.gtm4wp_first_container_id ).toBe( '' );
	} );

	it( 'skips a grid span with malformed JSON and still reports its valid sibling', () => {
		// Hostile-input shape: the assertion is the ABSENCE/PRESENCE of pushes,
		// never not.toThrow() - the shipped bundle is a non-strict classic
		// script, so a throw-shaped assertion would measure jest's language
		// mode, not production (R26/L18).
		document.body.innerHTML =
			'<div class="edd_download">' +
			'<span class="gtm4wp_edd_productdata" data-gtm4wp_product_data=\'{not json\'></span>' +
			'</div>' +
			grid_fixture();

		boot_tracker();

		const impression_calls = global.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === 'view_item_list'
		);
		expect( impression_calls ).toHaveLength( 1 );
		expect( impression_calls[ 0 ][ 1 ] ).toHaveLength( 1 );
		expect( impression_calls[ 0 ][ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: '55' } )
		);
	} );

	it( 'pushes nothing for a purchase form whose payload parses to null', () => {
		// JSON.parse('null') succeeds but yields a falsy non-object; the parse
		// guard must swallow it (the #190/#193 parse-result family).
		document.body.innerHTML = purchase_form_fixture(
			PURCHASE_FORM_ITEM
		).replace(
			"value='" + JSON.stringify( PURCHASE_FORM_ITEM ) + "'",
			"value='null'"
		);

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd-add-to-cart-label' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'pushes nothing for a cart row without its data span', () => {
		document.body.innerHTML =
			'<table><tbody>' +
			'<tr class="edd_cart_item">' +
			'<td class="edd_cart_item_name">My eBook</td>' +
			'<td><a class="edd_cart_remove_item_btn" href="#">Remove</a></td>' +
			'</tr>' +
			'</tbody></table>';

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_cart_remove_item_btn' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'exposes gtm4wp_edd_track_add_to_cart for themes and guards its inputs', () => {
		document.body.innerHTML = purchase_form_fixture( PURCHASE_FORM_ITEM );

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		// The public API tracks when handed the button (or a descendant)...
		const tracked = window.gtm4wp_edd_track_add_to_cart(
			document.querySelector( '.edd-add-to-cart-label' )
		);
		expect( tracked ).toBe( true );
		expect(
			global.gtm4wp_push_ecommerce.mock.calls.filter(
				( c ) => c[ 0 ] === 'add_to_cart'
			)
		).toHaveLength( 1 );

		// ...and refuses garbage and disabled buttons without pushing.
		global.gtm4wp_push_ecommerce.mockClear();
		expect( window.gtm4wp_edd_track_add_to_cart( null ) ).toBe( false );

		document
			.querySelector( '.edd-add-to-cart' )
			.classList.add( 'disabled' );
		expect(
			window.gtm4wp_edd_track_add_to_cart(
				document.querySelector( '.edd-add-to-cart-label' )
			)
		).toBe( false );
		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'clamps an invalid purchase-form quantity to 1', () => {
		document.body.innerHTML = purchase_form_fixture(
			PURCHASE_FORM_ITEM
		).replace(
			'</form>',
			'<input type="number" name="edd_download_quantity" value="0" /></form>'
		);

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
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { quantity: 1 } )
		);
	} );

	it( 'sends one unchunked view_item_list when the chunk size is 0', () => {
		document.body.innerHTML = grid_fixture() + grid_fixture();
		global.gtm4wp_product_per_impression = 0;

		boot_tracker();

		const impression_calls = global.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === 'view_item_list'
		);
		expect( impression_calls ).toHaveLength( 1 );
		expect( impression_calls[ 0 ][ 1 ] ).toHaveLength( 2 );
	} );

	it( 'ignores grid link clicks whose href is not the download page', () => {
		document.body.innerHTML =
			'<div class="edd_download">' +
			'<span class="gtm4wp_edd_productdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( GRID_ITEM ) +
			"'></span>" +
			'<a href="https://elsewhere.example/">Author site</a>' +
			'</div>';

		boot_tracker();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.edd_download a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		expect( global.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'labels a submit without any gateway as payment type not found', () => {
		window.gtm4wp_checkout_products = [ { item_id: '55' } ];
		window.gtm4wp_checkout_value = 9.99;
		document.body.innerHTML =
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
		expect( call[ 2 ].payment_type ).toBe( '(payment type not found)' );
	} );

	it( 'runs the select_item redirect callback only for the first container', () => {
		document.body.innerHTML = grid_fixture();
		// Take the redirect branch, but never invoke the callback with the
		// matching container - jsdom cannot navigate.
		window.gtm4wp_datalayer_max_timeout = 2000;

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
		expect( window.gtm4wp_first_container_id ).toBe( 'GTM-TEST' );

		// A second container's eventCallback firing must return early without
		// touching the location (the redirect runs once per click, not once per
		// container).
		const event_callback = call[ 3 ];
		expect( typeof event_callback ).toBe( 'function' );
		expect( event_callback( 'GTM-OTHER' ) ).toBe( true );
	} );
} );
