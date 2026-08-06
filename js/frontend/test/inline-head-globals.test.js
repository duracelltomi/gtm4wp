/**
 * Guards the one thing an ordinary jsdom test structurally cannot see: HOW the
 * trackers read the globals the PHP side prints into the page.
 *
 * ContainerCode::header_top() writes every GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY entry
 * as `const <name> = <value>;` inside a CLASSIC inline <script>. A top-level `const`
 * binds in the global LEXICAL environment record and is not a property of `window`,
 * so `window.<name>` is undefined on every real page - which is how #405's list
 * attribution, the select_item timeout option and the #385 CheckoutWC bridge all
 * shipped dead.
 *
 * Every other test here sets `window.<name>` and cannot tell the two apart: jest's
 * jsdom makes `global === window`, so a window property satisfies a bare read as
 * well. This file reproduces the real page instead. It appends a classic <script> to
 * the document - jest-environment-jsdom runs with runScripts: 'dangerously' and the
 * whole file shares that realm - creating genuine global lexical bindings, then
 * plants the OPPOSITE value on `window.<name>` as a decoy. Correct code sees the
 * lexical value; code that regresses to `window.<name>` sees the decoy and the case
 * fails.
 *
 * `let` rather than the production `const` only so the cases can flip the value: a
 * global lexical binding can be neither redeclared nor deleted. The binding KIND -
 * the thing under test - is identical.
 *
 * The mocked-state variables are mock-prefixed because Jest hoists the jest.mock()
 * factory above the file and only lets it reference such names.
 */

let mockSubscriber;
let mockCartData;

// @wordpress/data is externalized to window.wp.data by the production build and is
// not installed in node_modules, so the mock must be virtual (mirrors
// woocommerce-blocks-tracker.test.js).
jest.mock(
	'@wordpress/data',
	() => ( {
		select: ( name ) =>
			name === 'wc/store/cart'
				? {
						getCartData: () => mockCartData,
						hasFinishedResolution: () => true,
				  }
				: null,
		subscribe: ( cb ) => {
			mockSubscriber = cb;
		},
	} ),
	{ virtual: true }
);

// The inline head block, as a classic script. The setter is exposed on window on
// purpose: only code evaluated inside this script can assign to its own lexical
// bindings, so the cases need a door back in.
const INLINE_HEAD_BLOCK = [
	'let gtm4wp_list_attribution = 0;',
	'let gtm4wp_datalayer_max_timeout = 0;',
	'let gtm4wp_checkoutwc = 0;',
	'window.gtm4wp_test_set_lexical = function ( name, value ) {',
	'	switch ( name ) {',
	'		case "gtm4wp_list_attribution":',
	'			gtm4wp_list_attribution = value;',
	'			break;',
	'		case "gtm4wp_datalayer_max_timeout":',
	'			gtm4wp_datalayer_max_timeout = value;',
	'			break;',
	'		case "gtm4wp_checkoutwc":',
	'			gtm4wp_checkoutwc = value;',
	'			break;',
	'	}',
	'};',
].join( '\n' );

/**
 * Sets the lexical global (the real page shape) and plants a conflicting value on
 * the same-named window property, so a `window.<name>` read is guaranteed to
 * disagree with a correct one.
 *
 * @param {string} name  The global's name.
 * @param {*}      value The value a correct bare read must see.
 * @param {*}      decoy The value a regressed `window.<name>` read would see.
 */
function setLexicalGlobal( name, value, decoy ) {
	window.gtm4wp_test_set_lexical( name, value );
	window[ name ] = decoy;
}

const LEXICAL_GLOBALS = [
	'gtm4wp_list_attribution',
	'gtm4wp_datalayer_max_timeout',
	'gtm4wp_checkoutwc',
];

// Whether each name was an own property of window immediately after the inline
// script ran - captured here because the cases then plant decoys under those very
// names, which would make the same check meaningless later.
const wasWindowPropertyOnLoad = {};

beforeAll( () => {
	const el = document.createElement( 'script' );
	el.textContent = INLINE_HEAD_BLOCK;
	// jsdom executes an inline script synchronously on insertion.
	document.head.appendChild( el );

	LEXICAL_GLOBALS.forEach( ( name ) => {
		wasWindowPropertyOnLoad[ name ] = Object.prototype.hasOwnProperty.call(
			window,
			name
		);
	} );
} );

