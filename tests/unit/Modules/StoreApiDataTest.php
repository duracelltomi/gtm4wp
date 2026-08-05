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
require_once __DIR__ . '/store-api-stub.php';

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

	/**
	 * TS-11 raw-passthrough (RI-4). The block Store-API sink is the ONLY place the
	 * `block`-context item passthrough is asserted, so a product-title `item_name`
	 * that carries special characters must reach the endpoint object RAW: the Store
	 * API / block tracker JSON-encodes it downstream, so the callback must never
	 * pre-escape (esc_js/esc_html). Break-out chars are written with \xNN so no
	 * literal char appears in this file (TC-2); stubEscapeFunctions() installs real
	 * htmlspecialchars so a re-introduced pre-escape would change the value and fail.
	 */
	public function test_extend_product_data_passes_hostile_title_without_entity_escaping(): void {
		Functions\stubEscapeFunctions();

		// \x26 & , \x22 " , \x3C/script\x3E </script>  (no literal break-out char here).
		$hostile = 'Ben ' . "\x26" . ' ' . "\x22" . 'Jerry' . "\x22" . ' ' . "\x3C/script\x3E";
		$data    = $this->make_store_api_data()->extend_product_data(
			new \WC_Product(
				array(
					'id'    => 7,
					'title' => $hostile,
					'sku'   => 'X-1',
				)
			)
		);

		$this->assertSame( $hostile, $data['item']['item_name'], 'The product title must reach the Store API sink verbatim.' );
		$this->assertStringContainsString( "\x26", $data['item']['item_name'], 'A raw & must survive for the downstream encoder.' );
		$this->assertStringContainsString( "\x22", $data['item']['item_name'], 'A raw " must survive for the downstream encoder.' );
		$this->assertStringNotContainsString( '&amp;', $data['item']['item_name'], 'The value must not be entity-encoded upstream of the sink.' );
		$this->assertStringNotContainsString( '&quot;', $data['item']['item_name'] );
	}

	/**
	 * TS-11 raw-passthrough through the cart-item callback's own wp_json_encode():
	 * the item is delivered as a JSON string that the block tracker JSON.parse()s
	 * back, so a hostile item_name must round-trip verbatim.
	 */
	public function test_extend_cart_item_data_round_trips_a_hostile_title(): void {
		Functions\stubEscapeFunctions();

		$hostile = 'Ben ' . "\x26" . ' ' . "\x22" . 'Jerry' . "\x22" . ' ' . "\x3C/script\x3E";
		$data    = $this->make_store_api_data()->extend_cart_item_data(
			array(
				'data'     => new \WC_Product(
					array(
						'id'    => 7,
						'title' => $hostile,
						'sku'   => 'X-1',
					)
				),
				'quantity' => 2,
			)
		);

		$decoded = json_decode( $data['item'], true );
		$this->assertSame( $hostile, $decoded['item_name'], 'The item_name must round-trip the cart-item JSON string verbatim.' );
	}

	/**
	 * Wiring: register() must register the product and cart-item endpoint data on
	 * the Store API extend service with the right endpoint identifiers, the gtm4wp
	 * namespace and an ARRAY_A schema type. (The defensive early returns for a
	 * missing Store API / ExtendSchema class are not unit-tested — once the stub
	 * classes are defined they exist process-wide; those guards are trivial.)
	 */
	public function test_register_registers_product_and_cart_item_endpoint_data(): void {
		\Automattic\WooCommerce\StoreApi\StoreApi::$registered = array();

		$this->make_store_api_data()->register();

		$registered = \Automattic\WooCommerce\StoreApi\StoreApi::$registered;
		$this->assertCount( 2, $registered, 'Both the product and cart-item endpoints are extended.' );

		$endpoints = array_column( $registered, 'endpoint' );
		$this->assertContains( 'product', $endpoints );
		$this->assertContains( 'cart-item', $endpoints );

		foreach ( $registered as $entry ) {
			$this->assertSame( 'gtm4wp', $entry['namespace'] );
			$this->assertSame( ARRAY_A, $entry['schema_type'] );
			$this->assertIsCallable( $entry['data_callback'] );
			$this->assertIsCallable( $entry['schema_callback'] );
		}
	}

	/**
	 * The schema callbacks describe the extension to every Store API consumer,
	 * so the `item` key they declare has to keep matching the key the data
	 * callbacks actually emit - and both must stay read-only, because the
	 * extension is a one-way feed and a writable field would be a REST surface
	 * nothing gates.
	 *
	 * Registered but never asserted until test-review Run 5 (gap T34): the
	 * wiring test above only proved the callbacks are callable.
	 */
	public function test_product_schema_declares_a_readonly_item_field(): void {
		$schema = $this->make_store_api_data()->product_schema();

		$this->assertArrayHasKey( 'item', $schema, 'The schema key must match the data callback key.' );
		$this->assertSame( array( 'object', 'null' ), $schema['item']['type'], 'The product item is an object, or null for an untrackable product.' );
		$this->assertTrue( $schema['item']['readonly'], 'The extension is a one-way feed.' );
		$this->assertSame( array( 'view', 'edit' ), $schema['item']['context'] );
		$this->assertNotSame( '', $schema['item']['description'] );
	}

	public function test_cart_item_schema_declares_a_readonly_string_item_field(): void {
		// The cart-item payload is a JSON *string* (the data callback encodes it),
		// so the declared type differs from the product one on purpose.
		$schema = $this->make_store_api_data()->cart_item_schema();

		$this->assertArrayHasKey( 'item', $schema );
		$this->assertSame( 'string', $schema['item']['type'], 'The cart line is delivered JSON encoded, so the schema type is string.' );
		$this->assertTrue( $schema['item']['readonly'] );
		$this->assertSame( array( 'view', 'edit' ), $schema['item']['context'] );
	}

	public function test_schema_keys_match_the_data_callback_keys(): void {
		// The pairing is the contract: a renamed data key with an unchanged schema
		// silently drops the field from every Store API response.
		Functions\stubEscapeFunctions();

		$store_api = $this->make_store_api_data();
		$product   = new \WC_Product(
			array(
				'id'    => 7,
				'title' => 'Mug',
				'sku'   => 'SKU-7',
			)
		);

		$this->assertSame(
			array_keys( $store_api->product_schema() ),
			array_keys( $store_api->extend_product_data( $product ) ),
			'The product schema and data callback must declare the same keys.'
		);

		$this->assertSame(
			array_keys( $store_api->cart_item_schema() ),
			array_keys(
				$store_api->extend_cart_item_data(
					array(
						'data'     => $product,
						'quantity' => 1,
					)
				)
			),
			'The cart-item schema and data callback must declare the same keys.'
		);
	}
}
