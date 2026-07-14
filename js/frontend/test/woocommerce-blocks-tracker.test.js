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
let mockCartData;
let mockActivePaymentMethod;
let mockHasPaymentStore;

// @wordpress/data is externalized to window.wp.data by the production build and
// is not installed in node_modules, so the mock must be virtual.
jest.mock(
	'@wordpress/data',
	() => ( {
		select: ( name ) => {
			if ( name === 'wc/store/cart' ) {
				return {
					getCartData: () => mockCartData,
					hasFinishedResolution: () => true,
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
			mockSubscriber = cb;
		},
	} ),
	{ virtual: true }
);

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
		mockCartData = { items: [], totals: { currency_code: 'EUR' } };
		mockActivePaymentMethod = '';
		mockHasPaymentStore = false;
		window.gtm4wp_woocommerce_blocks_inited = false;
		window.gtm4wp_push_ecommerce = jest.fn();
		global.gtm4wp_currency = 'EUR';
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

	it( 'does not fire checkout events when the payment store is absent (cart page)', () => {
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

	it( 'fires add_shipping_info and add_payment_info once on the checkout block', () => {
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
} );
