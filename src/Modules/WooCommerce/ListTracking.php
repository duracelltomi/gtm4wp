<?php
/**
 * WooCommerce product list and interaction markup.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Adds hidden product data markup to product lists, widgets, WooCommerce
 * blocks, grouped products, the add to cart form and cart item remove
 * links so that the frontend script can fire view_item_list, select_item,
 * add_to_cart and remove_from_cart events.
 *
 * Port of the product list related functions of integration/woocommerce.php
 * from 1.x. The list state globals ($gtm4wp_product_counter,
 * $gtm4wp_last_widget_title, $gtm4wp_grouped_product_ix,
 * $gtm4wp_cart_item_proddata) are kept for third party compatibility.
 */
final class ListTracking {

	/**
	 * Constructor.
	 *
	 * @param Options     $options      The plugin options service.
	 * @param ProductData $product_data The product data builder.
	 */
	public function __construct(
		private Options $options,
		private ProductData $product_data
	) {
		$GLOBALS['gtm4wp_product_counter']    = 0;
		$GLOBALS['gtm4wp_last_widget_title']  = 'Sidebar Products';
		$GLOBALS['gtm4wp_grouped_product_ix'] = 1;
		$GLOBALS['gtm4wp_cart_item_proddata'] = '';
	}

	/**
	 * Executed with the woocommerce_after_add_to_cart_button hook.
	 * Outputs a hidden input element with the product data of the currently shown product.
	 *
	 * @return void
	 */
	public function single_add_to_cart_tracking(): void {
		global $product;

		// Exit early if there is nothing to do.
		if ( false === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			return;
		}

		$eec_product_array = $this->product_data->process_product(
			$product,
			array(),
			'addtocartsingle'
		);

		echo '<input type="hidden" name="gtm4wp_product_data" value="' . esc_attr( wp_json_encode( $eec_product_array ) ) . '" />' . "\n";
	}

	/**
	 * Executed during woocommerce_cart_item_product for each product in the cart.
	 * Stores the ecommerce product data into a global variable to be processed
	 * when the cart item is rendered.
	 *
	 * @param \WC_Product $product A WooCommerce product that is shown in the cart.
	 * @param string      $cart_item Not used by this hook.
	 * @param string      $cart_id Not used by this hook.
	 * @return \WC_Product The unchanged product.
	 */
	public function cart_item_product_filter( $product, $cart_item = '', $cart_id = '' ) {
		$eec_product_array = $this->product_data->process_product(
			$product,
			array(
				'productlink' => apply_filters( 'the_permalink', get_permalink(), 0 ),
			),
			'cart'
		);

		$GLOBALS['gtm4wp_cart_item_proddata'] = $eec_product_array;

		return $product;
	}

	/**
	 * Executed during woocommerce_cart_item_remove_link.
	 * Adds additional product data into the remove product link of the cart table to be able to track
	 * the ecommerce remove_from_cart action with product data.
	 *
	 * @param string $remove_from_cart_link The HTML code of the remove from cart link element.
	 * @return string The updated remove product from cart link with product data added in data attributes.
	 */
	public function cart_item_remove_link_filter( $remove_from_cart_link ) {
		$gtm4wp_cart_item_proddata = $GLOBALS['gtm4wp_cart_item_proddata'] ?? null;

		if ( ! isset( $gtm4wp_cart_item_proddata ) ) {
			return $remove_from_cart_link;
		}

		if ( ! is_array( $gtm4wp_cart_item_proddata ) ) {
			return $remove_from_cart_link;
		}

		if ( ! isset( $gtm4wp_cart_item_proddata['item_variant'] ) ) {
			$gtm4wp_cart_item_proddata['item_variant'] = '';
		}

		if ( ! isset( $gtm4wp_cart_item_proddata['item_brand'] ) ) {
			$gtm4wp_cart_item_proddata['item_brand'] = '';
		}

		$cartlink_with_data = sprintf(
			'data-gtm4wp_product_data="%s" href="',
			esc_attr( wp_json_encode( $gtm4wp_cart_item_proddata ) )
		);

		$GLOBALS['gtm4wp_cart_item_proddata'] = '';

		return Helpers::str_replace_first( 'href="', $cartlink_with_data, $remove_from_cart_link );
	}

