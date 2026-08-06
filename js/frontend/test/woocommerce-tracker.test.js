/**
 * Targeted unit test for the classic WooCommerce tracker
 * (js/frontend/gtm4wp-woocommerce.js).
 *
 * The classic tracker is a large, DOM-driven file; this guards only the behavior
 * added for the block-editor Product Collection grid: a product rendered as
 * <li class="wc-block-product post-{ID}"> (with a server-injected
 * .gtm4wp_productdata span) must fire select_item when its product link is
 * clicked — the new selector wired into the click handler.
 *
 * Recipe (TC-9): set the bare globals the tracker reads, stub the jQuery + Google
 * Tag globals it touches, build a jsdom fixture, let the deferred process_pages()
 * run (fake timers), then dispatch a bubbling click and assert the dataLayer push.
 * gtm4wp_datalayer_max_timeout is 0 so select_item takes the no-redirect branch
 * (jsdom cannot navigate).
 */

// The bundle guards its boot with window.gtm4wp_woocommerce_inited so a re-injected
// copy cannot attach its document listeners twice (#71). That flag lives on window,
// which jsdom keeps for the whole file, so every test has to clear it before it
// boots the module again - otherwise the second and later tests silently get an
// early return and no listeners at all. Mirrors the blocks tracker's suite.
//
// TS-7/TS-14 (test-review T33): loading the module binds delegated listeners to
// the document (gtm4wp-woocommerce.js:503 click, capture:true, plus the cart-page
// click/keypress ones) and jest.isolateModules never detaches them - the wp-scripts
// jest preset shares ONE jsdom document across the whole file. Every leaked copy
// then answers the next test's dispatched event and pushes the same payload again.
//
// This used to be handled per-describe, which left the seven describes above the
// T24/T25 block leaking; the tests that came after them had to assert pushed SHAPE
// via find() instead of exact counts, and a comment said so. That is the shape of a
// defect being routed around rather than asserted (TS-14), so the capture is now
// file-wide: record here, detach in afterEach, and every test can count exactly.
let capturedDocListeners = [];
let originalDocAdd = null;

beforeEach( () => {
	delete window.gtm4wp_woocommerce_inited;

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

const PRODUCT_DATA = {
	item_id: 42,
	item_name: 'PC Product',
	price: 5,
	productlink: 'https://shop/p42',
	internal_id: 42,
};

const FIXTURE =
	'<ul class="wc-block-product-template">' +
	'<li class="wc-block-product post-42 type-product">' +
	'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
	JSON.stringify( PRODUCT_DATA ) +
	"'></span>" +
	'<a class="wc-block-components-product-image__link" href="https://shop/p42">PC Product</a>' +
	'</li></ul>';

describe( 'gtm4wp-woocommerce Product Collection select_item', () => {
	beforeEach( () => {
		document.body.className = '';
		document.body.innerHTML = FIXTURE;

		// Bare globals the tracker reads (undeclared reads throw in strict mode).
		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 0;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0; // no-redirect select_item branch
		window.google_tag_manager = { 'GTM-TEST': {} };

		global.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_read_json_from_node = ( el, key, exclude = [] ) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			const parsed = JSON.parse( raw );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};

		// The tracker binds a few jQuery-only WooCommerce events; a chainable
		// no-op stub is enough (none of them fire in this test).
		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );

		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
	} );

	it( 'fires select_item when a Product Collection product link is clicked', () => {
		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers(); // run the deferred process_pages(): binds the click listener

		// Drop the view_item_list impression fired for the span on load.
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( 'a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'select_item'
		);
		expect( call ).toBeDefined();
		// The productlink is stripped before the push; the GA4 item id survives.
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 42, item_name: 'PC Product' } )
		);
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'productlink' );
	} );
} );

describe( 'gtm4wp-woocommerce PDP add_to_cart validation guard (#274)', () => {
	const buildForm = ( requiredEmpty ) =>
		'<form class="cart" method="post" action="https://shop/p42">' +
		'<input type="text" name="addon"' +
		( requiredEmpty ? ' required' : ' value="filled"' ) +
		' />' +
		'<input type="hidden" name="gtm4wp_product_data" />' +
		'<input type="number" name="quantity" value="1" />' +
		// type=button (not submit) so jsdom does not attempt a real form
		// submission (requestSubmit is unimplemented); the tracker fires on the
		// click regardless of the button type.
		'<button type="button" class="single_add_to_cart_button">Add</button>' +
		'</form>';

	beforeEach( () => {
		document.body.className = '';

		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 0;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0;
		window.google_tag_manager = { 'GTM-TEST': {} };

		global.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_read_from_json = ( json ) => {
			const parsed = JSON.parse( json );
			delete parsed.productlink;
			delete parsed.internal_id;
			return parsed;
		};
		global.gtm4wp_read_json_from_node = ( el, key, exclude = [] ) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			const parsed = JSON.parse( raw );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};

		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );

		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
	} );

	const clickAddToCart = () => {
		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers(); // binds the delegated click listener
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.single_add_to_cart_button' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		return global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
	};

	it( 'does not fire add_to_cart when a required field is empty', () => {
		document.body.innerHTML = buildForm( true );
		document.querySelector( '[name=gtm4wp_product_data]' ).value =
			JSON.stringify( PRODUCT_DATA );

		expect( clickAddToCart() ).toBeUndefined();
	} );

	it( 'fires add_to_cart when the form passes validation', () => {
		document.body.innerHTML = buildForm( false );
		document.querySelector( '[name=gtm4wp_product_data]' ).value =
			JSON.stringify( PRODUCT_DATA );

		const call = clickAddToCart();
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 42 } )
		);
	} );
} );

describe( 'gtm4wp-woocommerce shortcode add_to_cart button (#110)', () => {
	const SHORTCODE_DATA = {
		item_id: 42,
		item_name: 'Shortcode Product',
		price: 7,
	};

	beforeEach( () => {
		document.body.className = '';
		// [add_to_cart] shortcode: a .product wrapper with an add-to-cart button but
		// no .gtm4wp_productdata span; the data lives on the button (href="#" so
		// jsdom does not attempt a navigation).
		document.body.innerHTML =
			'<p class="product woocommerce add_to_cart_inline">' +
			'<a href="#" class="button add_to_cart_button ajax_add_to_cart product_type_simple">Add to cart</a>' +
			'</p>';
		document
			.querySelector( '.add_to_cart_button' )
			.setAttribute(
				'data-gtm4wp_product_data',
				JSON.stringify( SHORTCODE_DATA )
			);

		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 0;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0;
		window.google_tag_manager = { 'GTM-TEST': {} };

		global.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_read_json_from_node = ( el, key, exclude = [] ) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			const parsed = JSON.parse( raw );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};

		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );

		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
	} );

	it( 'fires add_to_cart from the button data when there is no list span', () => {
		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.add_to_cart_button' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 42 } )
		);
		expect( call[ 1 ][ 0 ] ).toHaveProperty( 'quantity', 1 );
	} );
} );

