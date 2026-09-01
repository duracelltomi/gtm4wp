<?php
/**
 * Unit tests for the WooCommerce module class methods.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;
use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;

require_once __DIR__ . '/wc-blocks-stub.php';

/**
 * Covers WooCommerceModule::filter_is_order_received_page() (custom order-received
 * page promotion) and is_block_cart_or_checkout() (block vs. classic detection
 * that decides which tracker bundle loads).
 */
final class WooCommerceModuleTest extends TestCase {

	/**
	 * Snapshot of $_COOKIE, restored in tearDown (TS-7).
	 *
	 * @var array<string, mixed>
	 */
	private array $cookie_backup = array();

	protected function setUp(): void {
		parent::setUp();

		// Reset the block-detection stub between tests (TS-7 isolation).
		CartCheckoutUtils::$checkout_block = false;
		CartCheckoutUtils::$cart_block     = false;

		$this->cookie_backup = $_COOKIE;
		$_COOKIE             = array();

		// Stubbed in our own setUp rather than relied on from another file
		// (TS-16). The default is the case that matters most for #124: an
		// anonymous visitor who has never touched the shop.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
	}

	protected function tearDown(): void {
		$_COOKIE = $this->cookie_backup;

		parent::tearDown();
	}

	/**
	 * Builds a WooCommerceModule with the given stored options injected, without
	 * booting its frontend hooks (which require the Plugin singleton).
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return WooCommerceModule
	 */
	private function make_module( array $stored = array() ): WooCommerceModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module     = new WooCommerceModule();
		$reflection = new \ReflectionProperty( $module, 'options' );
		$reflection->setValue( $module, new Options( $module->defaults() ) );

