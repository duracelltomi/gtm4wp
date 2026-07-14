<?php
/**
 * Unit tests for the WooCommerce Store API data extension.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\StoreApiData;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * Covers the data callbacks that expose the GA4 item array on the Store API
 * (extensions.gtm4wp.item), which the Cart & Checkout block tracker consumes.
 */
final class StoreApiDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'wc_get_price_to_display' )->justReturn( 12.5 );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
	}

	/**
	 * Builds a StoreApiData backed by real Options + ProductData.
	 *
	 * @return StoreApiData
	 */
	private function make_store_api_data(): StoreApiData {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options( ( new WooCommerceModule() )->defaults() );

		return new StoreApiData( new ProductData( $options ) );
	}

	private function make_product(): \WC_Product {
		return new \WC_Product(
			array(
				'id'    => 42,
				'title' => 'Boots',
				'sku'   => 'BOOT-1',
			)
		);
	}

	public function test_extend_product_data_exposes_the_ga4_item_object(): void {
		$data = $this->make_store_api_data()->extend_product_data( $this->make_product() );

		$this->assertArrayHasKey( 'item', $data );
		$this->assertIsArray( $data['item'] );
		$this->assertSame( 42, $data['item']['item_id'] );
		$this->assertSame( 'Boots', $data['item']['item_name'] );
	}

	public function test_extend_product_data_returns_null_for_a_non_product(): void {
		$data = $this->make_store_api_data()->extend_product_data( null );

		$this->assertSame( array( 'item' => null ), $data, 'A non-product must not fabricate item data.' );
	}

	public function test_extend_cart_item_data_returns_a_json_string_with_quantity(): void {
		$data = $this->make_store_api_data()->extend_cart_item_data(
			array(
				'data'     => $this->make_product(),
				'quantity' => 3,
			)
		);

		$this->assertArrayHasKey( 'item', $data );
		$this->assertIsString( $data['item'], 'Cart-item extension values must be JSON strings.' );

		$decoded = json_decode( $data['item'], true );
		$this->assertSame( 42, $decoded['item_id'] );
		$this->assertSame( 3, $decoded['quantity'] );
	}

	public function test_extend_cart_item_data_returns_empty_string_for_a_bad_line(): void {
		$data = $this->make_store_api_data()->extend_cart_item_data( array() );

		$this->assertSame( array( 'item' => '' ), $data );
	}
}