describe( 'gtm4wp-woocommerce variation id prefix (#383)', () => {
	// Parent product data as the server emits it: the gads id field is already
	// prefixed; item_id stays unprefixed.
	const PARENT_DATA = {
		item_id: 10,
		id: 'woocommerce_gpf_10',
		item_name: 'Parent',
		price: 5,
		internal_id: 10,
	};

	let handlers;

	afterEach( () => {
		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
	} );

	// Fires WooCommerce's found_variation handler with the given prefix and returns
	// the pushed view_item item. found_variation is bound through jQuery, so a
	// capturing jQuery stub records the handler and we invoke it directly.
	const fireFoundVariation = ( prefix ) => {
		document.body.className = '';
		document.body.innerHTML =
			'<form class="cart variations_form">' +
			'<input type="hidden" name="gtm4wp_product_data" />' +
			'</form>';
		document.querySelector( '[name=gtm4wp_product_data]' ).value =
			JSON.stringify( PARENT_DATA );

		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 0;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		global.gtm4wp_remarketing_prod_id_prefix = prefix;
		global.gtm4wp_make_sure_is_float = ( v ) => parseFloat( v ) || 0;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0;
		window.google_tag_manager = { 'GTM-TEST': {} };

		global.gtm4wp_push_ecommerce = jest.fn();

		handlers = {};
		const jq = {
			on: ( ...args ) => {
				const evt = args[ 0 ];
				const fn = args[ args.length - 1 ];
				if ( typeof fn === 'function' ) {
					handlers[ evt ] = fn;
				}
				return jq;
			},
			trigger: () => jq,
			ajaxSuccess: () => jq,
		};
		global.jQuery = jest.fn( () => jq );

		jest.useFakeTimers();
		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers(); // binds the found_variation handler via the jQuery stub

		handlers.found_variation(
			{ target: document.querySelector( 'form' ) },
			{
				variation_id: 456,
				sku: 'VAR-SKU',
				display_price: 9.99,
				attributes: { attribute_pa_color: 'blue' },
			}
		);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'view_item'
		);
		return call && call[ 1 ][ 0 ];
	};

	it( 're-applies the id prefix to the selected variation', () => {
		const item = fireFoundVariation( 'woocommerce_gpf_' );
		expect( item ).toBeDefined();
		expect( item.id ).toBe( 'woocommerce_gpf_456' );
		expect( item.item_id ).toBe( 456 );
		expect( item.item_group_id ).toBe( 'woocommerce_gpf_10' );
		// #348: the variation view_item must carry an explicit quantity of 1.
		expect( item.quantity ).toBe( 1 );
	} );

	it( 'leaves the id unprefixed and numeric when no prefix is set', () => {
		const item = fireFoundVariation( '' );
		expect( item ).toBeDefined();
		expect( item.id ).toBe( 456 );
		expect( item.item_id ).toBe( 456 );
	} );
} );

describe( 'gtm4wp-woocommerce exposed add_to_cart trackers (#273)', () => {
	const PRODUCT = { item_id: 77, item_name: 'API Product', price: 12 };

	beforeEach( () => {
		document.body.className = '';
		document.body.innerHTML = '';

		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 0;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0;
		window.google_tag_manager = { 'GTM-TEST': {} };

		global.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_read_from_json = ( json ) => {
			const parsed = JSON.parse( json );
			delete parsed.productlink;
			delete parsed.internal_id;
			return parsed;
		};
		global.gtm4wp_read_json_from_node = ( el, key, exclude = [] ) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			const parsed = JSON.parse( raw );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};

		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );

		jest.useFakeTimers();
		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers();
		global.gtm4wp_push_ecommerce.mockClear();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
	} );

	it( 'exposes the add_to_cart trackers on window', () => {
		expect( typeof window.gtm4wp_track_single_add_to_cart ).toBe(
			'function'
		);
		expect( typeof window.gtm4wp_track_list_add_to_cart ).toBe(
			'function'
		);
	} );

	it( 'gtm4wp_track_single_add_to_cart tracks a form + button directly', () => {
		// What a theme with its own AJAX add to cart would call from its success
		// handler, instead of copying the tracker (#273).
		document.body.innerHTML =
			'<form class="cart">' +
			'<input type="hidden" name="gtm4wp_product_data" />' +
			'<input type="number" name="quantity" value="2" />' +
			'<button type="button" class="single_add_to_cart_button">Add</button>' +
			'</form>';
		document.querySelector( '[name=gtm4wp_product_data]' ).value =
			JSON.stringify( PRODUCT );

		const tracked = window.gtm4wp_track_single_add_to_cart(
			document.querySelector( '.single_add_to_cart_button' ),
			document.querySelector( 'form.cart' )
		);

		expect( tracked ).toBe( true );
		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 77 } )
		);
		// Number, not the string the input carries: every quantity site parses
		// before reporting so the type is the same on every surface (#79).
		expect( call[ 1 ][ 0 ].quantity ).toBe( 2 );
	} );

	it( 'gtm4wp_track_list_add_to_cart tracks a list button directly', () => {
		document.body.innerHTML =
			'<div class="product">' +
			'<span class="gtm4wp_productdata"></span>' +
			'<a class="add_to_cart_button product_type_simple">Add</a>' +
			'</div>';
		document
			.querySelector( '.gtm4wp_productdata' )
			.setAttribute(
				'data-gtm4wp_product_data',
				JSON.stringify( PRODUCT )
			);

		const tracked = window.gtm4wp_track_list_add_to_cart(
			document.querySelector( '.add_to_cart_button' )
		);

		expect( tracked ).toBe( true );
		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 77 } )
		);
	} );
} );

