<?php
/**
 * Unit tests for the WooCommerce page-load data layer builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Modules\WooCommerce\Helpers;
use GTM4WP\Modules\VisitorData\VisitorField;
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
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);
		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'rest_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-json/' . ltrim( (string) $path, '/' ) );
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
			$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ],
			$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ],
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
			$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ],
			$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ],
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
	 * @param array<string, mixed> $cart_items  Cart items keyed by cart id.
	 * @param mixed                $customer    The WC()->customer object (or null).
	 * @param array<string, mixed> $cart_totals Optional cart totals getter overrides
	 *                                          (discount_total, subtotal, total).
	 * @return void
	 */
	private function stub_wc( array $cart_items = array(), $customer = null, array $cart_totals = array() ): void {
		$cart = new class( $cart_items, $cart_totals ) {
			public function __construct( private array $items, private array $totals ) {}
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
				return $this->totals['discount_total'] ?? 0;
			}
			public function get_subtotal() {
				return $this->totals['subtotal'] ?? 0;
			}
			public function get_cart_contents_total() {
				return $this->totals['total'] ?? 0;
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

	public function test_view_item_carries_quantity_one(): void {
		// #348: the simple-product view_item item must carry an explicit quantity of 1.
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 7 );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->stub_wc();

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"view_item"', $this->inline_js );
		$this->assertStringContainsString( '"quantity":1', $this->inline_js, 'view_item must report quantity 1 on the item.' );
	}

	public function test_checkout_hex_encodes_hostile_list_attribution_cookie(): void {
		// #405 / TC-5: the list-attribution cookie is untrusted input reaching the
		// begin_checkout inline <script>. Even if the sanitizer let a </script> through,
		// the wp_json_encode() hex flags on the sink must keep it from breaking out.
		Functions\when( 'is_checkout' )->justReturn( true );

		$_COOKIE[ Helpers::LIST_ATTRIBUTION_COOKIE ] = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array( 7 => array( 'item_list_name' => "\x3C/script\x3EList", 'item_list_id' => 'x' ) ) // phpcs:ignore
		);

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 1, 'line_subtotal' => 10.0 ) ) ); // phpcs:ignore

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE  => true,
				GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true,
			)
		)->add_datalayer_data( array() );

		$checkout = $this->inline_for( 'gtm4wp-woocommerce' );
		$this->assertStringContainsString( 'List', $checkout['code'], 'The list-name value must reach the checkout products.' );
		$this->assertStringNotContainsString( '</script', $checkout['code'], 'The < of the list name must be hex-encoded (JSON_HEX_TAG); no raw </script may survive.' );
	}

	public function test_checkout_items_carry_per_unit_discount(): void {
		// #348: a discounted checkout line exposes the per-unit discount on the item.
		Functions\when( 'is_checkout' )->justReturn( true );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc(
			array(
				'item-1' => array(
					'data'          => $product,
					'quantity'      => 2,
					'line_subtotal' => 40.0,
					'line_total'    => 30.0,
				),
			)
		);

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$checkout = $this->inline_for( 'gtm4wp-woocommerce' );
		// (40 - 30) / 2 = 5 per unit.
		$this->assertStringContainsString( '"discount":5', $checkout['code'], 'A discounted checkout line must report its per-unit discount.' );
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
		// The one-shot resolvers only load the WC session when the event cookie is
		// present (oneshot_wc()'s gate keeps an anonymous Tier-2 fetch cheap).
		$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] = '1';

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
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js );
		$this->assertStringContainsString( 'gtm4wp_orderid_tracked', $this->inline_js, 'The browser dedupe guard is emitted by default.' );
	}

	/**
	 * The guard writes the order number VERBATIM, so it stays byte compatible with
	 * the copy gtm4wp-visitor-data.js writes to the SAME localStorage/cookie key.
	 * It used to be esc_js()'d - an encoding (PA-4: esc_js is for HTML-attribute
	 * JS, not a raw <script> body) that rewrote &, " and < into entities this
	 * inline-script path never decodes, so the two guards stored different bytes
	 * for the same order and stopped recognising each other's entries.
	 */
	public function test_purchase_dedupe_guard_stores_the_order_number_verbatim(): void {
		$order = $this->make_recent_order( array( 'order_number' => 'A&B-1001' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		// Built with the same encoder the source uses, never hand-typed: the & is
		// emitted as the JSON/JS escape &, which the JavaScript engine
		// resolves while parsing the string literal - so the value actually stored
		// is the raw "A&B-1001", byte-identical to what gtm4wp-visitor-data.js
		// writes to this key. The old esc_js() form produced "A&amp;B-1001", an
		// HTML entity that stays literal inside a <script> and was therefore
		// stored, and compared, as itself.
		$expected = wp_json_encode( 'A&B-1001', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

		$this->assertStringContainsString(
			'window.localStorage.setItem( "gtm4wp_orderid_tracked", ' . $expected . ' )',
			$this->inline_js,
			'The order number must be stored as a JSON string literal that resolves to the raw value.'
		);
		$this->assertStringContainsString(
			'( ' . $expected . ' == gtm4wp_orderid_tracked )',
			$this->inline_js,
			'The comparison operand must use the same encoding as the stored value.'
		);
		$this->assertStringNotContainsString( 'A&amp;B-1001', $this->inline_js, 'The esc_js entity form must not come back.' );
	}

	/**
	 * The same value is also the comparison operand, and it is JSON-encoded with
	 * the full hex flag set (RI-2), so an order number carrying a break-out
	 * sequence cannot escape the inline script.
	 */
	public function test_purchase_dedupe_guard_hex_encodes_a_hostile_order_number(): void {
		$order = $this->make_recent_order( array( 'order_number' => 'ORD</script>' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString( 'ORD\u003', $this->inline_js, 'The < must be hex-encoded (JSON_HEX_TAG) in the dedupe guard.' );
		$this->assertStringNotContainsString( 'ORD</script>', $this->inline_js, 'The raw </script> must never appear in the dedupe guard.' );
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
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
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

	public function test_customer_and_cart_money_totals_typed_as_numbers(): void {
		// WC_Customer::get_total_spent() and (via woocommerce_cart_* filters) the
		// cart totals can arrive as decimal STRINGS. Since the data layer encode
		// no longer numeric-coerces (JSON_NUMERIC_CHECK removed), the builders
		// must type money values themselves - while identifier-like values (a
		// leading-zero SKU) stay byte-exact strings. Fixtures feed the string
		// form on purpose (TS-13).
		\WC_Customer::$fixtures[42] = array(
			'order_count' => 3,
			'total_spent' => '123.45',
		);

		$product = new \WC_Product(
			array(
				'id'    => 7,
				'title' => 'Mug',
				'sku'   => '000035180',
			)
		);
		$this->stub_wc(
			array(
				'item-1' => array(
					'data'     => $product,
					'quantity' => 2,
				),
			),
			new \WC_Customer( 42 ),
			array(
				'discount_total' => '2.50',
				'subtotal'       => '33.50',
				'total'          => '35.90',
			)
		);

		$result = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA     => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
			)
		)->add_datalayer_data( array() );

		$this->assertSame( 123.45, $result['customerTotalOrderValue'], 'The customer lifetime value must be typed as a float.' );
		$this->assertSame( 2.5, $result['cartContent']['totals']['discount_total'] );
		$this->assertSame( 33.5, $result['cartContent']['totals']['subtotal'] );
		$this->assertSame( 35.9, $result['cartContent']['totals']['total'] );
		$this->assertSame( '000035180', $result['cartContent']['items'][0]['sku'], 'A leading-zero SKU must stay a byte-exact string.' );
	}

	public function test_cache_safe_omits_customer_data_and_cart_content(): void {
		// Issue #398 (1b): with the cache-safe data layer on, the visitor's customer
		// details and cart must not be baked into cacheable HTML. Both features are
		// enabled here so the ONLY reason they are absent is the cache-safe gate.
		\WC_Customer::$fixtures[42] = array(
			'order_count'     => 3,
			'billing_company' => 'Marks & Spencer',
			'billing_email'   => 'a@b.com',
		);

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 3 ) ), new \WC_Customer( 42 ) ); // phpcs:ignore

		$result = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA     => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
			)
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'customerTotalOrders', $result, 'Customer data must be withheld from cacheable HTML in cache-safe mode.' );
		$this->assertArrayNotHasKey( 'customerBillingCompany', $result );
		$this->assertArrayNotHasKey( 'cartContent', $result, 'Cart content must be withheld from cacheable HTML in cache-safe mode.' );

		// And no customer value leaks anywhere in the compiled data layer.
		$serialized = json_encode( $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringNotContainsString( 'Marks & Spencer', $serialized );
		$this->assertStringNotContainsString( 'a@b.com', $serialized );
	}

	public function test_cache_safe_omits_the_pending_purchase_one_shot_event(): void {
		// Issue #398 (1b): the reliable-tracking fallback is a session one-shot that
		// fires on arbitrary (cacheable) pages, so it is withheld in cache-safe mode.
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER       => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_js, 'The pending-purchase one-shot must not fire on a cacheable page in cache-safe mode.' );
	}

	public function test_cache_safe_off_keeps_customer_data_and_cart(): void {
		// Negative case: with the mode explicitly off, today's behavior is unchanged.
		\WC_Customer::$fixtures[42] = array(
			'order_count'     => 3,
			'billing_company' => 'Marks & Spencer',
		);

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 3 ) ), new \WC_Customer( 42 ) ); // phpcs:ignore

		$result = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA     => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => false,
			)
		)->add_datalayer_data( array() );

		$this->assertSame( 3, $result['customerTotalOrders'] );
		$this->assertArrayHasKey( 'cartContent', $result );
		$this->assertSame( 'Mug', $result['cartContent']['items'][0]['item_name'] );
	}

	/**
	 * Issue #398 Phase 2: the customer/cart block is delivered client-side over
	 * cart-fragments only when the cache-safe mode is on AND at least one of the
	 * customer-data / cart-content features is enabled.
	 */
	public function test_delivers_visitor_cart_client_side_gating(): void {
		$this->assertTrue(
			$this->make_page_datalayer(
				array(
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)->delivers_visitor_cart_client_side()
		);

		$this->assertFalse(
			$this->make_page_datalayer(
				array(
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => false,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)->delivers_visitor_cart_client_side(),
			'Off unless the cache-safe mode is on.'
		);

		$this->assertFalse(
			$this->make_page_datalayer( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) )
				->delivers_visitor_cart_client_side(),
			'Off unless a customer/cart feature is enabled.'
		);
	}

	/**
	 * Issue #398 Phase 2: the cart-fragments carrier delivers the same customer +
	 * cart block under the same key names, on the placeholder selector, next to any
	 * pre-existing fragments — and a hostile customer field round-trips through the
	 * data attribute hex-encoded (safe form present, raw break-out absent).
	 */
	public function test_visitor_cart_fragment_carries_customer_and_cart_safely(): void {
		\WC_Customer::$fixtures[42] = array(
			'order_count'     => 3,
			'billing_company' => 'Evil</script>"&Co',
		);

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 3 ) ), new \WC_Customer( 42 ) ); // phpcs:ignore

		$page_datalayer = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA     => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
			)
		);

		$fragments = $page_datalayer->add_visitor_cart_fragment( array( 'existing' => '<span></span>' ) );

		$this->assertArrayHasKey( 'div.gtm4wp-wc-visitor-data', $fragments, 'The block rides the placeholder selector.' );
		$this->assertArrayHasKey( 'existing', $fragments, 'Pre-existing fragments must survive.' );

		$html = $fragments['div.gtm4wp-wc-visitor-data'];

		// Customer + cart delivered under the same 1.x key names.
		$this->assertStringContainsString( 'customerTotalOrders', $html );
		$this->assertStringContainsString( 'cartContent', $html );
		$this->assertStringContainsString( 'Mug', $html );

		// The hostile customer field is present hex-encoded (TC-2) and no raw
		// break-out survives in the fragment HTML (TS-2).
		$safe = json_encode( 'Evil</script>"&Co', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( trim( $safe, '"' ), $html );
		$this->assertStringNotContainsString( '</script>', $html );
	}

	public function test_visitor_cart_placeholder_is_empty_and_cache_safe(): void {
		ob_start();
		$this->make_page_datalayer()->output_visitor_cart_placeholder();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="gtm4wp-wc-visitor-data"', $html );
		// The placeholder itself carries no visitor value (safe to bake into cache).
		$this->assertStringNotContainsString( 'data-gtm4wp-visitor-cart', $html );
	}

	public function test_cart_page_fires_view_cart_event(): void {
		// TS-5: the is_cart() branch (add_cart_view) fires the GA4 view_cart event for a
		// non-empty cart when e-commerce tracking is on.
		Functions\when( 'is_cart' )->justReturn( true );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 2 ) ) ); // phpcs:ignore

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"view_cart"', $this->inline_js, 'The cart page must fire the GA4 view_cart event.' );
		$this->assertStringContainsString( '"item_name":"Mug"', $this->inline_js, 'The cart item must be carried on the view_cart event.' );
	}

	public function test_item_with_source_filter_receives_the_cart_item_on_a_cart_line(): void {
		// #324 (a): on a cart line, the new gtm4wp_eec_item_with_source filter receives
		// the raw WooCommerce cart item (with its custom meta) as its source argument,
		// so extensions can read cart-item meta that is absent from the WC_Product.
		$product   = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$cart_item = array(
			'data'           => $product,
			'quantity'       => 2,
			'my_custom_meta' => 'cart-source-value',
		);
		$this->stub_wc( array( 'item-1' => $cart_item ) );

		$captured = 'unset';
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE )
			->andReturnUsing(
				static function ( $item, $context, $source ) use ( &$captured ) {
					if ( 'cart' === $context ) {
						$captured = $source;
					}
					return $item;
				}
			);

		$result = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true ) )
			->add_datalayer_data( array() );

		$this->assertIsArray( $captured, 'The new filter must receive the cart item array as source on a cart line.' );
		$this->assertSame( 'cart-source-value', $captured['my_custom_meta'] ?? null, 'The raw cart item (with its custom meta) must reach the new filter as source.' );

		// #324 (b): the cart item source must not leak into the built GA4 item.
		$this->assertArrayNotHasKey( 'my_custom_meta', $result['cartContent']['items'][0], 'The cart item source must not be merged into the GA4 item.' );
	}

	public function test_cart_content_added_when_include_cart_option_enabled(): void {
		// TS-5: the add_cart_content branch (WCEINCLUDECARTINDL) attaches the current
		// cart's totals and visible items to the returned data layer on every page view.
		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 3 ) ) ); // phpcs:ignore

		$result = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true ) )
			->add_datalayer_data( array() );

		$this->assertArrayHasKey( 'cartContent', $result, 'The cart content must be added when WCEINCLUDECARTINDL is on.' );
		$this->assertArrayHasKey( 'totals', $result['cartContent'] );
		$this->assertSame( 'Mug', $result['cartContent']['items'][0]['item_name'], 'The visible cart item must appear in cartContent.' );
	}

	public function test_readded_to_cart_fires_add_to_cart_event(): void {
		// TS-5: the maybe_add_readded_to_cart branch. The cart-page "Undo" link re-adds a
		// removed item; cart_item_restored() flags its hash in the WC session, and the
		// next page load fires an add_to_cart for it and clears the marker.
		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore

		$cart = new class( array( 'hash-1' => array( 'data' => $product, 'quantity' => 1 ) ) ) { // phpcs:ignore
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
			/**
			 * Recorded set() calls, keyed by session key.
			 *
			 * @var array<string, mixed>
			 */
			public array $sets = array();

			public function get( $key ) {
				return 'gtm4wp_product_readded_to_cart' === $key ? 'hash-1' : null;
			}
			public function set( $key, $value ) {
				$this->sets[ $key ] = $value;
			}
		};

		$store           = new \stdClass();
		$store->cart     = $cart;
		$store->session  = $session;
		$store->customer = null;
		Functions\when( 'WC' )->justReturn( $store );

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"add_to_cart"', $this->inline_js, 'A re-added cart item must fire the add_to_cart event.' );
		$this->assertArrayHasKey( 'gtm4wp_product_readded_to_cart', $session->sets, 'The re-add marker must be consumed.' );
		$this->assertNull( $session->sets['gtm4wp_product_readded_to_cart'], 'The re-add marker must be cleared after firing.' );
	}

	public function test_variable_product_fires_view_item_on_parent_when_enabled(): void {
		// TS-5: the productType === 'variable' branch of add_product_view. With
		// WCVIEWITEMONPARENT on, a view_item fires on the variable parent (and the
		// productIsVariable flag is set to 1).
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 7 );

		$product = new \WC_Product( array( 'id' => 7, 'type' => 'variable', 'title' => 'Tee', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->stub_wc();

		$result = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE   => true,
				GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT => true,
			)
		)->add_datalayer_data( array() );

		$this->assertSame( 1, $result['productIsVariable'], 'A variable product is flagged productIsVariable=1.' );
		$this->assertStringContainsString( '"event":"view_item"', $this->inline_js, 'view_item fires on the variable parent when WCVIEWITEMONPARENT is on.' );
	}

	public function test_grouped_product_view_sets_flag_without_firing_view_item(): void {
		// TS-5: the productType === 'grouped' branch of add_product_view. A grouped
		// product sets productIsVariable=0 and, unlike simple / variable-on-parent, must
		// NOT fire a single view_item event (the item has no own price to report).
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 7 );

		$product = new \WC_Product( array( 'id' => 7, 'type' => 'grouped', 'title' => 'Bundle', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->stub_wc();

		$result = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$this->assertSame( 0, $result['productIsVariable'], 'A grouped product is flagged productIsVariable=0.' );
		$this->assertStringNotContainsString( '"event":"view_item"', $this->inline_js, 'A grouped product must not fire a single view_item event.' );
	}

	// ---- Phase 3 (issue #398): the two one-shot events delivered client-side ----

	/**
	 * Stubs WC() with a cart holding a re-added item under $hash and a session that
	 * reports that hash as the pending re-add marker and records its set() calls, so
	 * the re-added-to-cart resolver (and its omission from server HTML) can be tested.
	 *
	 * @param string      $hash    The re-add cart item key.
	 * @param \WC_Product $product The product in that cart line.
	 * @return object The session object (inspect its ->sets array).
	 */
	private function stub_wc_readded( string $hash, \WC_Product $product ): object {
		// oneshot_wc()'s gate: the resolver loads the session only with the cookie set.
		$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] = '1';

		$cart = new class( array( $hash => array( 'data' => $product, 'quantity' => 1 ) ) ) { // phpcs:ignore
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

		$session = new class( $hash ) {
			/**
			 * Recorded set() calls, keyed by session key.
			 *
			 * @var array<string, mixed>
			 */
			public array $sets = array();

			public function __construct( private string $hash ) {}

			public function get( $key ) {
				return 'gtm4wp_product_readded_to_cart' === $key ? $this->hash : null;
			}

			public function set( $key, $value ) {
				$this->sets[ $key ] = $value;
			}
		};

		$store           = new \stdClass();
		$store->cart     = $cart;
		$store->session  = $session;
		$store->customer = null;
		Functions\when( 'WC' )->justReturn( $store );

		return $session;
	}

	public function test_declare_visitor_scoped_fields_declares_both_oneshots_when_enabled(): void {
		$fields = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->declare_visitor_scoped_fields( array() );

		$this->assertCount( 2, $fields );
		$keys = array_map( static fn ( $field ) => $field->key, $fields );
		$this->assertContains( 'readdedToCart', $keys );
		$this->assertContains( 'pendingPurchase', $keys );

		foreach ( $fields as $field ) {
			$this->assertSame( VisitorField::TIER_ACTION, $field->tier );
			$this->assertTrue( $field->one_shot, 'Both must be flagged as one-shot events (never cached/replayed).' );
			$this->assertSame( \GTM4WP\Modules\WooCommerce\Helpers::ONESHOT_EVENT_COOKIE, $field->cookie_gate, 'Both share the one-shot event cookie gate.' );
		}
	}

	public function test_declare_visitor_scoped_fields_skips_pending_purchase_without_its_option(): void {
		$fields = $this->make_page_datalayer( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) )
			->declare_visitor_scoped_fields( array() );

		$keys = array_map( static fn ( $field ) => $field->key, $fields );
		$this->assertSame( array( 'readdedToCart' ), $keys, 'The fallback needs the purchase-on-any-page option; the re-add is always declared.' );
	}

	public function test_declare_visitor_scoped_fields_declares_nothing_when_cache_safe_off(): void {
		$fields = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true ) )
			->declare_visitor_scoped_fields( array( 'sentinel' ) );

		$this->assertSame( array( 'sentinel' ), $fields, 'No one-shot fields unless the cache-safe mode is on.' );
	}

	public function test_resolve_pending_purchase_returns_payload_without_mutating_state(): void {
		// The fallback resolver returns the purchase payload keyed on the SAME order
		// number the order-received inline guard writes. Being a public, unauthenticated
		// GET it is READ-ONLY (issue #398): it neither consumes the delivery marker nor
		// writes _ga_tracked - every state change happens on the confirm POST beacon.
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$session = $this->stub_wc_pending( 1001 );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		$this->assertIsArray( $payload );
		$this->assertSame( 'purchase', $payload['push']['event'] );
		$this->assertSame( '1001', $payload['push']['ecommerce']['transaction_id'] );
		$this->assertSame( '1001', $payload['orderNumber'], 'The de-dupe key is the order number (shared with the order-received guard).' );
		$this->assertTrue( $payload['flag'], 'By default the browser guard is written.' );

		// Read-only: the GET writes NOTHING to the session and no order meta.
		$this->assertSame( array(), $session->sets, 'The read-only GET must not write any session state.' );
		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta, 'The endpoint (a public GET) must not write the _ga_tracked order meta.' );
	}

	public function test_resolve_pending_purchase_null_without_the_event_cookie(): void {
		// oneshot_wc()'s gate: with no event cookie the resolver never loads the WC
		// session (keeping an anonymous Tier-2 fetch free of WooCommerce session work),
		// so nothing is resolved even when a marker would otherwise be pending.
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );
		unset( $_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		$this->assertNull( $payload, 'No one-shot is resolved when the event cookie is absent.' );
	}

	public function test_resolve_pending_purchase_flag_false_under_no_tracked_flag_option(): void {
		// #369: with "Do not flag orders as being tracked" on, the client must write no
		// browser guard either - the payload flag is false.
		Functions\when( 'wc_get_order' )->justReturn( $this->make_recent_order() );
		$this->stub_wc_pending( 1001 );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
				GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG => true,
			)
		)->resolve_pending_purchase();

		$this->assertIsArray( $payload );
		$this->assertFalse( $payload['flag'], 'No browser order-tracked state may be written when the tracked flag is disabled.' );
	}

	public function test_resolve_pending_purchase_null_when_option_disabled(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->make_recent_order() );
		$this->stub_wc_pending( 1001 );

		$payload = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->resolve_pending_purchase();

		$this->assertNull( $payload, 'The fallback is not resolved unless the reliable-tracking option is on.' );
	}

	public function test_resolve_pending_purchase_null_for_already_tracked_order_without_mutating_state(): void {
		// Scenario (b) server half: the order-received page already flagged _ga_tracked,
		// so the fallback resolver yields nothing - no second purchase. Still read-only:
		// it does not consume the marker (the confirm POST beacon owns that).
		$order = $this->make_recent_order( array( 'meta' => array( '_ga_tracked' => 1 ) ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$session = $this->stub_wc_pending( 1001 );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		$this->assertNull( $payload, 'An order already flagged tracked must not resolve a fallback purchase.' );
		$this->assertSame( array(), $session->sets, 'The read-only GET must not touch the session, even when it resolves nothing.' );
	}

	public function test_resolve_pending_purchase_hostile_order_number_round_trips_hex_encoded(): void {
		// TS-11: the resolver returns the order number RAW; the endpoint hex-encodes the
		// whole payload, so a hostile order number can never surface a raw break-out.
		$order = $this->make_recent_order( array( 'order_number' => 'ORD</script>' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		// Raw at the module boundary (the sink escapes it once and correctly).
		$this->assertSame( 'ORD</script>', $payload['orderNumber'] );
		$this->assertSame( 'ORD</script>', $payload['push']['ecommerce']['transaction_id'] );

		// Encoded the way the endpoint does (TC-2): safe form present, raw break-out gone.
		$encoded = json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( 'ORD\u003', $encoded, 'The < must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( 'ORD</script>', $encoded, 'No raw </script> may survive in the endpoint payload.' );
	}

	public function test_resolve_pending_purchase_hostile_transaction_id_prefix_round_trips_hex_encoded(): void {
		// TS-11: the transaction id now has a second, admin-controlled source (the
		// "Transaction ID prefix" option). It reaches the same sink raw, so a hostile
		// prefix must be hex-encoded there just like a hostile order number - and it
		// must not leak into the orderNumber the browser de-dupes on.
		$order = $this->make_recent_order( array( 'order_number' => '1001' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
				GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => '</script>-',
			)
		)->resolve_pending_purchase();

		// Raw at the module boundary, and only on the transaction id.
		$this->assertSame( '</script>-1001', $payload['push']['ecommerce']['transaction_id'] );
		$this->assertSame( '1001', $payload['orderNumber'], 'The de-dupe key keeps the unprefixed order number.' );

		// Encoded the way the endpoint does (TC-2): safe form present, raw break-out gone.
		$flags    = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;
		$encoded  = json_encode( $payload, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$expected = json_encode( '</script>-1001', $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertStringContainsString( trim( $expected, '"' ), $encoded, 'The prefix angle brackets must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( '</script>', $encoded, 'No raw </script> may survive in the endpoint payload.' );
	}

	public function test_resolve_readded_to_cart_returns_payload_without_mutating_state(): void {
		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$session = $this->stub_wc_readded( 'hash-1', $product );

		$payload = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->resolve_readded_to_cart();

		$this->assertIsArray( $payload );
		$this->assertSame( 'add_to_cart', $payload['push']['event'] );
		$this->assertSame( 'Mug', $payload['push']['ecommerce']['items'][0]['item_name'] );
		$this->assertSame( 'hash-1', $payload['token'], 'The re-add cart key is the de-dupe token.' );

		// Read-only GET (issue #398): the marker is consumed by the confirm-readd POST
		// beacon, not here, so a cross-site navigation to the endpoint cannot destroy
		// the pending event.
		$this->assertSame( array(), $session->sets, 'The read-only GET must not consume the re-add marker.' );
	}

	public function test_resolve_readded_to_cart_null_when_no_marker(): void {
		// The event cookie is present (so the session loads) but no re-add marker is
		// set, so the resolver finds nothing pending. The default stub_wc() session
		// returns null for every key.
		$this->stub_wc();
		$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] = '1';

		$payload = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->resolve_readded_to_cart();

		$this->assertNull( $payload, 'Nothing is resolved when no re-add is pending.' );
	}

	public function test_resolve_readded_to_cart_null_without_the_event_cookie(): void {
		// oneshot_wc()'s gate: no event cookie, no WC session load, nothing resolved.
		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc_readded( 'hash-1', $product );
		unset( $_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] );

		$payload = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->resolve_readded_to_cart();

		$this->assertNull( $payload, 'No re-add is resolved when the event cookie is absent.' );
	}

	public function test_cache_safe_omits_the_readded_to_cart_one_shot_event(): void {
		// Issue #398: the re-add add_to_cart is a session one-shot; in cache-safe mode it
		// is withheld from cacheable HTML and delivered client-side via the endpoint. The
		// page path must not even touch the marker (the endpoint resolver consumes it).
		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$session = $this->stub_wc_readded( 'hash-1', $product );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER       => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringNotContainsString( '"event":"add_to_cart"', $this->inline_js, 'The re-add one-shot must not render into cacheable HTML in cache-safe mode.' );
		$this->assertArrayNotHasKey( 'gtm4wp_product_readded_to_cart', $session->sets, 'The page path must not consume the marker in cache-safe mode (the endpoint does).' );
	}

	// ---- Cross-device fallback dedupe: the authenticated confirm-purchase POST (#398) ----

	/**
	 * Stubs WC() with an empty cart and a STATEFUL session (get() reflects prior
	 * set() calls), seeded with the given key => value map, so the two-step fallback
	 * flow — the GET resolver stashing a needs-flag marker and the POST beacon
	 * consuming it — can be exercised across calls on one session.
	 *
	 * @param array<string, mixed> $initial Initial session values.
	 * @return object The session object (inspect ->store / ->sets).
	 */
	private function stub_wc_stateful( array $initial = array() ): object {
		// oneshot_wc()'s gate: the resolver loads the session only with the cookie set.
		$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] = '1';

		$session = new class( $initial ) {
			/**
			 * Live session store (get reads this, set writes it).
			 *
			 * @var array<string, mixed>
			 */
			public array $store;

			/**
			 * Recorded set() calls, keyed by session key.
			 *
			 * @var array<string, mixed>
			 */
			public array $sets = array();

			public function __construct( array $initial ) {
				$this->store = $initial;
			}

			public function get( $key ) {
				return $this->store[ $key ] ?? null;
			}

			public function set( $key, $value ) {
				$this->sets[ $key ] = $value;
				if ( null === $value ) {
					unset( $this->store[ $key ] );
				} else {
					$this->store[ $key ] = $value;
				}
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

	public function test_resolve_pending_purchase_is_fully_read_only(): void {
		// Issue #398: the read-only GET delivers the payload but changes NOTHING - it
		// leaves the delivery marker in place (the confirm POST beacon consumes it) and
		// writes no order meta. A GET that mutated state could be fired by a cross-site
		// navigation carrying the visitor's Lax cookies.
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$session = $this->stub_wc_stateful( array( ProductData::PENDING_PURCHASE_SESSION_KEY => 1001 ) );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		$this->assertTrue( $payload['flag'] );
		$this->assertSame( array(), $session->sets, 'The read-only GET must not write any session state.' );
		$this->assertSame( 1001, $session->store[ ProductData::PENDING_PURCHASE_SESSION_KEY ] ?? null, 'The delivery marker is left for the confirm POST beacon.' );
		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta, 'The GET (public) must never write order meta.' );
	}

	public function test_cross_device_confirm_beacon_flags_order_and_suppresses_order_received(): void {
		// HEADLINE (issue #398): the fallback is delivered on session A and its confirm
		// beacon flags _ga_tracked; a later order-received render for the SAME order (on
		// another device, another session) is then suppressed - exactly ONE purchase
		// counts across the two contexts.
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$page = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		);

		// Session A, page N: the GET resolver delivers the one fallback purchase and,
		// being read-only, changes nothing (no marker consumption, no _ga_tracked).
		$session = $this->stub_wc_stateful( array( ProductData::PENDING_PURCHASE_SESSION_KEY => 1001 ) );
		$payload = $page->resolve_pending_purchase();

		$this->assertSame( 'purchase', $payload['push']['event'] ?? null, 'The fallback resolves exactly one purchase on session A.' );
		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta, 'The GET must not flag the order.' );
		$this->assertSame( 1001, $session->store[ ProductData::PENDING_PURCHASE_SESSION_KEY ] ?? null, 'The GET leaves the marker for the beacon.' );

		// Session A, the POST beacon: reads the delivery marker, consumes it, and is the
		// ONLY writer of _ga_tracked for the fallback.
		$page->confirm_pending_purchase_tracked();
		$this->assertSame( 1, $order->saved_meta['_ga_tracked'] ?? null, 'The POST beacon flags the order tracked.' );
		$this->assertNull( $session->store[ ProductData::PENDING_PURCHASE_SESSION_KEY ] ?? null, 'The beacon consumes the delivery marker.' );

		// Device B renders the real order-received page for the SAME order: _ga_tracked
		// is set now, so is_purchase_already_tracked() suppresses the second purchase.
		Functions\when( 'is_order_received_page' )->justReturn( true );
		$_GET['order'] = '1001';
		$_GET['key']   = 'k';
		$this->stub_wc(); // A fresh device-B session with no markers.
		$this->inline_js = '';

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_js, 'A second render for the already-flagged order must emit no purchase (exactly-once cross-device).' );
	}

	public function test_confirm_purchase_ignores_the_request_body_and_flags_only_the_session_order(): void {
		// No-IDOR: the confirm callback resolves the order id ONLY from the session
		// marker (it reads no request param at all), so a forged order id can flag
		// nothing but this session's own queued order.
		$session_order = $this->make_recent_order( array( 'order_number' => '1001' ) );
		$forged_order  = $this->make_recent_order( array( 'order_number' => '9999' ) );

		$requested = array();
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) use ( &$requested, $session_order, $forged_order ) {
				$requested[] = (int) $id;
				if ( 1001 === (int) $id ) {
					return $session_order;
				}
				if ( 9999 === (int) $id ) {
					return $forged_order;
				}
				return null;
			}
		);

		// The session queued order 1001; a hostile client would "ask" for 9999 in the
		// body, which the callback never reads.
		$this->stub_wc_stateful( array( ProductData::PENDING_PURCHASE_SESSION_KEY => 1001 ) );

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->confirm_pending_purchase_tracked();

		$this->assertSame( array( 1001 ), $requested, 'Only the session-queued order id is ever loaded - never a body-supplied one.' );
		$this->assertSame( 1, $session_order->saved_meta['_ga_tracked'] ?? null, 'The session order is flagged.' );
		$this->assertArrayNotHasKey( '_ga_tracked', $forged_order->saved_meta, 'A forged order id in the body flags nothing.' );
	}

	public function test_confirm_purchase_is_idempotent_flags_once_then_no_ops(): void {
		// Idempotent: the marker is consumed on the first POST, so a second POST for the
		// same session loads nothing and writes nothing.
		$order     = $this->make_recent_order();
		$requested = array();
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) use ( &$requested, $order ) {
				$requested[] = (int) $id;
				return 1001 === (int) $id ? $order : null;
			}
		);

		$session = $this->stub_wc_stateful( array( ProductData::PENDING_PURCHASE_SESSION_KEY => 1001 ) );

		$page = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		);

		$first = $page->confirm_pending_purchase_tracked();
		$page->confirm_pending_purchase_tracked();

		$this->assertSame( 204, $first->get_status(), 'The beacon returns 204 No Content.' );
		$this->assertNull( $first->get_data() );
		$this->assertSame( array( 1001 ), $requested, 'The marker is consumed on the first POST; the second loads nothing.' );
		$this->assertSame( 1, $order->saved_meta['_ga_tracked'] ?? null, 'The order is flagged exactly once.' );
		$this->assertNull( $session->store[ ProductData::PENDING_PURCHASE_SESSION_KEY ] ?? null, 'The delivery marker is consumed.' );
	}

	public function test_confirm_readded_to_cart_consumes_the_marker(): void {
		// The re-add's state change (consuming its session marker) happens on the
		// authenticated POST beacon, not the read-only GET (issue #398). Idempotent.
		$session = $this->stub_wc_stateful( array( 'gtm4wp_product_readded_to_cart' => 'hash-1' ) );

		$page = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );

		$response = $page->confirm_readded_to_cart_tracked();
		$page->confirm_readded_to_cart_tracked();

		$this->assertSame( 204, $response->get_status(), 'The beacon returns 204 No Content.' );
		$this->assertNull( $session->store['gtm4wp_product_readded_to_cart'] ?? null, 'The re-add marker is consumed.' );
	}

	public function test_do_not_flag_option_confirm_writes_no_meta(): void {
		// "Do not flag orders as being tracked" ON, end to end (#398 / #369): resolve
		// returns flag:false without touching the session, and even if the beacon fires
		// it writes no _ga_tracked (flag_order_tracked() no-ops under the option).
		$order = $this->make_recent_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$session = $this->stub_wc_stateful( array( ProductData::PENDING_PURCHASE_SESSION_KEY => 1001 ) );

		$page = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
				GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG => true,
			)
		);

		$payload = $page->resolve_pending_purchase();
		$this->assertFalse( $payload['flag'], 'flag:false tells the client to send no beacon.' );
		$this->assertSame( array(), $session->sets, 'The read-only GET writes no session state, flagging on or off.' );

		// If the beacon fires anyway, the write still no-ops under the option.
		$page->confirm_pending_purchase_tracked();
		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta, 'flag_order_tracked() no-ops under the do-not-flag option.' );
	}

	/**
	 * Origin reported by get_http_origin() for the request under test.
	 *
	 * @var string
	 */
	private string $stub_origin = '';

	/**
	 * Referer reported by wp_get_raw_referer() for the request under test.
	 *
	 * @var string
	 */
	private string $stub_referer = '';

	/**
	 * Stubs the URL helpers the permission callback reads, driven by the two
	 * properties above so a single test can vary them per assertion.
	 *
	 * @return void
	 */
	private function stub_origin_helpers(): void {
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		// wp_parse_url() is parse_url() plus a PHP 5.4 compat shim, so the plain
		// function is a faithful stand-in on the supported PHP versions.
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( $url, $component = -1 ) => parse_url( $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		);
		Functions\when( 'get_http_origin' )->alias( fn () => $this->stub_origin );
		Functions\when( 'wp_get_raw_referer' )->alias( fn () => $this->stub_referer );
	}

	public function test_confirm_purchase_permission_requires_a_valid_rest_nonce(): void {
		// The nonce is a malformed-request filter, not the gate (#78) - but it is still
		// verified, and a missing or bad one is rejected before anything else runs.
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn ( $nonce, $action ) => ( 'wp_rest' === $action && 'good' === $nonce ) ? 1 : false
		);
		$this->stub_origin_helpers();
		$this->stub_origin = 'https://shop.example';

		$page = $this->make_page_datalayer();

		$this->assertTrue(
			$page->check_confirm_purchase_permission( new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'good' ) ) ),
			'A valid nonce in the X-WP-Nonce header (fetch keepalive) is accepted.'
		);
		$this->assertTrue(
			$page->check_confirm_purchase_permission( new \WP_REST_Request( array( '_wpnonce' => 'good' ) ) ),
			'A valid nonce as the _wpnonce param (sendBeacon fallback) is accepted.'
		);
		$this->assertFalse(
			$page->check_confirm_purchase_permission( new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'bad' ) ) ),
			'A bad nonce is rejected.'
		);
		$this->assertFalse(
			$page->check_confirm_purchase_permission( new \WP_REST_Request() ),
			'A request with no nonce at all is rejected.'
		);
	}

	/**
	 * #78: a valid nonce used to be sufficient, and this test asserted exactly that.
	 * It cannot be: for a logged-out caller WordPress derives wp_rest from uid 0 with an
	 * empty session token, so every guest on the site shares one value for the whole
	 * tick - and the plugin publishes one from its own public GET. The nonce proves the
	 * caller obtained a site-wide constant. The Origin is what a cross-site page cannot
	 * produce, so that is the gate; these cases all carry a VALID nonce so the only
	 * thing under test is the origin.
	 */
	public function test_confirm_purchase_permission_rejects_a_cross_origin_request(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->stub_origin_helpers();

		$page    = $this->make_page_datalayer();
		$request = static fn () => new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'good' ) );

		$this->stub_origin = 'https://shop.example';
		$this->assertTrue( $page->check_confirm_purchase_permission( $request() ), 'Own origin is accepted.' );

		$this->stub_origin = 'https://evil.example';
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'A foreign origin is rejected despite a valid nonce.' );

		// A subdomain is a different host, so it is a different origin.
		$this->stub_origin = 'https://shop.example.evil.example';
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'A look-alike host is rejected.' );

		// Same host, different port: still a different origin.
		$this->stub_origin = 'https://shop.example:8443';
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'A different port is a different origin.' );

		// Scheme is deliberately NOT compared: TLS-terminating proxies make it
		// unreliable, and it is not what separates this site from an attacker.
		$this->stub_origin = 'http://shop.example';
		$this->assertTrue( $page->check_confirm_purchase_permission( $request() ), 'Scheme alone does not disqualify an origin.' );
	}

	public function test_confirm_purchase_permission_falls_back_to_the_referer_only_when_origin_is_absent(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->stub_origin_helpers();

		$page    = $this->make_page_datalayer();
		$request = static fn () => new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'good' ) );

		$this->stub_origin  = '';
		$this->stub_referer = 'https://shop.example/checkout/order-received/42/';
		$this->assertTrue( $page->check_confirm_purchase_permission( $request() ), 'Referer vouches for the request when Origin is absent.' );

		$this->stub_referer = 'https://evil.example/attack.html';
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'A foreign referer is rejected.' );

		// Neither signal: refuse. "No evidence" is not "same origin", and a state
		// change on a visitor's behalf should come from a page.
		$this->stub_referer = '';
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'With no Origin and no Referer the request is refused.' );

		// Origin wins when both are present, so a stripped-then-forged referer cannot
		// talk its way past a foreign origin.
		$this->stub_origin  = 'https://evil.example';
		$this->stub_referer = 'https://shop.example/';
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'Referer must not override a foreign Origin.' );
	}
}
