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
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\WooCommerce\Helpers;
use GTM4WP\Modules\VisitorData\VisitorDataEndpoint;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Modules\WooCommerce\PageDataLayer;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';
require_once __DIR__ . '/wc-datastore-stub.php';
require_once __DIR__ . '/wc-users-stub.php';
require_once __DIR__ . '/wc-shortcode-checkout-stub.php';

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

	/**
	 * Whether the wp_add_inline_script() stub reports success. Set to false to
	 * model the handle being already printed or unregistered, which is when the
	 * wp_footer fallback has to take over.
	 *
	 * @var bool
	 */
	private bool $inline_script_succeeds = true;

	/**
	 * Whether the gtm4wp-woocommerce handle has already been printed by the time
	 * the data layer is compiled (a tracker filtered into the <head>).
	 *
	 * @var bool
	 */
	private bool $tracker_already_done = false;

	/**
	 * Whether the gtm4wp-woocommerce handle is on the page at all. False models a
	 * block-based checkout, where WooCommerceModule loads the block tracker
	 * instead - so there is no reader for the checkout globals and the footer
	 * fallback must stay silent rather than print a payload nothing consumes.
	 *
	 * @var bool
	 */
	private bool $tracker_enqueued = true;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		// Models the ampersand encoding that makes wp_kses() worth having a restore
		// step for (RI-3), rather than the identity function. Under an identity stub
		// every assertion about print_script_block()'s output is vacuous while the
		// line still shows as covered - that is exactly how #92 stayed hidden for
		// three reviews (test-review TS-1).
		Functions\when( 'wp_kses' )->alias( static fn ( $content ) => str_replace( '&', '&amp;', (string) $content ) );
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

		// The order-received visitor check (TS-16: stubbed here, in this file's own
		// setUp, never borrowed from whichever test file happened to run first).
		// A logged-out visitor is the default; the guest email-verification helper
		// is asked for nothing by default.
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		\Automattic\WooCommerce\Internal\Utilities\Users::$verify_order_email = false;
		\Automattic\WooCommerce\Internal\Utilities\Users::$calls              = array();

		// Page-type predicates default to false; tests turn one on.
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );

		// Capture the two script sinks.
		$this->enqueued_js            = '';
		$this->inline_js              = '';
		$this->inline_scripts         = array();
		$this->inline_script_succeeds = true;
		$this->tracker_already_done   = false;
		$this->tracker_enqueued       = true;
		Functions\when( 'wc_enqueue_js' )->alias(
			function ( $code ) {
				$this->enqueued_js .= $code;
			}
		);
		// Returns a bool like the real function: false when the handle is already
		// printed or not registered, which is what the wp_footer fallback keys on.
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				if ( ! $this->inline_script_succeeds ) {
					return false;
				}

				$this->inline_js       .= $code;
				$this->inline_scripts[] = array(
					'handle'   => $handle,
					'code'     => $code,
					'position' => $position,
				);

				return true;
			}
		);
		Functions\when( 'wp_script_is' )->alias(
			function ( $handle, $status = 'enqueued' ) {
				if ( 'gtm4wp-woocommerce' !== $handle ) {
					return false;
				}

				// 'enqueued' stays true after the script is printed - WP_Dependencies
				// clears to_do, not queue - so a handle can be both enqueued and done.
				if ( 'enqueued' === $status ) {
					return $this->tracker_enqueued;
				}

				return 'done' === $status && $this->tracker_already_done;
			}
		);
		Functions\when( 'current_theme_supports' )->justReturn( true );

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

		// Process-wide state on the WooCommerce stub: restored here so a test that
		// switches the verification answer on cannot leak it into the next file.
		\Automattic\WooCommerce\Internal\Utilities\Users::$verify_order_email = false;
		\Automattic\WooCommerce\Internal\Utilities\Users::$calls              = array();

		// Same reason, for the feature-guard shim: a hidden member left in place
		// would make the next file's guards resolve differently.
		$GLOBALS['gtm4wp_test_hidden_members'] = array();

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
		$service_options = $this->make_options( $options );

		return new PageDataLayer(
			$service_options,
			new ProductData( $service_options ),
			new DataLayer( $service_options ),
			new ScriptTag( $service_options )
		);
	}

	/**
	 * Builds just the Options service over the given stored option row, for the
	 * static reads that take Options rather than a PageDataLayer instance.
	 *
	 * @param array<string, mixed> $options The stored option row.
	 * @return Options
	 */
	private function make_options( array $options = array() ): Options {
		Functions\when( 'get_option' )->justReturn( $options );

		return new Options( ( new WooCommerceModule() )->defaults() );
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

		$page_datalayer = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		$page_datalayer->add_datalayer_data( array() );

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

		// The normal path attaches to the handle, so nothing is deferred to wp_footer.
		ob_start();
		$page_datalayer->print_deferred_checkout_js();
		$this->assertSame( '', ob_get_clean(), 'Nothing may be printed in the footer once the handle took the inline script.' );
	}

	/**
	 * T40 (#141 at the call site): the encode-failure guard is pinned at
	 * ScriptTag::json_literal() itself, but reverting THIS call site to a bare
	 * wp_json_encode() left the whole suite green - and this is the one checkout
	 * sink third-party code can actually poison, because every item passes the
	 * public product-array filter. An unencodable value must cost the value, not
	 * the block: `= null;`, never `= ;` (a SyntaxError that would take the
	 * checkout globals and their reader down with it).
	 */
	public function test_checkout_products_fall_back_to_null_when_an_item_cannot_be_encoded(): void {
		Functions\when( 'is_checkout' )->justReturn( true );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 2 ) ) ); // phpcs:ignore

		// A third-party filter callback can hand back any PHP value. NAN is the
		// faithful unencodable trigger: real wp_json_encode() repairs bad UTF-8
		// but returns false for NAN (see ScriptTagTest's json_literal cases).
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY )
			->andReturnUsing(
				static function ( $product_array ) {
					$product_array['broken'] = NAN;
					return $product_array;
				}
			);

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$checkout = $this->inline_for( 'gtm4wp-woocommerce' )['code'];
		$this->assertStringContainsString( 'window.gtm4wp_checkout_products = null;', $checkout, 'The guard costs the value, not the block.' );
		$this->assertStringNotContainsString( 'gtm4wp_checkout_products = ;', $checkout, 'An empty literal is a SyntaxError that kills the whole inline block.' );
		$this->assertStringContainsString( 'window.gtm4wp_checkout_value', $checkout, 'The sibling global survives the broken one.' );
	}

	/**
	 * The tracker can be filtered into the <head>
	 * (gtm4wp_integrate-woocommerce-track-enhanced-ecommerce => false), and this
	 * code runs on wp_head priority 10 - after wp_print_head_scripts() at 9. The
	 * handle is 'done' by then, so wp_add_inline_script() can no longer attach and
	 * the checkout globals must reach the page through the wp_footer fallback
	 * instead of being dropped (which left add_shipping_info / add_payment_info
	 * reporting an empty item list and a value of 0).
	 */
	public function test_checkout_globals_are_printed_in_the_footer_when_the_tracker_is_already_done(): void {
		Functions\when( 'is_checkout' )->justReturn( true );

		$this->tracker_already_done = true;

		$product = new \WC_Product(
			array(
				'id'    => 7,
				'title' => 'Poster</script>',
				'sku'   => 'SKU-7',
			)
		);
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 2 ) ) ); // phpcs:ignore

		$page_datalayer = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		$page_datalayer->add_datalayer_data( array() );

		// The attach was never attempted against a printed handle.
		$this->assertSame( '', $this->inline_for( 'gtm4wp-woocommerce' )['code'], 'A printed handle must not be given an inline script.' );
		$this->assertSame( '', $this->enqueued_js, 'The deprecated wc_enqueue_js() must not come back as the fallback.' );

		$this->assertTrue(
			(bool) has_action( 'wp_footer', array( $page_datalayer, 'print_deferred_checkout_js' ) ),
			'The footer fallback must be registered when the handle is already done.'
		);

		ob_start();
		$page_datalayer->print_deferred_checkout_js();
		$printed = ob_get_clean();

		$this->assertStringContainsString( 'window.gtm4wp_checkout_products', $printed, 'The checkout products must still reach the page.' );
		$this->assertStringContainsString( 'window.gtm4wp_checkout_value', $printed, 'The checkout value must still reach the page.' );
		$this->assertStringContainsString( '<script', $printed, 'The fallback must emit a complete script block.' );
		$this->assertStringContainsString( '</script>', $printed );

		// TS-2: the hostile product name stays hex-encoded on this sink too.
		$this->assertStringContainsString( 'Poster\u003', $printed, 'The < must be hex-encoded (JSON_HEX_TAG) on the fallback sink as well.' );
		$this->assertStringNotContainsString( 'Poster</script>', $printed );

		// A second wp_footer pass must not repeat the block.
		ob_start();
		$page_datalayer->print_deferred_checkout_js();
		$this->assertSame( '', ob_get_clean(), 'The deferred block must be printed exactly once.' );
	}

	/**
	 * The other half of the same guard: the handle is on the page but the attach
	 * still fails, and the checkout data must not be lost in that case either.
	 */
	public function test_checkout_globals_are_printed_in_the_footer_when_the_attach_fails(): void {
		Functions\when( 'is_checkout' )->justReturn( true );

		$this->inline_script_succeeds = false;

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 2 ) ) ); // phpcs:ignore

		$page_datalayer = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		$page_datalayer->add_datalayer_data( array() );

		$this->assertSame( '', $this->enqueued_js, 'The deprecated wc_enqueue_js() must not come back as the fallback.' );

		ob_start();
		$page_datalayer->print_deferred_checkout_js();
		$printed = ob_get_clean();

		$this->assertStringContainsString( 'window.gtm4wp_checkout_products', $printed );
		$this->assertStringContainsString( 'window.gtm4wp_checkout_value', $printed );
	}

	/**
	 * Finding #108. A missing handle is not a missed attach - it means nothing on
	 * the page reads these globals. That is the ordinary state of a BLOCK-based
	 * checkout: WooCommerceModule::enqueue_scripts() loads gtm4wp-woocommerce-blocks
	 * INSTEAD of gtm4wp-woocommerce (the block tracker reads the wc/store registry),
	 * while is_checkout() is still true so this code still runs. Emitting the
	 * fallback there printed a full checkout payload with no reader, duplicating the
	 * items the begin_checkout push already carries.
	 */
	public function test_no_footer_fallback_when_the_classic_tracker_is_not_on_the_page(): void {
		Functions\when( 'is_checkout' )->justReturn( true );

		// The block checkout: the handle is not on the page at all. Attaching is left
		// able to succeed, so the assertions below show the attach was never even
		// ATTEMPTED against that handle, rather than merely having failed.
		$this->tracker_enqueued = false;

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 2 ) ) ); // phpcs:ignore

		$page_datalayer = $this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		$page_datalayer->add_datalayer_data( array() );

		$this->assertFalse(
			(bool) has_action( 'wp_footer', array( $page_datalayer, 'print_deferred_checkout_js' ) ),
			'No footer fallback may be registered when nothing on the page reads the globals.'
		);

		ob_start();
		$page_datalayer->print_deferred_checkout_js();
		$this->assertSame( '', ob_get_clean(), 'The block checkout must not receive a checkout payload with no reader.' );

		$this->assertSame( '', $this->enqueued_js, 'wc_enqueue_js() must not be used here either.' );
		$this->assertSame(
			'',
			$this->inline_for( 'gtm4wp-woocommerce' )['code'],
			'A handle that is not on the page must not be given an inline script.'
		);

		// The event itself is unaffected: the block tracker and the GTM container
		// both still get begin_checkout through the data layer push.
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
	 * T46: the (string) casts on both hash_equals() arguments exist because the
	 * request key has passed through a public filter that can hand back any
	 * type, and hash_equals() throws a TypeError on a non-string. Both prior
	 * tests fed strings, so removing the casts stayed green - this case is the
	 * one that fatals without them.
	 */
	public function test_order_key_filter_returning_a_non_string_denies_without_a_fatal(): void {
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$_GET['order'] = '1001';
		$_GET['key']   = '12345';

		Filters\expectApplied( 'woocommerce_thankyou_order_key' )->once()->andReturn( 12345 );

		$order = new \WC_Order(
			array(
				'order_number' => '1001',
				'order_key'    => 'correct-key',
				'status'       => 'processing',
				'items'        => array(),
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

		$this->assertArrayNotHasKey( 'orderData', $result, 'A non-string filtered key is a mismatch, denied - never a TypeError on the order-received page.' );
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
	 * Stubs WC() the way WooCommerce really behaves on a REST request: the object
	 * exists but ->session is NULL, because WooCommerce::init() only calls
	 * initialize_session() when is_request( 'frontend' ) is true, and that check
	 * ends in `&& ! $this->is_rest_api_request()`.
	 *
	 * This is the whole point of the finding-#33 tests below (test-review TS-13).
	 * stub_wc_pending() above - correctly, for every other test in this file -
	 * always hands back a live ->session, which is exactly the collaborator state
	 * the real WC() does not have here. Under that double the load_wc() remedy
	 * branch never executes, so the fix could be deleted with the whole suite
	 * staying green (probe-verified 2026-08-05).
	 *
	 * wc_load_cart() is WooCommerce's own remedy for this context; calling it is
	 * what makes ->session appear, so the stub models that too.
	 *
	 * NOT modelled here: the `function_exists( 'wc_load_cart' )` leg of the guard
	 * (an older WooCommerce without the helper). Brain Monkey defines a mocked
	 * function into the process for good, so once any test in the run mocks
	 * wc_load_cart() every later function_exists() call reports true - the "helper
	 * absent" state cannot be reached again in the same process. The guard is
	 * one-line and defensive; the two legs that CAN vary per request
	 * (before_woocommerce_init, the one-shot cookie) are covered below.
	 *
	 * @param int  $pending_order_id  The order id the session reports once loaded.
	 * @param bool $woo_init_happened Whether before_woocommerce_init has fired.
	 * @return object {store, session, calls} - `calls` counts wc_load_cart() invocations.
	 */
	private function stub_wc_rest_without_session( int $pending_order_id, bool $woo_init_happened = true ): object {
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

		// The REST-request shape: WC() answers, but with no session at all.
		$store           = new \stdClass();
		$store->session  = null;
		$store->cart     = null;
		$store->customer = null;

		$state          = new \stdClass();
		$state->store   = $store;
		$state->session = $session;
		$state->calls   = 0;

		Functions\when( 'WC' )->justReturn( $store );
		Functions\when( 'did_action' )->alias(
			static fn ( $hook ) => 'before_woocommerce_init' === $hook ? (int) $woo_init_happened : 0
		);

		// Model the real effect: wc_load_cart() initializes the session for the
		// current request, which is why the resolver can work afterwards.
		Functions\when( 'wc_load_cart' )->alias(
			static function () use ( $store, $session, $state ) {
				++$state->calls;
				$store->session = $session;
			}
		);

		return $state;
	}

	/**
	 * Finding #33 (High): on the cache-safe session endpoint WC()->session is null,
	 * so every one-shot resolver used to take its "no session" guard and silently
	 * return null - and because the cache-safe mode ALSO omits these events from the
	 * cached page HTML, the purchase was lost outright rather than merely delayed.
	 *
	 * Deleting load_wc()'s remedy left the whole suite green before this test existed.
	 */
	public function test_pending_purchase_resolves_on_a_rest_request_with_no_wc_session(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->make_recent_order() );
		$state = $this->stub_wc_rest_without_session( 1001 );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		$this->assertSame( 1, $state->calls, 'The session must be loaded for the request; without it the resolver silently returns nothing.' );
		$this->assertIsArray( $payload, 'The pending purchase must still resolve when WooCommerce starts the request session-less.' );
		$this->assertSame( 'purchase', $payload['push']['event'] );
		$this->assertSame( '1001', $payload['push']['ecommerce']['transaction_id'] );

		// The #34 read-only property still holds on this path.
		$this->assertSame( array(), $state->session->sets, 'Loading the session must not make the GET write to it.' );
	}

	public function test_session_is_not_loaded_before_woocommerce_has_initialized(): void {
		// wc_load_cart() before before_woocommerce_init is unsafe, so the guard must
		// hold even though the helper exists. Also proves the resolver degrades to
		// "no one-shot" instead of erroring when no session can be obtained.
		Functions\when( 'wc_get_order' )->justReturn( $this->make_recent_order() );
		$state = $this->stub_wc_rest_without_session( 1001, false );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		$this->assertSame( 0, $state->calls, 'The session must not be loaded before WooCommerce has initialized.' );
		$this->assertNull( $payload );
	}

	public function test_session_is_not_loaded_when_no_one_shot_event_is_pending(): void {
		// oneshot_wc()'s gate: with no event cookie an anonymous Tier-2 fetch must pay
		// no WooCommerce session cost at all - the remedy is for pending events only.
		Functions\when( 'wc_get_order' )->justReturn( $this->make_recent_order() );
		$state = $this->stub_wc_rest_without_session( 1001 );
		unset( $_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ] );

		$payload = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
			)
		)->resolve_pending_purchase();

		$this->assertSame( 0, $state->calls, 'No pending one-shot means no session load.' );
		$this->assertNull( $payload );
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

	/**
	 * Puts the request on the standard order-received page with a MATCHING order
	 * key - the state a leaked or forwarded confirmation URL is also in - and
	 * returns the order it resolves to. The billing block carries values that are
	 * recognisable in the output, so a test can assert the customer identity
	 * itself is present or absent rather than only that some key exists.
	 *
	 * @param array<string, mixed> $data Order fixture overrides (e.g. customer_id).
	 * @return \WC_Order
	 */
	private function stub_order_received_request( array $data = array() ): \WC_Order {
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$_GET['order'] = '1001';
		$_GET['key']   = 'correct-key';

		$order = $this->make_recent_order(
			array_merge(
				array(
					'id'                 => 1001,
					'order_key'          => 'correct-key',
					'billing_first_name' => 'Ada',
					'billing_last_name'  => 'Lovelace',
					'billing_address_1'  => '12 Reeling Street',
					'billing_city'       => 'London',
					'billing_postcode'   => 'W1A 1AA',
					'billing_country'    => 'GB',
					'billing_email'      => 'buyer@example.com',
					'billing_phone'      => '+44 20 7946 0000',
				),
				$data
			)
		);

		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc();

		return $order;
	}

	/**
	 * The order-received page with everything that can carry customer identity
	 * switched on: raw order data (orderData.customer) and customer data, which
	 * is what attaches the Enhanced Conversions user_data block to the purchase.
	 *
	 * @return PageDataLayer
	 */
	private function make_order_received_datalayer(): PageDataLayer {
		return $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCORDERDATA      => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA   => true,
			)
		);
	}

	public function test_order_received_withholds_customer_data_when_woocommerce_would_require_login(): void {
		// WooCommerce 8.4.0: an order that belongs to a registered customer is only
		// rendered to that customer; anyone else - a forwarded URL, a shared
		// browser - gets a login form. The order key alone cannot tell the two
		// apart, so the customer identity is withheld while the sale still counts.
		$order = $this->stub_order_received_request( array( 'customer_id' => 5 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertArrayHasKey( 'orderData', $result, 'The order data block itself still ships.' );
		$this->assertArrayNotHasKey( 'customer', $result['orderData'], 'The customer identity must not be exposed to a visitor WooCommerce would ask to log in.' );
		$this->assertArrayHasKey( 'totals', $result['orderData'], 'Only the identity is withheld - the order totals are not identity.' );

		// #171: pin what the gate KEEPS, not only what it removes. Tightening the
		// withheld branch (dropping 'attributes', or the whole of orderData) left the
		// entire suite green before this assertion existed, so the conversion-preserving
		// half of the design - the half the change argues hardest for - was unenforced.
		//
		// The exact key set, not a subset: three of these are values this visitor
		// demonstrably already holds (order_key came from their own URL, order_number is
		// printed beside this block by the duplicate guard, coupons is in the purchase
		// event), and orderData.attributes.order_number is named in readme.txt as a
		// variable containers read - so a future "tighten this too" would break a
		// documented consumer silently.
		$this->assertArrayHasKey( 'attributes', $result['orderData'], 'The order description survives the gate; without this guard the key-set assertion below fails as a TypeError instead of saying what broke.' );
		$this->assertSame(
			array( 'date', 'order_number', 'order_key', 'payment_method', 'payment_method_title', 'shipping_method', 'status', 'coupons' ),
			array_keys( $result['orderData']['attributes'] ),
			'The withheld branch keeps the full order description; only the buyer identity goes.'
		);
		$this->assertArrayHasKey( 'items', $result['orderData'], 'The item list is order data, and the purchase event carries it anyway.' );

		$this->assertStringNotContainsString( 'buyer@example.com', wp_json_encode( $result ), 'The billing email must not survive anywhere in the page data layer.' );
		$this->assertStringNotContainsString( 'Lovelace', wp_json_encode( $result ), 'Neither must the billing name.' );

		// The other half of the split: the conversion is not lost.
		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js, 'The purchase event still fires on the order key alone.' );
		$this->assertStringContainsString( '"transaction_id":"1001"', $this->inline_js );
		$this->assertStringNotContainsString( 'sha256_email_address', $this->inline_js, 'The Enhanced Conversions user_data block is identity too, and goes with it.' );
		$this->assertStringNotContainsString( 'Reeling Street', $this->inline_js, 'user_data carries the address in plaintext, so its absence is what proves the block is gone.' );

		// The new/returning signal is derived from the buyer's order history, so it
		// is identity too. Asserted ABSENT rather than falsy: a GTM trigger may test
		// for key presence, so an invented 'returning' would be a behaviour change
		// (RI-13 omit-don't-invent).
		$this->assertArrayNotHasKey( 'new_customer', $result, 'new_customer describes the buyer, not the order, so it goes with the identity.' );
		$this->assertArrayNotHasKey( 'customer_type', $result, 'And its sibling, which carries the same fact as a string.' );

		$this->assertSame( array(), \Automattic\WooCommerce\Internal\Utilities\Users::$calls, 'The guest email check is downstream of the known-shopper gate in WooCommerce, so a known shopper must never reach it.' );
	}

	/**
	 * T42: the ordering contract the withhold comment promises - the unset runs
	 * AFTER the GTM4WP_WPFILTER_EEC_ORDER_DATA filter, is the last write to the
	 * 'customer' KEY only, and has no say over anything else a third party
	 * writes. Nothing asserted this before: moving the unset above the filter
	 * (or never building 'customer' on the withheld path) left the suite green
	 * while breaking the documented third-party shape in both directions.
	 */
	public function test_withheld_customer_is_unset_after_the_order_data_filter_sees_it(): void {
		$this->stub_order_received_request( array( 'customer_id' => 5 ) );

		$filter_saw_customer = null;
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ORDER_DATA )
			->once()
			->andReturnUsing(
				static function ( $order_data ) use ( &$filter_saw_customer ) {
					$filter_saw_customer = array_key_exists( 'customer', $order_data );

					// A filter that copies billing details onto a key of its own
					// must survive the unset - the gate owns 'customer', not
					// orderData as a whole.
					$order_data['third_party_copy'] = $order_data['customer']['billing']['email'] ?? '(customer was missing)';

					return $order_data;
				}
			);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertTrue( $filter_saw_customer, 'The filter keeps seeing the shape it has always been handed - customer included.' );
		$this->assertSame( 'buyer@example.com', $result['orderData']['third_party_copy'], "The third party's own key survives the unset." );
		$this->assertArrayNotHasKey( 'customer', $result['orderData'], 'The gate still has the final say over the one key it names.' );
	}

	public function test_order_received_keeps_customer_data_for_the_logged_in_customer(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$this->stub_order_received_request( array( 'customer_id' => 5 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'The customer viewing their own order must still get the full order data.' );
		$this->assertStringContainsString( 'sha256_email_address', $this->inline_js, 'And the Enhanced Conversions block with it.' );
		// The paired positive of the withholding assertions above: these strings are
		// genuinely in the output when nothing is withheld, so their absence there
		// measures the gate rather than a fixture that never carried them.
		$this->assertStringContainsString( 'Reeling Street', $this->inline_js, 'user_data.address.street is plaintext.' );
		$this->assertSame( 'Lovelace', $result['orderData']['customer']['billing']['last_name'] );
		// The paired positive for the withholding assertions: these keys really are
		// emitted when nothing is withheld, so their absence there measures the gate
		// rather than a fixture that never carried them.
		$this->assertArrayHasKey( 'new_customer', $result, 'The order-history signal ships normally for the buyer.' );
		$this->assertArrayHasKey( 'customer_type', $result );
	}

	public function test_order_received_keeps_customer_data_when_the_known_shopper_gate_is_filtered_off(): void {
		// A site that turns WooCommerce's gate off with its own filter gets the
		// pre-8.4 behavior back here too: this plugin follows that decision rather
		// than making a second, stricter one of its own.
		Filters\expectApplied( 'woocommerce_order_received_verify_known_shoppers' )->andReturn( false );

		$this->stub_order_received_request( array( 'customer_id' => 5 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'With the gate filtered off, WooCommerce renders the order - so the data layer describes it.' );
	}

	public function test_order_received_withholds_customer_data_for_a_guest_order_needing_email_verification(): void {
		// WooCommerce 7.9.0: past the (filterable, 10 minute) grace period, a guest
		// order is only rendered after the visitor confirms the billing email.
		\Automattic\WooCommerce\Internal\Utilities\Users::$verify_order_email = true;

		$this->stub_order_received_request( array( 'customer_id' => 0 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'customer', $result['orderData'], 'A visitor WooCommerce would ask to verify an email address must not be handed that email address.' );
		$this->assertStringNotContainsString( 'buyer@example.com', wp_json_encode( $result ) );
		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js, 'The purchase event still fires.' );
		$this->assertStringNotContainsString( 'sha256_email_address', $this->inline_js );

		$this->assertArrayNotHasKey( 'new_customer', $result, 'Same on the guest leg: the order-history signal is withheld with the rest of the identity.' );
		$this->assertArrayNotHasKey( 'customer_type', $result );

		$this->assertSame(
			array( array( 1001, null, 'order-received' ) ),
			\Automattic\WooCommerce\Internal\Utilities\Users::$calls,
			'WooCommerce must be asked about THIS order id, in the order-received context, with no supplied email (the POSTed-email escape hatch is deliberately not reproduced).'
		);
	}

	/**
	 * The upstream helper is unavailable - it moved, was renamed, or (measured, and
	 * the reason this exists) the site runs a WooCommerce between 7.9.0 and 8.5.x,
	 * where order_received() already hides guest orders but the Users helper does
	 * not exist yet. The fallback must not read that as "nothing to withhold".
	 */
	public function test_order_received_withholds_customer_data_for_a_stale_guest_order_when_the_upstream_helper_is_missing(): void {
		$this->hide_order_email_verification_helper();

		// Older than the 10-minute grace period, younger than the 30-minute
		// tracking max age - so the order still reaches the data layer at all,
		// which is what makes this an exposure rather than a no-op.
		$this->stub_order_received_request(
			array(
				'customer_id'  => 0,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertArrayHasKey( 'orderData', $result, 'The order itself still reaches the data layer - otherwise this test would pass for the wrong reason.' );
		$this->assertArrayNotHasKey( 'customer', $result['orderData'], 'With no helper to ask, a stale guest order must fail CLOSED: WooCommerce would be hiding it.' );
		$this->assertStringNotContainsString( 'buyer@example.com', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'sha256_email_address', $this->inline_js, 'The Enhanced Conversions block goes with it.' );
		$this->assertArrayNotHasKey( 'new_customer', $result, 'And the order-history signal.' );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js, 'The conversion is still counted - only the identity is withheld.' );
		$this->assertSame( array(), \Automattic\WooCommerce\Internal\Utilities\Users::$calls, 'The helper is hidden, so it must not have been called - proving the fallback ran and not the delegation.' );
	}

	/**
	 * The other half, and the one that keeps the fallback honest: with the helper
	 * missing, a buyer arriving straight from checkout must keep everything. A
	 * blanket fail-closed passes the test above and fails this one.
	 */
	public function test_order_received_keeps_customer_data_for_a_fresh_guest_order_when_the_upstream_helper_is_missing(): void {
		$this->hide_order_email_verification_helper();

		$this->stub_order_received_request( array( 'customer_id' => 0 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'Inside the grace period WooCommerce renders the order, so the buyer keeps their conversion data.' );
		$this->assertStringContainsString( 'sha256_email_address', $this->inline_js );
		$this->assertArrayHasKey( 'new_customer', $result );
	}

	/**
	 * With the known-shopper gate filtered off on 8.4.0-8.5.x, upstream does NOT
	 * simply render a registered customer's order to everyone: order_received()
	 * falls through to the same email verification the guest path gets, whose
	 * only customer-id term is the logged-in-owner short-circuit (read at the
	 * 8.4.0 and 8.5.2 tags). So past the grace period that visitor is asked to
	 * verify the billing email, gate filter or not - and the mirror follows.
	 * The earlier version of this test asserted the opposite from an unmeasured
	 * "the fallback models the GUEST decision only" premise.
	 */
	public function test_order_received_withholds_stale_known_shopper_data_when_the_gate_is_off_and_the_helper_is_missing(): void {
		$this->hide_order_email_verification_helper();
		Filters\expectApplied( 'woocommerce_order_received_verify_known_shoppers' )->andReturn( false );

		$this->stub_order_received_request(
			array(
				'customer_id'  => 5,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'customer', $result['orderData'], 'Past the grace period upstream asks this visitor to verify the billing email even with the login gate off, so the identity is withheld.' );
		$this->assertStringNotContainsString( 'buyer@example.com', wp_json_encode( $result ) );
		$this->assertStringContainsString( '"event":"purchase"', $this->inline_js, 'The conversion still counts.' );
	}

	/**
	 * The same shape inside the grace period publishes: this is a buyer with an
	 * account coming back sessionless from an off-site gateway on a site that
	 * relaxed the login gate, and upstream renders for them - the grace
	 * short-circuit runs before the session terms.
	 */
	public function test_order_received_keeps_fresh_known_shopper_data_when_the_gate_is_off_and_the_helper_is_missing(): void {
		$this->hide_order_email_verification_helper();
		Filters\expectApplied( 'woocommerce_order_received_verify_known_shoppers' )->andReturn( false );

		$this->stub_order_received_request( array( 'customer_id' => 5 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'Within the grace period upstream renders, so the admin\'s opt-out keeps the buyer\'s data complete.' );
	}

	/**
	 * The fallback reads the site's own grace period rather than carrying a second
	 * hardcoded copy of WooCommerce's ten minutes.
	 */
	public function test_order_received_fallback_honors_a_filtered_grace_period(): void {
		$this->hide_order_email_verification_helper();

		// Deliberately the NARROWING direction. Widening it would leave the data in
		// place, which is also what the pre-fix code did - so that version of this
		// test would pass without the fix and prove nothing.
		Filters\expectApplied( 'woocommerce_order_email_verification_grace_period' )->andReturn( 2 * MINUTE_IN_SECONDS );

		// Five minutes old: inside WooCommerce's default ten, past this site's two.
		$this->stub_order_received_request(
			array(
				'customer_id'  => 0,
				'date_created' => '@' . ( time() - ( 5 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'customer', $result['orderData'], 'A site that narrowed the grace period has said this visitor is no longer presumed to be the buyer, and the fallback follows the site rather than a second hardcoded ten minutes.' );
	}

	/**
	 * The filter is WooCommerce's, so its ARGUMENT LIST is WooCommerce's too. Upstream
	 * applies it with three ( $grace_period, $order, $context ) in both homes it has had
	 * - the shortcode on 8.0.0-8.5.x and Users::should_user_verify_order_email() from
	 * 8.6.0 - so a site registers its callback against three parameters.
	 *
	 * WP_Hook passes through exactly what apply_filters() was handed: when
	 * accepted_args >= the number supplied it calls the callback with the supplied list
	 * and never pads it. Passing fewer than upstream therefore does not degrade, it
	 * raises an uncaught ArgumentCountError on the order received page.
	 *
	 * The stand-in below is a site callback written the documented way - three REQUIRED
	 * parameters - because a callback with optional ones cannot fail and would assert
	 * nothing (.testing UC-3).
	 */
	public function test_order_received_grace_period_filter_gets_the_three_arguments_woocommerce_passes(): void {
		$this->hide_order_email_verification_helper();

		$captured = array();

		Filters\expectApplied( 'woocommerce_order_email_verification_grace_period' )
			->andReturnUsing(
				static function ( $grace_period, $order, $context ) use ( &$captured ) {
					$captured = array( $grace_period, $order, $context );
					return $grace_period;
				}
			);

		$order = $this->stub_order_received_request(
			array(
				'customer_id'  => 0,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertCount( 3, $captured, 'A site callback written to WooCommerce\'s documented three-parameter signature must not be handed fewer arguments than WooCommerce hands it.' );
		$this->assertSame( 10 * MINUTE_IN_SECONDS, $captured[0], 'The unfiltered default stays WooCommerce\'s own ten minutes.' );
		$this->assertSame( $order, $captured[1], 'Upstream passes the order as the second argument, so a callback that varies the grace period per order can read it.' );
		$this->assertSame( 'order-received', $captured[2], 'Upstream passes the context as the third; this call site is the order received page.' );
	}

	/**
	 * Constraint one of this whole feature, pinned on the fallback path: the
	 * buyer logged in as the order's customer keeps everything however old the
	 * order is. Upstream's owner short-circuit renders for them on every gated
	 * version, and the known-shopper gate above the fallback only ever matches
	 * OTHER visitors.
	 */
	public function test_order_received_keeps_customer_data_for_the_logged_in_customer_when_the_upstream_helper_is_missing(): void {
		$this->hide_order_email_verification_helper();
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$this->stub_order_received_request(
			array(
				'customer_id'  => 5,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'The buyer reading their own order keeps everything on 7.9.0-8.5.x too, however old the order.' );
		$this->assertStringContainsString( 'sha256_email_address', $this->inline_js, 'Including Enhanced Conversions.' );
	}

	/**
	 * An order with no billing email cannot be verified against anyone, and
	 * upstream renders it - the empty-email short-circuit is identical on 7.9.0
	 * through current, and an admin-created phone order is the common shape.
	 * The mirror follows; withholding here would only hide from the data layer
	 * a name and address the rendered page is already showing.
	 */
	public function test_order_received_keeps_customer_data_for_a_stale_guest_order_without_billing_email_when_the_upstream_helper_is_missing(): void {
		$this->hide_order_email_verification_helper();

		$this->stub_order_received_request(
			array(
				'customer_id'   => 0,
				'billing_email' => '',
				'date_created'  => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertArrayHasKey( 'customer', $result['orderData'], 'Upstream renders an order it cannot verify anyone against, so the identity block ships.' );
		$this->assertSame( 'Lovelace', $result['orderData']['customer']['billing']['last_name'] );
	}

	/**
	 * The woocommerce_order_email_verification_required filter is upstream's
	 * final say and its documented opt-out ("the filter primarily exists as a
	 * way to *remove* the email verification step"). A site that removed
	 * verification renders the order to key holders, so its data layer stays
	 * complete too.
	 */
	public function test_order_received_fallback_honors_the_verification_required_filter_opt_out(): void {
		$this->hide_order_email_verification_helper();

		Filters\expectApplied( 'woocommerce_order_email_verification_required' )->andReturn( false );

		$this->stub_order_received_request(
			array(
				'customer_id'  => 0,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'The site removed email verification, so upstream renders and the mirror follows.' );
	}

	/**
	 * The second WooCommerce-owned hook this method re-applies, held to the same
	 * RI-25 contract as the grace-period one: upstream passes three arguments in
	 * both of the filter's homes (the 7.9.0-8.5.x shortcode and the 8.6.0+
	 * helper), so a site callback written to the documented three-parameter
	 * signature must not be handed fewer. Required parameters, or the stand-in
	 * cannot fail (.testing UC-3).
	 */
	public function test_order_received_verification_required_filter_gets_the_three_arguments_woocommerce_passes(): void {
		$this->hide_order_email_verification_helper();

		$captured = array();

		Filters\expectApplied( 'woocommerce_order_email_verification_required' )
			->andReturnUsing(
				static function ( $required, $order, $context ) use ( &$captured ) {
					$captured = array( $required, $order, $context );
					return $required;
				}
			);

		$order = $this->stub_order_received_request(
			array(
				'customer_id'  => 0,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertCount( 3, $captured, 'A callback written to WooCommerce\'s documented three-parameter signature must not be handed fewer arguments than WooCommerce hands it (RI-25).' );
		$this->assertTrue( $captured[0], 'With the request-identity terms unmodelled, the computed requirement handed to the filter is true here.' );
		$this->assertSame( $order, $captured[1], 'Upstream passes the order second.' );
		$this->assertSame( 'order-received', $captured[2], 'And the context third; this call site is the order received page.' );
	}

	/**
	 * Upstream applies the verification-required filter only PAST the grace
	 * period - the grace short-circuit returns first, which is why WooCommerce's
	 * own docblock says the filter cannot force verification in all cases. The
	 * mirror keeps that ordering, so a fresh order publishes without the filter
	 * ever being consulted.
	 */
	public function test_order_received_grace_period_short_circuits_before_the_verification_required_filter(): void {
		$this->hide_order_email_verification_helper();
		Filters\expectApplied( 'woocommerce_order_email_verification_required' )->never();

		$this->stub_order_received_request( array( 'customer_id' => 0 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'Within the grace period upstream renders without asking the filter.' );
	}

	/**
	 * WooCommerce below 7.9.0 has NO visitor gate on the order received page:
	 * order_received() renders the full order to any holder of a valid key link
	 * (read at the 5.0.0 and 7.8.0 tags). With nothing to mirror, nothing is
	 * withheld - the data layer must match what the page itself is already
	 * showing, or three years of supported versions silently lose their
	 * identity data. The probe is the symbol both gates arrived with
	 * (guest_should_verify_email, 7.9.0), so no WC_VERSION appears anywhere.
	 */
	public function test_order_received_keeps_customer_data_when_woocommerce_has_no_visitor_gates(): void {
		$this->hide_order_received_visitor_gates();

		// Stale enough that every gated branch would withhold - which is what
		// makes this prove the gates-absent branch specifically and not pass by
		// riding the grace period.
		$this->stub_order_received_request(
			array(
				'customer_id'  => 0,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'No gate exists upstream, so the order the page renders is described in full.' );
		$this->assertStringContainsString( 'sha256_email_address', $this->inline_js, 'The Enhanced Conversions user_data block ships with it.' );
		$this->assertArrayHasKey( 'new_customer', $result, 'And the order-history signal.' );
		$this->assertSame( array(), \Automattic\WooCommerce\Internal\Utilities\Users::$calls, 'The helper belongs to the gated era and must not be consulted when the gates are absent.' );
	}

	/**
	 * The login requirement for known shoppers is also 7.9.0+ (unconditional
	 * there, filterable from 8.4.0), so below 7.9.0 a registered customer's
	 * order is likewise rendered to any key holder and the known-shopper mirror
	 * must not run either. Both mirrors sit behind the same probe because both
	 * gates arrived in the same release as the probed symbol.
	 */
	public function test_order_received_keeps_known_shopper_data_when_woocommerce_has_no_visitor_gates(): void {
		$this->hide_order_received_visitor_gates();

		$this->stub_order_received_request(
			array(
				'customer_id'  => 5,
				'date_created' => '@' . ( time() - ( 20 * MINUTE_IN_SECONDS ) ),
			)
		);

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'Below 7.9.0 upstream renders a registered customer\'s order to any key holder, so the mirror follows.' );
		$this->assertStringContainsString( 'sha256_email_address', $this->inline_js );
	}

	/**
	 * Hides WooCommerce's Internal email-verification helper from the production
	 * feature guard, so the "upstream helper unavailable" branch is reachable.
	 * Cleared in tearDown() - this is process-wide state.
	 *
	 * @return void
	 */
	private function hide_order_email_verification_helper(): void {
		$GLOBALS['gtm4wp_test_hidden_members'][] = 'Automattic\WooCommerce\Internal\Utilities\Users::should_user_verify_order_email';
	}

	/**
	 * Hides WC_Shortcode_Checkout::guest_should_verify_email() - the symbol the
	 * production code probes to know whether this WooCommerce has the
	 * order-received visitor gates at all (both gates and the symbol are 7.9.0)
	 * - so the "no gates on this WooCommerce" branch is reachable. Cleared in
	 * tearDown() - this is process-wide state.
	 *
	 * @return void
	 */
	private function hide_order_received_visitor_gates(): void {
		$GLOBALS['gtm4wp_test_hidden_members'][] = 'WC_Shortcode_Checkout::guest_should_verify_email';
	}

	public function test_order_received_keeps_customer_data_for_a_guest_inside_the_grace_period(): void {
		// The buyer arriving straight from checkout: WooCommerce renders the order,
		// so nothing is withheld. This is the case that decides the feature costs
		// nothing in practice, which is why it is asserted rather than assumed.
		\Automattic\WooCommerce\Internal\Utilities\Users::$verify_order_email = false;

		$this->stub_order_received_request( array( 'customer_id' => 0 ) );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'] );
		$this->assertStringContainsString( 'sha256_email_address', $this->inline_js );
	}

	public function test_session_resolved_order_keeps_customer_data_for_a_logged_out_visitor(): void {
		// The custom order-received page resolves the order from THIS browser's
		// WooCommerce session, not from a URL anyone can forward, so the visitor
		// check does not apply to it. A registered customer's order plus a
		// logged-out visitor is the exact shape that is withheld on the standard
		// page - here it must survive, or every custom thank-you page silently
		// loses its customer data.
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$order = $this->make_recent_order(
			array(
				'id'            => 1001,
				'customer_id'   => 5,
				'billing_email' => 'buyer@example.com',
			)
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->stub_wc_pending( 1001 );

		$result = $this->make_order_received_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'buyer@example.com', $result['orderData']['customer']['billing']['email'], 'A session-resolved order belongs to the browser holding the session.' );
		$this->assertArrayHasKey( 'new_customer', $result, 'A session-resolved order is the buyer, so nothing is withheld - including this.' );

		$this->assertSame( array(), \Automattic\WooCommerce\Internal\Utilities\Users::$calls, 'The session path must not consult the request-visitor gates at all.' );
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
		// Also the negative control for the two-part split (issue #398): on the server
		// path both families stay merged FLAT into the page-load data layer object, with
		// no 'customer'/'cart' envelope and no events involved.
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
			PageDataLayer::delivers_visitor_cart_client_side(
				$this->make_options(
					array(
						GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
						GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
					)
				)
			)
		);

		$this->assertTrue(
			PageDataLayer::delivers_visitor_cart_client_side(
				$this->make_options(
					array(
						GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
						GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
					)
				)
			),
			'The cart-content feature on its own is enough (the condition is an OR).'
		);

		$this->assertFalse(
			PageDataLayer::delivers_visitor_cart_client_side(
				$this->make_options(
					array(
						GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false,
						GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
					)
				)
			),
			'Off unless the cache-safe mode is on.'
		);

		$this->assertFalse(
			PageDataLayer::delivers_visitor_cart_client_side(
				$this->make_options( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) )
			),
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

		// The two families ride in SEPARATE parts, so the client can push each under
		// its own event name. Asserted in the escaped form the attribute really carries
		// (JSON_HEX_QUOT leaves structural quotes alone, then esc_attr() turns them into
		// &quot;) — and, the other direction, the raw form must be absent (TS-2).
		$this->assertStringContainsString( '&quot;customer&quot;:{', $html );
		$this->assertStringContainsString( '&quot;cart&quot;:{', $html );
		$this->assertStringNotContainsString( '"customer":{', $html );
		$this->assertStringNotContainsString( '"cart":{', $html );

		// The hostile customer field is present hex-encoded (TC-2) and no raw
		// break-out survives in the fragment HTML (TS-2).
		$safe = json_encode( 'Evil</script>"&Co', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( trim( $safe, '"' ), $html );
		$this->assertStringNotContainsString( '</script>', $html );

		// TC-2/UC-3: the whole attribute equals the payload run through the exact flag
		// set the source uses, so dropping any one JSON_HEX_* flag fails here.
		$expected = esc_attr(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				$page_datalayer->visitor_cart_datalayer(),
				JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
			)
		);
		$this->assertStringContainsString( 'data-gtm4wp-visitor-cart="' . $expected . '"', $html );
	}

	/**
	 * Issue #398: the payload is split into a 'customer' and a 'cart' part so each is
	 * delivered as its own data layer event (gtm4wp.customerData / gtm4wp.cartData).
	 * With both features on, both parts are present and nothing else is.
	 */
	public function test_visitor_cart_datalayer_returns_both_parts(): void {
		\WC_Customer::$fixtures[42] = array( 'order_count' => 3 );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 3 ) ), new \WC_Customer( 42 ) ); // phpcs:ignore

		$data = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA     => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
			)
		)->visitor_cart_datalayer();

		$this->assertSame( array( 'customer', 'cart' ), array_keys( $data ) );
		$this->assertSame( 3, $data['customer']['customerTotalOrders'] );
		$this->assertSame( 'Mug', $data['cart']['cartContent']['items'][0]['item_name'] );
	}

	/**
	 * Issue #398: the classification must stay exact — every customer* key in the
	 * customer part and nothing else, cartContent in the cart part and nothing else.
	 *
	 * This is the guard on an invariant the event-name contract now depends on: the two
	 * builders are called independently, on a fresh array each, so neither may read or
	 * write the other's keys. An edit to add_cart_content() that touched a customer*
	 * key would otherwise silently deliver it under the wrong event name.
	 */
	public function test_visitor_cart_datalayer_classification_is_exact(): void {
		\WC_Customer::$fixtures[42] = array( 'order_count' => 3 );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 3 ) ), new \WC_Customer( 42 ) ); // phpcs:ignore

		$data = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA     => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
			)
		)->visitor_cart_datalayer();

		$customer_keys = array_keys( $data['customer'] );

		$this->assertNotEmpty( $customer_keys );
		$this->assertSame(
			$customer_keys,
			array_values( array_filter( $customer_keys, fn( $key ) => str_starts_with( $key, 'customer' ) ) ),
			'The customer part must hold only customer* keys.'
		);
		$this->assertArrayNotHasKey( 'cartContent', $data['customer'] );

		$this->assertSame(
			array( 'cartContent' ),
			array_keys( $data['cart'] ),
			'The cart part must hold cartContent and nothing else.'
		);
	}

	/**
	 * Issue #398: a part is omitted when its builder wrote no keys, so the client fires
	 * no event for it at all. Each feature's absence is independent of the other's.
	 */
	public function test_visitor_cart_datalayer_omits_a_part_with_no_data(): void {
		\WC_Customer::$fixtures[42] = array( 'order_count' => 3 );
		$product                    = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore

		// Cart feature off: no cart part.
		$this->stub_wc( array( 'item-1' => array( 'data' => $product, 'quantity' => 3 ) ), new \WC_Customer( 42 ) ); // phpcs:ignore
		$data = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
			)
		)->visitor_cart_datalayer();

		$this->assertSame( array( 'customer' ), array_keys( $data ) );

		// Customer feature off: no customer part.
		$data = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
			)
		)->visitor_cart_datalayer();

		$this->assertSame( array( 'cart' ), array_keys( $data ) );

		// Customer feature on but no WC_Customer on this request (the instanceof gate):
		// the part is omitted rather than delivered empty.
		$this->stub_wc( array(), null );
		$data = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
			)
		)->visitor_cart_datalayer();

		$this->assertSame( array(), $data );
	}

	/**
	 * Issue #398: an EMPTY cart is delivered, not omitted. "The cart is now empty" is
	 * exactly the state a tag reads after the last remove_from_cart, so the omission
	 * test must be "the builder wrote no keys", never "the cart has no items".
	 */
	public function test_visitor_cart_datalayer_delivers_an_empty_cart(): void {
		$this->stub_wc( array() );

		$data = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
				GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
			)
		)->visitor_cart_datalayer();

		$this->assertArrayHasKey( 'cart', $data, 'An empty cart must still be delivered.' );
		$this->assertSame( array(), $data['cart']['cartContent']['items'] );
		$this->assertSame( 0.0, $data['cart']['cartContent']['totals']['total'] );
	}

	/**
	 * Issue #398: an anonymous visitor still gets the customer part, carrying the same
	 * blank values the server-rendered path emits for them. The contract of the
	 * cache-safe mode is the same keys with the same values, only delivered
	 * differently, so this is deliberate and not a bug to be "fixed" by suppressing it.
	 */
	public function test_visitor_cart_datalayer_delivers_a_guest_customer_part(): void {
		$this->stub_wc( array(), new \WC_Customer( 0 ) );

		$data = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
			)
		)->visitor_cart_datalayer();

		$this->assertArrayHasKey( 'customer', $data );
		$this->assertSame( 0, $data['customer']['customerTotalOrders'] );
		$this->assertSame( '', $data['customer']['customerBillingEmail'] );
	}

	/**
	 * Issue #398: the fragment key is emitted even for an empty payload. Dropping it
	 * would leave WooCommerce's previously cached fragment — with its stale customer /
	 * cart data — in the DOM instead of replacing it.
	 */
	public function test_visitor_cart_fragment_is_emitted_for_an_empty_payload(): void {
		Functions\when( 'WC' )->justReturn( null );

		$fragments = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
			)
		)->add_visitor_cart_fragment( array() );

		$this->assertArrayHasKey( 'div.gtm4wp-wc-visitor-data', $fragments );
		// wp_json_encode( array() ) is [], which the client treats as "no data".
		$this->assertStringContainsString( 'data-gtm4wp-visitor-cart="[]"', $fragments['div.gtm4wp-wc-visitor-data'] );
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

	/**
	 * #405: ProductData refuses to merge the list attribution into the
	 * `productdetail` context, because a product page is full-page cacheable and
	 * the merged value is visitor-specific. The compensating path is this wrapper -
	 * the same static HTML for everyone, with the list resolved in the browser.
	 *
	 * Its counterpart is
	 * ProductDataTest::test_list_attribution_not_merged_in_cacheable_context(): that
	 * one pins the server refusing to merge, this one pins the client being asked
	 * to. Without this test, deleting the wrapper leaves both suites green and the
	 * feature silently half-dead - which is exactly how it shipped the first time.
	 *
	 * @return void
	 */
	public function test_view_item_push_is_wrapped_for_client_side_list_attribution(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 7 );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->stub_wc();

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE  => true,
				GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"view_item"', $this->inline_js );
		$this->assertStringContainsString(
			'(window.' . Helpers::LIST_ATTRIBUTION_JS_WRAPPER . '||function(d){return d;})(',
			$this->inline_js,
			'The push is wrapped in the client-side enrichment helper, with an identity fallback.'
		);
		$this->assertStringContainsString(
			',7));',
			$this->inline_js,
			'The wrapper receives the product id the list attribution cookie is keyed by.'
		);
	}

	public function test_view_item_push_is_not_wrapped_when_list_attribution_is_off(): void {
		// The feature is opt-in and off by default: with the option off the emitted
		// push must stay exactly the plain form every release so far produced.
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 7 );

		$product = new \WC_Product( array( 'id' => 7, 'title' => 'Mug', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->stub_wc();

		$this->make_page_datalayer( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"view_item"', $this->inline_js );
		$this->assertStringNotContainsString( Helpers::LIST_ATTRIBUTION_JS_WRAPPER, $this->inline_js );
		$this->assertStringNotContainsString( 'function(d){return d;}', $this->inline_js );
	}

	public function test_variable_parent_view_item_push_is_wrapped_too(): void {
		// The variable parent fires its own server-side view_item, and it is just as
		// cacheable as the simple one - the found_variation event that follows is a
		// different event, not a correction of this one.
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 7 );

		$product = new \WC_Product( array( 'id' => 7, 'type' => 'variable', 'title' => 'Tee', 'sku' => 'SKU-7' ) ); // phpcs:ignore
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->stub_wc();

		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE   => true,
				GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT => true,
				GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION  => true,
			)
		)->add_datalayer_data( array() );

		$this->assertStringContainsString(
			'(window.' . Helpers::LIST_ATTRIBUTION_JS_WRAPPER . '||function(d){return d;})(',
			$this->inline_js
		);
	}

	/**
	 * RI-14 / UC-6: PHP writes this identifier into a <script> body and JS has to
	 * define it under exactly that name. Nothing at runtime notices a mismatch -
	 * the identity fallback swallows it and the event fires unenriched - and the
	 * two suites never meet, so only reading the other side's source can catch a
	 * rename. Three shipped features have already died at this exact seam.
	 *
	 * @return void
	 */
	public function test_list_attribution_wrapper_name_matches_the_javascript_export(): void {
		// A local source file in this repository, not a remote URL: the WP HTTP API
		// has nothing to offer here and is not available in this suite anyway.
		$js = file_get_contents( dirname( __DIR__, 3 ) . '/js/frontend/gtm4wp-ecommerce-generic.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertIsString( $js, 'The tracker source must be readable to verify the name contract.' );
		$this->assertStringContainsString(
			'window.' . Helpers::LIST_ATTRIBUTION_JS_WRAPPER . ' =',
			(string) $js,
			'gtm4wp-ecommerce-generic.js must export the wrapper under the name PHP emits.'
		);
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

		// T46: the payload merges customer_signals() the same way the thank-you
		// path does - this is the THIRD emission site, and it relied on the other
		// two sites' tests until these keys were pinned here.
		$this->assertArrayHasKey( 'customer_type', $payload['push'], 'The fallback payload carries the customer signals the thank-you path carries.' );
		$this->assertArrayHasKey( 'new_customer', $payload['push'], 'Both spellings of the signal ship together (GA4 string + Google Ads boolean).' );

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

	/**
	 * T39 (TS-12, attachment form): the permission gate has two halves - the
	 * callback and its registration. check_confirm_purchase_permission() is
	 * asserted at length elsewhere in this file, but nothing executed the
	 * register_rest_route() calls that BIND it to the two state-changing POST
	 * routes: swapping either route's permission_callback to __return_true left
	 * the entire suite green. These two tests pin the registration itself.
	 */
	public function test_confirm_route_registration_binds_the_permission_callback_to_both_routes(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = array(
					'namespace' => $ns,
					'args'      => $args,
				);
			}
		);

		$page_datalayer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true )
		);
		$page_datalayer->register_confirm_purchase_route();

		$this->assertSame(
			array( PageDataLayer::REST_ROUTE_CONFIRM_PURCHASE, PageDataLayer::REST_ROUTE_CONFIRM_READD ),
			array_keys( $routes ),
			'With the reliable-purchase option on, both beacon routes are registered.'
		);

		foreach ( $routes as $route => $registration ) {
			$this->assertSame( VisitorDataEndpoint::REST_NAMESPACE, $registration['namespace'], $route );
			$this->assertSame( 'POST', $registration['args']['methods'], $route . ' changes state, so it must be a POST.' );
			$this->assertSame(
				array( $page_datalayer, 'check_confirm_purchase_permission' ),
				$registration['args']['permission_callback'],
				$route . ': the nonce/origin gate must be bound to the route - a __return_true here re-opens CSRF on a state-changing route.'
			);
		}

		$this->assertSame(
			array( $page_datalayer, 'confirm_pending_purchase_tracked' ),
			$routes[ PageDataLayer::REST_ROUTE_CONFIRM_PURCHASE ]['args']['callback']
		);
		$this->assertSame(
			array( $page_datalayer, 'confirm_readded_to_cart_tracked' ),
			$routes[ PageDataLayer::REST_ROUTE_CONFIRM_READD ]['args']['callback']
		);
	}

	public function test_confirm_route_registration_gates_the_purchase_route_on_its_option(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = $args;
			}
		);

		$this->make_page_datalayer( array() )->register_confirm_purchase_route();

		$this->assertSame(
			array( PageDataLayer::REST_ROUTE_CONFIRM_READD ),
			array_keys( $routes ),
			'Without purchase-on-any-page only the re-add beacon ships; its permission gate is unconditional.'
		);
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
	 * Referer carried by the request under test, as $_SERVER['HTTP_REFERER'].
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

		// The Referer is read straight from $_SERVER, so the fixture sets $_SERVER
		// rather than stubbing a helper (#91). wp_get_raw_referer() is deliberately
		// NOT used by the code under test: it prefers $_REQUEST['_wp_http_referer'],
		// which the caller supplies. Driven per assertion through $stub_referer.
		Functions\when( 'wp_unslash' )->alias( static fn ( $value ) => $value );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => (string) $value );

		// The Referer leg goes through esc_url_raw(), not sanitize_text_field() (#106).
		// Stubbed explicitly rather than left to Brain Monkey's undefined-function
		// handling, so the fixture states which helper the code is expected to call.
		Functions\when( 'esc_url_raw' )->alias( static fn ( $value ) => (string) $value );
	}

	/**
	 * Applies the current $stub_referer to the superglobal the code reads.
	 *
	 * @return void
	 */
	private function apply_stub_referer(): void {
		if ( '' === $this->stub_referer ) {
			unset( $_SERVER['HTTP_REFERER'] );

			return;
		}

		$_SERVER['HTTP_REFERER'] = $this->stub_referer;
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

	/**
	 * #130: an explicit default port in home_url() must not lock every guest out.
	 *
	 * A browser never puts the default port in Origin, so a site configured as
	 * `https://shop.example:443` compared 443 against null and refused every
	 * beacon - fail-closed, silent, and indistinguishable from the feature simply
	 * not being used. Each side is normalized against its OWN scheme, which is
	 * what keeps this from smuggling back the scheme comparison the gate leaves
	 * out on purpose.
	 *
	 * @return void
	 */
	public function test_confirm_purchase_permission_treats_an_explicit_default_port_as_absent(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->stub_origin_helpers();
		Functions\when( 'home_url' )->justReturn( 'https://shop.example:443' );

		$page    = $this->make_page_datalayer();
		$request = static fn () => new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'good' ) );

		$this->stub_origin = 'https://shop.example';
		$this->assertTrue(
			$page->check_confirm_purchase_permission( $request() ),
			'An https site declaring :443 must still accept its own port-less Origin.'
		);

		// The scheme exclusion still holds: an http home_url behind a
		// TLS-terminating proxy and an https Origin both reduce to "no port".
		Functions\when( 'home_url' )->justReturn( 'http://shop.example:80' );
		$this->assertTrue(
			$page->check_confirm_purchase_permission( $request() ),
			'An http site declaring :80 must accept an https Origin with no port.'
		);

		// And a genuinely different port survives normalization and is refused.
		Functions\when( 'home_url' )->justReturn( 'https://shop.example:8443' );
		$this->assertFalse(
			$page->check_confirm_purchase_permission( $request() ),
			'A non-default port is significant and must still be compared.'
		);
	}

	public function test_confirm_purchase_permission_falls_back_to_the_referer_only_when_origin_is_absent(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->stub_origin_helpers();

		$page    = $this->make_page_datalayer();
		$request = static fn () => new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'good' ) );

		$this->stub_origin  = '';
		$this->stub_referer = 'https://shop.example/checkout/order-received/42/';
		$this->apply_stub_referer();
		$this->assertTrue( $page->check_confirm_purchase_permission( $request() ), 'Referer vouches for the request when Origin is absent.' );

		$this->stub_referer = 'https://evil.example/attack.html';
		$this->apply_stub_referer();
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'A foreign referer is rejected.' );

		// Neither signal: refuse. "No evidence" is not "same origin", and a state
		// change on a visitor's behalf should come from a page.
		$this->stub_referer = '';
		$this->apply_stub_referer();
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'With no Origin and no Referer the request is refused.' );

		// Origin wins when both are present, so a stripped-then-forged referer cannot
		// talk its way past a foreign origin.
		$this->stub_origin  = 'https://evil.example';
		$this->stub_referer = 'https://shop.example/';
		$this->apply_stub_referer();
		$this->assertFalse( $page->check_confirm_purchase_permission( $request() ), 'Referer must not override a foreign Origin.' );
	}

	/**
	 * #106: the Referer leg reaches the URL parser byte-for-byte.
	 *
	 * The value is a URL about to be parsed, so it goes through esc_url_raw().
	 * sanitize_text_field() - what this leg used to call - strips EVERY %XX sequence
	 * out of whatever it is handed. That cannot change the verdict today, because
	 * only host and port are compared and removing characters can never turn a
	 * foreign host into this one; the point is that a gate must not be built on a
	 * sanitizer that silently rewrites the value it is judging.
	 *
	 * Both stubs here model the ONE behaviour that separates the two functions. An
	 * identity stub for sanitize_text_field() - which is what the shared fixture
	 * uses, correctly, for every other test - would make this assertion vacuous
	 * while still executing the line (TS-1).
	 */
	public function test_confirm_purchase_permission_hands_the_referer_to_the_parser_unmodified(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->stub_origin_helpers();

		// Faithful sanitize_text_field(): percent sequences are stripped. If the
		// production code ever calls this again, the assertion below goes red.
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => (string) preg_replace( '/%[a-f0-9]{2}/i', '', (string) $value )
		);

		$parsed = array();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) use ( &$parsed ) {
				$parsed[] = $url;

				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			}
		);

		$page    = $this->make_page_datalayer();
		$referer = 'https://shop.example/checkout/order%20received/42/';

		$this->stub_origin  = '';
		$this->stub_referer = $referer;
		$this->apply_stub_referer();

		$this->assertTrue(
			$page->check_confirm_purchase_permission(
				new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'good' ) )
			),
			'A same-origin Referer carrying percent-encoding still vouches for the request.'
		);

		$this->assertContains(
			$referer,
			$parsed,
			'The Referer must reach wp_parse_url() exactly as the transport delivered it; sanitize_text_field() would have dropped the %20.'
		);
	}

	/**
	 * #91: the Referer leg must come from the transport, never from the payload.
	 *
	 * WordPress' wp_get_raw_referer() - the obvious helper, and the one this callback
	 * used to call - returns $_REQUEST['_wp_http_referer'] before the header. That
	 * makes the fallback leg of a CSRF gate settable by the request it is deciding
	 * about: a cross-site form POST need only append the parameter. The header is the
	 * only version of this value a foreign page cannot choose.
	 */
	public function test_confirm_purchase_permission_ignores_a_request_supplied_referer_parameter(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->stub_origin_helpers();

		// Pin the mechanism, not only the outcome: the helper must not be consulted
		// at all. Asserting the result alone would still pass if someone reinstated
		// wp_get_raw_referer() and the fixture happened not to trip it.
		Functions\expect( 'wp_get_raw_referer' )->never();

		$page = $this->make_page_datalayer();

		// No Origin (so the fallback leg is reached) and no Referer header, with the
		// attacker's own _wp_http_referer naming this site in both the query and the
		// body. Neither may vouch for anything.
		$this->stub_origin  = '';
		$this->stub_referer = '';
		$this->apply_stub_referer();

		$_REQUEST['_wp_http_referer'] = 'https://shop.example/';
		$_GET['_wp_http_referer']     = 'https://shop.example/';

		$this->assertFalse(
			$page->check_confirm_purchase_permission(
				new \WP_REST_Request(
					array( '_wp_http_referer' => 'https://shop.example/' ),
					array( 'X-WP-Nonce' => 'good' )
				)
			),
			'A request-supplied _wp_http_referer must not satisfy the same-origin gate.'
		);

		unset( $_REQUEST['_wp_http_referer'], $_GET['_wp_http_referer'] );
	}
}