describe( 'gtm4wp-woocommerce list attribution cookie (#405)', () => {
	const LIST_PRODUCT = {
		item_id: 42,
		item_name: 'PC Product',
		price: 5,
		productlink: 'https://shop/p42',
		internal_id: 42,
		item_list_name: 'Summer Sale',
		item_list_id: 'summer-sale',
	};

	const setCommonGlobals = () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 0;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0;
		window.google_tag_manager = { 'GTM-TEST': {} };
		window.gtm4wp_list_attribution = 1; // opt-in ON

		global.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_store_item_list_attribution = jest.fn();
		// A faithful merge helper so tests can assert the enriched item, not just
		// that the call happened.
		global.gtm4wp_apply_stored_item_list = ( item, id ) => {
			const stored = {
				42: {
					item_list_name: 'Summer Sale',
					item_list_id: 'summer-sale',
				},
			};
			if ( item && id && ! item.item_list_name && stored[ id ] ) {
				item.item_list_name = stored[ id ].item_list_name;
				item.item_list_id = stored[ id ].item_list_id;
			}
			return item;
		};
		// exclude-aware reads so internal_id survives when the tracker keeps it.
		global.gtm4wp_read_json_from_node = ( el, key, exclude = [] ) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			const parsed = JSON.parse( raw );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};
		global.gtm4wp_read_from_json = (
			json,
			exclude = [ 'productlink', 'internal_id' ]
		) => {
			const parsed = JSON.parse( json );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};

		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );

		jest.useFakeTimers();
	};

	afterEach( () => {
		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
		delete window.gtm4wp_list_attribution;
	} );

	it( 'writes the list attribution cookie on a select_item click', () => {
		document.body.className = '';
		document.body.innerHTML =
			'<ul class="products"><li class="product">' +
			'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( LIST_PRODUCT ) +
			"'></span>" +
			'<a href="https://shop/p42">PC Product</a>' +
			'</li></ul>';
		setCommonGlobals();

		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers();

		document
			.querySelector( 'a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		expect(
			global.gtm4wp_store_item_list_attribution
		).toHaveBeenCalledWith( 42, 'Summer Sale', 'summer-sale' );
	} );

	it( 'does not write the cookie when the option is off', () => {
		document.body.className = '';
		document.body.innerHTML =
			'<ul class="products"><li class="product">' +
			'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( LIST_PRODUCT ) +
			"'></span>" +
			'<a href="https://shop/p42">PC Product</a>' +
			'</li></ul>';
		setCommonGlobals();
		window.gtm4wp_list_attribution = 0; // opt-in OFF

		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers();

		document
			.querySelector( 'a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		expect(
			global.gtm4wp_store_item_list_attribution
		).not.toHaveBeenCalled();
	} );

	it( 'carries the stored list onto a product-page add_to_cart', () => {
		document.body.className = '';
		document.body.innerHTML =
			'<form class="cart">' +
			'<input type="hidden" name="gtm4wp_product_data" value=\'' +
			JSON.stringify( {
				item_id: 42,
				price: 5,
				internal_id: 42,
				productlink: 'https://shop/p42',
			} ) +
			"' />" +
			'<input type="number" name="quantity" value="1" />' +
			'<button type="button" class="single_add_to_cart_button">Add</button>' +
			'</form>';
		setCommonGlobals();

		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers();
		global.gtm4wp_push_ecommerce.mockClear();

		document
			.querySelector( '.single_add_to_cart_button' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				item_id: 42,
				item_list_name: 'Summer Sale',
				item_list_id: 'summer-sale',
			} )
		);
		// internal_id must not leak into the pushed item.
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'internal_id' );
	} );
} );

describe( 'gtm4wp-woocommerce CheckoutWC compatibility (#385)', () => {
	// CheckoutWC replaces the checkout template (no body.woocommerce-checkout),
	// so the tracker must bind add_shipping_info / add_payment_info to
	// CheckoutWC's own cfw_step_changed event when the option is on.
	const setGlobals = () => {
		global.gtm4wp_datalayer_name = 'dataLayer';
		global.gtm4wp_currency = 'EUR';
		global.gtm4wp_product_per_impression = 0;
		global.gtm4wp_clear_ecommerce = false;
		global.gtm4wp_console_log = false;
		global.gtm4wp_use_sku_instead = false;
		window.dataLayer = [];
		window.gtm4wp_datalayer_max_timeout = 0;
		window.google_tag_manager = { 'GTM-TEST': {} };

		// Checkout products/value the step handlers read (server-populated on the
		// real checkout page).
		window.gtm4wp_checkout_products = [ { item_id: 1, price: 25 } ];
		window.gtm4wp_checkout_value = 50;

		// Each test here simulates a fresh page load, and a fresh page load means a
		// fresh window - so the "already reported" state the bundle now preserves
		// across a re-injection (#82) starts empty. jsdom keeps one window for the
		// whole file, so it has to be cleared explicitly; before #82 the module body
		// wiped it on every load, which is precisely the bug.
		delete window.gtm4wp_checkout_step_fired;
		delete window.gtm4wp_view_item_fired_during_pageload;
		delete window.gtm4wp_first_container_id;

		global.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_read_json_from_node = ( el, key, exclude = [] ) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			const parsed = JSON.parse( raw );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};

		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );

		jest.useFakeTimers();
	};

	// A CheckoutWC checkout page: no body.woocommerce-checkout, but the standard
	// WooCommerce shipping/payment method inputs (which CheckoutWC keeps).
	const CHECKOUT_DOM =
		'<input type="radio" name="shipping_method[0]" value="flat_rate:1" checked />' +
		'<div class="payment_methods">' +
		'<input type="radio" name="payment_method" value="cod" checked />' +
		'</div>';

	// jsdom's document is shared across the tests in this file and isolateModules
	// does not detach listeners, so capture the cfw_step_changed listener this
	// test binds and remove it in afterEach - otherwise a prior test's listener
	// would fire in the option-off test (TS-7 leak) and make it order-dependent.
	let boundStepListeners = [];

	const boot = () => {
		boundStepListeners = [];
		const originalAdd = document.addEventListener;
		document.addEventListener = function ( type, fn, opts ) {
			if ( 'cfw_step_changed' === type ) {
				boundStepListeners.push( fn );
			}
			return originalAdd.call( this, type, fn, opts );
		};

		jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
		jest.runAllTimers(); // binds the cfw_step_changed listener in process_pages()

		document.addEventListener = originalAdd;
	};

	const dispatchStep = ( step ) =>
		document.dispatchEvent(
			new window.CustomEvent( 'cfw_step_changed', { detail: { step } } )
		);

	const calls = ( event ) =>
		global.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === event
		);

	afterEach( () => {
		boundStepListeners.forEach( ( fn ) =>
			document.removeEventListener( 'cfw_step_changed', fn )
		);
		boundStepListeners = [];

		jest.useRealTimers();
		delete window.google_tag_manager;
		delete window.gtm4wp_datalayer_max_timeout;
		delete window.gtm4wp_checkoutwc;
		delete window.gtm4wp_checkout_products;
		delete window.gtm4wp_checkout_value;
	} );

	it( 'fires add_shipping_info and add_payment_info on the payment step', () => {
		document.body.className = '';
		document.body.innerHTML = CHECKOUT_DOM;
		setGlobals();
		window.gtm4wp_checkoutwc = 1; // opt-in ON

		boot();
		dispatchStep( 'payment' );

		const shipping = calls( 'add_shipping_info' );
		const payment = calls( 'add_payment_info' );
		expect( shipping ).toHaveLength( 1 );
		expect( payment ).toHaveLength( 1 );
		// The selected method reaches the event (assert the effect, not just the call).
		expect( shipping[ 0 ][ 2 ] ).toEqual(
			expect.objectContaining( {
				shipping_tier: 'flat_rate:1',
				value: 50,
			} )
		);
		expect( payment[ 0 ][ 2 ] ).toEqual(
			expect.objectContaining( { payment_type: 'cod', value: 50 } )
		);
	} );

	it( 'does not fire the same step twice across repeated step changes', () => {
		document.body.className = '';
		document.body.innerHTML = CHECKOUT_DOM;
		setGlobals();
		window.gtm4wp_checkoutwc = 1;

		boot();
		dispatchStep( 'shipping' ); // add_shipping_info
		dispatchStep( 'payment' ); // add_payment_info (shipping already fired)
		dispatchStep( 'payment' ); // nothing new

		expect( calls( 'add_shipping_info' ) ).toHaveLength( 1 );
		expect( calls( 'add_payment_info' ) ).toHaveLength( 1 );
	} );

	it( 'does not bind the CheckoutWC listener when the option is off', () => {
		document.body.className = '';
		document.body.innerHTML = CHECKOUT_DOM;
		setGlobals();
		window.gtm4wp_checkoutwc = 0; // opt-in OFF

		boot();
		dispatchStep( 'payment' );

		expect( calls( 'add_shipping_info' ) ).toHaveLength( 0 );
		expect( calls( 'add_payment_info' ) ).toHaveLength( 0 );
	} );
} );

