/**
 * Unit tests for the WooCommerce block tracker
 * (js/frontend/gtm4wp-woocommerce-blocks.js).
 *
 * The WordPress data module (wp.data) is mocked with a controllable
 * select/subscribe so the cart and checkout store changes can be driven and the
 * resulting dataLayer events asserted (the TC-10 capture-and-drive recipe
 * applied to a data-store tracker).
 *
 * The mocked-state variables are mock-prefixed because Jest hoists the
 * jest.mock() factory above the file and only lets it reference such names.
 */

let mockSubscriber;
let mockHasCartStore;
let mockCartData;
let mockActivePaymentMethod;
let mockHasPaymentStore;
let mockHasFinishedResolution;
let mockSubscribeCount;

// @wordpress/data is externalized to window.wp.data by the production build and
// is not installed in node_modules, so the mock must be virtual.
jest.mock(
	'@wordpress/data',
	() => ( {
		select: ( name ) => {
			if ( name === 'wc/store/cart' && mockHasCartStore ) {
				return {
					getCartData: () => mockCartData,
					hasFinishedResolution: () => mockHasFinishedResolution,
				};
			}
			if ( name === 'wc/store/payment' && mockHasPaymentStore ) {
				return {
					getActivePaymentMethod: () => mockActivePaymentMethod,
				};
			}
			return null;
		},
		subscribe: ( cb ) => {
			mockSubscribeCount++;
			mockSubscriber = cb;
		},
	} ),
	{ virtual: true }
);

// TS-7/TS-14: loading the tracker registers a window listener for the cart sync
// event, and jest.isolateModules never detaches it - the wp-scripts jest preset
// shares ONE jsdom window across the whole file. Every leaked copy would answer
// the next test's sync event with its own stale baseline and push again, so the
// capture is file-wide, exactly as in the classic tracker's suite: record here,
// detach in afterEach, and every test can count exactly.
let capturedWindowListeners = [];
let originalWindowAdd = null;

beforeEach( () => {
	capturedWindowListeners = [];
	originalWindowAdd = window.addEventListener;
	window.addEventListener = function ( type, fn, opts ) {
		capturedWindowListeners.push( { type, fn, opts } );
		return originalWindowAdd.call( this, type, fn, opts );
	};
} );

afterEach( () => {
	if ( originalWindowAdd ) {
		window.addEventListener = originalWindowAdd;
		originalWindowAdd = null;
	}

	capturedWindowListeners.forEach( ( { type, fn, opts } ) =>
		window.removeEventListener( type, fn, opts )
	);
	capturedWindowListeners = [];
} );

/**
 * Builds a raw cart line carrying its GA4 item.
 *
 * @param {string} key      Cart-item key.
 * @param {number} quantity Line quantity.
 * @param {Object} item     GA4 item.
 * @return {Object} The raw cart line.
 */
function cartLine( key, quantity, item ) {
	return { key, quantity, extensions: { gtm4wp: { item } } };
}

/**
 * Loads the tracker fresh and returns the captured subscribe() callback.
 *
 * @return {Function} The subscriber.
 */
function loadTracker() {
	jest.isolateModules( () => require( '../gtm4wp-woocommerce-blocks' ) );
	return mockSubscriber;
}

