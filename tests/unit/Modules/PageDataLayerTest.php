<?php
/**
 * Unit tests for the WooCommerce page-load data layer builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Modules\WooCommerce\PageDataLayer;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';
require_once __DIR__ . '/wc-datastore-stub.php';

/**
 * Covers the page-type branches of PageDataLayer::add_datalayer_data():
 * the AJAX guard, the checkout begin_checkout event and its safely encoded
 * product list, the order-received order-key access check, and raw customer
 * data.
 */
final class PageDataLayerTest extends TestCase {

	/**
	 * Guards that the deprecated wc_enqueue_js() (WooCommerce 10.4) is never
	 * called: any output captured here is a migration regression.
	 *
	 * @var string
	 */
	private string $enqueued_js = '';

	/**
	 * Concatenated code of every wp_add_inline_script() call across all handles.
	 *
	 * @var string
	 */
	private string $inline_js = '';

	/**
	 * Structured record of every wp_add_inline_script() call so a test can
	 * assert which handle and position a payload was attached to.
	 *
	 * @var array<int, array{handle: string, code: string, position: string}>
	 */
	private array $inline_scripts = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_kses' )->alias( static fn ( $content ) => $content );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'current_theme_supports' )->justReturn( true );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		// ProductData dependencies.
		Functions\when( 'wc_get_price_to_display' )->justReturn( 9.99 );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );

		// Page-type predicates default to false; tests turn one on.
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );

		// Capture the two script sinks.
		$this->enqueued_js    = '';
		$this->inline_js      = '';
		$this->inline_scripts = array();
		Functions\when( 'wc_enqueue_js' )->alias(
			function ( $code ) {
				$this->enqueued_js .= $code;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_js       .= $code;
				$this->inline_scripts[] = array(
					'handle'   => $handle,
					'code'     => $code,
					'position' => $position,
				);
			}
		);

		\WC_Customer::$fixtures = array();

		unset(
			$_SERVER['HTTP_X_REQUESTED_WITH'],
			$_GET['order'],
			$_GET['key'],
			$_COOKIE['gtm4wp_orderid_tracked'],
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'],
			$GLOBALS['gtm4wp_additional_datalayer_pushes']
		);
	}

	protected function tearDown(): void {
		\WC_Customer::$fixtures = array();

		unset(
			$_SERVER['HTTP_X_REQUESTED_WITH'],
			$_GET['order'],
			$_GET['key'],
			$_COOKIE['gtm4wp_orderid_tracked'],
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'],
			$GLOBALS['gtm4wp_additional_datalayer_pushes']
		);

		parent::tearDown();
	}

	/**
	 * Builds a PageDataLayer backed by the given stored options.
	 *
	 * @param array<string, mixed> $options Stored option overrides.
	 * @return PageDataLayer
	 */
	private function make_page_datalayer( array $options = array() ): PageDataLayer {
		Functions\when( 'get_option' )->justReturn( $options );

		$service_options = new Options( ( new WooCommerceModule() )->defaults() );

		return new PageDataLayer(
			$service_options,
			new ProductData( $service_options ),
			new DataLayer( $service_options )
		);
	}

	/**
	 * Stubs WC() to return a store object with the given cart and customer.
	 *
	 * @param array<string, mixed> $cart_items Cart items keyed by cart id.
	 * @param mixed                $customer   The WC()->customer object (or null).
	 * @return void
	 */
	private function stub_wc( array $cart_items = array(), $customer = null ): void {
		$cart = new class( $cart_items ) {
			public function __construct( private array $items ) {}
			public function get_cart() {
				return $this->items;
			}
			public function get_cart_item( $key ) {
				return $this->items[ $key ] ?? null;
			}
			public function get_applied_coupons() {
				return array();
			}
			public function get_discount_total() {
				return 0;
			}
			public function get_subtotal() {
				return 0;
			}
			public function get_cart_contents_total() {
				return 0;
			}
		};

		$session = new class() {
			public function get( $key ) {
				return null;
			}
			public function set( $key, $value ) {}
		};

		$store           = new \stdClass();
		$store->cart     = $cart;
		$store->session  = $session;
		$store->customer = $customer;

		Functions\when( 'WC' )->justReturn( $store );
	}

	/**
	 * Returns the concatenated inline-script code attached to a given handle,
	 * plus the position of its last wp_add_inline_script() call.
	 *
	 * @param string $handle The script handle to filter by.
	 * @return array{code: string, position: ?string}
	 */
	private function inline_for( string $handle ): array {
		$code     = '';
		$position = null;

		foreach ( $this->inline_scripts as $script ) {
			if ( $handle === $script['handle'] ) {
				$code    .= $script['code'];
				$position = $script['position'];
			}
		}

		return array(
			'code'     => $code,
			'position' => $position,
		);
	}

	public function test_returns_early_on_ajax_request(): void {
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		$this->stub_wc();

		$result = $this->make_page_datalayer()->add_datalayer_data( array( 'marker' => 'kept' ) );

		$this->assertSame( array( 'marker' => 'kept' ), $result, 'AJAX requests must return the data layer untouched.' );
		$this->assertSame( '', $this->enqueued_js, 'The deprecated wc_enqueue_js() must never be called.' );
		$this->assertSame( '', $this->inline_js, 'AJAX requests must not emit any inline script.' );
	}

	public function test_checkout_adds_hex_encoded_products_inline_and_fires_begin_checkout(): void {
		Functions\when( 'is_checkout' )->justReturn( true );

		$product = new \WC_Product(
			array(
				'id'    => 7,
				'title' => 'Poster</script>',
				'sku'   => 'SKU-7',
			)
		);
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 2 ) ) ); // phpcs:ignore

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		// The deprecated wc_enqueue_js() (WooCommerce 10.4) must not be used; the
		// checkout globals are attached to the gtm4wp-woocommerce tracker handle
		// via wp_add_inline_script( ..., 'before' ) instead.
		$this->assertSame( '', $this->enqueued_js, 'wc_enqueue_js() must not be called after the 10.4 migration.' );

		$checkout = $this->inline_for( 'gtm4wp-woocommerce' );
		$this->assertStringContainsString( 'window.gtm4wp_checkout_products', $checkout['code'] );
		$this->assertSame( 'before', $checkout['position'], 'The checkout globals must be injected before the tracker script.' );

		// #7: the checkout product JSON must be hex-encoded so a product name can
		// not break out of the inline <script> block.
		$this->assertStringContainsString( 'Poster\u003', $checkout['code'], 'The < must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( 'Poster</script>', $checkout['code'] );

		// The begin_checkout event is queued and flushed as an inline push.
		$this->assertStringContainsString( '"event":"begin_checkout"', $this->inline_js );
	}

	public function test_checkout_prices_items_from_cart_line_totals_not_price_display(): void {
		// #436: the begin_checkout products must be priced from the cart's
		// already-calculated line totals (line_subtotal / quantity), not by calling
		// wc_get_price_to_display() once per cart item.
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'wc_get_price_to_display' )->justReturn( 9.99 );

		$product = new \WC_Product(
			array(
				'id'    => 7,
				'title' => 'Mug',
				'sku'   => 'SKU-7',
			)
		);
		$this->stub_wc(
			array(
				'item-1' => array(
					'data'          => $product,
					'quantity'      => 2,
					'line_subtotal' => 40.0,
				),
			)
		);

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$checkout = $this->inline_for( 'gtm4wp-woocommerce' );
		$this->assertStringContainsString( '"price":20', $checkout['code'], 'The item price must be line_subtotal / quantity (40 / 2 = 20).' );
		$this->assertStringNotContainsString( '9.99', $checkout['code'], 'wc_get_price_to_display() must not price a cart line whose totals are known.' );
	}

	public function test_order_received_requires_matching_order_key(): void {
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$_GET['order'] = '1001';
		$_GET['key']   = 'wrong-key';

		$order = new \WC_Order(
			array(
				'order_number'    => '1001',
				'order_key'       => 'correct-key',
				'status'          => 'processing',
				'items'           => array(),
				'billing_company' => 'Marks & Spencer',
			)
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc();

		$result = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCORDERDATA      => true,
			)
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'orderData', $result, 'A mismatched order key must not expose order data.' );
		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta );
	}

	/**
	 * Stubs WC() with a cart plus a session that reports the given pending-purchase
	 * order id and records its set() calls, so the reliable-tracking fallback and
	 * the custom order-received page resolution can be exercised.
	 *
	 * @param int $pending_order_id The order id the session get() should return.
	 * @return object The session object (inspect its ->sets array).
	 */
	private function stub_wc_pending( int $pending_order_id ): object {
		$session = new class( $pending_order_id ) {
			/**
			 * Recorded set() calls, keyed by session key.
			 *
			 * @var array<string, mixed>
			 */
			public array $sets = array();

			public function __construct( private int $pending ) {}

			public function get( $key ) {
				if ( ProductData::PENDING_PURCHASE_SESSION_KEY === $key && $this->pending > 0 ) {
					return $this->pending;
				}
				return null;
			}

			public function set( $key, $value ) {
				$this->sets[ $key ] = $value;
			}
		};

		$cart = new class() {
			public function get_cart() {
				return array();
			}
			public function get_cart_item( $key ) {
				return null;
			}
			public function get_applied_coupons() {
				return array();
			}
			public function get_discount_total() {
				return 0;
			}
			public function get_subtotal() {
				return 0;
			}
			public function get_cart_contents_total() {
				return 0;
			}
		};

		$store           = new \stdClass();
		$store->session  = $session;
		$store->cart     = $cart;
		$store->customer = null;

		Functions\when( 'WC' )->justReturn( $store );

		return $session;
	}

	/**
	 * Builds a recent, trackable order with the given overrides.
	 *
	 * @param array<string, mixed> $data Order overrides.
	 * @return \WC_Order
	 */
	private function make_recent_order( array $data = array() ): \WC_Order {
		return new \WC_Order(
			array_merge(
				array(
					'order_number' => '1001',
					'order_key'    => 'k',
					'status'       => 'processing',
					'total'        => 100.0,
					'currency'     => 'EUR',
					'items'        => array(),
				),
				$data
			)
		);
	}

	public function test_pending_purchase_fallback_emits_on_a_later_page_when_enabled(): void {
		// A normal page view after checkout (not the order-received page).
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$session = $this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js, 'The purchase must be emitted on a later page when reliable tracking is on.' );
		$this->assertStringContainsString( '"transaction_id":"1001"', $this->inline_js );
		$this->assertSame( 1, $order->saved_meta['_ga_tracked'] ?? null, 'The tracked order must be flagged so it is not counted again.' );
		$this->assertArrayHasKey( ProductData::PENDING_PURCHASE_SESSION_KEY, $session->sets, 'The pending marker must be consumed.' );
		$this->assertNull( $session->sets[ ProductData::PENDING_PURCHASE_SESSION_KEY ], 'The pending marker must be cleared to null after emitting.' );
		$this->assertNotEmpty( $GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] );
	}

	public function test_pending_purchase_fallback_ignored_when_option_disabled(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->make_recent_order() );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_js, 'The fallback must not fire unless the reliable-tracking option is on.' );
	}

	public function test_pending_purchase_fallback_skips_an_already_tracked_order(): void {
		$order = $this->make_recent_order( array( 'meta' => array( '_ga_tracked' => 1 ) ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$session = $this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_js, 'An order already flagged tracked must not fire again via the fallback.' );
		$this->assertArrayHasKey( ProductData::PENDING_PURCHASE_SESSION_KEY, $session->sets, 'The stale pending marker must still be consumed.' );
	}

	public function test_pending_purchase_fallback_hex_encodes_a_hostile_order_number(): void {
		$order = $this->make_recent_order( array( 'order_number' => 'ORD</script>' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString( 'ORD\u003', $this->inline_js, 'The < must be hex-encoded (JSON_HEX_TAG) in the fallback purchase push.' );
		$this->assertStringNotContainsString( 'ORD</script>', $this->inline_js, 'The raw </script> must never appear in the fallback purchase push.' );
	}

	public function test_purchase_dedupe_guard_emitted_by_default(): void {
		// By default the browser-side gtm4wp_orderid_tracked guard wraps the push.
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE    => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js );
		$this->assertStringContainsString( 'gtm4wp_orderid_tracked', $this->inline_js, 'The browser dedupe guard is emitted by default.' );
	}

	public function test_purchase_dedupe_guard_skipped_when_do_not_flag_option_is_on(): void {
		// #369: with "Do not flag orders as being tracked" on, the plugin must not
		// remember the order anywhere - so the browser-side gtm4wp_orderid_tracked
		// localStorage/cookie guard is skipped too, not just the server _ga_tracked meta.
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE     => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE  => true,
				GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js, 'The purchase must still fire.' );
		$this->assertStringNotContainsString( 'gtm4wp_orderid_tracked', $this->inline_js, 'No browser dedupe guard may be written when the tracked flag is disabled.' );
		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta, 'The server _ga_tracked flag must not be written either.' );
	}

	public function test_custom_order_received_page_resolves_order_from_session(): void {
		// The custom-page filter has made is_order_received_page() true, but the
		// bespoke page URL carries no order id or key, so the order is resolved
		// from the browser session instead.
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js, 'A custom thank-you page must resolve the order from the session and fire the purchase.' );
		$this->assertStringContainsString( '"transaction_id":"1001"', $this->inline_js );
		$this->assertSame( 1, $order->saved_meta['_ga_tracked'] ?? null );
	}

	public function test_order_received_outputs_raw_order_data_with_matching_key(): void {
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$_GET['order'] = '1001';
		$_GET['key']   = 'correct-key';

		$order = new \WC_Order(
			array(
				'order_number'    => '1001',
				'order_key'       => 'correct-key',
				'status'          => 'processing',
				'items'           => array(),
				'billing_company' => 'Marks & Spencer',
				'billing_email'   => 'a@b.com',
			)
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc();

		$result = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCORDERDATA      => true,
			)
		)->add_datalayer_data( array() );

		$this->assertArrayHasKey( 'orderData', $result );
		$this->assertSame( 'Marks & Spencer', $result['orderData']['customer']['billing']['company'], 'Order data must reach the data layer raw (no entity escaping).' );
		$this->assertSame( 1, $order->saved_meta['_ga_tracked'] ?? null, 'A tracked order must be flagged.' );
		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js );
	}

	public function test_customer_data_added_raw_when_enabled(): void {
		\WC_Customer::$fixtures[42] = array(
			'order_count'     => 3,
			'billing_company' => 'Marks & Spencer',
			'billing_email'   => 'a@b.com',
		);

		$this->stub_wc( array(), new \WC_Customer( 42 ) );

		$result = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true )
		)->add_datalayer_data( array() );

		$this->assertSame( 3, $result['customerTotalOrders'] );
		$this->assertSame( 'Marks & Spencer', $result['customerBillingCompany'], 'Customer data must reach the data layer raw.' );
	}
}