// ---------------------------------------------------------------------------
// Branch/isolation coverage for the classic tracker's cart-page quantity change,
// remove-from-cart links, grouped/variable/disabled add_to_cart, view_item_list
// chunking and the QuickView / found_variation JSON.parse guards (test-review
// gaps T24/T25).
//
// TS-7 (T26, widened to the whole file by T33): the document-listener capture and
// detach now live in the file-level beforeEach/afterEach at the top, so no describe
// can leak a listener into the next one and every click-driven test below asserts
// EXACT counts.
// ---------------------------------------------------------------------------

// The bare globals every classic-tracker test needs (undeclared reads throw in
// strict mode); factored out because the new describes below all share them.
const applyWooGlobals = () => {
	global.gtm4wp_datalayer_name = 'dataLayer';
	global.gtm4wp_currency = 'EUR';
	global.gtm4wp_product_per_impression = 0;
	global.gtm4wp_clear_ecommerce = false;
	global.gtm4wp_console_log = false;
	global.gtm4wp_use_sku_instead = false;
	window.dataLayer = [];
	window.gtm4wp_datalayer_max_timeout = 0;
	window.google_tag_manager = { 'GTM-TEST': {} };

	global.gtm4wp_push_ecommerce = jest.fn();
	global.gtm4wp_read_from_json = (
		json,
		exclude = [ 'productlink', 'internal_id' ]
	) => {
		const parsed = JSON.parse( json );
		exclude.forEach( ( k ) => delete parsed[ k ] );
		return parsed;
	};
	global.gtm4wp_read_json_from_node = ( el, key, exclude = [] ) => {
		const raw = el && el.dataset && el.dataset[ key ];
		if ( ! raw ) {
			return false;
		}
		const parsed = JSON.parse( raw );
		exclude.forEach( ( k ) => delete parsed[ k ] );
		return parsed;
	};
};

const cleanupWooGlobals = () => {
	jest.useRealTimers();
	delete window.google_tag_manager;
	delete window.gtm4wp_datalayer_max_timeout;
};

// Loads the bundle and runs the deferred process_pages() that binds its listeners.
// The file-level beforeEach/afterEach above record and detach them, so the count of
// listeners a load added is readable from capturedDocListeners.
const bootWithCapture = () => {
	jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
	jest.runAllTimers(); // run the deferred process_pages(): binds the listeners
};

describe( 'gtm4wp-woocommerce cart page quantity change (T25)', () => {
	const CART_PRODUCT = { item_id: 42, item_name: 'Cart Product', price: 10 };

	// A classic cart row: the qty input's HTML value attribute is the baseline
	// (defaultValue) the handler compares against; the .remove cell carries the
	// product JSON.
	const cartFixture = ( defaultQty ) =>
		'<form class="woocommerce-cart-form"><table><tbody>' +
		'<tr class="cart_item">' +
		'<td class="product-remove">' +
		'<a href="#" class="remove" data-gtm4wp_product_data=\'' +
		JSON.stringify( CART_PRODUCT ) +
		"'>x</a></td>" +
		'<td class="product-quantity">' +
		'<input type="number" class="qty" value="' +
		defaultQty +
		'" /></td>' +
		'</tr>' +
		'</tbody></table>' +
		// type=button (not submit) so jsdom does not attempt a real form
		// submission (requestSubmit is unimplemented); the cart handler fires on
		// the click regardless of the button type.
		'<button type="button" name="update_cart">Update cart</button>' +
		'</form>';

	beforeEach( () => {
		document.body.className = 'woocommerce-cart'; // binds the cart listeners
		applyWooGlobals();
		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );
		jest.useFakeTimers();
	} );

	afterEach( cleanupWooGlobals );

	// Boot, change the visible quantity, then click "Update cart".
	const clickUpdateCart = ( newQty ) => {
		bootWithCapture();
		global.gtm4wp_push_ecommerce.mockClear();

		document.querySelector( 'input.qty' ).value = String( newQty );
		document
			.querySelector( '[name=update_cart]' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		return global.gtm4wp_push_ecommerce.mock.calls;
	};

	it( 'fires a single add_to_cart with the net delta on a quantity increase', () => {
		document.body.innerHTML = cartFixture( 2 ); // baseline qty 2
		const calls = clickUpdateCart( 5 ); // raise to 5 => +3

		const adds = calls.filter( ( c ) => c[ 0 ] === 'add_to_cart' );
		expect( adds ).toHaveLength( 1 );
		expect( adds[ 0 ][ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 42, quantity: 3 } )
		);
		// value = price (10) * net delta (3).
		expect( adds[ 0 ][ 2 ] ).toEqual(
			expect.objectContaining( { currency: 'EUR', value: 30 } )
		);
		expect(
			calls.filter( ( c ) => c[ 0 ] === 'remove_from_cart' )
		).toHaveLength( 0 );
	} );

	it( 'fires a single remove_from_cart with the net delta on a quantity decrease', () => {
		document.body.innerHTML = cartFixture( 5 ); // baseline qty 5
		const calls = clickUpdateCart( 2 ); // lower to 2 => -3

		const removes = calls.filter( ( c ) => c[ 0 ] === 'remove_from_cart' );
		expect( removes ).toHaveLength( 1 );
		expect( removes[ 0 ][ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 42, quantity: 3 } )
		);
		// value = price (10) * net delta (3).
		expect( removes[ 0 ][ 2 ] ).toEqual(
			expect.objectContaining( { value: 30 } )
		);
		expect(
			calls.filter( ( c ) => c[ 0 ] === 'add_to_cart' )
		).toHaveLength( 0 );
	} );
} );