// The classic tracker binds delegated document listeners that jest.isolateModules
// never detaches, and the wp-scripts preset shares ONE jsdom document for the whole
// file - so a leaked copy would answer the next case's event. Capture and detach.
let capturedDocListeners = [];
let originalDocAdd = null;

beforeEach( () => {
	delete window.gtm4wp_woocommerce_inited;
	delete window.gtm4wp_woocommerce_blocks_inited;
	delete window.gtm4wp_checkout_step_fired;
	delete window.gtm4wp_view_item_fired_during_pageload;
	delete window.gtm4wp_first_container_id;

	// Baseline: every flag off, and every decoy the OPPOSITE, so a case that forgets
	// to set one still cannot pass by reading window.
	setLexicalGlobal( 'gtm4wp_list_attribution', 0, 1 );
	setLexicalGlobal( 'gtm4wp_datalayer_max_timeout', 0, 5000 );
	setLexicalGlobal( 'gtm4wp_checkoutwc', 0, 1 );

	global.gtm4wp_datalayer_name = 'dataLayer';
	global.gtm4wp_currency = 'EUR';
	global.gtm4wp_product_per_impression = 0;
	global.gtm4wp_clear_ecommerce = false;
	global.gtm4wp_console_log = false;
	global.gtm4wp_use_sku_instead = false;
	window.dataLayer = [];
	window.google_tag_manager = { 'GTM-TEST': {} };

	global.gtm4wp_push_ecommerce = jest.fn();
	window.gtm4wp_push_ecommerce = global.gtm4wp_push_ecommerce;
	global.gtm4wp_store_item_list_attribution = jest.fn();
	window.gtm4wp_store_item_list_attribution =
		global.gtm4wp_store_item_list_attribution;
	global.gtm4wp_apply_stored_item_list = jest.fn();
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

	mockSubscriber = null;
	mockCartData = { items: [], totals: { currency_code: 'EUR' } };
	window.gtm4wp_blocks_context = undefined;

	document.body.className = '';
	document.body.innerHTML = '';

	capturedDocListeners = [];
	originalDocAdd = document.addEventListener;
	document.addEventListener = function ( type, fn, opts ) {
		capturedDocListeners.push( { type, fn, opts } );
		return originalDocAdd.call( this, type, fn, opts );
	};

	jest.useFakeTimers();
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

	jest.useRealTimers();
	delete window.google_tag_manager;
} );

const LIST_PRODUCT = {
	item_id: 42,
	item_name: 'PC Product',
	price: 5,
	productlink: 'https://shop/p42',
	internal_id: 42,
	item_list_name: 'Summer Sale',
	item_list_id: 'summer-sale',
};

const LIST_DOM =
	'<ul class="products"><li class="product">' +
	'<span class="gtm4wp_productdata" data-gtm4wp_product_data=\'' +
	JSON.stringify( LIST_PRODUCT ) +
	"'></span>" +
	'<a href="https://shop/p42">PC Product</a>' +
	'</li></ul>';

const bootClassic = () => {
	jest.isolateModules( () => require( '../gtm4wp-woocommerce' ) );
	jest.runAllTimers();
	global.gtm4wp_push_ecommerce.mockClear();
};

const clickProductLink = () =>
	document
		.querySelector( 'a' )
		.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );

describe( 'the harness reproduces the real page', () => {
	// If these ever become window properties, every case below silently stops
	// discriminating and this whole file is worthless - so assert it outright
	// rather than trusting the mechanism.
	it.each( LEXICAL_GLOBALS )(
		'%s is a lexical global, not a window property',
		( name ) => {
			expect( wasWindowPropertyOnLoad[ name ] ).toBe( false );
		}
	);
} );

