<?php
/**
 * Easy Digital Downloads product list and buy button markup.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\EasyDigitalDownloads;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the hidden product data markup the gtm4wp-edd frontend tracker
 * reads: a span per item in the [downloads] grid (view_item_list /
 * select_item), a hidden input inside every purchase form (add_to_cart)
 * and a span in every checkout cart row (remove_from_cart).
 *
 * The CSS classes are deliberately different from the WooCommerce
 * integration's .gtm4wp_productdata so a site running both store plugins
 * never counts the same element in two trackers.
 */
final class ListTracking {

	/**
	 * Running index of the downloads emitted into list markup this request,
	 * giving every item its GA4 list position.
	 *
	 * @var int
	 */
	private int $list_item_index = 0;

	/**
	 * Constructor.
	 *
	 * @param Options      $options       The plugin options service.
	 * @param DownloadData $download_data The download data builder.
	 */
	public function __construct(
		private Options $options,
		private DownloadData $download_data
	) {
	}

	/**
	 * Executed during edd_download_after, which fires inside every item of the
	 * [downloads] shortcode grid. Emits a hidden span with the GA4 item data
	 * so the tracker can report view_item_list impressions and select_item
	 * clicks.
	 *
	 * @return void
	 */
	public function after_download_shortcode_item(): void {
		$download_id = (int) get_the_ID();
		if ( $download_id <= 0 ) {
			return;
		}

		++$this->list_item_index;

		$list_name = is_search()
			? __( 'Search Results', 'duracelltomi-google-tag-manager' )
			: __( 'Downloads List', 'duracelltomi-google-tag-manager' );

		$eec_product_array = $this->download_data->process_download(
			$download_id,
			array(
				'productlink'    => (string) get_permalink( $download_id ),
				'item_list_name' => $list_name,
				'index'          => $this->list_item_index,
			),
			'productlist'
		);

		if ( false === $eec_product_array ) {
			return;
		}

		if ( ! isset( $eec_product_array['item_brand'] ) ) {
			$eec_product_array['item_brand'] = '';
		}

		printf(
			'<span class="gtm4wp_edd_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_data="%s"></span>',
			esc_attr( (string) wp_json_encode( $eec_product_array ) )
		);
	}

	/**
	 * Executed during edd_purchase_link_end, which fires just before the
	 * closing form tag of every EDD purchase (buy button) form. Emits a hidden
	 * input with the GA4 item data so the tracker can fire add_to_cart on the
	 * button click. For variable-priced downloads the price options are
	 * included as a map, so the tracker can attach the selected option's price
	 * and item_variant client-side.
	 *
	 * @param int   $download_id The download the purchase form belongs to.
	 * @param array $args        The purchase link arguments (unused).
	 * @return void
	 */
	public function purchase_link_data( $download_id, $args = array() ): void {
		unset( $args );

		$download_id = (int) $download_id;
		if ( $download_id <= 0 ) {
			return;
		}

		$eec_product_array = $this->download_data->process_download(
			$download_id,
			array(),
			'addtocartsingle'
		);

		if ( false === $eec_product_array ) {
			return;
		}

		$price_options = $this->price_options_map( $download_id );
		if ( array() !== $price_options ) {
			$eec_product_array['price_options'] = $price_options;
		}

		printf(
			'<input type="hidden" name="gtm4wp_product_data" value="%s" />',
			esc_attr( (string) wp_json_encode( $eec_product_array ) )
		);
	}

	/**
	 * Executed during edd_checkout_cart_item_title_after, which fires inside
	 * the name cell of every checkout cart row. Emits a hidden span with the
	 * GA4 item data so the tracker can fire remove_from_cart when the row's
	 * remove link is clicked. Uses its own CSS class (not the list one) so
	 * cart rows are never counted as list impressions.
	 *
	 * @param array $item The cart item as passed to the checkout cart template.
	 * @param mixed $key  The cart position key of the row.
	 * @return void
	 */
	public function checkout_cart_item_data( $item, $key = 0 ): void {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return;
		}

		$eec_product_array = $this->download_data->process_download(
			(int) $item['id'],
			$this->download_data->cart_line_attributes( $item ),
			'cart',
			$item,
			DownloadData::cart_item_price_id( $item )
		);

		if ( false === $eec_product_array ) {
			return;
		}

		unset( $eec_product_array['internal_id'] );

		$eec_product_array['cart_key'] = (int) $key;

		printf(
			'<span class="gtm4wp_edd_cartitemdata" style="display:none; visibility:hidden;" data-gtm4wp_product_data="%s"></span>',
			esc_attr( (string) wp_json_encode( $eec_product_array ) )
		);
	}

	/**
	 * Returns the variable price options of a download as a price_id =>
	 * { name, price } map, or an empty array for downloads without variable
	 * prices.
	 *
	 * @param int $download_id The download id.
	 * @return array<int|string, array{name: string, price: float}>
	 */
	private function price_options_map( int $download_id ): array {
		if ( ! edd_has_variable_prices( $download_id ) ) {
			return array();
		}

		$prices = edd_get_variable_prices( $download_id );
		if ( ! is_array( $prices ) ) {
			return array();
		}

		$map = array();
		foreach ( $prices as $price_id => $price_option ) {
			if ( ! is_array( $price_option ) ) {
				continue;
			}

			$map[ $price_id ] = array(
				'name'  => (string) ( $price_option['name'] ?? '' ),
				'price' => round( (float) ( $price_option['amount'] ?? 0 ), 2 ),
			);
		}

		return $map;
	}
}