describe( 'gtm4wp-woocommerce remove-from-cart links (T25)', () => {
	const REMOVE_PRODUCT = { item_id: 55, item_name: 'Removed', price: 8 };

	beforeEach( () => {
		document.body.className = '';
		applyWooGlobals();
		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );
		jest.useFakeTimers();
	} );

	afterEach( cleanupWooGlobals );

	// The remove-link path lives inside the always-bound delegated click listener.
	// With the file-level detach in place there is exactly one live copy of it, so
	// these assert the exact number of pushes - a leaked second copy would double
	// every count and fail here rather than pass quietly (T33).
	const clickRemove = () => {
		bootWithCapture();
		global.gtm4wp_push_ecommerce.mockClear();
		document
			.querySelector( 'a.remove' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		return global.gtm4wp_push_ecommerce.mock.calls;
	};

	it( 'reads the removed quantity from the cart-row qty input', () => {
		document.body.innerHTML =
			'<table><tbody><tr class="cart_item">' +
			'<td class="product-remove">' +
			'<a href="#" class="remove" data-gtm4wp_product_data=\'' +
			JSON.stringify( REMOVE_PRODUCT ) +
			"'>x</a></td>" +
			'<td class="product-quantity">' +
			'<input type="number" class="qty" value="3" /></td>' +
			'</tr></tbody></table>';

		const removes = clickRemove().filter(
			( c ) => c[ 0 ] === 'remove_from_cart'
		);
		expect( removes ).toHaveLength( 1 );
		const call = removes[ 0 ];
		// qty parsed from the input value (3, as a number - #79); value = price (8) * 3.
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 55, quantity: 3 } )
		);
		expect( call[ 2 ] ).toEqual(
			expect.objectContaining( { currency: 'EUR', value: 24 } )
		);
	} );

	it( 'reads the removed quantity from the mini-cart .quantity text', () => {
		document.body.innerHTML =
			'<ul><li class="mini_cart_item">' +
			'<a href="#" class="remove" data-gtm4wp_product_data=\'' +
			JSON.stringify( REMOVE_PRODUCT ) +
			"'>x</a>" +
			'<span class="quantity">2 &times; $8.00</span>' +
			'</li></ul>';

		const removes = clickRemove().filter(
			( c ) => c[ 0 ] === 'remove_from_cart'
		);
		expect( removes ).toHaveLength( 1 );
		const call = removes[ 0 ];
		// parseInt( '2 × $8.00' ) === 2; value = price (8) * 2.
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 55, quantity: 2 } )
		);
		expect( call[ 2 ] ).toEqual( expect.objectContaining( { value: 16 } ) );
	} );

	it( 'does not fire remove_from_cart when the resolved quantity is zero', () => {
		document.body.innerHTML =
			'<ul><li class="mini_cart_item">' +
			'<a href="#" class="remove" data-gtm4wp_product_data=\'' +
			JSON.stringify( REMOVE_PRODUCT ) +
			"'>x</a>" +
			'<span class="quantity">0</span>' +
			'</li></ul>';

		expect(
			clickRemove().filter( ( c ) => c[ 0 ] === 'remove_from_cart' )
		).toHaveLength( 0 );
	} );

	it( 'does not fire remove_from_cart when a cart-row qty input is zero', () => {
		// The mini-cart case above always passed: parseInt gave a real 0 and the
		// strict `0 === qty` guard matched. The cart page handed that guard the
		// input's string '0', which never matched, so a zero-quantity line fired
		// an event there while the mini-cart suppressed it - the two surfaces
		// disagreed on whether this is an event at all (#79). The sibling test
		// existed for the working surface only, which is why nothing caught it.
		document.body.innerHTML =
			'<table><tbody><tr class="cart_item">' +
			'<td class="product-remove">' +
			'<a href="#" class="remove" data-gtm4wp_product_data=\'' +
			JSON.stringify( REMOVE_PRODUCT ) +
			"'>x</a></td>" +
			'<td class="product-quantity">' +
			'<input type="number" class="qty" value="0" /></td>' +
			'</tr></tbody></table>';

		expect(
			clickRemove().filter( ( c ) => c[ 0 ] === 'remove_from_cart' )
		).toHaveLength( 0 );
	} );

	it( 'binds no further listeners when the bundle is loaded twice (#71)', () => {
		// A re-injected bundle (AJAX navigation, a page builder duplicating the
		// handle) used to attach every document listener a second time and
		// double-push each ecommerce event.
		//
		// Both halves are asserted now (T33): the second load adds no listener,
		// AND one click still produces exactly one push. The push half used to be
		// impossible in this file because leaked listeners from earlier describes
		// made counts meaningless - which is precisely the symptom #71 names,
		// recorded as a harness quirk instead of being asserted.
		document.body.innerHTML =
			'<table><tbody><tr class="cart_item">' +
			'<td class="product-remove">' +
			'<a href="#" class="remove" data-gtm4wp_product_data=\'' +
			JSON.stringify( REMOVE_PRODUCT ) +
			"'>x</a></td>" +
			'<td class="product-quantity">' +
			'<input type="number" class="qty" value="1" /></td>' +
			'</tr></tbody></table>';

		const before = capturedDocListeners.length;
		bootWithCapture();
		const afterFirst = capturedDocListeners.length;
		expect( afterFirst ).toBeGreaterThan( before );

		// Second load with the guard flag left in place, exactly as the second copy
		// of the bundle would find it.
		bootWithCapture();
		expect( capturedDocListeners ).toHaveLength( afterFirst );

		global.gtm4wp_push_ecommerce.mockClear();
		document
			.querySelector( 'a.remove' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		expect(
			global.gtm4wp_push_ecommerce.mock.calls.filter(
				( c ) => c[ 0 ] === 'remove_from_cart'
			)
		).toHaveLength( 1 );
	} );

	it( 'keeps the de-dupe state a second copy of the bundle would have reset (#82)', () => {
		// #71's guard lives INSIDE process_pages(), so it protects nothing written
		// above it. The module body's state initializers used to run unconditionally
		// on every re-injection, and the first copy's still-attached listeners then
		// read a freshly emptied gtm4wp_checkout_step_fired - re-firing
		// add_payment_info / add_shipping_info and resetting the page-load view_item
		// suppression, which is the double-push #71 exists to prevent, by another
		// route. Asserting the state directly rather than the pushes: the listener
		// leak documented above makes push counts unreliable in this file.
		document.body.innerHTML = '';
		delete window.gtm4wp_checkout_step_fired;
		delete window.gtm4wp_view_item_fired_during_pageload;
		delete window.gtm4wp_first_container_id;

		bootWithCapture();
		expect( window.gtm4wp_checkout_step_fired ).toEqual( [] );

		// State the first copy's listeners have accumulated by now.
		window.gtm4wp_checkout_step_fired.push( 'payment_method' );
		window.gtm4wp_view_item_fired_during_pageload = true;
		window.gtm4wp_first_container_id = 0;

		// The re-injected copy: its module body re-runs in full.
		bootWithCapture();

		expect( window.gtm4wp_checkout_step_fired ).toEqual( [
			'payment_method',
		] );
		expect( window.gtm4wp_view_item_fired_during_pageload ).toBe( true );
		// 0 is a legitimate container index (it is a loop counter), so a
		// `x = x || ''` initializer would wrongly reset it - hence the typeof test.
		expect( window.gtm4wp_first_container_id ).toBe( 0 );
	} );
} );