		return $module;
	}

	public function test_add_global_vars_exposes_the_remarketing_id_prefix(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$module = $this->make_module(
			array( GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX => 'woocommerce_gpf_' )
		);

		$vars = $module->add_global_vars( array() );

		// The prefix must be localized so the found_variation handler can re-apply it
		// to a selected variation's id (#383).
		$this->assertSame( 'woocommerce_gpf_', $vars['gtm4wp_remarketing_prod_id_prefix'] );
	}

	public function test_add_global_vars_remarketing_prefix_defaults_to_empty(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$vars = $this->make_module()->add_global_vars( array() );

		$this->assertSame( '', $vars['gtm4wp_remarketing_prod_id_prefix'] );
	}

	public function test_add_global_vars_list_attribution_flag_reflects_the_opt_in(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		// Opt-in ON: emit 1 so the tracker persists list attribution across the funnel (#405).
		$on = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ) );
		$this->assertSame( 1, $on->add_global_vars( array() )['gtm4wp_list_attribution'] );

		// Default OFF: emit 0 so the tracker never writes or reads the attribution cookie.
		$off = $this->make_module();
		$this->assertSame( 0, $off->add_global_vars( array() )['gtm4wp_list_attribution'] );
	}

	public function test_add_global_vars_checkoutwc_flag_reflects_the_opt_in(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		// Opt-in ON: emit 1 so the checkout-step events also bind to CheckoutWC's cfw_step_changed (#385).
		$on = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WC_CHECKOUTWC => true ) );
		$this->assertSame( 1, $on->add_global_vars( array() )['gtm4wp_checkoutwc'] );

		// Default OFF: emit 0 so the CheckoutWC bridge stays inert.
		$off = $this->make_module();
		$this->assertSame( 0, $off->add_global_vars( array() )['gtm4wp_checkoutwc'] );
	}

	/**
	 * ContainerCode::header_top() prints each of these as a top-level `const`, which
	 * binds lexically and never becomes a property of window - so the JS trackers
	 * read them as BARE identifiers, and .eslintrc.js repeats the same list to make
	 * `no-undef` catch a misspelled read (plus a rule forbidding the `window.`
	 * spelling, which is how three of them once shipped dead).
	 *
	 * Nothing in either language can see a rename on THIS side: PHP would happily
	 * emit the new name and the tracker would go on reading the old one, silently.
	 * Pinning the set as a literal makes a rename a deliberate three-place edit
	 * (here, the module, .eslintrc.js) rather than a silent break of the tracker.
	 */
	public function test_add_global_vars_emits_the_names_the_frontend_trackers_read(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$this->assertSame(
			array(
				'gtm4wp_use_sku_instead',
				'gtm4wp_currency',
				'gtm4wp_product_per_impression',
				'gtm4wp_clear_ecommerce',
				'gtm4wp_datalayer_max_timeout',
				'gtm4wp_console_log',
				'gtm4wp_remarketing_prod_id_prefix',
				'gtm4wp_list_attribution',
				'gtm4wp_checkoutwc',
			),
			array_keys( $this->make_module()->add_global_vars( array() ) )
		);
	}

	public function test_keeps_true_when_woocommerce_already_says_order_received(): void {
		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		// Never downgrade WooCommerce's own positive result, whatever page we are on.
		$this->assertTrue( $module->filter_is_order_received_page( true ) );
	}

	public function test_promotes_the_configured_custom_page(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_page' )->alias( static fn ( $id ) => 42 === $id );

		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		$this->assertTrue( $module->filter_is_order_received_page( false ), 'The configured custom page must be treated as the order-received page.' );
	}

	public function test_does_not_promote_other_pages(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_page' )->alias( static fn ( $id ) => 42 === $id );

		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		// A different page (99) is not the configured custom order-received page.
		Functions\when( 'is_page' )->alias( static fn ( $id ) => 99 === $id );
		$this->assertFalse( $module->filter_is_order_received_page( false ) );
	}

	public function test_never_promotes_in_the_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( true );

		$module = $this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42' ) );

		$this->assertFalse( $module->filter_is_order_received_page( false ), 'The custom-page promotion must not run on admin screens.' );
	}

	public function test_block_checkout_page_is_detected(): void {
		CartCheckoutUtils::$checkout_block = true;
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertTrue( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	public function test_block_cart_page_is_detected(): void {
		CartCheckoutUtils::$cart_block = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( true );

		$this->assertTrue( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	public function test_classic_checkout_is_not_treated_as_block(): void {
		// The utils report the classic (shortcode) checkout.
		CartCheckoutUtils::$checkout_block = false;
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertFalse( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	public function test_order_received_page_is_not_treated_as_block_checkout(): void {
		CartCheckoutUtils::$checkout_block = true;
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( true );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertFalse(
			( new WooCommerceModule() )->is_block_cart_or_checkout(),
			'The order-received endpoint is excluded; its purchase event is server-side.'
		);
	}

	public function test_non_cart_checkout_page_is_not_block(): void {
		CartCheckoutUtils::$checkout_block = true;
		CartCheckoutUtils::$cart_block     = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$this->assertFalse( ( new WooCommerceModule() )->is_block_cart_or_checkout() );
	}

	/**
	 * Drives enqueue_scripts() with the script machinery stubbed, capturing which
	 * bundles were enqueued and the inline "window.gtm4wp_blocks_context" it sets.
	 *
	 * @param WooCommerceModule $module     The module under test.
	 * @param bool              $is_product Whether the request is a product page.
	 * @return array{scripts: array<int, string>, inline: array<string, string>, args: array<string, mixed>}
	 */
	private function run_enqueue( WooCommerceModule $module, bool $is_product = false ): array {
		$enqueued = array();
		$inline   = array();
		$args     = array();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/build/x.js' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, $options = 0 ) => json_encode( $data, $options ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src = '', $deps = array(), $ver = false, $script_args = array() ) use ( &$enqueued, &$args ) {
				$enqueued[]      = $handle;
				$args[ $handle ] = $script_args;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code = '', $position = 'after' ) use ( &$inline ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- mock matches the real wp_add_inline_script() signature
				$inline[ $handle ] = $code;
			}
		);

		// #405 reaches for this conditional tag when deciding whether the shared
		// helper has to be parsed before the inline data layer pushes. Stubbed here,
		// from the caller's argument, so no case depends on another file having
		// defined it first and none can be reordered into a different answer.
		Functions\when( 'is_product' )->justReturn( $is_product );

		$module->enqueue_scripts();

		return array(
			'scripts' => $enqueued,
			'inline'  => $inline,
			'args'    => $args,
		);
	}

	/**
	 * #463: the Cart page must receive its own context, not the merged
	 * "cartcheckout" one - the tracker used to tell Cart and Checkout apart by the
	 * presence of the wc/store/payment store, which WooCommerce registers on the
	 * Cart page too, so the merged context fired the checkout-step events there.
	 */
	public function test_block_cart_page_loads_block_tracker_in_cart_context(): void {
		CartCheckoutUtils::$cart_block = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );

		$result = $this->run_enqueue( $this->make_module() );

		$this->assertContains( 'gtm4wp-woocommerce-blocks', $result['scripts'] );
		$this->assertNotContains( 'gtm4wp-woocommerce', $result['scripts'], 'The classic tracker is skipped on a block Cart page (no double counting).' );
		$this->assertStringContainsString( '"cart"', $result['inline']['gtm4wp-woocommerce-blocks'] );
		$this->assertStringNotContainsString( 'cartcheckout', $result['inline']['gtm4wp-woocommerce-blocks'], 'The merged 2.0.0 context value must not come back (#463).' );
		$this->assertStringNotContainsString( 'minicart', $result['inline']['gtm4wp-woocommerce-blocks'] );
	}

	public function test_block_checkout_page_loads_block_tracker_in_checkout_context(): void {
		CartCheckoutUtils::$checkout_block = true;
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$result = $this->run_enqueue( $this->make_module() );

		$this->assertContains( 'gtm4wp-woocommerce-blocks', $result['scripts'] );
		$this->assertNotContains( 'gtm4wp-woocommerce', $result['scripts'], 'The classic tracker is skipped on a block Checkout page (no double counting).' );
		$this->assertStringContainsString( '"checkout"', $result['inline']['gtm4wp-woocommerce-blocks'] );
		$this->assertStringNotContainsString( 'cartcheckout', $result['inline']['gtm4wp-woocommerce-blocks'], 'The merged 2.0.0 context value must not come back (#463).' );
	}

	public function test_block_store_ordinary_page_loads_classic_and_minicart_tracker(): void {
		// A block store (cart is block-based) on a page that is neither cart nor
		// checkout: the classic tracker runs and the block tracker rides along in
		// minicart mode to catch Mini-Cart removals.
		CartCheckoutUtils::$cart_block = true;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$result = $this->run_enqueue( $this->make_module() );

		$this->assertContains( 'gtm4wp-woocommerce', $result['scripts'] );
		$this->assertContains( 'gtm4wp-woocommerce-blocks', $result['scripts'] );
		$this->assertStringContainsString( 'minicart', $result['inline']['gtm4wp-woocommerce-blocks'] );
	}

	public function test_classic_store_ordinary_page_loads_classic_tracker_only(): void {
		// Neither cart nor checkout is block-based: the block tracker never loads.
		CartCheckoutUtils::$cart_block     = false;
		CartCheckoutUtils::$checkout_block = false;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );

		$result = $this->run_enqueue( $this->make_module() );

		$this->assertContains( 'gtm4wp-woocommerce', $result['scripts'] );
		$this->assertNotContains( 'gtm4wp-woocommerce-blocks', $result['scripts'] );
		$this->assertArrayNotHasKey( 'gtm4wp-woocommerce-blocks', $result['inline'] );
	}

	/**
	 * #405: the product-detail view_item push is wrapped in a helper that
	 * gtm4wp-ecommerce-generic.js defines, and that push is an inline script with no
	 * src - it executes while the document is parsed, before any deferred bundle. So
	 * on this one page the helper must NOT be deferred, or the wrapper resolves to
	 * its identity fallback and the attribution is silently lost on every product
	 * view. The deferred default everywhere else is asserted by its sibling below,
	 * because dropping defer site-wide would be a real cost paid for nothing.
	 *
	 * @return void
	 */
	public function test_product_page_loads_the_shared_helper_blocking_for_list_attribution(): void {
		$this->stub_ordinary_page();

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE  => true,
					GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true,
				)
			),
			true
		);

		$this->assertContains( 'gtm4wp-ecommerce-generic', $result['scripts'] );
		$this->assertArrayNotHasKey(
			'strategy',
			$result['args']['gtm4wp-ecommerce-generic'],
			'The helper must be parser-blocking on a product page, or the inline push runs first.'
		);
	}

	public function test_shared_helper_stays_deferred_without_the_list_attribution_option(): void {
		$this->stub_ordinary_page();

		$result = $this->run_enqueue(
			$this->make_module( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) ),
			true
		);

		$this->assertSame(
			'defer',
			$result['args']['gtm4wp-ecommerce-generic']['strategy'] ?? null,
			'With the opt-in off there is no wrapped push, so nothing justifies blocking.'
		);
	}

	public function test_shared_helper_stays_deferred_off_the_product_page(): void {
		$this->stub_ordinary_page();

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE  => true,
					GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true,
				)
			),
			false
		);

		$this->assertSame(
			'defer',
			$result['args']['gtm4wp-ecommerce-generic']['strategy'] ?? null,
			'Only the product page carries a wrapped push; every other page keeps the deferred default.'
		);
	}

	/**
	 * The ordering hook is the other half of the same guarantee, and it is the half
	 * that can take the whole page down: WordPress silently drops a script whose
	 * dependency is unregistered, so declaring one where there is no wrapped push
	 * would risk deleting every data layer push for no benefit at all. It must
	 * therefore leave the push handle's dependency list alone on every page that
	 * does not carry one.
	 *
	 * @param array<string, mixed> $stored     Stored option values.
	 * @param bool                 $is_product Whether the request is a product page.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_pages_without_a_wrapped_push' )]
	public function test_push_handle_dependency_is_not_declared_without_a_wrapped_push( array $stored, bool $is_product ): void {
		$this->stub_ordinary_page();
		Functions\when( 'is_product' )->justReturn( $is_product );

		$push_script       = new \stdClass();
		$push_script->deps = array();

		$registry             = new \stdClass();
		$registry->registered = array( 'gtm4wp-additional-datalayer-pushes' => $push_script );

		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_scripts' )->justReturn( $registry );

		$this->make_module( $stored )->order_generic_before_pushes();

		$this->assertSame( array(), $push_script->deps, 'Only a page with a wrapped push may constrain the push handle.' );
	}

	/**
	 * Option/page combinations that render no wrapped view_item push.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function provide_pages_without_a_wrapped_push(): array {
		$on = array(
			GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE  => true,
			GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true,
		);

		return array(
			'not a product page'     => array( $on, false ),
			'list attribution off'   => array( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), true ),
			'ecommerce tracking off' => array( array( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION => true ), true ),
			'nothing enabled at all' => array( array(), true ),
		);
	}

	/**
	 * Sets the page context the enqueue path reads, so the cache-safe cases below are
	 * not accidentally testing a block Cart/Checkout page.
	 *
	 * @return void
	 */
	private function stub_ordinary_page(): void {
		CartCheckoutUtils::$cart_block     = false;
		CartCheckoutUtils::$checkout_block = false;
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
	}

	/**
	 * Issue #398: the two ends of the cart-fragments delivery channel load TOGETHER.
	 *
	 * WooCommerce enqueues wc-cart-fragments only from WC_Widget_Cart::widget(), which
	 * bails when woocommerce_widget_cart_is_hidden is true (default:
	 * is_cart() || is_checkout()), and the Mini-Cart block never enqueues it at all. So
	 * without our own enqueue the customer/cart block silently never arrived on a page
	 * load. The runtime that reads the fragment and the script that fetches it are
	 * useless apart, which is why both are asserted in one test.
	 *
	 * Since #124 the DELIVERING end is additionally gated on the visitor already
	 * having WooCommerce state - WooCommerce's own cart-fragments script has no
	 * empty-cart bail-out, so an ungated enqueue costs every visitor of the store an
	 * uncached wc-ajax round trip per browser tab. The reading end is never gated.
	 *
	 * @return void
	 */
	public function test_cache_safe_mode_loads_both_ends_of_the_cart_fragments_channel(): void {
		$this->stub_ordinary_page();
		$_COOKIE['woocommerce_items_in_cart'] = '1';

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)
		);

		$this->assertContains( 'gtm4wp-visitor-data', $result['scripts'], 'The reading end of the channel.' );
		$this->assertContains( 'wc-cart-fragments', $result['scripts'], 'The delivering end of the channel.' );
	}

	public function test_cart_content_option_alone_also_loads_the_channel(): void {
		// The condition is an OR, so the cart-content half needs its own case.
		$this->stub_ordinary_page();
		$_COOKIE['woocommerce_items_in_cart'] = '1';

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
					GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL => true,
				)
			)
		);

		$this->assertContains( 'gtm4wp-visitor-data', $result['scripts'] );
		$this->assertContains( 'wc-cart-fragments', $result['scripts'] );
	}

	/**
	 * #124: a visitor with no WooCommerce state costs the store no cart-fragments
	 * request, while the reading end still loads.
	 *
	 * Both directions asserted (TS-2). The negative half is the finding; the
	 * positive half is what stops a future "just skip the whole channel" from
	 * passing this test, because the runtime is what delivers the very first
	 * cartData - WooCommerce's own wc-add-to-cart script splices our fragment in
	 * from the add-to-cart response, with no cart-fragments script involved.
	 *
	 * @return void
	 */
	public function test_cache_safe_mode_skips_cart_fragments_without_wc_state(): void {
		$this->stub_ordinary_page();

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)
		);

		$this->assertContains( 'gtm4wp-visitor-data', $result['scripts'], 'The reading end is never gated.' );
		$this->assertNotContains(
			'wc-cart-fragments',
			$result['scripts'],
			'A visitor with no cart and no session must not pay WooCommerce cart-refresh request.'
		);
	}

	/**
	 * #124: a logged-in visitor opens the gate on its own, with no cookie at all.
	 *
	 * This is the case a cart-only gate would silently drop: the customer half of
	 * the payload is populated for a logged-in visitor whose cart is empty.
	 *
	 * @return void
	 */
	public function test_cache_safe_mode_loads_cart_fragments_for_a_logged_in_visitor(): void {
		$this->stub_ordinary_page();
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)
		);

		$this->assertContains( 'wc-cart-fragments', $result['scripts'] );
	}

	/**
	 * #124: the WooCommerce session cookie opens the gate too - that is where a
	 * guest's checkout-entered customer fields live, so gating on the cart cookie
	 * alone would drop them. Matched by PREFIX, because WooCommerce appends
	 * COOKIEHASH to the name.
	 *
	 * @return void
	 */
	public function test_cache_safe_mode_loads_cart_fragments_for_a_guest_with_a_session(): void {
		$this->stub_ordinary_page();
		$_COOKIE['wp_woocommerce_session_2f1e3b4c5d6a7b8c9d0e1f2a3b4c5d6e'] = 'guest|||1|||2|||3';

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)
		);

		$this->assertContains( 'wc-cart-fragments', $result['scripts'] );
	}

	/**
	 * T46: the third WC_STATE_COOKIES entry - woocommerce_cart_hash - opens the
	 * gate on its own. It was the one untested leg of visitor_has_wc_state():
	 * WooCommerce writes it beside the items-in-cart cookie but the two are not
	 * guaranteed to travel together, so dropping the entry would silently gate
	 * out a visitor whose only surviving cookie is the hash.
	 *
	 * @return void
	 */
	public function test_cache_safe_mode_loads_cart_fragments_for_the_cart_hash_cookie_alone(): void {
		$this->stub_ordinary_page();
		$_COOKIE['woocommerce_cart_hash'] = 'a1b2c3d4e5f6';

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => true,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)
		);

		$this->assertContains( 'wc-cart-fragments', $result['scripts'], 'The cart-hash cookie is WooCommerce state like the other two.' );
	}

	public function test_cache_safe_mode_off_does_not_load_the_channel(): void {
		// The block is server-rendered in this configuration, so neither end is needed —
		// and GTM4WP must not add WooCommerce's cart-refresh request to a store that
		// would not otherwise make it.
		$this->stub_ordinary_page();

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER     => false,
					GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA => true,
				)
			)
		);

		$this->assertNotContains( 'wc-cart-fragments', $result['scripts'] );
		$this->assertNotContains( 'gtm4wp-visitor-data', $result['scripts'] );
	}

	public function test_cache_safe_mode_without_a_customer_or_cart_feature_does_not_load_the_channel(): void {
		// Cache-safe mode on, but neither feature produces a block to deliver.
		$this->stub_ordinary_page();

		$result = $this->run_enqueue(
			$this->make_module(
				array(
					GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
					GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
				)
			)
		);

		$this->assertNotContains( 'wc-cart-fragments', $result['scripts'] );
		$this->assertNotContains( 'gtm4wp-visitor-data', $result['scripts'] );
	}
}