describe( 'gtm4wp-woocommerce-blocks', () => {
	beforeEach( () => {
		mockSubscriber = null;
		mockHasCartStore = true;
		mockCartData = { items: [], totals: { currency_code: 'EUR' } };
		mockActivePaymentMethod = '';
		mockHasPaymentStore = false;
		mockHasFinishedResolution = true;
		mockSubscribeCount = 0;
		window.gtm4wp_woocommerce_blocks_inited = false;
		window.gtm4wp_blocks_context = undefined;
		window.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_currency = 'EUR';
		document.body.innerHTML = '';
	} );

	it( 'baselines the first snapshot without reporting existing items', () => {
		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 1, price: 5 } ) ],
			totals: { currency_code: 'EUR' },
		};
		const subscriber = loadTracker();

		subscriber();

		expect( window.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'fires add_to_cart with the delta and value when a line is added', () => {
		const subscriber = loadTracker();
		subscriber(); // baseline: empty cart

		mockCartData = {
			items: [ cartLine( 'A', 2, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		subscriber();

		expect( window.gtm4wp_push_ecommerce ).toHaveBeenCalledWith(
			'add_to_cart',
			[ expect.objectContaining( { item_id: 7, quantity: 2 } ) ],
			expect.objectContaining( { currency: 'EUR', value: 20 } )
		);
	} );

	it( 'fires remove_from_cart when a line quantity drops', () => {
		mockCartData = {
			items: [ cartLine( 'A', 3, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		const subscriber = loadTracker();
		subscriber(); // baseline: qty 3

		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		subscriber();

		expect( window.gtm4wp_push_ecommerce ).toHaveBeenCalledWith(
			'remove_from_cart',
			[ expect.objectContaining( { item_id: 7, quantity: 2 } ) ],
			expect.objectContaining( { value: 20 } )
		);
	} );

	it( 'does not fire again when the cart is unchanged', () => {
		const subscriber = loadTracker();
		subscriber(); // baseline

		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		subscriber(); // one add_to_cart
		window.gtm4wp_push_ecommerce.mockClear();

		subscriber(); // same cart -> nothing

		expect( window.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'legacy cartcheckout default: does not fire checkout events when the payment store is absent', () => {
		mockHasPaymentStore = false;
		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
			shippingRates: [
				{ shipping_rates: [ { name: 'Flat rate', selected: true } ] },
			],
		};
		const subscriber = loadTracker();
		subscriber();

		const events = window.gtm4wp_push_ecommerce.mock.calls.map(
			( call ) => call[ 0 ]
		);
		expect( events ).not.toContain( 'add_shipping_info' );
		expect( events ).not.toContain( 'add_payment_info' );
	} );

	it( 'legacy cartcheckout default: fires add_shipping_info and add_payment_info once when the payment store is present', () => {
		mockHasPaymentStore = true;
		mockActivePaymentMethod = 'stripe';
		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
			shippingRates: [
				{
					shipping_rates: [
						{ name: 'Free shipping', selected: true },
					],
				},
			],
		};
		const subscriber = loadTracker();
		subscriber();

		const calls = window.gtm4wp_push_ecommerce.mock.calls;
		const shipping = calls.find( ( c ) => c[ 0 ] === 'add_shipping_info' );
		const payment = calls.find( ( c ) => c[ 0 ] === 'add_payment_info' );

		expect( shipping ).toBeDefined();
		expect( shipping[ 2 ] ).toEqual(
			expect.objectContaining( { shipping_tier: 'Free shipping' } )
		);
		expect( payment ).toBeDefined();
		expect( payment[ 2 ] ).toEqual(
			expect.objectContaining( { payment_type: 'stripe' } )
		);

		// Fire again: neither step repeats.
		window.gtm4wp_push_ecommerce.mockClear();
		subscriber();
		const repeated = window.gtm4wp_push_ecommerce.mock.calls.map(
			( c ) => c[ 0 ]
		);
		expect( repeated ).not.toContain( 'add_shipping_info' );
		expect( repeated ).not.toContain( 'add_payment_info' );
	} );

	// #463 regression. WooCommerce registers wc/store/payment on the Cart block
	// page too, so the store's presence must not decide the checkout steps: the
	// old merged context did exactly that, and every Cart page view with a
	// preselected shipping rate pushed add_shipping_info (and add_payment_info)
	// with no interaction, then again on Checkout. The earlier cart-page test
	// mocked the store as ABSENT, which is why the suite never caught it (UC-3:
	// the double was more permissive than the real collaborator).
	it( 'cart context: does not fire checkout events even when the payment store is registered (#463)', () => {
		window.gtm4wp_blocks_context = 'cart';
		mockHasPaymentStore = true;
		mockActivePaymentMethod = 'stripe';
		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
			shippingRates: [
				{ shipping_rates: [ { name: 'Flat rate', selected: true } ] },
			],
		};
		const subscriber = loadTracker();
		subscriber();

		const events = window.gtm4wp_push_ecommerce.mock.calls.map(
			( call ) => call[ 0 ]
		);
		expect( events ).not.toContain( 'add_shipping_info' );
		expect( events ).not.toContain( 'add_payment_info' );
	} );

	// The positive half of the #463 split (TS-2 both directions): the same store
	// state that must stay silent on the Cart page fires both steps on Checkout.
	it( 'checkout context: fires add_shipping_info and add_payment_info', () => {
		window.gtm4wp_blocks_context = 'checkout';
		mockHasPaymentStore = true;
		mockActivePaymentMethod = 'stripe';
		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
			shippingRates: [
				{ shipping_rates: [ { name: 'Flat rate', selected: true } ] },
			],
		};
		const subscriber = loadTracker();
		subscriber();

		const events = window.gtm4wp_push_ecommerce.mock.calls.map(
			( call ) => call[ 0 ]
		);
		expect( events ).toContain( 'add_shipping_info' );
		expect( events ).toContain( 'add_payment_info' );
	} );

	// The cart context must keep owning the cart events after the split - losing
	// add_to_cart on the Cart page would be the silent cost of over-gating.
	it( 'cart context: still owns add_to_cart from the cart diff', () => {
		window.gtm4wp_blocks_context = 'cart';
		const subscriber = loadTracker();
		subscriber(); // baseline: empty cart

		mockCartData = {
			items: [ cartLine( 'A', 2, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		subscriber();

		expect( window.gtm4wp_push_ecommerce ).toHaveBeenCalledWith(
			'add_to_cart',
			[ expect.objectContaining( { item_id: 7, quantity: 2 } ) ],
			expect.objectContaining( { currency: 'EUR', value: 20 } )
		);
	} );

	it( 'in minicart context fires remove_from_cart but never add_to_cart', () => {
		window.gtm4wp_blocks_context = 'minicart';
		mockCartData = {
			items: [ cartLine( 'A', 3, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		const subscriber = loadTracker();
		subscriber(); // baseline: qty 3

		// Remove two units in the Mini-Cart drawer.
		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		subscriber();

		const events = window.gtm4wp_push_ecommerce.mock.calls.map(
			( call ) => call[ 0 ]
		);
		expect( events ).toContain( 'remove_from_cart' );
		expect( events ).not.toContain( 'add_to_cart' );
	} );

	it( 'in minicart context does not fire add_to_cart when a line is added (classic tracker owns adds)', () => {
		window.gtm4wp_blocks_context = 'minicart';
		const subscriber = loadTracker();
		subscriber(); // baseline: empty cart

		mockCartData = {
			items: [ cartLine( 'A', 2, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		subscriber();

		const events = window.gtm4wp_push_ecommerce.mock.calls.map(
			( call ) => call[ 0 ]
		);
		expect( events ).not.toContain( 'add_to_cart' );
	} );

	it( 'fires view_item_list once for cart cross-sells', () => {
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
		const subscriber = loadTracker();
		subscriber();

		const call = window.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'view_item_list'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ] ).toEqual( [
			expect.objectContaining( {
				item_id: 9,
				item_list_name: 'Cross-Sells',
				item_list_id: 'cross-sells',
			} ),
		] );

		// The list impression is not reported again on a later store change.
		window.gtm4wp_push_ecommerce.mockClear();
		subscriber();
		const repeated = window.gtm4wp_push_ecommerce.mock.calls.map(
			( c ) => c[ 0 ]
		);
		expect( repeated ).not.toContain( 'view_item_list' );
	} );

	it( 'strips internal_id from cross-sell view_item_list / select_item items', () => {
		mockCartData = {
			items: [],
			totals: { currency_code: 'EUR' },
			crossSells: [
				{
					id: 9,
					permalink: 'https://shop/p9',
					extensions: {
						gtm4wp: {
							item: { item_id: 9, price: 4, internal_id: 9 },
						},
					},
				},
			],
		};
		const subscriber = loadTracker();
		subscriber(); // fires view_item_list and resolves the click closure

		const view = window.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'view_item_list'
		);
		expect( view ).toBeDefined();
		// Both directions: the list identity is present AND the bookkeeping key is gone.
		expect( view[ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				item_id: 9,
				item_list_name: 'Cross-Sells',
			} )
		);
		expect( view[ 1 ][ 0 ] ).not.toHaveProperty( 'internal_id' );

		document.body.innerHTML =
			'<div class="wp-block-woocommerce-cart-cross-sells-block">' +
			'<a href="https://shop/p9">Buy</a></div>';
		document
			.querySelector( 'a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const select = window.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'select_item'
		);
		expect( select ).toBeDefined();
		expect( select[ 1 ][ 0 ] ).not.toHaveProperty( 'internal_id' );
	} );

	it( 'fires select_item when a cross-sell product link is clicked', () => {
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
		const subscriber = loadTracker();
		subscriber(); // resolves crossSells into the click-listener closure

		document.body.innerHTML =
			'<div class="wp-block-woocommerce-cart-cross-sells-block">' +
			'<a href="https://shop/p9">Buy</a></div>';
		document
			.querySelector( 'a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = window.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'select_item'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ] ).toEqual( [
			expect.objectContaining( {
				item_id: 9,
				item_list_name: 'Cross-Sells',
			} ),
		] );
	} );

	// UC-3 guard. The test above wraps the link in the cross-sells *container*
	// block, so it passes through the first selector and can never fail when the
	// per-product selector is wrong - which it was: we shipped
	// `.wc-block-cart-cross-sells-product`, a class no WooCommerce release emits
	// (checked 8.0.0 / 10.6.1 / 11.0.0; all emit `wp-block-...`). This case omits
	// the container so ONLY the per-product selector can match, which pins the
	// upstream class name rather than our own spelling of it.
	it( 'fires select_item for a cross-sell link matched by the per-product class alone', () => {
		mockCartData = {
			items: [],
			totals: { currency_code: 'EUR' },
			crossSells: [
				{
					id: 11,
					permalink: 'https://shop/p11',
					extensions: { gtm4wp: { item: { item_id: 11, price: 7 } } },
				},
			],
		};
		const subscriber = loadTracker();
		subscriber();

		// No `.wp-block-woocommerce-cart-cross-sells-block` ancestor on purpose.
		document.body.innerHTML =
			'<div class="wp-block-cart-cross-sells-product">' +
			'<a href="https://shop/p11">Buy</a></div>';
		document
			.querySelector( 'a' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);

		const call = window.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'select_item'
		);
		expect( call ).toBeDefined();
		expect( call[ 1 ] ).toEqual( [
			expect.objectContaining( {
				item_id: 11,
				item_list_name: 'Cross-Sells',
			} ),
		] );
	} );

	it( 'does not read the cart until getCartData has finished resolving', () => {
		const subscriber = loadTracker();
		subscriber(); // baseline: empty cart, resolution finished

		// The store is mid-resolution: the subscriber must bail before diffing.
		mockHasFinishedResolution = false;
		mockCartData = {
			items: [ cartLine( 'A', 2, { item_id: 7, price: 10 } ) ],
			totals: { currency_code: 'EUR' },
		};
		subscriber();
		expect( window.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();

		// Once resolution finishes, the very same change is reported - proving the
		// early return suppressed the push, not the baseline.
		mockHasFinishedResolution = true;
		subscriber();
		expect( window.gtm4wp_push_ecommerce ).toHaveBeenCalledWith(
			'add_to_cart',
			[ expect.objectContaining( { item_id: 7, quantity: 2 } ) ],
			expect.objectContaining( { value: 20 } )
		);
	} );

	it( 'falls back to the global currency when the cart totals omit it', () => {
		global.gtm4wp_currency = 'USD';
		mockCartData = { items: [], totals: {} }; // no currency_code
		const subscriber = loadTracker();
		subscriber(); // baseline

		mockCartData = {
			items: [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ],
			totals: {}, // still no currency_code
		};
		subscriber();

		const call = window.gtm4wp_push_ecommerce.mock.calls.find(
			( c ) => c[ 0 ] === 'add_to_cart'
		);
		expect( call ).toBeDefined();
		expect( call[ 2 ].currency ).toBe( 'USD' );
	} );

	it( 'subscribes only once when the bundle is initialized twice', () => {
		loadTracker(); // first init subscribes
		loadTracker(); // re-injected bundle: the guard blocks a second subscribe

		expect( mockSubscribeCount ).toBe( 1 );
	} );
} );

/**
 * A store whose blocks are built on the Interactivity API never registers the
 * wc/store/cart data store, so the subscribe path above has nothing to read and
 * such a store reported no cart events at all. The tracker then reads the cart
 * back from the Store API, announced by WooCommerce's own cart sync event, and
 * diffs it exactly as the data store path does.
 *
 * The window listener is captured and detached per test: jsdom keeps one window
 * for the whole file, so a leaked listener from an earlier load would answer the
 * next test's event and double the pushes (TS-7/TS-14).
 */
describe( 'gtm4wp-woocommerce-blocks Store API fallback', () => {
	const CART_URL = 'https://shop.example/wp-json/wc/store/v1/cart';

	/**
	 * Lets the fetch promise chain settle. The chain is fetch -> response.json
	 * -> handler, so a few microtask turns are enough and no timer is involved.
	 *
	 * @return {Promise<void>} Resolves once the chain has run.
	 */
	const settle = async () => {
		for ( let i = 0; i < 5; i++ ) {
			await Promise.resolve();
		}
	};

	/**
	 * Queues the cart responses the fallback will read, in order.
	 *
	 * @param {Array} carts The cart payloads to return, one per call.
	 * @return {void}
	 */
	const respondWith = ( carts ) => {
		let call = 0;
		window.fetch = jest.fn( () => {
			const cart = carts[ Math.min( call, carts.length - 1 ) ];
			call++;
			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( cart ),
			} );
		} );
	};

	const cartOf = ( items ) => ( {
		items,
		totals: { currency_code: 'EUR' },
	} );

	const syncCart = () =>
		window.dispatchEvent(
			new window.CustomEvent( 'wc-blocks_store_sync_required', {
				detail: { type: 'from_iAPI', quantityChanges: {} },
			} )
		);

	const pushedEvents = ( name ) =>
		window.gtm4wp_push_ecommerce.mock.calls.filter(
			( c ) => c[ 0 ] === name
		);

	beforeEach( () => {
		mockSubscriber = null;
		mockHasCartStore = false; // an Interactivity API store
		mockCartData = { items: [], totals: { currency_code: 'EUR' } };
		mockHasFinishedResolution = true;
		window.gtm4wp_woocommerce_blocks_inited = false;
		window.gtm4wp_blocks_context = 'minicart';
		window.gtm4wp_blocks_cart_url = CART_URL;
		window.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_currency = 'EUR';
		document.cookie = 'woocommerce_items_in_cart=; max-age=0';
		respondWith( [ cartOf( [] ) ] );
	} );

	afterEach( () => {
		delete window.fetch;
		delete window.gtm4wp_blocks_cart_url;
		document.cookie = 'woocommerce_items_in_cart=; max-age=0';
	} );

	it( 'reports remove_from_cart from the Store API when the data store is absent', async () => {
		respondWith( [
			cartOf( [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ] ),
			cartOf( [] ),
		] );
		loadTracker();

		syncCart(); // establishes the baseline, reports nothing
		await settle();
		expect( window.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();

		syncCart(); // the item is gone
		await settle();

		const removed = pushedEvents( 'remove_from_cart' );
		expect( removed ).toHaveLength( 1 );
		expect( removed[ 0 ][ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 7, quantity: 1 } )
		);
		expect( removed[ 0 ][ 2 ] ).toEqual(
			expect.objectContaining( { currency: 'EUR', value: 10 } )
		);
	} );

	it( 'leaves add_to_cart to the classic tracker outside the block cart pages', async () => {
		window.gtm4wp_blocks_context = 'minicart';
		respondWith( [
			cartOf( [] ),
			cartOf( [ cartLine( 'A', 1, { item_id: 7, price: 10 } ) ] ),
		] );
		loadTracker();

		syncCart();
		await settle();
		syncCart();
		await settle();

		expect( pushedEvents( 'add_to_cart' ) ).toHaveLength( 0 );
	} );

	it( 'reports add_to_cart on the block Cart page, where it owns the event', async () => {
		window.gtm4wp_blocks_context = 'cart';
		respondWith( [
			cartOf( [] ),
			cartOf( [ cartLine( 'A', 2, { item_id: 7, price: 10 } ) ] ),
		] );
		loadTracker();

		syncCart();
		await settle();
		syncCart();
		await settle();

		const added = pushedEvents( 'add_to_cart' );
		expect( added ).toHaveLength( 1 );
		expect( added[ 0 ][ 1 ][ 0 ] ).toEqual(
			expect.objectContaining( { item_id: 7, quantity: 2 } )
		);
	} );

	it( 'stays out of the way once the data store has answered', async () => {
		mockHasCartStore = true;
		mockCartData = cartOf( [
			cartLine( 'A', 1, { item_id: 7, price: 10 } ),
		] );
		const subscriber = loadTracker();

		subscriber(); // the data store path takes the cart

		syncCart();
		await settle();

		// No second reader: the cart must not be fetched, and the data store
		// path's own baseline must not be doubled by a fallback event.
		expect( window.fetch ).not.toHaveBeenCalled();
		expect( window.gtm4wp_push_ecommerce ).not.toHaveBeenCalled();
	} );

	it( 'reads the baseline at load only for a visitor who has a cart', () => {
		jest.useFakeTimers();

		loadTracker();
		jest.advanceTimersByTime( 5000 );
		expect( window.fetch ).not.toHaveBeenCalled();

		jest.useRealTimers();
	} );

	it( 'reads the baseline at load when the WooCommerce cart cookie is present', () => {
		jest.useFakeTimers();
		document.cookie = 'woocommerce_items_in_cart=1';

		loadTracker();
		jest.advanceTimersByTime( 5000 );
		expect( window.fetch ).toHaveBeenCalledWith(
			CART_URL,
			expect.objectContaining( { credentials: 'same-origin' } )
		);

		jest.useRealTimers();
	} );
} );