describe( 'gtm4wp-woocommerce single add_to_cart branches (T24)', () => {
	beforeEach( () => {
		document.body.className = '';
		applyWooGlobals();
		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );
		jest.useFakeTimers();
	} );

	afterEach( cleanupWooGlobals );

	it( 'sums the grouped-product quantities into one add_to_cart', () => {
		document.body.innerHTML =
			'<form class="cart grouped_form" action="https://shop/grouped">' +
			'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( {
				item_id: 1,
				price: 10,
				internal_id: 1,
				productlink: 'https://shop/p1',
			} ) +
			"'></span>" +
			'<input type="number" name="quantity[1]" value="2" />' +
			'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
			JSON.stringify( {
				item_id: 2,
				price: 5,
				internal_id: 2,
				productlink: 'https://shop/p2',
			} ) +
			"'></span>" +
			'<input type="number" name="quantity[2]" value="3" />' +
			'<button type="button" class="single_add_to_cart_button">Add</button>' +
			'</form>';

		bootWithCapture();
		global.gtm4wp_push_ecommerce.mockClear();

		// Call the exposed tracker directly (no click) so exactly one push happens,
		// independent of any leaked delegated listener.
		const tracked = window.gtm4wp_track_single_add_to_cart(
			document.querySelector( '.single_add_to_cart_button' ),
			document.querySelector( 'form.cart' )
		);

		expect( tracked ).toBe( true );
		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ] ).toHaveLength( 2 );
		// 2 * 10 + 3 * 5 = 35, formatted to two decimals by the source.
		expect( call[ 2 ].value ).toBe( '35.00' );
		// internal_id is stripped from every grouped item before the push.
		expect( call[ 1 ][ 0 ] ).not.toHaveProperty( 'internal_id' );
		expect( call[ 1 ][ 1 ] ).not.toHaveProperty( 'internal_id' );
	} );

	it( 'falls back to quantity 1 when the product form has no quantity field', () => {
		// #69: the guard was `isNaN( quantity )` applied AFTER a lookup that
		// short-circuits to null when the field is absent - and isNaN( null ) is
		// false, because Number( null ) is 0. So the fallback never ran and the
		// event carried quantity: null with value: 0. Some themes and product
		// add-on plugins render no quantity input at all.
		document.body.innerHTML =
			'<form class="cart" action="https://shop/p">' +
			'<input type="hidden" name="gtm4wp_product_data" value=\'' +
			JSON.stringify( { item_id: 9, price: 3 } ) +
			"' />" +
			'<button type="submit" class="single_add_to_cart_button">Add</button>' +
			'</form>';

		bootWithCapture();
		global.gtm4wp_push_ecommerce.mockClear();

		window.gtm4wp_track_single_add_to_cart(
			document.querySelector( '.single_add_to_cart_button' ),
			document.querySelector( 'form.cart' )
		);

		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ][ 0 ].quantity ).toBe( 1 );
		expect( call[ 1 ][ 0 ].quantity ).not.toBeNull();
		// value must follow the quantity, not collapse to 0.
		expect( call[ 2 ] ).toEqual( expect.objectContaining( { value: 3 } ) );
	} );

	it( 'does not fire add_to_cart on a disabled add-to-cart button', () => {
		document.body.innerHTML =
			'<form class="cart" action="https://shop/p">' +
			'<input type="hidden" name="gtm4wp_product_data" value=\'' +
			JSON.stringify( { item_id: 9, price: 3 } ) +
			"' />" +
			'<input type="number" name="quantity" value="1" />' +
			'<button type="button" class="single_add_to_cart_button disabled">Add</button>' +
			'</form>';

		bootWithCapture();
		global.gtm4wp_push_ecommerce.mockClear();

		const tracked = window.gtm4wp_track_single_add_to_cart(
			document.querySelector( '.single_add_to_cart_button' ),
			document.querySelector( 'form.cart' )
		);

		expect( tracked ).toBe( false );
		expect(
			global.gtm4wp_push_ecommerce.mock.calls.find(
				( c ) => c[ 0 ] === 'add_to_cart'
			)
		).toBeUndefined();
	} );

	it( 'fires add_to_cart for the last selected variation (variable product)', () => {
		global.gtm4wp_make_sure_is_float = ( v ) => parseFloat( v ) || 0;
		global.gtm4wp_remarketing_prod_id_prefix = '';

		// Capture found_variation, which sets the module-scoped last-selected
		// variation the variable add_to_cart branch reads.
		const handlers = {};
		const jq = {
			on: ( ...args ) => {
				const evt = args[ 0 ];
				const fn = args[ args.length - 1 ];
				if ( typeof fn === 'function' ) {
					handlers[ evt ] = fn;
				}
				return jq;
			},
			trigger: () => jq,
			ajaxSuccess: () => jq,
		};
		global.jQuery = jest.fn( () => jq );

		document.body.innerHTML =
			'<form class="cart variations_form" action="https://shop/var">' +
			'<input type="hidden" name="gtm4wp_product_data" value=\'' +
			JSON.stringify( {
				item_id: 10,
				id: 10,
				item_name: 'Var',
				price: 5,
				internal_id: 10,
			} ) +
			"' />" +
			'<input type="hidden" name="variation_id" value="456" />' +
			'<input type="number" name="quantity" value="2" />' +
			'<button type="button" class="single_add_to_cart_button">Add</button>' +
			'</form>';

		bootWithCapture();

		handlers.found_variation(
			{ target: document.querySelector( 'form' ) },
			{
				variation_id: 456,
				sku: 'VAR-1',
				display_price: 9.99,
				attributes: { attribute_pa_color: 'blue' },
			}
		);
		global.gtm4wp_push_ecommerce.mockClear();

		const tracked = window.gtm4wp_track_single_add_to_cart(
			document.querySelector( '.single_add_to_cart_button' ),
			document.querySelector( 'form.cart' )
		);

		expect( tracked ).toBe( true );
		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		// The variation object (item_id = variation id) is added with the chosen
		// quantity from the form.
		expect( call[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 456, quantity: 2 } )
		);
		// value = variation price (9.99) * quantity (2), two decimals.
		expect( call[ 2 ].value ).toBe( '19.98' );
	} );
} );

describe( 'gtm4wp-woocommerce view_item_list impression chunking (T24)', () => {
	beforeEach( () => {
		document.body.className = '';
		applyWooGlobals();
		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );
		jest.useFakeTimers();
	} );

	afterEach( cleanupWooGlobals );

	it( 'splits impressions into chunks of gtm4wp_product_per_impression', () => {
		global.gtm4wp_product_per_impression = 2;

		let spans = '';
		for ( let id = 1; id <= 5; id++ ) {
			spans +=
				'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
				JSON.stringify( { item_id: id, price: id } ) +
				"'></span>";
		}
		document.body.innerHTML = '<div class="products">' + spans + '</div>';

		bootWithCapture(); // process_pages() fires the impressions on load

		const lists = global.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === 'view_item_list'
		);
		// 5 products, chunk size 2 => 3 events of sizes 2, 2, 1.
		expect( lists ).toHaveLength( 3 );
		expect( lists.map( ( c ) => c[ 1 ].length ) ).toEqual( [ 2, 2, 1 ] );
	} );
} );