	/**
	 * Executed during woocommerce_cart_item_restored.
	 * When the user restores the just removed cart item, this function stores the cart item key to
	 * be able to generate an add_to_cart event after restoration completes.
	 *
	 * @param string $cart_item_key A unique cart item key.
	 * @return void
	 */
	public function cart_item_restored( $cart_item_key ): void {
		$woo = WC();

		if ( $woo && $woo->session ) {
			$woo->session->set( 'gtm4wp_product_readded_to_cart', $cart_item_key );
		}
	}

	/**
	 * Executed during loop_end.
	 * Resets the product impression list name after a specific product list ended rendering.
	 *
	 * @param mixed $query The query passed by the loop_end filter, returned unchanged.
	 * @return mixed
	 */
	public function reset_loop( $query = null ) {
		global $woocommerce_loop;

		$woocommerce_loop['listtype'] = '';

		return $query;
	}

	/**
	 * Sets the currently rendered product list impression name.
	 *
	 * @param string $listtype Translated list name.
	 * @return void
	 */
	private function set_list_type( string $listtype ): void {
		global $woocommerce_loop;

		$woocommerce_loop['listtype'] = $listtype;
	}

	/**
	 * Executed during woocommerce_related_products_args and woocommerce_related_products_columns.
	 *
	 * @param mixed $arg Not used by this hook, returned unchanged.
	 * @return mixed
	 */
	public function add_related_to_loop( $arg ) {
		$this->set_list_type( __( 'Related Products', 'duracelltomi-google-tag-manager' ) );

		return $arg;
	}

	/**
	 * Executed during woocommerce_cross_sells_columns.
	 *
	 * @param mixed $arg Not used by this hook, returned unchanged.
	 * @return mixed
	 */
	public function add_cross_sell_to_loop( $arg ) {
		$this->set_list_type( __( 'Cross-Sell Products', 'duracelltomi-google-tag-manager' ) );

		return $arg;
	}

	/**
	 * Executed during woocommerce_upsells_columns.
	 *
	 * @param mixed $arg Not used by this hook, returned unchanged.
	 * @return mixed
	 */
	public function add_upsells_to_loop( $arg ) {
		$this->set_list_type( __( 'Upsell Products', 'duracelltomi-google-tag-manager' ) );

		return $arg;
	}

	/**
	 * Executed during widget_title.
	 * The widget title will be used to report a custom product list name into Google Analytics.
	 *
	 * @param string $widget_title The title of the widget being rendered.
	 * @return string The unchanged widget title.
	 */
	public function widget_title_filter( $widget_title ) {
		$GLOBALS['gtm4wp_product_counter']   = 1;
		$GLOBALS['gtm4wp_last_widget_title'] = $widget_title . __( ' (widget)', 'duracelltomi-google-tag-manager' );

		return $widget_title;
	}

	/**
	 * Shortcode loop list name setters, executed during the
	 * woocommerce_shortcode_before_*_loop hooks.
	 */

	/**
	 * Sets the product list title for the recent products shortcode.
	 *
	 * @return void
	 */
	public function before_recent_products_loop(): void {
		$this->set_list_type( __( 'Recent Products', 'duracelltomi-google-tag-manager' ) );
	}

	/**
	 * Sets the product list title for the sale products shortcode.
	 *
	 * @return void
	 */
	public function before_sale_products_loop(): void {
		$this->set_list_type( __( 'Sale Products', 'duracelltomi-google-tag-manager' ) );
	}

	/**
	 * Sets the product list title for the best selling products shortcode.
	 *
	 * @return void
	 */
	public function before_best_selling_products_loop(): void {
		$this->set_list_type( __( 'Best Selling Products', 'duracelltomi-google-tag-manager' ) );
	}

	/**
	 * Sets the product list title for the top rated products shortcode.
	 *
	 * @return void
	 */
	public function before_top_rated_products_loop(): void {
		$this->set_list_type( __( 'Top Rated Products', 'duracelltomi-google-tag-manager' ) );
	}

	/**
	 * Sets the product list title for the featured products shortcode.
	 *
	 * @return void
	 */
	public function before_featured_products_loop(): void {
		$this->set_list_type( __( 'Featured Products', 'duracelltomi-google-tag-manager' ) );
	}

