<?php
/**
 * Unit tests for the Easy Digital Downloads PageDataLayer.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Ecommerce\Helpers as EcommerceHelpers;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Modules\EasyDigitalDownloads\DownloadData;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Modules\EasyDigitalDownloads\PageDataLayer;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/edd-stubs.php';

/**
 * Covers the EDD page load data layer: customer/cart blocks, the server-side
 * view_item / view_cart / begin_checkout events and the success-page
 * purchase flow with its eligibility gauntlet, all asserted at the final
 * inline-script sink (hex-flag JSON encoding included).
 */
final class EddPageDataLayerTest extends TestCase {

	/**
	 * Captured wp_add_inline_script calls as handle/script pairs.
	 *
	 * @var array<int, array{handle: string, script: string}>
	 */
	private array $inline_scripts = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			static fn ( $data, $flags = 0 ) => json_encode( $data, $flags )
		);

		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );

		// Page conditionals default to "none of these pages".
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'edd_is_success_page' )->justReturn( false );
		Functions\when( 'edd_is_checkout' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		// The buyer viewing their own confirmation page is the default: EDD would
		// show them the receipt, so nothing is withheld. Individual tests override
		// this to false to exercise the leaked/shared-URL withholding (#219).
		// NOTE: the sibling withhold direction - `! function_exists(
		// 'edd_can_view_receipt' )` in add_success_page_data() - cannot be
		// exercised in-process: Brain Monkey defines a stubbed function
		// permanently, so once this stub exists the function does too. Documented
		// untestable leg (same status as the NOTE in PageVariablesModuleTest),
		// not a gap; the withhold-on-unreadable direction is the safe one.
		Functions\when( 'edd_can_view_receipt' )->justReturn( true );

		Functions\when( 'edd_get_currency' )->justReturn( 'USD' );
		Functions\when( 'edd_get_download_sku' )->justReturn( false );
		Functions\when( 'edd_get_order_meta' )->justReturn( '' );
		Functions\when( 'edd_get_payment_meta' )->justReturn( null );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => 'My eBook',
					'price' => 9.99,
				)
			)
		);

		$this->inline_scripts = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $script ) {
				$this->inline_scripts[] = array(
					'handle' => (string) $handle,
					'script' => (string) $script,
				);

				return true;
			}
		);

		// Isolate the request/queue state between tests (TS-7).
		unset( $_SERVER['HTTP_X_REQUESTED_WITH'], $_GET['payment_key'], $_GET['order'], $_GET['id'], $_COOKIE['gtm4wp_orderid_tracked'] );
		$GLOBALS['gtm4wp_additional_datalayer_pushes'] = array();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_REQUESTED_WITH'], $_GET['payment_key'], $_GET['order'], $_GET['id'], $_COOKIE['gtm4wp_orderid_tracked'] );
		unset( $GLOBALS['gtm4wp_additional_datalayer_pushes'] );

		parent::tearDown();
	}

	/**
	 * Builds a PageDataLayer with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return PageDataLayer
	 */
	private function make_page_datalayer( array $stored = array() ): PageDataLayer {
		Functions\when( 'get_option' )->justReturn( $stored );

		$options = new Options( ( new EasyDigitalDownloadsModule() )->defaults() );

		return new PageDataLayer( $options, new DownloadData( $options ), new DataLayer( $options ) );
	}

	/**
	 * Returns the concatenated captured inline scripts, optionally for one handle.
	 *
	 * @param string $handle Optional script handle filter.
	 * @return string
	 */
	private function inline_script_output( string $handle = '' ): string {
		$output = '';
		foreach ( $this->inline_scripts as $call ) {
			if ( '' === $handle || $call['handle'] === $handle ) {
				$output .= $call['script'];
			}
		}

		return $output;
	}

	/**
	 * Builds a stubbed complete order.
	 *
	 * @param array<string, mixed> $data Order data overrides.
	 * @return \EDD\Orders\Order
	 */
	private function make_order( array $data = array() ): \EDD\Orders\Order {
		return new \EDD\Orders\Order(
			array_merge(
				array(
					'id'           => 77,
					'status'       => 'complete',
					'currency'     => 'USD',
					'tax'          => 2.0,
					'total'        => 38.0,
					'email'        => 'buyer@example.com',
					'customer_id'  => 0,
					'payment_key'  => 'pk_super_secret_key',
					'date_created' => gmdate( 'Y-m-d H:i:s' ),
					'items'        => array(
						new \EDD\Orders\Order_Item(
							array(
								'product_id' => 55,
								'quantity'   => 2,
								'tax'        => 2.0,
								'total'      => 38.0,
							)
						),
					),
				),
				$data
			)
		);
	}

	public function test_ajax_requests_are_skipped(): void {
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$page_datalayer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA => true )
		);

		$this->assertSame( array( 'existing' => 1 ), $page_datalayer->add_datalayer_data( array( 'existing' => 1 ) ) );
	}

	public function test_customer_data_is_added_only_when_enabled(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'edd_get_customer_by' )->justReturn(
			new \EDD_Customer(
				array(
					'name'           => 'Jane Doe',
					'email'          => 'jane@example.com',
					'purchase_count' => 4,
					'purchase_value' => 100.5,
				)
			)
		);

		$enabled = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA => true )
		)->add_datalayer_data( array() );

		$this->assertSame( 4, $enabled['customerTotalOrders'] );
		$this->assertSame( 100.5, $enabled['customerTotalOrderValue'] );
		$this->assertSame( 'Jane', $enabled['customerFirstName'] );
		$this->assertSame( 'Doe', $enabled['customerLastName'] );
		$this->assertSame( 'jane@example.com', $enabled['customerEmail'] );
		$this->assertSame( hash( 'sha256', 'jane@example.com' ), $enabled['customerEmailHash'] );

		$disabled = $this->make_page_datalayer()->add_datalayer_data( array() );
		$this->assertArrayNotHasKey( 'customerEmail', $disabled );
	}

	public function test_customer_and_cart_blocks_are_omitted_under_cache_safe_mode(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'edd_get_cart_content_details' )->justReturn( array() );

		$data_layer = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA    => true,
				GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL => true,
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
			)
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'customerEmail', $data_layer, 'Visitor-specific data must not be baked into cacheable HTML.' );
		$this->assertArrayNotHasKey( 'cartContent', $data_layer );
	}

	public function test_cart_content_lists_items_and_respects_the_exclusion_filter(): void {
		Functions\when( 'edd_get_cart_subtotal' )->justReturn( 30.0 );
		Functions\when( 'edd_get_cart_total' )->justReturn( 33.0 );
		Functions\when( 'edd_get_cart_content_details' )->justReturn(
			array(
				array(
					'id'       => 55,
					'quantity' => 1,
					'price'    => 9.99,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
				array(
					'id'       => 66,
					'quantity' => 1,
					'price'    => 23.01,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
			)
		);

		// Exclude the second line through the public cart item filter.
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_EDD_CART_ITEM )
			->twice()
			->andReturnUsing( static fn ( $include_item, $cart_item ) => 66 !== $cart_item['id'] );

		$data_layer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL => true )
		)->add_datalayer_data( array() );

		$this->assertSame( 30.0, $data_layer['cartContent']['totals']['subtotal'] );
		$this->assertSame( 33.0, $data_layer['cartContent']['totals']['total'] );
		$this->assertCount( 1, $data_layer['cartContent']['items'] );
		$this->assertArrayNotHasKey( 'internal_id', $data_layer['cartContent']['items'][0] );
	}

	public function test_view_item_fires_on_the_download_page(): void {
		Functions\when( 'is_singular' )->alias( static fn ( $type = '' ) => 'download' === $type );
		Functions\when( 'get_the_ID' )->justReturn( 55 );

		$data_layer = $this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 'default', $data_layer['productType'] );
		$this->assertSame( 0, $data_layer['productHasVariablePrices'] );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertStringContainsString( '"event":"view_item"', $pushed );
		$this->assertStringContainsString( '"value":9.99', $pushed );
		$this->assertStringContainsString( '"item_name":"My eBook"', $pushed );
		$this->assertStringNotContainsString( 'internal_id', $pushed, 'The internal id must never reach the data layer.' );

		$this->assertStringNotContainsString(
			'gtm4wp_edd_variable_view_item',
			$this->inline_script_output( 'gtm4wp-edd' ),
			'A single-price download must not arm the view_item re-fire listener.'
		);

		$this->assertStringNotContainsString(
			EcommerceHelpers::LIST_ATTRIBUTION_JS_WRAPPER,
			$pushed,
			'With list attribution off (default) the push stays exactly the plain form.'
		);
	}

	public function test_view_item_push_is_wrapped_for_client_side_list_attribution(): void {
		// #405: a download page is full-page cacheable, so the list the
		// visitor came from is merged in the browser - the wrapped push stays
		// identical for every visitor and carries the download id the cookie
		// is keyed by.
		Functions\when( 'is_singular' )->alias( static fn ( $type = '' ) => 'download' === $type );
		Functions\when( 'get_the_ID' )->justReturn( 55 );

		$this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION => true )
		)->add_datalayer_data( array() );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertStringContainsString( '"event":"view_item"', $pushed );
		$this->assertStringContainsString(
			'(window.' . EcommerceHelpers::LIST_ATTRIBUTION_JS_WRAPPER . '||function(d){return d;})(',
			$pushed,
			'The push is wrapped in the client-side enrichment helper, with an identity fallback.'
		);
		$this->assertStringContainsString(
			',55));',
			$pushed,
			'The wrapper receives the download id the list attribution cookie is keyed by.'
		);
	}

	public function test_variable_price_download_arms_the_view_item_refire(): void {
		Functions\when( 'is_singular' )->alias( static fn ( $type = '' ) => 'download' === $type );
		Functions\when( 'get_the_ID' )->justReturn( 55 );
		Functions\when( 'edd_get_lowest_price_option' )->justReturn( 5.0 );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'                  => (int) $id,
					'name'                => 'My eBook',
					'price'               => 9.99,
					'has_variable_prices' => true,
				)
			)
		);

		$data_layer = $this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertSame( 1, $data_layer['productHasVariablePrices'] );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertStringContainsString( '"event":"view_item"', $pushed );
		$this->assertStringContainsString( '"value":5', $pushed, 'The lowest price option is reported before the buyer picks one.' );

		$this->assertStringContainsString(
			'window.gtm4wp_edd_variable_view_item = true;',
			$this->inline_script_output( 'gtm4wp-edd' ),
			'A variable-priced download page must arm the client-side view_item re-fire.'
		);
	}

	public function test_view_cart_fires_on_the_cart_shortcode_page(): void {
		Functions\when( 'is_singular' )->alias( static fn ( $type = '' ) => '' === $type );
		Functions\when( 'get_post' )->justReturn( $this->make_post_with_content( '[download_cart]' ) );
		Functions\when( 'has_shortcode' )->alias(
			static fn ( $content, $shortcode ) => str_contains( (string) $content, '[' . $shortcode . ']' )
		);
		Functions\when( 'edd_get_cart_content_details' )->justReturn(
			array(
				array(
					'id'       => 55,
					'quantity' => 2,
					'price'    => 19.98,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
			)
		);

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertStringContainsString( '"event":"view_cart"', $pushed );
		$this->assertStringContainsString( '"value":19.98', $pushed );
	}

	public function test_view_cart_fires_on_the_full_cart_block_page(): void {
		Functions\when( 'is_singular' )->alias( static fn ( $type = '' ) => '' === $type );
		Functions\when( 'get_post' )->justReturn( $this->make_post_with_content( '<!-- wp:edd/cart {"mini":false} /-->' ) );
		Functions\when( 'has_shortcode' )->justReturn( false );
		Functions\when( 'has_block' )->alias(
			static fn ( $block, $content ) => str_contains( (string) $content, 'wp:' . $block )
		);
		// The full cart block sits nested in a group to exercise the
		// recursive walk; mini is explicitly disabled.
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName'   => 'core/group',
					'attrs'       => array(),
					'innerBlocks' => array(
						array(
							'blockName'   => 'edd/cart',
							'attrs'       => array( 'mini' => false ),
							'innerBlocks' => array(),
						),
					),
				),
			)
		);
		Functions\when( 'edd_get_cart_content_details' )->justReturn(
			array(
				array(
					'id'       => 55,
					'quantity' => 2,
					'price'    => 19.98,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
			)
		);

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertStringContainsString( '"event":"view_cart"', $pushed );
	}

	public function test_mini_cart_block_does_not_mark_a_cart_page(): void {
		Functions\when( 'is_singular' )->alias( static fn ( $type = '' ) => '' === $type );
		Functions\when( 'get_post' )->justReturn( $this->make_post_with_content( '<!-- wp:edd/cart /-->' ) );
		Functions\when( 'has_shortcode' )->justReturn( false );
		Functions\when( 'has_block' )->alias(
			static fn ( $block, $content ) => str_contains( (string) $content, 'wp:' . $block )
		);
		// A bare edd/cart block defaults to mini=true (icon + total link, no
		// cart rows), so it must not fire view_cart.
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName'   => 'edd/cart',
					'attrs'       => array(),
					'innerBlocks' => array(),
				),
			)
		);
		Functions\when( 'edd_get_cart_content_details' )->justReturn(
			array(
				array(
					'id'       => 55,
					'quantity' => 1,
					'price'    => 9.99,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
			)
		);

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertStringNotContainsString(
			'"event":"view_cart"',
			$this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' )
		);
	}

	public function test_begin_checkout_encodes_hostile_product_names_at_the_script_sink(): void {
		Functions\when( 'edd_is_checkout' )->justReturn( true );
		Functions\when( 'edd_get_cart_content_details' )->justReturn(
			array(
				array(
					'id'       => 55,
					'quantity' => 1,
					'price'    => 9.99,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
			)
		);
		// A hostile download title trying to break out of the inline script.
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => '</script><script>alert(1)</script>"&\'',
					'price' => 9.99,
				)
			)
		);

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$pushed   = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$checkout = $this->inline_script_output( 'gtm4wp-edd' );

		$this->assertStringContainsString( '"event":"begin_checkout"', $pushed );
		$this->assertStringContainsString( 'window.gtm4wp_checkout_products', $checkout );

		// Both directions (TS-2): the hex-encoded form is present AND no raw
		// break-out sequence survives, on both inline-script sinks. The expected
		// encoded forms are built with the same encoder + flags the source uses
		// (TC-2), never hand-typed.
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- plain PHP encoder on purpose: builds the expectation independent of the wp_json_encode stub.
		$encoded_lt   = trim( (string) json_encode( '<', JSON_HEX_TAG ), '"' );
		$encoded_quot = trim( (string) json_encode( '"', JSON_HEX_QUOT ), '"' );
		$encoded_amp  = trim( (string) json_encode( '&', JSON_HEX_AMP ), '"' );
		$encoded_apos = trim( (string) json_encode( "'", JSON_HEX_APOS ), '"' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode

		foreach ( array( $pushed, $checkout ) as $script_sink ) {
			$this->assertStringContainsString( $encoded_lt, $script_sink );
			$this->assertStringContainsString( $encoded_quot, $script_sink );
			$this->assertStringContainsString( $encoded_amp, $script_sink );
			$this->assertStringContainsString( $encoded_apos, $script_sink );
			$this->assertStringNotContainsString( '</script', $script_sink );
			$this->assertStringNotContainsString( '<script', $script_sink );
			$this->assertStringNotContainsString( '"&\'', $script_sink );
		}
	}

	public function test_success_page_fires_the_guarded_purchase_and_flags_the_order(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET['payment_key'] = 'pk_super_secret_key';

		$order = $this->make_order();
		Functions\expect( 'edd_get_order_by' )
			->once()
			->with( 'payment_key', 'pk_super_secret_key' )
			->andReturn( $order );
		Functions\expect( 'edd_update_order_meta' )
			->once()
			->with( 77, DownloadData::ORDER_TRACKED_META, 1 );

		$data_layer = $this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertTrue( $data_layer['new_customer'] );
		$this->assertSame( 'new', $data_layer['customer_type'], 'GA4 reads customer_type alongside the Google Ads new_customer boolean.' );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertStringContainsString( '"event":"purchase"', $pushed );
		$this->assertStringContainsString( '"transaction_id":"77"', $pushed );
		$this->assertStringContainsString( 'gtm4wp_orderid_tracked', $pushed, 'The browser-side dedupe guard must wrap the purchase push.' );
		$this->assertStringContainsString( '"77" == gtm4wp_orderid_tracked', $pushed );
	}

	public function test_reliable_purchase_tracking_delivers_a_missed_purchase_from_the_session(): void {
		// Any regular page: no success-page, checkout or download conditions.
		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => 'pk_super_secret_key' ) );

		Functions\expect( 'edd_get_order_by' )
			->once()
			->with( 'payment_key', 'pk_super_secret_key' )
			->andReturn( $this->make_order() );
		Functions\expect( 'edd_update_order_meta' )
			->once()
			->with( 77, DownloadData::ORDER_TRACKED_META, 1 );

		$data_layer = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE => true,
				GTM4WP_OPTION_INTEGRATE_EDDORDERDATA      => true,
			)
		)->add_datalayer_data( array() );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertStringContainsString( '"event":"purchase"', $pushed );
		$this->assertArrayNotHasKey(
			'orderData',
			$data_layer,
			'The raw order data block stays exclusive to the confirmation page even with the order-data option on.'
		);
	}

	public function test_reliable_purchase_tracking_stays_inert_when_disabled_or_unsafe(): void {
		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => 'pk_super_secret_key' ) );
		Functions\when( 'edd_get_order_by' )->justReturn( $this->make_order() );
		Functions\when( 'edd_update_order_meta' )->justReturn( true );

		// Off by default.
		$this->make_page_datalayer()->add_datalayer_data( array() );
		$this->assertStringNotContainsString(
			'"event":"purchase"',
			$this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' )
		);

		// Cache-safe mode: a visitor-specific purchase must not be baked into
		// cacheable HTML.
		$this->inline_scripts = array();
		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE => true,
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER        => true,
			)
		)->add_datalayer_data( array() );
		$this->assertStringNotContainsString(
			'"event":"purchase"',
			$this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' )
		);

		// Without the server-side tracked flag the event would repeat on
		// every page view, so the fallback disarms itself.
		$this->inline_scripts = array();
		$this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE => true,
				GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG => true,
			)
		)->add_datalayer_data( array() );
		$this->assertStringNotContainsString(
			'"event":"purchase"',
			$this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' )
		);
	}

	/**
	 * T40's EDD call site: the encode-failure guard is pinned at
	 * ScriptTag::json_literal() itself, but this checkout sink is the one EDD
	 * sink third-party code can poison, because every item passes the public
	 * item-with-source filter. An unencodable value must cost the value, not
	 * the block: `= null;`, never `= ;` (a SyntaxError that would take the
	 * checkout globals and their reader down with it).
	 */
	public function test_checkout_products_fall_back_to_null_when_an_item_cannot_be_encoded(): void {
		Functions\when( 'edd_is_checkout' )->justReturn( true );
		Functions\when( 'edd_get_cart_content_details' )->justReturn(
			array(
				array(
					'id'       => 55,
					'quantity' => 1,
					'price'    => 9.99,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
			)
		);

		// A third-party filter callback can hand back any PHP value. NAN is the
		// faithful unencodable trigger: real wp_json_encode() repairs bad UTF-8
		// but returns false for NAN (see ScriptTagTest's json_literal cases).
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE )
			->andReturnUsing(
				static function ( $item ) {
					$item['broken'] = NAN;
					return $item;
				}
			);

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$checkout = $this->inline_script_output( 'gtm4wp-edd' );
		$this->assertStringContainsString( 'window.gtm4wp_checkout_products = null;', $checkout, 'The guard costs the value, not the block.' );
		$this->assertStringNotContainsString( 'gtm4wp_checkout_products = ;', $checkout, 'An empty literal is a SyntaxError that kills the whole inline block.' );
		$this->assertStringContainsString( 'window.gtm4wp_checkout_value', $checkout, 'The sibling global survives the broken one.' );
	}

	/**
	 * T51's EDD counterpart: the confirmation-page render and the reliable
	 * fallback both funnel into add_purchase_for_order(), and only the
	 * `! $is_success_page` leg keeps them exclusive within one request - the
	 * server-side tracked-meta guard cannot help here, because within a single
	 * render the fallback would re-resolve the order before any meta write is
	 * visible to a stubbed (or object-cached) reader. Deleting that leg left
	 * the whole suite green, so this pins it: the confirmation page with the
	 * reliable option on emits exactly one purchase and one tracked flag.
	 */
	public function test_success_page_render_keeps_the_reliable_fallback_quiet(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET['payment_key'] = 'pk_super_secret_key';

		// The buyer's purchase session is still live (EDD keeps it after
		// checkout), so an unguarded fallback would resolve the same order.
		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => 'pk_super_secret_key' ) );
		Functions\when( 'edd_get_order_by' )->justReturn( $this->make_order() );

		Functions\expect( 'edd_update_order_meta' )
			->once()
			->with( 77, DownloadData::ORDER_TRACKED_META, 1 );

		$this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE => true )
		)->add_datalayer_data( array() );

		$pushed = $this->inline_script_output( 'gtm4wp-additional-datalayer-pushes' );
		$this->assertSame( 1, substr_count( $pushed, '"event":"purchase"' ), 'The confirmation page must deliver the purchase exactly once; the fallback stays quiet on the same request.' );
	}

	public function test_success_page_resolves_the_order_from_the_purchase_session(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => 'pk_from_session' ) );
		Functions\when( 'edd_update_order_meta' )->justReturn( true );

		Functions\expect( 'edd_get_order_by' )
			->once()
			->with( 'payment_key', 'pk_from_session' )
			->andReturn( $this->make_order() );

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	public function test_success_page_without_any_payment_key_stays_silent(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		Functions\when( 'edd_get_purchase_session' )->justReturn( false );
		Functions\expect( 'edd_get_order_by' )->never();

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	public function test_untrackable_status_skips_the_purchase_but_keeps_order_data(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET['payment_key'] = 'pk_super_secret_key';
		Functions\when( 'edd_get_order_by' )->justReturn( $this->make_order( array( 'status' => 'failed' ) ) );
		Functions\expect( 'edd_update_order_meta' )->never();

		$data_layer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDORDERDATA => true )
		)->add_datalayer_data( array() );

		$this->assertArrayHasKey( 'orderData', $data_layer, 'Raw order data is independent of purchase eligibility.' );
		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_script_output() );

		// The payment key authorizes viewing the receipt - it must never appear
		// in the data layer, not even inside the raw order data block.
		$this->assertStringNotContainsString( 'pk_super_secret_key', (string) wp_json_encode( $data_layer ) );
	}

	public function test_already_tracked_order_is_not_pushed_again(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET['payment_key'] = 'pk_super_secret_key';
		Functions\when( 'edd_get_order_by' )->justReturn( $this->make_order() );
		Functions\when( 'edd_get_order_meta' )->justReturn( 1 );
		Functions\expect( 'edd_update_order_meta' )->never();

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	public function test_do_not_flag_option_drops_the_browser_guard_and_the_meta_write(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET['payment_key'] = 'pk_super_secret_key';
		Functions\when( 'edd_get_order_by' )->justReturn( $this->make_order() );
		Functions\expect( 'edd_update_order_meta' )->never();

		$this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG => true )
		)->add_datalayer_data( array() );

		$pushed = $this->inline_script_output();
		$this->assertStringContainsString( '"event":"purchase"', $pushed );
		$this->assertStringNotContainsString( 'gtm4wp_orderid_tracked', $pushed, 'No browser guard when the admin disabled all tracked flags.' );
	}

	/**
	 * #217/#220: the ?order= + ?id= branch of resolve_payment_key() must verify
	 * the receipt hash. The genuine EDD receipt link carries
	 * order = md5( id . payment_key . email ), so a matching hash resolves the
	 * order and the purchase fires as normal.
	 */
	public function test_success_page_order_id_branch_resolves_when_the_receipt_hash_matches(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );

		$order         = $this->make_order();
		$_GET['id']    = '77';
		$_GET['order'] = md5( '77pk_super_secret_keybuyer@example.com' );

		Functions\expect( 'edd_get_order' )
			->once()
			->with( 77 )
			->andReturn( $order );
		Functions\expect( 'edd_get_order_by' )
			->once()
			->with( 'payment_key', 'pk_super_secret_key' )
			->andReturn( $order );
		Functions\expect( 'edd_update_order_meta' )
			->once()
			->with( 77, DownloadData::ORDER_TRACKED_META, 1 );

		$this->make_page_datalayer()->add_datalayer_data( array() );

		$this->assertStringContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	/**
	 * #217: a guessable order id with a WRONG (or absent) ?order= hash must
	 * resolve nothing - otherwise an unauthenticated visitor could map a
	 * sequential order id to that order's data. edd_get_order_by must never be
	 * reached and no purchase or tracked flag is written.
	 */
	public function test_success_page_order_id_branch_is_silent_when_the_receipt_hash_is_wrong(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );

		$_GET['id']    = '77';
		$_GET['order'] = 'deadbeefdeadbeefdeadbeefdeadbeef';

		Functions\when( 'edd_get_order' )->justReturn( $this->make_order() );
		// The vulnerable pre-fix branch resolved the key straight from the id via
		// edd_get_payment_key(); stub it to the real key so this test goes red if
		// the hash check is removed or that branch is ever restored.
		Functions\when( 'edd_get_payment_key' )->justReturn( 'pk_super_secret_key' );
		// The forged hash must stop resolution before the key lookup.
		Functions\expect( 'edd_get_order_by' )->never();
		Functions\expect( 'edd_update_order_meta' )->never();

		$data_layer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDORDERDATA => true )
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'orderData', $data_layer, 'A forged receipt hash must expose no order data at all.' );
		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	/**
	 * A present-but-wrong ?payment_key= (the key lookup finds no order) must
	 * emit nothing and write nothing - the silent-return guard on the request
	 * input, exercised with the lookup actually missing instead of a stubbed
	 * live order.
	 */
	public function test_success_page_is_silent_when_the_payment_key_matches_no_order(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET['payment_key'] = 'pk_no_such_order';

		Functions\when( 'edd_get_order_by' )->justReturn( false );
		Functions\expect( 'edd_update_order_meta' )->never();

		$data_layer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDORDERDATA => true )
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'orderData', $data_layer );
		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	/**
	 * A bogus ?id= (edd_get_order() finds nothing) must stop resolution before
	 * the hash comparison and before any key lookup.
	 */
	public function test_success_page_is_silent_when_the_order_id_matches_no_order(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );

		$_GET['id']    = '999999';
		$_GET['order'] = 'deadbeefdeadbeefdeadbeefdeadbeef';

		Functions\when( 'edd_get_order' )->justReturn( null );
		Functions\expect( 'edd_get_order_by' )->never();
		Functions\expect( 'edd_update_order_meta' )->never();

		$data_layer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDORDERDATA => true )
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'orderData', $data_layer );
		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	/**
	 * ?id= without the accompanying ?order= verification hash is not the
	 * receipt-link shape at all: the branch must not run (no order lookup from
	 * the bare id) and resolution falls through to the purchase session.
	 */
	public function test_success_page_ignores_a_bare_order_id_without_the_hash(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );

		$_GET['id'] = '77';

		Functions\expect( 'edd_get_order' )->never();
		Functions\expect( 'edd_get_order_by' )->never();
		Functions\expect( 'edd_update_order_meta' )->never();
		// Stubbed in this test's own setUp scope (TS-16): the session fallback is
		// reached and must find nothing.
		Functions\when( 'edd_get_purchase_session' )->justReturn( false );

		$data_layer = $this->make_page_datalayer(
			array( GTM4WP_OPTION_INTEGRATE_EDDORDERDATA => true )
		)->add_datalayer_data( array() );

		$this->assertArrayNotHasKey( 'orderData', $data_layer );
		$this->assertStringNotContainsString( '"event":"purchase"', $this->inline_script_output() );
	}

	/**
	 * TC-14: is_singular() true with a null get_post() - the state a conflicting
	 * plugin or unusual template routing produces. The cart-page detection must
	 * decide "not a cart page" without raising a warning.
	 */
	public function test_singular_request_without_a_post_is_not_a_cart_page_and_raises_no_warning(): void {
		// Argument-sensitive: is_singular( 'download' ) stays false (this is not
		// a download page), while the bare is_singular() inside is_cart_page()
		// reports true - the exact tag-true/global-null routing under test.
		Functions\when( 'is_singular' )->alias( static fn ( $type = '' ) => '' === $type );
		Functions\when( 'get_post' )->justReturn( null );

		// Promote warnings to failures: assertions alone pass straight over a
		// warning-raising read (warnings are not failures by default).
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only warning trap.
		set_error_handler(
			static function ( $errno, $errstr ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only.
				throw new \RuntimeException( $errstr );
			},
			E_WARNING | E_NOTICE
		);

		try {
			$data_layer = $this->make_page_datalayer()->add_datalayer_data( array() );
		} finally {
			restore_error_handler();
		}

		$this->assertStringNotContainsString( '"event":"view_cart"', $this->inline_script_output() );
		$this->assertIsArray( $data_layer );
	}

	/**
	 * #219: when EDD itself would not show the visitor the receipt (a leaked or
	 * shared success URL - edd_can_view_receipt() false), the customer identity
	 * blocks are withheld while the purchase EVENT still fires (WC parity).
	 */
	public function test_success_page_withholds_customer_identity_when_the_receipt_is_not_viewable(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		Functions\when( 'edd_can_view_receipt' )->justReturn( false );
		$_GET['payment_key'] = 'pk_super_secret_key';

		Functions\when( 'edd_get_order_by' )->justReturn( $this->make_order() );
		Functions\when( 'edd_update_order_meta' )->justReturn( true );

		$data_layer = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDORDERDATA    => true,
				GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA => true,
			)
		)->add_datalayer_data( array() );

		// The purchase event and the order/totals still ship.
		$this->assertStringContainsString( '"event":"purchase"', $this->inline_script_output() );
		$this->assertArrayHasKey( 'orderData', $data_layer );

		// The identity blocks do not.
		$this->assertArrayNotHasKey( 'customer', $data_layer['orderData'], 'orderData.customer is withheld from a non-viewer.' );
		$this->assertArrayNotHasKey( 'new_customer', $data_layer, 'Buyer-history signals are withheld with the identity block.' );
		$this->assertArrayNotHasKey( 'customer_type', $data_layer );
		$this->assertStringNotContainsString( 'user_data', $this->inline_script_output(), 'The Enhanced Conversions block is withheld from a non-viewer.' );
		$this->assertStringNotContainsString( 'buyer@example.com', (string) wp_json_encode( $data_layer ) );
	}

	/**
	 * #219 counterpart: the buyer viewing their own receipt (edd_can_view_receipt()
	 * true) gets the full identity blocks. Pins that the withholding gate does not
	 * fire for the legitimate case.
	 */
	public function test_success_page_includes_customer_identity_for_the_buyer(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		Functions\when( 'edd_can_view_receipt' )->justReturn( true );
		$_GET['payment_key'] = 'pk_super_secret_key';

		Functions\when( 'edd_get_order_by' )->justReturn( $this->make_order() );
		Functions\when( 'edd_update_order_meta' )->justReturn( true );

		$data_layer = $this->make_page_datalayer(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDORDERDATA    => true,
				GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA => true,
			)
		)->add_datalayer_data( array() );

		$this->assertArrayHasKey( 'customer', $data_layer['orderData'], 'The buyer sees their own customer block.' );
		$this->assertTrue( $data_layer['new_customer'] );
		$this->assertStringContainsString( 'user_data', $this->inline_script_output() );
	}

	/**
	 * Builds a minimal WP_Post-ish object for get_post() stubs.
	 *
	 * @param string $content The post content.
	 * @return \WP_Post
	 */
	private function make_post_with_content( string $content ): \WP_Post {
		$post               = new \WP_Post();
		$post->post_content = $content;

		return $post;
	}
}