describe( 'gtm4wp-woocommerce QuickView & found_variation JSON.parse guards (T24)', () => {
	let handlers;
	let ajaxSuccessCb;
	let errorSpy;

	beforeEach( () => {
		document.body.className = '';
		document.body.innerHTML = '';
		applyWooGlobals();
		global.gtm4wp_make_sure_is_float = ( v ) => parseFloat( v ) || 0;

		handlers = {};
		ajaxSuccessCb = undefined;
		const jq = {
			on: ( ...args ) => {
				const evt = args[ 0 ];
				const fn = args[ args.length - 1 ];
				if ( typeof fn === 'function' ) {
					handlers[ evt ] = fn;
				}
				return jq;
			},
			trigger: () => jq,
			ajaxSuccess: ( fn ) => {
				ajaxSuccessCb = fn;
				return jq;
			},
		};
		global.jQuery = jest.fn( () => jq );

		errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		jest.useFakeTimers();
	} );

	afterEach( () => {
		errorSpy.mockRestore();
		cleanupWooGlobals();
	} );

	it( 'pushes the QuickView dataLayer object on a valid response', () => {
		bootWithCapture();

		const el = document.createElement( 'div' );
		el.id = 'gtm4wp_quickview_data';
		el.dataset.gtm4wp_datalayer = JSON.stringify( {
			event: 'gtm4wp.quickview',
		} );
		document.body.appendChild( el );

		ajaxSuccessCb( {}, {}, { url: '/?wc-api=WC_Quick_View' } );
		jest.advanceTimersByTime( 500 ); // the handler reads the node after 500ms

		expect( window.dataLayer ).toContainEqual( {
			event: 'gtm4wp.quickview',
		} );
	} );

	describe( 'list attribution (#405)', () => {
		// Quick View builds its view_item on the server exactly like a product page
		// does, so it arrives with no idea which list the visitor clicked. Unlike the
		// product page this payload still carries internal_id, so the lookup key is
		// in the item itself and no wrapper argument is needed.
		beforeEach( () => {
			// TS-16: stubbed here rather than inherited, so these cases cannot pass
			// because some other file defined the helper first.
			global.gtm4wp_apply_stored_item_list = jest.fn( ( item, id ) => {
				if ( item && id === 42 && ! item.item_list_name ) {
					item.item_list_name = 'Summer Sale';
					item.item_list_id = 'summer-sale';
				}
				return item;
			} );
		} );

		afterEach( () => {
			delete window.gtm4wp_list_attribution;
			delete global.gtm4wp_apply_stored_item_list;
		} );

		const pushQuickView = ( payload ) => {
			bootWithCapture();

			const el = document.createElement( 'div' );
			el.id = 'gtm4wp_quickview_data';
			el.dataset.gtm4wp_datalayer = JSON.stringify( payload );
			document.body.appendChild( el );

			ajaxSuccessCb( {}, {}, { url: '/?wc-api=WC_Quick_View' } );
			jest.advanceTimersByTime( 500 );
		};

		const quickViewPayload = () => ( {
			event: 'view_item',
			ecommerce: {
				currency: 'EUR',
				value: 5,
				item: { item_id: 'SKU-42', internal_id: 42 },
			},
		} );

		it( 'enriches the Quick View item from the stored list', () => {
			window.gtm4wp_list_attribution = 1; // opt-in ON

			pushQuickView( quickViewPayload() );

			const pushed = window.dataLayer.find(
				( e ) => e.event === 'view_item'
			);
			expect( pushed.ecommerce.item ).toMatchObject( {
				item_list_name: 'Summer Sale',
				item_list_id: 'summer-sale',
			} );
		} );

		it( 'leaves the Quick View item alone with the option off', () => {
			window.gtm4wp_list_attribution = 0; // opt-in OFF

			pushQuickView( quickViewPayload() );

			const pushed = window.dataLayer.find(
				( e ) => e.event === 'view_item'
			);
			expect( pushed.ecommerce.item.item_list_name ).toBeUndefined();
			expect(
				global.gtm4wp_apply_stored_item_list
			).not.toHaveBeenCalled();
		} );

		it( 'still pushes a Quick View payload that carries no item', () => {
			// A variable or grouped product gets no ecommerce block at all here, and
			// the enrichment must not stand between that payload and the data layer.
			window.gtm4wp_list_attribution = 1;

			pushQuickView( { event: 'view_item', productType: 'variable' } );

			expect( window.dataLayer ).toContainEqual( {
				event: 'view_item',
				productType: 'variable',
			} );
		} );
	} );

	it( 'swallows a malformed QuickView payload without throwing or pushing', () => {
		bootWithCapture();

		const el = document.createElement( 'div' );
		el.id = 'gtm4wp_quickview_data';
		el.dataset.gtm4wp_datalayer = '{ not valid json';
		document.body.appendChild( el );

		expect( () => {
			ajaxSuccessCb( {}, {}, { url: '/?wc-api=WC_Quick_View' } );
			jest.advanceTimersByTime( 500 );
		} ).not.toThrow();

		expect( window.dataLayer ).toHaveLength( 0 );
		expect( errorSpy ).toHaveBeenCalled();
	} );

	it( 'swallows a malformed found_variation product payload', () => {
		document.body.innerHTML =
			'<form class="cart variations_form">' +
			'<input type="hidden" name="gtm4wp_product_data" value="{ not valid json" />' +
			'</form>';

		bootWithCapture();

		expect( () =>
			handlers.found_variation(
				{ target: document.querySelector( 'form' ) },
				{ variation_id: 1, attributes: {} }
			)
		).not.toThrow();

		expect(
			global.gtm4wp_push_ecommerce.mock.calls.find(
				( c ) => c[ 0 ] === 'view_item'
			)
		).toBeUndefined();
		expect( errorSpy ).toHaveBeenCalled();
	} );
} );