	/**
	 * Sets the product list title for the related products shortcode.
	 *
	 * @return void
	 */
	public function before_related_products_loop(): void {
		$this->set_list_type( __( 'Related Products', 'duracelltomi-google-tag-manager' ) );
	}

	/**
	 * Executed during woocommerce_before_template_part.
	 * Starts output buffering in order to be able to add product data attributes to the link element
	 * of a product list (classic) widget.
	 *
	 * @param string $template_name The template part that is being rendered.
	 * @return void
	 */
	public function before_template_part( $template_name ): void {
		ob_start();
	}

	/**
	 * Executed during woocommerce_after_template_part.
	 * Stops output buffering and adds data attributes into the product link of
	 * content-widget-product.php to be able to track product list impression and click actions.
	 *
	 * @param string $template_name The template part that is being rendered.
	 * @return void
	 */
	public function after_template_part( $template_name ): void {
		global $product;

		$productitem = ob_get_contents();
		ob_end_clean();

		if ( 'content-widget-product.php' === $template_name ) {
			$eec_product_array = $this->product_data->process_product(
				$product,
				array(
					'productlink'    => apply_filters( 'the_permalink', get_permalink(), 0 ),
					'item_list_name' => $GLOBALS['gtm4wp_last_widget_title'],
					'index'          => $GLOBALS['gtm4wp_product_counter'],
				),
				'widgetproduct'
			);

			if ( ! isset( $eec_product_array['item_brand'] ) ) {
				$eec_product_array['item_brand'] = '';
			}

			$productlink_with_data = sprintf(
				'data-gtm4wp_product_data="%s" href="',
				esc_attr( wp_json_encode( $eec_product_array ) )
			);

			++$GLOBALS['gtm4wp_product_counter'];

			$productitem = str_replace( 'href="', $productlink_with_data, $productitem );
		}

		/*
		$productitem is initialized as the template itself outputs a product item.
		Therefore this can not be passed to wp_kses() as it can include eventually any HTML.
		This filter function only adds additional attributes to the link element that points
		to a product detail page. Attribute values are escaped above.
		*/
		echo $productitem; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Generates a <span> element that can be used as a hidden addition to the DOM to be able to report
	 * product list impressions and clicks on list pages like product category or tag pages.
	 *
	 * @param mixed  $product A WooCommerce product object.
	 * @param string $listtype The name of the product list where the product is currently shown.
	 * @param mixed  $itemix The index of the product in the product list. The first product should have the index no. 1.
	 * @param string $permalink The link where the click should land when a user clicks on this product element.
	 * @return string|false|void A hidden <span> element that includes all product data needed for ecommerce reporting in product lists.
	 */
	public function get_product_list_item_extra_tag( $product, $listtype, $itemix, $permalink ) {
		if ( ! isset( $product ) ) {
			return;
		}

		if ( ! ( $product instanceof \WC_Product ) ) {
			return false;
		}

		if ( is_search() ) {
			$list_name = __( 'Search Results', 'duracelltomi-google-tag-manager' );
		} elseif ( '' !== $listtype ) {
			$list_name = $listtype;
		} else {
			$list_name = __( 'General Product List', 'duracelltomi-google-tag-manager' );
		}

		$paged          = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
		$posts_per_page = get_query_var( 'posts_per_page' );
		if ( $posts_per_page < 1 ) {
			$posts_per_page = 1;
		}

		$eec_product_array = $this->product_data->process_product(
			$product,
			array(
				'productlink'    => $permalink,
				'item_list_name' => $list_name,
				'index'          => (int) $itemix + ( $posts_per_page * ( $paged - 1 ) ),
				'product_type'   => $product->get_type(),
			),
			'productlist'
		);

		if ( false === $eec_product_array ) {
			return false;
		}

		if ( ! isset( $eec_product_array['item_brand'] ) ) {
			$eec_product_array['item_brand'] = '';
		}

		return sprintf(
			'<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_data="%s"></span>',
			esc_attr( wp_json_encode( $eec_product_array ) )
		);
	}

	/**
	 * Executed during woocommerce_after_shop_loop_item.
	 * Shows a hidden <span> element with product data to report ecommerce
	 * product impression and click actions in product lists.
	 *
	 * @return void
	 */
	public function after_shop_loop_item(): void {
		global $product, $woocommerce_loop;

		$listtype = '';
		if ( isset( $woocommerce_loop['listtype'] ) && ( '' !== $woocommerce_loop['listtype'] ) ) {
			$listtype = $woocommerce_loop['listtype'];
		}

		$itemix = '';
		if ( isset( $woocommerce_loop['loop'] ) && ( '' !== $woocommerce_loop['loop'] ) ) {
			$itemix = $woocommerce_loop['loop'];
		}

		$extra_tag = $this->get_product_list_item_extra_tag(
			$product,
			$listtype,
			$itemix,
			apply_filters(
				'the_permalink',
				get_permalink(),
				0
			)
		);

		// No need to escape here as everything is handled within the function call with esc_attr() and esc_url().
		echo $extra_tag; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Executed during wc_quick_view_before_single_product.
	 * Makes GTM4WP compatible with the WooCommerce Quick View plugin by allowing GTM4WP
	 * to fire the product detail action when quick view is opened.
	 *
	 * @return void
	 */
	public function quick_view_before_single_product(): void {
		$data_layer = array(
			'event' => 'view_item',
		);

		if ( $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			$postid  = get_the_ID();
			$product = wc_get_product( $postid );

			$eec_product_array = $this->product_data->process_product(
				$product,
				array(),
				'productdetail'
			);

			$data_layer['productRatingCounts']  = $product->get_rating_counts();
			$data_layer['productAverageRating'] = (float) $product->get_average_rating();
			$data_layer['productReviewCount']   = (int) $product->get_review_count();
			$data_layer['productType']          = $product->get_type();

			switch ( $data_layer['productType'] ) {
				case 'variable':
					$data_layer['productIsVariable'] = 1;

					break;

				case 'grouped':
					$data_layer['productIsVariable'] = 0;

					break;

				default:
					$data_layer['productIsVariable'] = 0;

					$gtm4wp_currency = get_woocommerce_currency();

					$data_layer['ecommerce'] = array(
						'currency' => $gtm4wp_currency,
						'value'    => $eec_product_array['price'],
						'item'     => $eec_product_array,
					);
			}
		}

		echo '
	<span style="display: none;" id="gtm4wp_quickview_data" data-gtm4wp_datalayer="' . esc_attr( wp_json_encode( $data_layer ) ) . '"></span>';
	}

	/**
	 * Executed during woocommerce_grouped_product_list_column_label.
	 * Adds product list impression info into every product listed on a grouped product detail page.
	 *
	 * @param string $labelvalue Returned with the hidden product data span appended.
	 * @param mixed  $product The WooCommerce product object being shown.
	 * @return string The label value extended with a hidden product data element.
	 */
	public function grouped_product_list_column_label( $labelvalue, $product ) {
		if ( ! isset( $product ) ) {
			return $labelvalue;
		}

		$list_name = __( 'Grouped Product Detail Page', 'duracelltomi-google-tag-manager' );

		$eec_product_array = $this->product_data->process_product(
			$product,
			array(
				'productlink'    => $product->get_permalink(),
				'item_list_name' => $list_name,
				'index'          => $GLOBALS['gtm4wp_grouped_product_ix'],
			),
			'groupedproductlist'
		);

		++$GLOBALS['gtm4wp_grouped_product_ix'];

		if ( ! isset( $eec_product_array['item_brand'] ) ) {
			$eec_product_array['item_brand'] = '';
		}

		$labelvalue .=
			sprintf(
				'<span class="gtm4wp_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_data="%s"></span>',
				esc_attr( wp_json_encode( $eec_product_array ) )
			);

		return $labelvalue;
	}

	/**
	 * Executed during woocommerce_blocks_product_grid_item_html.
	 * Adds product list impression data into a product list that has been generated using the block
	 * templates provided by WooCommerce.
	 *
	 * @param string $content Product grid item HTML.
	 * @param object $data Product data passed to the template.
	 * @param mixed  $product Product object.
	 * @return string The product grid item HTML with an added hidden <span> element for ecommerce tracking.
	 */
	public function add_productdata_to_wc_block( $content, $data, $product ) {
		$product_data_tag = $this->get_product_list_item_extra_tag( $product, '', 0, $data->permalink );

		// $product_data_tag carries esc_attr'd JSON that may contain literal $n / \1
		// sequences; escape them so preg_replace does not treat them as backreferences
		// in the replacement string (the leading $0 keeps the matched <li> element).
		$replacement = '$0' . addcslashes( (string) $product_data_tag, '\\$' );

		return preg_replace( '/<li.+class=("|"[^"]+)wc-block-grid__product("|[^"]+")[^<]*>/i', $replacement, $content );
	}

	/**
	 * Executed during render_block.
	 * Injects the hidden product-data span into every product rendered by the
	 * WooCommerce Product Collection block (woocommerce/product-collection). Unlike
	 * the classic product loop, that block fires neither woocommerce_after_shop_loop_item
	 * nor woocommerce_blocks_product_grid_item_html, so without this the frontend
	 * tracker would have no data to report view_item_list / select_item for it.
	 *
	 * Each product is rendered as a <li class="wc-block-product post-{ID} ...">, so
	 * the product id is read from the post-{ID} class and the span appended right
	 * after the opening tag. preg_replace_callback (not a data-bearing replacement
	 * string) keeps this free of the $n/\1 backreference hazard.
	 *
	 * @param string $block_content The rendered block HTML.
	 * @param array  $block         The parsed block (blockName + attrs).
	 * @return string The block HTML, each product item carrying a hidden product-data span.
	 */
	public function add_productdata_to_product_collection_block( $block_content, $block ) {
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || 'woocommerce/product-collection' !== $block['blockName'] ) {
			return $block_content;
		}

		if ( false === $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			return $block_content;
		}

		if ( ! is_string( $block_content ) || false === strpos( $block_content, 'wc-block-product' ) ) {
			return $block_content;
		}

		$collection = isset( $block['attrs']['collection'] ) ? (string) $block['attrs']['collection'] : '';
		$list_name  = $this->product_collection_list_name( $collection );

		$index = 0;

		return (string) preg_replace_callback(
			'/<li\b[^>]*>/i',
			function ( $matches ) use ( $list_name, &$index ) {
				$li_tag = $matches[0];

				// Only product items carry the wc-block-product class and a post-{ID}.
				if ( false === strpos( $li_tag, 'wc-block-product' ) || ! preg_match( '/\bpost-(\d+)\b/', $li_tag, $id_match ) ) {
					return $li_tag;
				}

				$product = wc_get_product( (int) $id_match[1] );
				if ( ! ( $product instanceof \WC_Product ) ) {
					return $li_tag;
				}

				++$index;

				$extra_tag = $this->get_product_list_item_extra_tag(
					$product,
					$list_name,
					$index,
					$product->get_permalink()
				);

				if ( ! is_string( $extra_tag ) || '' === $extra_tag ) {
					return $li_tag;
				}

				return $li_tag . $extra_tag;
			},
			$block_content
		);
	}

	/**
	 * Maps a Product Collection preset (the block's "collection" attribute) to a
	 * human-readable GA4 item_list_name, mirroring the shortcode / widget list
	 * names. Falls back to a generic name for the default catalog and custom queries.
	 *
	 * @param string $collection The block's collection attribute (e.g. woocommerce/product-collection/on-sale).
	 * @return string The translated list name.
	 */
	private function product_collection_list_name( string $collection ): string {
		switch ( $collection ) {
			case 'woocommerce/product-collection/on-sale':
				return __( 'Sale Products', 'duracelltomi-google-tag-manager' );

			case 'woocommerce/product-collection/best-sellers':
				return __( 'Best Selling Products', 'duracelltomi-google-tag-manager' );

			case 'woocommerce/product-collection/top-rated':
				return __( 'Top Rated Products', 'duracelltomi-google-tag-manager' );

			case 'woocommerce/product-collection/new-arrivals':
				return __( 'New Products', 'duracelltomi-google-tag-manager' );

			case 'woocommerce/product-collection/featured':
				return __( 'Featured Products', 'duracelltomi-google-tag-manager' );

			case 'woocommerce/product-collection/related':
				return __( 'Related Products', 'duracelltomi-google-tag-manager' );

			case 'woocommerce/product-collection/upsells':
				return __( 'Upsell Products', 'duracelltomi-google-tag-manager' );

			case 'woocommerce/product-collection/cross-sells':
				return __( 'Cross-Sell Products', 'duracelltomi-google-tag-manager' );

			default:
				return __( 'Product Collection', 'duracelltomi-google-tag-manager' );
		}
	}
}
