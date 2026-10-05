<?php
/**
 * The orderData key paths both stores share.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

/**
 * One GTM container reads the orderData of both stores by path, so the
 * WooCommerce and the EDD builders are pinned against this single list, each
 * from its own suite (T131, TS-19). A path missing on one side is a GTM
 * variable that reads undefined on that store.
 */
final class OrderDataKeys {

	/**
	 * Paths emitted by both stores.
	 */
	public const SHARED = array(
		'attributes.date',
		'attributes.order_number',
		'attributes.payment_method',
		'attributes.status',
		'attributes.coupons',
		'totals.currency',
		'totals.discount_total',
		'totals.cart_tax',
		'totals.total',
		'totals.total_tax',
		'totals.total_discount',
		'totals.subtotal',
		'customer.id',
		'customer.billing.first_name',
		'customer.billing.first_name_hash',
		'customer.billing.last_name',
		'customer.billing.last_name_hash',
		'customer.billing.address_1',
		'customer.billing.address_2',
		'customer.billing.city',
		'customer.billing.state',
		'customer.billing.postcode',
		'customer.billing.country',
		'customer.billing.email',
		'customer.billing.email_hash',
		'customer.billing.phone',
		'customer.billing.phone_hash',
		'items',
	);

	/**
	 * WooCommerce only: EDD has no shipping, company, order key or tax breakdown.
	 */
	public const WOOCOMMERCE_ONLY = array(
		'attributes.order_key',
		'attributes.payment_method_title',
		'attributes.shipping_method',
		'totals.discount_tax',
		'totals.shipping_total',
		'totals.shipping_tax',
		'totals.tax_totals',
		'customer.billing.company',
		'customer.shipping.first_name',
		'customer.shipping.last_name',
		'customer.shipping.company',
		'customer.shipping.address_1',
		'customer.shipping.address_2',
		'customer.shipping.city',
		'customer.shipping.state',
		'customer.shipping.postcode',
		'customer.shipping.country',
	);

	/**
	 * EDD only: the store mode (live / test).
	 */
	public const EDD_ONLY = array(
		'attributes.mode',
	);

	/**
	 * Dotted key paths of an orderData array, sorted. `items` and
	 * `totals.tax_totals` are leaves: their contents are lists, not schema.
	 *
	 * @param array<string, mixed> $data   The orderData array.
	 * @param string               $prefix Path prefix, for the recursion.
	 * @return string[]
	 */
	public static function paths( array $data, string $prefix = '' ): array {
		$paths = array();
		foreach ( $data as $key => $value ) {
			$path = $prefix . $key;
			if ( is_array( $value ) && 'items' !== $path && 'totals.tax_totals' !== $path ) {
				$paths = array_merge( $paths, self::paths( $value, $path . '.' ) );
			} else {
				$paths[] = $path;
			}
		}

		if ( '' === $prefix ) {
			sort( $paths );
		}

		return $paths;
	}

	/**
	 * The expected sorted path list of one store.
	 *
	 * @param string[] $own The store-only paths.
	 * @return string[]
	 */
	public static function expected( array $own ): array {
		$paths = array_merge( self::SHARED, $own );
		sort( $paths );

		return $paths;
	}
}