// ---------------------------------------------------------------------------
// Legacy WooCommerce product grid blocks (Handpicked Products, Newest Products,
// Products by Tag, ...).
//
// WooCommerce fires woocommerce_blocks_product_grid_item_html with no block
// context, so ListTracking::add_productdata_to_wc_block() cannot tell one grid
// from another and writes a generic placeholder pair. The real list identity
// lives in the wp-block-{block_name} class on the grid container and is resolved
// in the browser - which is why BOTH halves of the pair have to be written back:
// an item_list_name that no longer matches its item_list_id collapses every grid
// on the page onto one id in GA4.
//
// The rewrite loop had no test at all, which is how the missing item_list_id
// shipped (test-review TS-6).
// ---------------------------------------------------------------------------
describe( 'gtm4wp-woocommerce legacy product grid block list identity', () => {
	// Exactly what the server emits for a legacy grid item: the empty list type
	// falls back to "General Product List" and ProductData derives the id from it.
	// Seeding the REAL placeholder pair is what makes a stale id observable - a
	// fixture without it would pass whether or not the id is rewritten (TS-1).
	const serverItem = ( id, name ) => ( {
		item_id: id,
		item_name: name,
		price: 5,
		productlink: 'https://shop/p' + id,
		internal_id: id,
		item_list_name: 'General Product List',
		item_list_id: 'general-product-list',
	} );

	// Mirrors WC's AbstractProductGrid markup. Three structural details the
	// rewrite loop depends on: the product item is a descendant of .wc-block-grid,
	// the wp-block-{name} class sits on the SAME element as wc-block-grid (the
	// loop reads the classList of the .wc-block-grid ancestor), and the data span
	// is inside the <li>.
	const gridBlock = ( blockClass, items ) =>
		'<div class="wc-block-grid ' +
		blockClass +
		' has-3-columns">' +
		'<ul class="wc-block-grid__products">' +
		items
			.map(
				( item ) =>
					'<li class="wc-block-grid__product">' +
					'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
					JSON.stringify( item ) +
					"'></span>" +
					'<a class="wc-block-grid__product-link" href="' +
					item.productlink +
					'">' +
					item.item_name +
					'</a></li>'
			)
			.join( '' ) +
		'</ul></div>';

	const pushedItems = () => {
		const call = global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'view_item_list'
		);
		expect( call ).toBeDefined();
		return call[ 1 ];
	};

	beforeEach( () => {
		document.body.className = '';
		applyWooGlobals();

		// TS-13/UC-3: the double has to round-trip through the SAME dataset
		// attribute the impression loop reads back twenty lines later, exactly as
		// the real helper in gtm4wp-ecommerce-generic.js does. A jest.fn()
		// recorder here would leave the suite green while nothing is ever written
		// to the DOM - i.e. green *because* the behavior under test is untested.
		// Deliberately less capable in one respect: the real helper also floats
		// parsed_json.price, which is not the dimension under test.
		global.gtm4wp_update_json_in_node = ( el, key, new_key, new_value ) => {
			if ( ! el || ! el.dataset || ! el.dataset[ key ] ) {
				return false;
			}
			const parsed = JSON.parse( el.dataset[ key ] );
			parsed[ new_key ] = new_value;
			el.dataset[ key ] = JSON.stringify( parsed );
			return true;
		};

		// The shared helper above defaults its exclude list to [], which is more
		// permissive than the real signature; use the faithful default here so
		// productlink / internal_id do not reach the pushed items (TS-13).
		global.gtm4wp_read_json_from_node = (
			el,
			key,
			exclude = [ 'productlink', 'internal_id' ]
		) => {
			const raw = el && el.dataset && el.dataset[ key ];
			if ( ! raw ) {
				return false;
			}
			const parsed = JSON.parse( raw );
			exclude.forEach( ( k ) => delete parsed[ k ] );
			return parsed;
		};

		global.gtm4wp_store_item_list_attribution = jest.fn();

		const jq = { on: () => jq, trigger: () => jq, ajaxSuccess: () => jq };
		global.jQuery = jest.fn( () => jq );
		jest.useFakeTimers();
	} );

	afterEach( () => {
		delete window.gtm4wp_list_attribution;
		cleanupWooGlobals();
	} );

	it( 'gives every grid block on the page its own item_list_id', () => {
		document.body.innerHTML =
			gridBlock( 'wp-block-product-new', [ serverItem( 1, 'Newest' ) ] ) +
			gridBlock( 'wp-block-handpicked-products', [
				serverItem( 2, 'Picked' ),
			] );

		bootWithCapture();

		const byId = Object.fromEntries(
			pushedItems().map( ( item ) => [ item.item_id, item ] )
		);

		expect( byId[ 1 ] ).toEqual(
			expect.objectContaining( {
				item_list_name: 'New Products',
				item_list_id: 'new-products',
			} )
		);
		expect( byId[ 2 ] ).toEqual(
			expect.objectContaining( {
				item_list_name: 'Handpicked Products',
				item_list_id: 'handpicked-products',
			} )
		);

		// The reported symptom: two grids must not collapse onto one id.
		expect( byId[ 1 ].item_list_id ).not.toBe( byId[ 2 ].item_list_id );
		// TS-2, the other direction: the server placeholder has to be GONE, not
		// merely accompanied by a corrected name.
		expect( byId[ 1 ].item_list_id ).not.toBe( 'general-product-list' );
		expect( byId[ 2 ].item_list_id ).not.toBe( 'general-product-list' );
	} );

	// The whole map, pinned as a literal table. This is what breaks when
	// WooCommerce renames a container class or when someone edits one half of a
	// name/id pair (upstream registry U99, UD-2: a comment is not a control).
	// Do not derive the id from the name here - a test that recomputes the
	// production formula asserts nothing.
	it.each( [
		[
			'wp-block-handpicked-products',
			'Handpicked Products',
			'handpicked-products',
		],
		[
			'wp-block-product-best-sellers',
			'Best Selling Products',
			'best-selling-products',
		],
		[
			'wp-block-product-category',
			'Product Category List',
			'product-category-list',
		],
		[ 'wp-block-product-new', 'New Products', 'new-products' ],
		[ 'wp-block-product-on-sale', 'Sale Products', 'sale-products' ],
		[
			'wp-block-products-by-attribute',
			'Products By Attribute',
			'products-by-attribute',
		],
		[ 'wp-block-product-tag', 'Products By Tag', 'products-by-tag' ],
		[
			'wp-block-product-top-rated',
			'Top Rated Products',
			'top-rated-products',
		],
	] )( 'reports %s as its own list name and id', ( blockClass, name, id ) => {
		document.body.innerHTML = gridBlock( blockClass, [
			serverItem( 7, 'Product' ),
		] );

		bootWithCapture();

		expect( pushedItems()[ 0 ] ).toEqual(
			expect.objectContaining( {
				item_list_name: name,
				item_list_id: id,
			} )
		);
	} );

	it( 'numbers the items inside one grid without splitting the list id', () => {
		document.body.innerHTML = gridBlock( 'wp-block-product-on-sale', [
			serverItem( 1, 'First' ),
			serverItem( 2, 'Second' ),
		] );

		bootWithCapture();

		const items = pushedItems();
		expect( items.map( ( item ) => item.index ) ).toEqual( [ 1, 2 ] );
		expect( items.map( ( item ) => item.item_list_id ) ).toEqual( [
			'sale-products',
			'sale-products',
		] );
	} );

	it( 'leaves an unknown grid block on the consistent server pair', () => {
		document.body.innerHTML = gridBlock( 'wp-block-product-brand-new', [
			serverItem( 1, 'Unknown' ),
		] );

		bootWithCapture();

		// Degrades to the generic list rather than to a name/id mismatch.
		expect( pushedItems()[ 0 ] ).toEqual(
			expect.objectContaining( {
				item_list_name: 'General Product List',
				item_list_id: 'general-product-list',
			} )
		);
	} );

	it( 'carries the grid list id into the list attribution cookie', () => {
		document.body.innerHTML = gridBlock( 'wp-block-product-new', [
			serverItem( 1, 'Newest' ),
		] );
		window.gtm4wp_list_attribution = 1; // opt-in ON

		bootWithCapture();

		document
			.querySelector( '.wc-block-grid__product-link' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		// Without the id rewrite this is called with 'general-product-list', and
		// the wrong id then rides the cookie into add_to_cart / checkout /
		// purchase - the part the store owner actually sees in GA4.
		expect(
			global.gtm4wp_store_item_list_attribution
		).toHaveBeenCalledWith( 1, 'New Products', 'new-products' );
	} );
} );