describe( 'gtm4wp_list_attribution is read as a lexical global, not off window', () => {
	it( 'stores the attribution when the lexical flag is on and window says off', () => {
		document.body.innerHTML = LIST_DOM;
		setLexicalGlobal( 'gtm4wp_list_attribution', 1, 0 );

		bootClassic();
		clickProductLink();

		expect(
			global.gtm4wp_store_item_list_attribution
		).toHaveBeenCalledWith( 42, 'Summer Sale', 'summer-sale' );
	} );

	it( 'stores nothing when the lexical flag is off and window says on', () => {
		document.body.innerHTML = LIST_DOM;
		setLexicalGlobal( 'gtm4wp_list_attribution', 0, 1 );

		bootClassic();
		clickProductLink();

		expect(
			global.gtm4wp_store_item_list_attribution
		).not.toHaveBeenCalled();
	} );

	it( 'reaches the blocks cross-sell tracker too', () => {
		setLexicalGlobal( 'gtm4wp_list_attribution', 1, 0 );
		mockCartData = {
			items: [],
			totals: { currency_code: 'EUR' },
			crossSells: [
				{
					id: 9,
					permalink: 'https://shop/p9',
					extensions: { gtm4wp: { item: { item_id: 9, price: 4 } } },
				},
			],
		};

		jest.isolateModules( () => require( '../gtm4wp-woocommerce-blocks' ) );
		mockSubscriber(); // fires view_item_list and resolves the click closure

		document.body.innerHTML =
			'<div class="wp-block-woocommerce-cart-cross-sells-block">' +
			'<a href="https://shop/p9">Buy</a></div>';
		clickProductLink();

		expect(
			window.gtm4wp_store_item_list_attribution
		).toHaveBeenCalledWith( 9, 'Cross-Sells', 'cross-sells' );
	} );
} );

describe( 'gtm4wp_datalayer_max_timeout is read as a lexical global, not off window', () => {
	// timeout > 0 takes the eventCallback branch: 5 args and the click is held back.
	// timeout === 0 takes the no-redirect branch: 3 args and the link opens at once.
	const selectItemCall = () =>
		global.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'select_item'
		);

	it( 'uses the configured timeout when window carries 0', () => {
		document.body.innerHTML = LIST_DOM;
		setLexicalGlobal( 'gtm4wp_datalayer_max_timeout', 500, 0 );

		bootClassic();
		const link = document.querySelector( 'a' );
		const event = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
		} );
		link.dispatchEvent( event );

		const call = selectItemCall();
		expect( call ).toBeDefined();
		expect( call ).toHaveLength( 5 );
		expect( call[ 4 ] ).toBe( 500 );
		expect( event.defaultPrevented ).toBe( true );
	} );

	it( 'honors a configured 0 when window carries a large value', () => {
		document.body.innerHTML = LIST_DOM;
		setLexicalGlobal( 'gtm4wp_datalayer_max_timeout', 0, 5000 );

		bootClassic();
		const link = document.querySelector( 'a' );
		const event = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
		} );
		link.dispatchEvent( event );

		const call = selectItemCall();
		expect( call ).toBeDefined();
		expect( call ).toHaveLength( 3 );
		expect( event.defaultPrevented ).toBe( false );
	} );
} );

describe( 'gtm4wp_checkoutwc is read as a lexical global, not off window', () => {
	// A CheckoutWC checkout page: no body.woocommerce-checkout, but the standard
	// WooCommerce shipping/payment inputs (which CheckoutWC keeps).
	const CHECKOUT_DOM =
		'<input type="radio" name="shipping_method[0]" value="flat_rate:1" checked />' +
		'<div class="payment_methods">' +
		'<input type="radio" name="payment_method" value="cod" checked />' +
		'</div>';

	const calls = ( event ) =>
		global.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === event
		);

	it( 'binds the cfw_step_changed bridge when window says off', () => {
		document.body.innerHTML = CHECKOUT_DOM;
		window.gtm4wp_checkout_products = [ { item_id: 1, price: 25 } ];
		window.gtm4wp_checkout_value = 50;
		setLexicalGlobal( 'gtm4wp_checkoutwc', 1, 0 );

		bootClassic();
		document.dispatchEvent(
			new window.CustomEvent( 'cfw_step_changed', {
				detail: { step: 'payment' },
			} )
		);

		expect( calls( 'add_shipping_info' ) ).toHaveLength( 1 );
		expect( calls( 'add_payment_info' ) ).toHaveLength( 1 );

		delete window.gtm4wp_checkout_products;
		delete window.gtm4wp_checkout_value;
	} );
} );
