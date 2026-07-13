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
	 * Captures window.gtm4wp_checkout_products output (wc_enqueue_js).
	 *
	 * @var string
	 */
	private string $enqueued_js = '';

	/**
	 * Captures additional data layer push output (wp_add_inline_script).
	 *
	 * @var string
	 */
	private string $inline_js = '';

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
		$this->enqueued_js = '';
		$this->inline_js   = '';
		Functions\when( 'wc_enqueue_js' )->alias(
			function ( $code ) {
				$this->enqueued_js .= $code;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code ) {
				$this->inline_js .= $code;
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

	public function test_returns_early_on_ajax_request(): void {
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		$this->stub_wc();

		$result = $this->make_page_datalayer()->add_datalayer_data( array( 'marker' => 'kept' ) );

		$this->assertSame( array( 'marker' => 'kept' ), $result, 'AJAX requests must return the data layer untouched.' );
		$this->assertSame( '', $this->enqueued_js );
	}

	public function test_checkout_enqueues_hex_encoded_products_and_fires_begin_checkout(): void {
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

		// #7: the checkout product JSON must be hex-encoded so a product name can
		// not break out of the wc_enqueue_js <script> block.
		$this->assertStringContainsString( 'window.gtm4wp_checkout_products', $this->enqueued_js );
		$this->assertStringContainsString( 'Poster\u003', $this->enqueued_js, 'The < must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( 'Poster</script>', $this->enqueued_js );

		// The begin_checkout event is queued and flushed as an inline push.
		$this->assertStringContainsString( '"event":"begin_checkout"', $this->inline_js );
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
