<?php
/**
 * Unit tests for the WooCommerce product-list markup builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
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
}
