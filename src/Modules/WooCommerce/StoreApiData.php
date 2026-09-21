<?php
/**
 * WooCommerce Store API (Cart & Checkout blocks) data extension.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the GA4 item array on the WooCommerce Store API as
 * `extensions.gtm4wp.item` on every product and cart item, so the
 * gtm4wp-woocommerce-blocks tracker fires the block events with the same
 * product data as the classic path. The price is a real float from
 * ProductData::process_product(), so the tracker never divides minor units.
 */
final class StoreApiData {

	/**
	 * The Store API extension namespace under which the data is registered
	 * (surfaces as extensions.gtm4wp.* in the API responses).
	 */
	private const NAMESPACE = 'gtm4wp';

	/**
	 * Constructor.
	 *
	 * @param ProductData $product_data The product data builder.
	 */
	public function __construct( private ProductData $product_data ) {
	}

	/**
	 * Registers the product and cart-item endpoint data on the Store API, when
	 * the Store API and its schema-extension service are available. Hooked to
	 * woocommerce_blocks_loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		if (
			! class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' )
			|| ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema' )
		) {
			return;
		}

		$extend = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(
			\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class
		);

		if ( ! is_object( $extend ) || ! method_exists( $extend, 'register_endpoint_data' ) ) {
			return;
		}

		$extend->register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'extend_product_data' ),
				'schema_callback' => array( $this, 'product_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		$extend->register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'extend_cart_item_data' ),
				'schema_callback' => array( $this, 'cart_item_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Product endpoint data callback: the GA4 item array for a product, exposed
	 * as an object at extensions.gtm4wp.item.
	 *
	 * @param \WC_Product $product The product being serialized.
	 * @return array<string, mixed>
	 */
	public function extend_product_data( $product ): array {
		$item = $this->product_data->process_product( $product, array(), 'block' );

		return array(
			'item' => ( false === $item ) ? null : $item,
		);
	}

	/**
	 * Cart-item endpoint data callback: the GA4 item array for a cart line,
	 * carrying the line quantity. Serialized as a JSON string (cart-item
	 * extension values are strings) and parsed back by the block tracker.
	 *
	 * @param array<string, mixed> $cart_item The WooCommerce cart item.
	 * @return array<string, string>
	 */
	public function extend_cart_item_data( $cart_item ): array {
		$product  = is_array( $cart_item ) && isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$quantity = is_array( $cart_item ) && isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1;

		$item = $this->product_data->process_product(
			$product,
			array( 'quantity' => $quantity ),
			'block',
			is_array( $cart_item ) ? $cart_item : null
		);

		return array(
			'item' => ( false === $item ) ? '' : (string) wp_json_encode( $item ),
		);
	}

	/**
	 * Schema for the product endpoint extension.
	 *
	 * @return array<string, mixed>
	 */
	public function product_schema(): array {
		return array(
			'item' => array(
				'description' => __( 'GA4 ecommerce item data for the product.', 'duracelltomi-google-tag-manager' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Schema for the cart-item endpoint extension.
	 *
	 * @return array<string, mixed>
	 */
	public function cart_item_schema(): array {
		return array(
			'item' => array(
				'description' => __( 'GA4 ecommerce item data for the cart line, JSON encoded.', 'duracelltomi-google-tag-manager' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}
}
