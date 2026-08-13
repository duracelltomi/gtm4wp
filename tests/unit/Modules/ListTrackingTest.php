<?php
/**
 * Unit tests for the WooCommerce product-list markup builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\WooCommerce\Helpers;
use GTM4WP\Modules\WooCommerce\ListTracking;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * Covers the hidden-product-data markup builders of ListTracking, with the
 * emphasis the plugin cares about most: the WooCommerce-block injection path
 * (add_productdata_to_wc_block) that uses a data-bearing string as the
 * replacement argument of preg_replace() — the PA-7 / finding #16 site whose
 * addcslashes() guard had no regression test before this file.
 */
final class ListTrackingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// process_product() derives item_list_id from the list name via sanitize_title.
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);

		// ProductData dependencies (mirrors ProductDataTest::setUp()).
		Functions\when( 'wc_get_price_to_display' )->justReturn( 9.99 );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );

		// Product-list context helpers.
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		// Reset the cross-request/loop globals the list callbacks read and write
		// (TS-7 isolation).
		unset(
			$GLOBALS['product'],
			$GLOBALS['woocommerce_loop'],
			$GLOBALS['gtm4wp_cart_item_proddata'],
			$GLOBALS['gtm4wp_grouped_product_ix'],
			$GLOBALS['gtm4wp_last_widget_title'],
			$GLOBALS['gtm4wp_product_counter'],
			$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ]
		);

		parent::tearDown();
	}

	/**
	 * A WC()->session double that records set() calls; used by the cart-restore
	 * one-shot tests.
	 *
	 * @return object The session object (inspect its ->sets array).
	 */
	private function stub_wc_session(): object {
		$session = new class() {
			/**
			 * Recorded set() calls, keyed by session key.
			 *
			 * @var array<string, mixed>
			 */
			public array $sets = array();

			public function get( $key ) {
				return null;
			}

			public function set( $key, $value ) {
				$this->sets[ $key ] = $value;
			}
		};

		$store          = new \stdClass();
		$store->session = $session;
		Functions\when( 'WC' )->justReturn( $store );

		return $session;
	}

	public function test_cart_item_restored_seeds_marker_and_flags_event_cookie_in_cache_safe_mode(): void {
		// Phase 3 (issue #398): restoring a removed cart item seeds the re-add marker
		// and, in cache-safe mode, sets the one-shot event cookie so the client fetches
		// the add_to_cart on the next page.
		$session = $this->stub_wc_session();

		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'is_ssl' )->justReturn( false );
		$cookie_names = array();
		Functions\when( 'setcookie' )->alias(
			static function ( $name ) use ( &$cookie_names ) {
				$cookie_names[] = $name;
				return true;
			}
		);

		$this->make_list_tracking( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true ) )
			->cart_item_restored( 'hash-1' );

		$this->assertSame( 'hash-1', $session->sets['gtm4wp_product_readded_to_cart'] ?? null, 'The re-add marker must be seeded in the WC session.' );
		$this->assertContains( Helpers::ONESHOT_EVENT_COOKIE, $cookie_names, 'The one-shot event cookie must be set alongside the marker in cache-safe mode.' );
	}

	public function test_cart_item_restored_does_not_flag_event_cookie_when_cache_safe_off(): void {
		$session = $this->stub_wc_session();

		Functions\when( 'headers_sent' )->justReturn( false );
		$cookie_names = array();
		Functions\when( 'setcookie' )->alias(
			static function ( $name ) use ( &$cookie_names ) {
				$cookie_names[] = $name;
				return true;
			}
		);

		$this->make_list_tracking()->cart_item_restored( 'hash-1' );

		$this->assertSame( 'hash-1', $session->sets['gtm4wp_product_readded_to_cart'] ?? null );
		$this->assertSame( array(), $cookie_names, 'No event cookie may be set unless the cache-safe mode is on.' );
	}

	/**
	 * Builds a ListTracking instance backed by real Options + ProductData.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ListTracking
	 */
	private function make_list_tracking( array $stored = array() ): ListTracking {
		Functions\when( 'get_option' )->justReturn( $stored );

		$options = new Options( ( new WooCommerceModule() )->defaults() );

		return new ListTracking( $options, new ProductData( $options ) );
	}

	/**
	 * Builds a stubbed simple product.
	 *
	 * @param array<string, mixed> $data Product data overrides.
	 * @return \WC_Product
	 */
	private function make_product( array $data = array() ): \WC_Product {
		return new \WC_Product(
			array_merge(
				array(
					'id'    => 123,
					'title' => 'Test Product',
					'sku'   => 'SKU-1',
				),
				$data
			)
		);
	}

	public function test_extra_tag_returns_false_for_non_product(): void {
		$list_tracking = $this->make_list_tracking();

		// null product -> void (returns null); a non-WC_Product -> false.
		$this->assertNull( $list_tracking->get_product_list_item_extra_tag( null, '', 1, 'https://x' ) );
		$this->assertFalse( $list_tracking->get_product_list_item_extra_tag( 'not a product', '', 1, 'https://x' ) );
	}

	public function test_extra_tag_builds_hidden_span_with_escaped_json(): void {
		$list_tracking = $this->make_list_tracking();

		$tag = $list_tracking->get_product_list_item_extra_tag(
			$this->make_product(),
			'',
			1,
			'https://example.com/product/'
		);

		$this->assertIsString( $tag );
		$this->assertStringContainsString( 'class="gtm4wp_productdata"', $tag );
		$this->assertStringContainsString( 'data-gtm4wp_product_data="', $tag );
		// The JSON is written into an HTML attribute, so its quotes must be
		// esc_attr()-encoded (&quot;), never left as raw " that would break out.
		$this->assertStringContainsString( '&quot;item_name&quot;', $tag );
		$this->assertStringNotContainsString( '"item_name"', $tag );
	}

	/**
	 * On a search results page the list name reported for the impression is
	 * "Search Results" (the is_search() branch of get_product_list_item_extra_tag).
	 */
	public function test_extra_tag_uses_search_results_list_name_on_search(): void {
		Functions\when( 'is_search' )->justReturn( true );

		$tag = $this->make_list_tracking()->get_product_list_item_extra_tag(
			$this->make_product(),
			'',
			1,
			'https://example.com/product/'
		);

		$this->assertStringContainsString( 'Search Results', (string) $tag );
	}

	/**
	 * Regression for PA-7 / finding #16. add_productdata_to_wc_block() injects a
	 * data-bearing span as the *replacement* argument of preg_replace(); a
	 * product field containing a `$n` sequence must survive verbatim rather than
	 * being interpreted as a backreference and rewritten to a captured group.
	 *
	 * The source guards this with addcslashes( $tag, '\\$' ). Removing that guard
	 * makes `$1` below resolve to the class-attribute capture group instead of the
	 * literal text — this test fails if the guard is reverted.
	 */
	public function test_add_productdata_to_wc_block_preserves_dollar_backreference_sequences(): void {
		$list_tracking = $this->make_list_tracking();

		// Product name carries a literal `$1` — the exact sequence preg_replace
		// would otherwise treat as a replacement backreference. The source's
		// addcslashes also escapes `\`, so `\1` is covered by the same guard; `$1`
		// is asserted here because JSON does not double it the way it doubles `\`.
		$product = $this->make_product( array( 'title' => 'Deal $1 special' ) );
		$data    = (object) array( 'permalink' => 'https://example.com/product/' );
		$content = '<li class="wc-block-grid__product first">ORIGINAL PRODUCT ITEM</li>';

		$result = $list_tracking->add_productdata_to_wc_block( $content, $data, $product );

		// The original <li> element is preserved (leading $0 in the replacement).
		$this->assertStringContainsString( 'ORIGINAL PRODUCT ITEM', $result );
		// The injected data-bearing span is present.
		$this->assertStringContainsString( 'data-gtm4wp_product_data="', $result );
		// The literal `$1` survives verbatim inside the product name. Without the
		// addcslashes() guard, preg_replace would rewrite `$1` to capture group 1
		// (the class-attribute opening quote), producing `Deal " special`.
		$this->assertStringContainsString( 'Deal $1 special', $result );
	}

	/**
	 * The Product Collection block renders each product as a
	 * <li class="wc-block-product post-{ID} ..."> without firing the classic
	 * list hooks, so add_productdata_to_product_collection_block() must inject one
	 * hidden data span per product item.
	 */
	public function test_product_collection_injects_span_for_each_product(): void {
		$list_tracking = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		Functions\when( 'wc_get_product' )->alias(
			fn ( $id ) => $this->make_product( array( 'id' => (int) $id ) )
		);

		$block   = array(
			'blockName' => 'woocommerce/product-collection',
			'attrs'     => array(),
		);
		$content = '<ul class="wc-block-product-template">'
			. '<li class="wc-block-product post-123 type-product"><a href="/p1/">A</a></li>'
			. '<li class="wc-block-product post-456 type-product"><a href="/p2/">B</a></li>'
			. '</ul>';

		$result = $list_tracking->add_productdata_to_product_collection_block( $content, $block );

		$this->assertSame( 2, substr_count( $result, 'class="gtm4wp_productdata"' ), 'One data span per product item.' );
		// Default collection reports the generic list name.
		$this->assertStringContainsString( 'Product Collection', $result );
		// Original markup is preserved (the span is appended after the <li> tag).
		$this->assertStringContainsString( 'post-123', $result );
		$this->assertStringContainsString( 'post-456', $result );
	}

	/**
	 * The filter runs on every render_block, so it must be a no-op for any block
	 * other than woocommerce/product-collection.
	 */
	public function test_product_collection_ignores_other_blocks(): void {
		$list_tracking = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );

		$content = '<li class="wc-block-product post-123">X</li>';
		$result  = $list_tracking->add_productdata_to_product_collection_block(
			$content,
			array(
				'blockName' => 'core/paragraph',
				'attrs'     => array(),
			)
		);

		$this->assertSame( $content, $result );
		$this->assertStringNotContainsString( 'gtm4wp_productdata', $result );
	}

	/**
	 * A non-product <li> inside the collection (pagination, layout wrappers) has
	 * neither the wc-block-product class nor a post-{ID}, so it must be left alone.
	 */
	public function test_product_collection_skips_non_product_list_items(): void {
		$list_tracking = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		Functions\when( 'wc_get_product' )->alias(
			fn ( $id ) => $this->make_product( array( 'id' => (int) $id ) )
		);

		$content = '<ul class="wc-block-product-template">'
			. '<li class="wc-block-pagination-numbers">1</li>'
			. '<li class="wc-block-product post-123">P</li>'
			. '</ul>';

		$result = $list_tracking->add_productdata_to_product_collection_block(
			$content,
			array(
				'blockName' => 'woocommerce/product-collection',
				'attrs'     => array(),
			)
		);

		$this->assertSame( 1, substr_count( $result, 'class="gtm4wp_productdata"' ) );
	}

	/**
	 * The block's "collection" preset attribute maps to a friendlier GA4
	 * item_list_name (mirroring the shortcode/widget list names).
	 */
	public function test_product_collection_maps_preset_to_list_name(): void {
		$list_tracking = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		Functions\when( 'wc_get_product' )->alias(
			fn ( $id ) => $this->make_product( array( 'id' => (int) $id ) )
		);

		$result = $list_tracking->add_productdata_to_product_collection_block(
			'<li class="wc-block-product post-123">P</li>',
			array(
				'blockName' => 'woocommerce/product-collection',
				'attrs'     => array( 'collection' => 'woocommerce/product-collection/on-sale' ),
			)
		);

		$this->assertStringContainsString( 'Sale Products', $result );
	}

	/**
	 * The whole collection mapping, pinned as a literal table.
	 *
	 * The slugs are WooCommerce's, so this is what breaks when they rename one or
	 * when someone edits a name without its id (upstream registry U26). Only the
	 * default collection was asserted before. Four of the presets deliberately
	 * share an identity with the legacy grid block they replace, so a store that
	 * migrates a block keeps its GA4 list - which is only true while both sides
	 * agree, and this is the side that can be tested here.
	 *
	 * @param string $collection The block's collection attribute.
	 * @param string $name       The list name it must report.
	 * @param string $id         The stable id it must report.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_product_collection_identities' )]
	public function test_product_collection_preset_reports_its_own_identity( string $collection, string $name, string $id ): void {
		$list_tracking = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		Functions\when( 'wc_get_product' )->alias(
			fn ( $product_id ) => $this->make_product( array( 'id' => (int) $product_id ) )
		);

		$result = $list_tracking->add_productdata_to_product_collection_block(
			'<li class="wc-block-product post-123">P</li>',
			array(
				'blockName' => 'woocommerce/product-collection',
				'attrs'     => '' === $collection ? array() : array( 'collection' => $collection ),
			)
		);

		$item = $this->decode_product_data( $result );

		$this->assertSame( $name, $item['item_list_name'] );
		$this->assertSame( $id, $item['item_list_id'] );
	}

	/**
	 * WooCommerce's 14 core Product Collection slugs (CoreCollectionNames,
	 * measured 2026-08-06 against WC trunk and the 11.0.0 tag), plus the
	 * no-collection case that stands for a custom query or a third-party
	 * collection.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function provide_product_collection_identities(): array {
		return array(
			'product catalog' => array( 'woocommerce/product-collection/product-catalog', 'General Product List', 'general-product-list' ),
			'on sale'         => array( 'woocommerce/product-collection/on-sale', 'Sale Products', 'sale-products' ),
			'best sellers'    => array( 'woocommerce/product-collection/best-sellers', 'Best Selling Products', 'best-selling-products' ),
			'top rated'       => array( 'woocommerce/product-collection/top-rated', 'Top Rated Products', 'top-rated-products' ),
			'new arrivals'    => array( 'woocommerce/product-collection/new-arrivals', 'New Products', 'new-products' ),
			'featured'        => array( 'woocommerce/product-collection/featured', 'Featured Products', 'featured-products' ),
			'related'         => array( 'woocommerce/product-collection/related', 'Related Products', 'related-products' ),
			'upsells'         => array( 'woocommerce/product-collection/upsells', 'Upsell Products', 'upsell-products' ),
			'cross-sells'     => array( 'woocommerce/product-collection/cross-sells', 'Cross-Sell Products', 'cross-sell-products' ),
			'hand-picked'     => array( 'woocommerce/product-collection/hand-picked', 'Handpicked Products', 'handpicked-products' ),
			'by category'     => array( 'woocommerce/product-collection/by-category', 'Product Category List', 'product-category-list' ),
			'by tag'          => array( 'woocommerce/product-collection/by-tag', 'Products By Tag', 'products-by-tag' ),
			'by brand'        => array( 'woocommerce/product-collection/by-brand', 'Products By Brand', 'products-by-brand' ),
			'cart contents'   => array( 'woocommerce/product-collection/cart-contents', 'Cart Contents', 'cart-contents' ),
			'custom query'    => array( '', 'Product Collection', 'product-collection' ),
		);
	}

	/**
	 * WooCommerce gives the legacy grid-block filter no block context, so this
	 * path can only write a generic placeholder pair that the frontend tracker
	 * replaces from the wp-block-{name} class on the grid container. What PHP owes
	 * that arrangement is a pair that is *consistent* - the name and the id of the
	 * same list - because an unknown block class leaves exactly this pair in place.
	 * A name that does not match its id would collapse every grid on the page onto
	 * one id in GA4, which is the defect this change fixes on the JS side.
	 *
	 * @return void
	 */
	public function test_wc_block_span_carries_a_matching_name_and_id_pair(): void {
		$this->stub_translated_locale();

		$result = $this->make_list_tracking()->add_productdata_to_wc_block(
			'<li class="wc-block-grid__product first">ITEM</li>',
			(object) array( 'permalink' => 'https://example.com/product/' ),
			$this->make_product()
		);

		$item = $this->decode_product_data( $result );

		$this->assertSame( 'DE General Product List', $item['item_list_name'] );
		$this->assertSame( 'general-product-list', $item['item_list_id'] );
	}

	/**
	 * PA-7 / TC-6: the injection uses preg_replace_callback (not a data-bearing
	 * replacement string), so a product field carrying a literal `$1` survives
	 * verbatim rather than being interpreted as a backreference.
	 */
	public function test_product_collection_preserves_dollar_sequences_in_product_name(): void {
		$list_tracking = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		Functions\when( 'wc_get_product' )->alias(
			fn ( $id ) => $this->make_product(
				array(
					'id'    => (int) $id,
					'title' => 'Deal $1 special',
				)
			)
		);

		$result = $list_tracking->add_productdata_to_product_collection_block(
			'<li class="wc-block-product post-123">ITEM</li>',
			array(
				'blockName' => 'woocommerce/product-collection',
				'attrs'     => array(),
			)
		);

		$this->assertStringContainsString( 'Deal $1 special', $result );
		$this->assertStringContainsString( 'ITEM', $result );
	}

	public function test_add_to_cart_link_filter_adds_data_for_a_standalone_button(): void {
		// The [add_to_cart] shortcode renders a button outside a product loop, so it
		// gets no .gtm4wp_productdata list markup; the data is attached to the button
		// itself so the frontend click handler can still fire add_to_cart (#110).
		Functions\when( 'doing_action' )->justReturn( false );

		$result = $this->make_list_tracking()->add_to_cart_link_filter(
			'<a href="?add-to-cart=123" class="button add_to_cart_button ajax_add_to_cart">Add to cart</a>',
			$this->make_product()
		);

		$this->assertStringContainsString( 'data-gtm4wp_product_data=', $result, 'A standalone add-to-cart button must carry its product data.' );
		$this->assertStringContainsString( 'Test Product', $result, 'The encoded data must include the actual product.' );
	}

	public function test_add_to_cart_link_filter_skips_buttons_inside_a_product_loop(): void {
		// Inside a loop the .gtm4wp_productdata span already carries the data, so the
		// button must be left untouched to avoid duplicating it.
		Functions\when( 'doing_action' )->justReturn( true );

		$link   = '<a href="?add-to-cart=123" class="button add_to_cart_button">Add to cart</a>';
		$result = $this->make_list_tracking()->add_to_cart_link_filter( $link, $this->make_product() );

		$this->assertSame( $link, $result, 'A loop add-to-cart button must not be modified.' );
	}

	public function test_add_to_cart_link_filter_skips_non_simple_products(): void {
		// Variable/grouped buttons are "Select options" links to the product page,
		// so there is no inline add to track at this point.
		Functions\when( 'doing_action' )->justReturn( false );

		$link   = '<a href="?add-to-cart=123" class="button add_to_cart_button">Select options</a>';
		$result = $this->make_list_tracking()->add_to_cart_link_filter(
			$link,
			$this->make_product( array( 'type' => 'variable' ) )
		);

		$this->assertSame( $link, $result );
	}

	/**
	 * Sets up the WP functions quick_view_before_single_product() reads.
	 *
	 * @param \WC_Product $product The product wc_get_product() returns.
	 * @return void
	 */
	private function stub_quick_view_env( \WC_Product $product ): void {
		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );
	}

	public function test_quick_view_outputs_event_only_when_tracking_disabled(): void {
		// Gate off: only the bare view_item event, no product/ecommerce data.
		$this->stub_quick_view_env( $this->make_product() );

		ob_start();
		$this->make_list_tracking()->quick_view_before_single_product();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'id="gtm4wp_quickview_data"', $out );
		$this->assertStringNotContainsString( 'ecommerce', $out );
		$this->assertStringNotContainsString( 'productType', $out );
	}

	public function test_quick_view_simple_product_adds_ecommerce_block(): void {
		$this->stub_quick_view_env( $this->make_product() );

		ob_start();
		$this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->quick_view_before_single_product();
		$out = ob_get_clean();

		// The default (simple) branch carries the ecommerce item and is not variable.
		$this->assertStringContainsString( 'ecommerce', $out );
		$this->assertStringContainsString( 'productIsVariable', $out );
		$this->assertStringContainsString( 'view_item', $out );
	}

	public function test_quick_view_variable_product_sets_flag_without_ecommerce(): void {
		$this->stub_quick_view_env( $this->make_product( array( 'type' => 'variable' ) ) );

		ob_start();
		$this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->quick_view_before_single_product();
		$out = ob_get_clean();

		// The variable branch reports productIsVariable but builds no ecommerce block.
		$this->assertStringContainsString( 'productIsVariable', $out );
		$this->assertStringNotContainsString( 'ecommerce', $out );
	}

	public function test_cart_item_product_filter_stores_proddata_global_and_returns_product(): void {
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );

		$product = $this->make_product();
		$result  = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->cart_item_product_filter(
				$product,
				array(
					'line_subtotal' => 10.0,
					'line_total'    => 10.0,
					'quantity'      => 1,
				)
			);

		$this->assertSame( $product, $result, 'The filter must return the product unchanged.' );
		$this->assertIsArray( $GLOBALS['gtm4wp_cart_item_proddata'] );
		$this->assertSame( 'Test Product', $GLOBALS['gtm4wp_cart_item_proddata']['item_name'] );
	}

	public function test_cart_item_product_filter_passes_null_source_when_called_without_a_cart_item(): void {
		// #39: the "null when there is no per-line source" contract. Called with only the
		// product (its $cart_item default), the source handed to process_product - and so
		// to the gtm4wp_eec_item_with_source filter's third argument - must be NULL, not
		// the empty-array default.
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );

		$captured_source = 'unset';
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE )
			->once()
			->andReturnUsing(
				static function ( $item, $context, $source ) use ( &$captured_source ) {
					$captured_source = $source;
					return $item;
				}
			);

		$this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->cart_item_product_filter( $this->make_product() );

		$this->assertNull( $captured_source, 'With no cart item, the downstream source is null (no per-line source), not an empty array.' );
	}

	public function test_cart_item_remove_link_filter_injects_data_and_resets_global(): void {
		// The constructor resets the per-item global, so stage it AFTER building.
		$list_tracking                        = $this->make_list_tracking();
		$GLOBALS['gtm4wp_cart_item_proddata'] = array( 'item_name' => 'Test Product' );

		$out = $list_tracking->cart_item_remove_link_filter( '<a href="?remove_item=abc">x</a>' );

		$this->assertStringContainsString( 'data-gtm4wp_product_data="', $out, 'The remove link must carry the product data.' );
		// The JSON is written into an HTML attribute (esc_attr), so its quotes are
		// encoded, never raw " that would break out of the attribute (TS-2).
		$this->assertStringContainsString( '&quot;item_name&quot;', $out );
		$this->assertStringNotContainsString( '"item_name"', $out );
		$this->assertSame( '', $GLOBALS['gtm4wp_cart_item_proddata'], 'The per-item global must be reset after use.' );
	}

	/**
	 * The sibling of test_add_productdata_to_wc_block_preserves_dollar_backreference_sequences:
	 * this injector reaches preg_replace through Helpers::str_replace_first(), so the
	 * addcslashes() guard on the block injector never covered it. Here the match is
	 * `href="` - it contains a quote - so expanding a $0 from product data lands a raw
	 * quote inside the esc_attr'd attribute and terminates it (PA-7 / RI-17).
	 */
	public function test_cart_item_remove_link_filter_keeps_backreference_sequences_out_of_the_attribute(): void {
		$list_tracking                        = $this->make_list_tracking();
		$GLOBALS['gtm4wp_cart_item_proddata'] = array( 'item_name' => 'Deal $0 special' );

		$out = $list_tracking->cart_item_remove_link_filter( '<a href="?remove_item=abc">x</a>' );

		// The literal `$0` survives verbatim in the product name...
		$this->assertStringContainsString( 'Deal $0 special', $out );
		// ...and the matched text was not substituted in its place.
		$this->assertStringNotContainsString( 'Deal href=" special', $out );
		// Both directions: exactly one href attribute, so nothing broke out of the
		// data attribute to create a second one.
		$this->assertSame( 1, substr_count( $out, 'href="' ), 'No injected duplicate href attribute.' );
	}

	public function test_cart_item_remove_link_filter_returns_link_unchanged_without_global(): void {
		// No product data staged (non-array/unset global) -> the link is untouched.
		unset( $GLOBALS['gtm4wp_cart_item_proddata'] );
		$link = '<a href="?remove_item=abc">x</a>';

		$this->assertSame( $link, $this->make_list_tracking()->cart_item_remove_link_filter( $link ) );
	}

	public function test_single_add_to_cart_tracking_outputs_hidden_span_when_enabled(): void {
		$GLOBALS['product'] = $this->make_product();

		ob_start();
		$this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->single_add_to_cart_tracking();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'class="gtm4wp_single_productdata"', $out );
		$this->assertStringContainsString( 'data-gtm4wp_product_data="', $out );
		$this->assertStringContainsString( '&quot;item_name&quot;', $out );
	}

	/**
	 * #462: WooCommerce's blockified Add to Cart + Options block scans the output
	 * of the add-to-cart hooks and falls back to a legacy full-page POST form when
	 * it finds any INPUT, TEXTAREA, SELECT, BUTTON or FORM tag, which disables the
	 * interactive add to cart. The markup this method prints must therefore never
	 * contain a form element. Both directions of the fix: the span is asserted
	 * present above, the form elements are asserted absent here.
	 */
	public function test_single_add_to_cart_tracking_output_contains_no_form_elements(): void {
		$GLOBALS['product'] = $this->make_product();

		ob_start();
		$this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->single_add_to_cart_tracking();
		$out = ob_get_clean();

		$this->assertNotSame( '', $out, 'The tracking markup must still be printed.' );
		$this->assertDoesNotMatchRegularExpression(
			'/<\s*(input|textarea|select|button|form)\b/i',
			$out,
			'The add-to-cart hook output must not contain a form element, or WooCommerce renders the block in legacy mode.'
		);
	}

	/**
	 * The span class deliberately differs from the .gtm4wp_productdata list markup:
	 * that class is swept page-wide into view_item_list impressions by the frontend
	 * tracker, and a product detail page must not join them.
	 */
	public function test_single_add_to_cart_tracking_span_does_not_reuse_the_list_markup_class(): void {
		$GLOBALS['product'] = $this->make_product();

		ob_start();
		$this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) )
			->single_add_to_cart_tracking();
		$out = ob_get_clean();

		$this->assertStringNotContainsString( 'class="gtm4wp_productdata"', $out );
	}

	public function test_single_add_to_cart_tracking_outputs_nothing_when_disabled(): void {
		$GLOBALS['product'] = $this->make_product();

		ob_start();
		$this->make_list_tracking()->single_add_to_cart_tracking();
		$out = ob_get_clean();

		$this->assertSame( '', $out, 'The tracking markup must not be printed when e-commerce tracking is off.' );
	}

	public function test_grouped_product_list_column_label_appends_hidden_span(): void {
		// The constructor seeds the grouped index (to 1); reset it AFTER building.
		$list_tracking                        = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		$GLOBALS['gtm4wp_grouped_product_ix'] = 0;

		$out = $list_tracking->grouped_product_list_column_label( 'Qty', $this->make_product() );

		$this->assertStringContainsString( 'Qty', $out, 'The original label must be preserved.' );
		$this->assertStringContainsString( 'class="gtm4wp_productdata"', $out );
		$this->assertStringContainsString( '&quot;item_name&quot;', $out );
		$this->assertSame( 1, $GLOBALS['gtm4wp_grouped_product_ix'], 'The grouped index must advance.' );
	}

	public function test_grouped_product_list_column_label_returns_label_without_product(): void {
		$this->assertSame(
			'Qty',
			$this->make_list_tracking()->grouped_product_list_column_label( 'Qty', null ),
			'A missing product must leave the label untouched.'
		);
	}

	public function test_after_template_part_injects_product_data_into_widget_product(): void {
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );

		// gtm4wp_product_counter (0) and gtm4wp_last_widget_title are seeded by the
		// constructor; stage the current product AFTER building.
		$list_tracking      = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		$GLOBALS['product'] = $this->make_product();

		// after_template_part() consumes the current output buffer (the rendered
		// template item) and re-echoes it with the data attribute injected; capture
		// that echo in an outer buffer.
		ob_start();                                       // Outer: capture the echo.
		ob_start();                                       // Inner: the "template" output.
		echo '<a href="https://example.com/p/">Item</a>';
		$list_tracking->after_template_part( 'content-widget-product.php' );
		$out = (string) ob_get_clean();                   // Outer buffer.

		$this->assertStringContainsString( 'data-gtm4wp_product_data="', $out );
		$this->assertSame( 1, $GLOBALS['gtm4wp_product_counter'], 'The widget product counter must advance.' );
	}

	public function test_after_template_part_passes_non_widget_template_unchanged(): void {
		$GLOBALS['product'] = $this->make_product();

		ob_start();
		ob_start();
		echo '<a href="https://example.com/p/">Item</a>';
		$this->make_list_tracking()->after_template_part( 'content-product.php' );
		$out = (string) ob_get_clean();

		$this->assertSame( '<a href="https://example.com/p/">Item</a>', $out, 'A non-widget template must pass through unmodified.' );
	}

	/**
	 * The list-name setters are what give every impression and click its
	 * `item_list_name`, so a wrong or missing one silently mislabels a whole
	 * report in GA4 - and each is a separate WooCommerce hook, so nothing else
	 * covers them. Registered but unasserted until test-review Run 5 (gap T34).
	 *
	 * They all write $woocommerce_loop['listtype'], which the tearDown above now
	 * resets (TS-7).
	 *
	 * @param string $method   The ListTracking method to call.
	 * @param string $expected The list name it must set.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_list_name_setters' )]
	public function test_loop_list_name_setters_label_the_loop( string $method, string $expected ): void {
		$list_tracking = $this->make_list_tracking();

		$list_tracking->$method();

		$this->assertSame( $expected, $GLOBALS['woocommerce_loop']['listtype'] );
	}

	/**
	 * The shortcode-loop setters: method name => list name it must set.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_list_name_setters(): array {
		return array(
			'recent'       => array( 'before_recent_products_loop', 'Recent Products' ),
			'sale'         => array( 'before_sale_products_loop', 'Sale Products' ),
			'best selling' => array( 'before_best_selling_products_loop', 'Best Selling Products' ),
			'top rated'    => array( 'before_top_rated_products_loop', 'Top Rated Products' ),
			'featured'     => array( 'before_featured_products_loop', 'Featured Products' ),
			'related'      => array( 'before_related_products_loop', 'Related Products' ),
		);
	}

	/**
	 * The three filter-position setters must label the loop AND return their
	 * argument untouched: they run on woocommerce_related_products_columns /
	 * woocommerce_cross_sells_columns / woocommerce_upsells_columns, where
	 * swallowing the value would change the rendered column count.
	 *
	 * @param string $method   The ListTracking method to call.
	 * @param string $expected The list name it must set.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_passthrough_setters' )]
	public function test_filter_list_name_setters_pass_their_argument_through( string $method, string $expected ): void {
		$list_tracking = $this->make_list_tracking();

		$this->assertSame( 4, $list_tracking->$method( 4 ), 'The filtered value must be returned unchanged.' );
		$this->assertSame( $expected, $GLOBALS['woocommerce_loop']['listtype'] );
	}

	/**
	 * The filter-position setters: method name => list name it must set.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_passthrough_setters(): array {
		return array(
			'related'    => array( 'add_related_to_loop', 'Related Products' ),
			'cross-sell' => array( 'add_cross_sell_to_loop', 'Cross-Sell Products' ),
			'upsell'     => array( 'add_upsells_to_loop', 'Upsell Products' ),
		);
	}

	/**
	 * Pulls the GA4 item back out of an injected .gtm4wp_productdata span.
	 *
	 * The JSON is esc_attr()'d into an HTML attribute, so it has to come back
	 * through the entity decode before json_decode sees it.
	 *
	 * @param string $html Markup containing exactly one product-data attribute.
	 * @return array<string, mixed> The decoded GA4 item.
	 */
	private function decode_product_data( string $html ): array {
		$this->assertSame(
			1,
			preg_match( '/data-gtm4wp_product_data="([^"]*)"/', $html, $matches ),
			'Exactly one product-data attribute expected in the markup.'
		);

		$decoded = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
		$this->assertIsArray( $decoded, 'The product-data attribute must hold a JSON object.' );

		return $decoded;
	}

	/**
	 * Renders one classic-loop product item and returns its markup.
	 *
	 * @param ListTracking $list_tracking The instance under test.
	 * @return string The echoed hidden span.
	 */
	private function render_shop_loop_item( ListTracking $list_tracking ): string {
		$GLOBALS['product'] = $this->make_product();
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/' );

		ob_start();
		$list_tracking->after_shop_loop_item();

		return (string) ob_get_clean();
	}

	/**
	 * A translator that actually changes the string, so a locale-dependent id
	 * cannot pass as a locale-independent one. Anything derived from the
	 * translated name comes back slugged as "de-...".
	 *
	 * @return void
	 */
	private function stub_translated_locale(): void {
		Functions\when( '__' )->alias( static fn ( $text ) => 'DE ' . $text );
	}

	/**
	 * GA4 keys its list reports on item_list_id, so the id has to be the same on
	 * every language version of a store while the name is translated. Deriving it
	 * from the translated name - which is what the code did before the explicit
	 * literals - gave a German store "de-sale-products" where an English one had
	 * "sale-products", splitting one list into one report per language.
	 *
	 * This is the test that fails if a list literal is dropped and the derivation
	 * comes back.
	 *
	 * @param string             $method The ListTracking setter to call.
	 * @param array<int, mixed>  $args   Arguments that setter needs.
	 * @param string             $name   The untranslated list name.
	 * @param string             $id     The stable id it must report.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_loop_list_identities' )]
	public function test_loop_list_id_is_stable_across_locales( string $method, array $args, string $name, string $id ): void {
		$this->stub_translated_locale();

		$list_tracking = $this->make_list_tracking();
		$list_tracking->$method( ...$args );

		$item = $this->decode_product_data( $this->render_shop_loop_item( $list_tracking ) );

		$this->assertSame( 'DE ' . $name, $item['item_list_name'], 'The name is translated for the visitor...' );
		$this->assertSame( $id, $item['item_list_id'], '...while the id stays the locale-independent literal.' );
	}

	/**
	 * Every list setter, with the name it labels the loop with and the stable id
	 * that name must never be derived from.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: string, 3: string}>
	 */
	public static function provide_loop_list_identities(): array {
		return array(
			'recent'       => array( 'before_recent_products_loop', array(), 'Recent Products', 'recent-products' ),
			'sale'         => array( 'before_sale_products_loop', array(), 'Sale Products', 'sale-products' ),
			'best selling' => array( 'before_best_selling_products_loop', array(), 'Best Selling Products', 'best-selling-products' ),
			'top rated'    => array( 'before_top_rated_products_loop', array(), 'Top Rated Products', 'top-rated-products' ),
			'featured'     => array( 'before_featured_products_loop', array(), 'Featured Products', 'featured-products' ),
			'related'      => array( 'add_related_to_loop', array( 4 ), 'Related Products', 'related-products' ),
			'cross-sell'   => array( 'add_cross_sell_to_loop', array( 4 ), 'Cross-Sell Products', 'cross-sell-products' ),
			'upsell'       => array( 'add_upsells_to_loop', array( 4 ), 'Upsell Products', 'upsell-products' ),
		);
	}

	/**
	 * The two lists nothing sets a loop type for: an unlabelled loop and the
	 * search results page. Same locale rule.
	 *
	 * @return void
	 */
	public function test_unlabelled_and_search_lists_have_stable_ids(): void {
		$this->stub_translated_locale();

		$item = $this->decode_product_data( $this->render_shop_loop_item( $this->make_list_tracking() ) );
		$this->assertSame( 'DE General Product List', $item['item_list_name'] );
		$this->assertSame( 'general-product-list', $item['item_list_id'] );

		Functions\when( 'is_search' )->justReturn( true );

		$item = $this->decode_product_data( $this->render_shop_loop_item( $this->make_list_tracking() ) );
		$this->assertSame( 'DE Search Results', $item['item_list_name'] );
		$this->assertSame( 'search-results', $item['item_list_id'] );
	}

	public function test_grouped_product_list_has_a_stable_id(): void {
		$this->stub_translated_locale();

		$item = $this->decode_product_data(
			(string) $this->make_list_tracking()->grouped_product_list_column_label( '', $this->make_product() )
		);

		$this->assertSame( 'DE Grouped Product Detail Page', $item['item_list_name'] );
		$this->assertSame( 'grouped-product-detail-page', $item['item_list_id'] );
	}

	/**
	 * $woocommerce_loop['listtype'] is a 1.x extension point third party code
	 * writes to name a list of its own. Such a name has no stable id to pair with,
	 * so it keeps deriving one from the name exactly as before - and, crucially,
	 * must never inherit the id of whatever list the plugin labelled last. That
	 * mismatch is the defect this whole change is about, just from the other side.
	 *
	 * @return void
	 */
	public function test_a_third_party_list_name_never_inherits_our_list_id(): void {
		$list_tracking = $this->make_list_tracking();

		// The plugin labels a loop, then something else relabels it mid-flight
		// without clearing our paired identity.
		$list_tracking->before_sale_products_loop();
		$GLOBALS['woocommerce_loop']['listtype'] = 'Bundled Products';

		$item = $this->decode_product_data( $this->render_shop_loop_item( $list_tracking ) );

		$this->assertSame( 'Bundled Products', $item['item_list_name'] );
		$this->assertSame( 'bundled-products', $item['item_list_id'], 'The id must follow the name that is actually reported.' );
		$this->assertNotSame( 'sale-products', $item['item_list_id'] );
	}

	/**
	 * A widget title is authored by the site owner, so there is no stable literal
	 * to pair it with and its id keeps being derived from the title.
	 *
	 * @return void
	 */
	public function test_widget_list_id_is_still_derived_from_the_widget_title(): void {
		$list_tracking = $this->make_list_tracking();
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/' );
		$GLOBALS['product']                  = $this->make_product();
		$GLOBALS['gtm4wp_last_widget_title'] = 'Summer Picks (widget)';
		$GLOBALS['gtm4wp_product_counter']   = 1;

		ob_start();
		$list_tracking->before_template_part();
		echo '<a href="https://example.com/p/">Item</a>';
		$list_tracking->after_template_part( 'content-widget-product.php' );
		$markup = (string) ob_get_clean();

		$item = $this->decode_product_data( $markup );

		$this->assertSame( 'Summer Picks (widget)', $item['item_list_name'] );
		$this->assertSame( 'summer-picks-widget', $item['item_list_id'] );
	}

	public function test_reset_loop_clears_the_list_name_and_returns_the_query(): void {
		// Hooked to loop_end / the shortcode after-hooks: without the reset, the
		// NEXT loop on the page inherits the previous loop's list name and every
		// impression in it is attributed to the wrong list.
		$list_tracking = $this->make_list_tracking();
		$list_tracking->before_sale_products_loop();
		$this->assertSame( 'Sale Products', $GLOBALS['woocommerce_loop']['listtype'] );

		$query = new \stdClass();

		$this->assertSame( $query, $list_tracking->reset_loop( $query ), 'The query must be returned unchanged.' );
		$this->assertSame( '', $GLOBALS['woocommerce_loop']['listtype'], 'The list name must not leak into the next loop.' );
		// T50: the identity key is reset alongside the name. The name-match guard
		// in after_shop_loop_item() would neutralize a stale identity anyway, but
		// this is the reset's own contract - both keys, together.
		$this->assertNull( $GLOBALS['woocommerce_loop']['gtm4wp_list_identity'], 'The resolved list identity must be cleared with the name.' );
	}

	public function test_reset_loop_without_an_argument_returns_null(): void {
		// It is hooked as an action too (loop_end passes nothing).
		$this->assertNull( $this->make_list_tracking()->reset_loop() );
		$this->assertSame( '', $GLOBALS['woocommerce_loop']['listtype'] );
		$this->assertNull( $GLOBALS['woocommerce_loop']['gtm4wp_list_identity'] );
	}

	public function test_widget_title_filter_names_the_list_and_restarts_the_counter(): void {
		// The widget title becomes the list name for the products inside it, and
		// the per-widget counter restarts so item_index counts from 1 within each
		// widget rather than continuing across the page.
		$list_tracking                     = $this->make_list_tracking();
		$GLOBALS['gtm4wp_product_counter'] = 17;

		$this->assertSame(
			'On Sale',
			$list_tracking->widget_title_filter( 'On Sale' ),
			'The widget title itself must pass through unmodified - other plugins render it.'
		);

		$this->assertSame( 'On Sale (widget)', $GLOBALS['gtm4wp_last_widget_title'], 'The list name marks the source as a widget.' );
		$this->assertSame( 1, $GLOBALS['gtm4wp_product_counter'], 'The item counter restarts inside each widget.' );
	}

	public function test_before_template_part_opens_a_buffer_that_after_template_part_consumes(): void {
		// The pair is what lets after_template_part() inject the data attribute
		// into the widget product link. An unbalanced before_ would swallow page
		// output, so assert the nesting level returns to where it started.
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/p/' );

		$list_tracking      = $this->make_list_tracking( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ) );
		$GLOBALS['product'] = $this->make_product();

		$level = ob_get_level();

		ob_start(); // Outer: capture what after_template_part() re-echoes.
		$list_tracking->before_template_part();
		echo '<a href="https://example.com/p/">Item</a>';
		$list_tracking->after_template_part( 'content-widget-product.php' );
		$out = (string) ob_get_clean();

		$this->assertSame( $level, ob_get_level(), 'The buffer opened by before_template_part() must be consumed.' );
		$this->assertStringContainsString( 'data-gtm4wp_product_data="', $out );
	}
}
