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
