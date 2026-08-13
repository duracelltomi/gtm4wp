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
	 * Keys of the product lists the plugin names itself.
	 *
	 * Every key resolves through list_identity() to a translated GA4
	 * item_list_name and a locale-independent item_list_id. One key per list
	 * rather than one per rendering path, so a list rendered by a shortcode, by a
	 * Product Collection block and by WooCommerce's legacy grid block all report
	 * the same identity and GA4 does not see three lists.
	 */
	private const LIST_GENERAL      = 'general';
	private const LIST_SEARCH       = 'search';
	private const LIST_RELATED      = 'related';
	private const LIST_CROSSSELL    = 'crosssell';
	private const LIST_UPSELL       = 'upsell';
	private const LIST_RECENT       = 'recent';
	private const LIST_SALE         = 'sale';
	private const LIST_BESTSELLERS  = 'bestsellers';
	private const LIST_TOPRATED     = 'toprated';
	private const LIST_FEATURED     = 'featured';
	private const LIST_NEWARRIVALS  = 'newarrivals';
	private const LIST_HANDPICKED   = 'handpicked';
	private const LIST_BYCATEGORY   = 'bycategory';
	private const LIST_BYTAG        = 'bytag';
	private const LIST_BYBRAND      = 'bybrand';
	private const LIST_CARTCONTENTS = 'cartcontents';
	private const LIST_COLLECTION   = 'collection';
	private const LIST_GROUPED      = 'grouped';

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
	 * Outputs a hidden span element carrying the product data of the currently
	 * shown product in a data attribute.
	 *
	 * A span, never an input or any other form element: WooCommerce's blockified
	 * Add to Cart + Options block scans the buffered output of this hook with
	 * has_form_elements() and falls back to a classic full-page POST form when it
	 * finds one, which disables the interactive add to cart on block themes (#462).
	 * The class differs from the .gtm4wp_productdata list markup on purpose - that
	 * class is swept page-wide into view_item_list impressions, which a product
	 * detail page must not join.
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

		echo '<span class="gtm4wp_single_productdata" style="display:none; visibility:hidden;" data-gtm4wp_product_data="' . esc_attr( wp_json_encode( $eec_product_array ) ) . '"></span>' . "\n";
	}

	/**
	 * Executed during woocommerce_loop_add_to_cart_link.
	 * Attaches GA4 product data to a standalone add-to-cart button - the
	 * [add_to_cart] shortcode and similar buttons rendered outside a product loop,
	 * which get no .gtm4wp_productdata list markup - so the frontend click handler
	 * can still fire add_to_cart for them (#110). Buttons inside a product loop
	 * already carry that markup (added on woocommerce_after_shop_loop_item), so they
	 * are skipped here to avoid duplicating the data.
	 *
	 * @param string $link    The add-to-cart link/button HTML.
	 * @param mixed  $product The WooCommerce product this button belongs to.
	 * @return string The link HTML, with product data added for standalone buttons.
	 */
	public function add_to_cart_link_filter( $link, $product = null ) {
		// Inside a product loop the list markup already carries the product data.
		if ( doing_action( 'woocommerce_after_shop_loop_item' ) ) {
			return $link;
		}

		if ( ! ( $product instanceof \WC_Product ) ) {
			return $link;
		}

		// Only a simple product gets an inline add-to-cart button; variable/grouped
		// buttons are "Select options" links that navigate to the product page.
		if ( 'simple' !== $product->get_type() ) {
			return $link;
		}

		$eec_product_array = $this->product_data->process_product(
			$product,
			array(),
			'shortcodeaddtocart'
		);

		if ( ! is_array( $eec_product_array ) ) {
			return $link;
		}

		return Helpers::str_replace_first(
			'<a ',
			'<a data-gtm4wp_product_data="' . esc_attr( wp_json_encode( $eec_product_array ) ) . '" ',
			$link
		);
	}

	/**
	 * Executed during woocommerce_cart_item_product for each product in the cart.
	 * Stores the ecommerce product data into a global variable to be processed
	 * when the cart item is rendered.
	 *
	 * @param \WC_Product          $product   A WooCommerce product that is shown in the cart.
	 * @param array<string, mixed> $cart_item The cart item; its already-calculated line
	 *                                        totals supply the price so process_product()
	 *                                        does not recompute wc_get_price_to_display()
	 *                                        once per cart item (#436).
	 * @return \WC_Product The unchanged product.
	 */
	public function cart_item_product_filter( $product, $cart_item = array() ) {
		$attributes = array(
			'productlink' => apply_filters( 'the_permalink', get_permalink(), 0 ),
		);

		if ( is_array( $cart_item ) ) {
			$include_tax = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) );

			$price = Helpers::cart_line_display_price( $cart_item, $include_tax );
			if ( null !== $price ) {
				$attributes['price'] = $price;
			}
		}

		$eec_product_array = $this->product_data->process_product(
			$product,
			$attributes,
			'cart',
			! empty( $cart_item ) && is_array( $cart_item ) ? $cart_item : null
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

			// Cache-safe data layer (issue #398, Phase 3): flag that a one-shot event
			// (this add_to_cart) is pending so the client fetches it on the next page.
			// No-op unless the cache-safe mode is on.
			Helpers::flag_oneshot_event( (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER ) );
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

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own global, not ours to prefix; the list type has to live where WC's loop already reads it.
		$woocommerce_loop['listtype'] = '';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- see above; our own key inside WooCommerce's loop state.
		$woocommerce_loop['gtm4wp_list_identity'] = null;

		return $query;
	}

	/**
	 * Resolves one of the plugin's own product lists to its GA4 identity.
	 *
	 * The id is a locale-independent literal, deliberately NOT sanitize_title() of
	 * the translated name: GA4 keys its list reports on item_list_id, so a
	 * multilingual store has to report one id per list, not one per language. Each
	 * literal below is what sanitize_title() produced for the English name before
	 * this became explicit, so an English store's payload is unchanged.
	 *
	 * Names the site owner authors - a widget title - are not listed here and keep
	 * deriving their id from the name in ProductData::process_product(): there is
	 * nothing stable to key those on.
	 *
	 * The other end of this table lives in js/frontend/gtm4wp-woocommerce.js
	 * (gtm4wp_product_block_names), which resolves the same lists for WooCommerce's
	 * legacy product grid blocks - the one path where the block is only identifiable
	 * in the browser. Seven of that map's eight ids also appear here (all but
	 * products-by-attribute, which has no Product Collection equivalent) and have to
	 * be edited together; upstream registry row U99 carries the reminder.
	 *
	 * @param string $key One of the LIST_* constants.
	 * @return array{0: string, 1: string} The item_list_id and the translated item_list_name.
	 */
	private function list_identity( string $key ): array {
		switch ( $key ) {
			case self::LIST_SEARCH:
				return array( 'search-results', __( 'Search Results', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_RELATED:
				return array( 'related-products', __( 'Related Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_CROSSSELL:
				return array( 'cross-sell-products', __( 'Cross-Sell Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_UPSELL:
				return array( 'upsell-products', __( 'Upsell Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_RECENT:
				return array( 'recent-products', __( 'Recent Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_SALE:
				return array( 'sale-products', __( 'Sale Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_BESTSELLERS:
				return array( 'best-selling-products', __( 'Best Selling Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_TOPRATED:
				return array( 'top-rated-products', __( 'Top Rated Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_FEATURED:
				return array( 'featured-products', __( 'Featured Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_NEWARRIVALS:
				return array( 'new-products', __( 'New Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_HANDPICKED:
				return array( 'handpicked-products', __( 'Handpicked Products', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_BYCATEGORY:
				return array( 'product-category-list', __( 'Product Category List', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_BYTAG:
				return array( 'products-by-tag', __( 'Products By Tag', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_BYBRAND:
				return array( 'products-by-brand', __( 'Products By Brand', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_CARTCONTENTS:
				return array( 'cart-contents', __( 'Cart Contents', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_COLLECTION:
				return array( 'product-collection', __( 'Product Collection', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_GROUPED:
				return array( 'grouped-product-detail-page', __( 'Grouped Product Detail Page', 'duracelltomi-google-tag-manager' ) );

			case self::LIST_GENERAL:
			default:
				return array( 'general-product-list', __( 'General Product List', 'duracelltomi-google-tag-manager' ) );
		}
	}

	/**
	 * Sets the currently rendered product list impression name.
	 *
	 * $woocommerce_loop['listtype'] keeps holding the translated name: it is a 1.x
	 * extension point third party code reads and writes. The stable id travels
	 * beside it under our own key, paired with the name it belongs to - so if
	 * something else overwrites listtype with a list of its own, after_shop_loop_item()
	 * sees the names disagree and derives that list's id from its name instead of
	 * handing it ours.
	 *
	 * @param string $key One of the LIST_* constants.
	 * @return void
	 */
	private function set_list_type( string $key ): void {
		global $woocommerce_loop;

		list( $list_id, $list_name ) = $this->list_identity( $key );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own global, not ours to prefix; the list type has to live where WC's loop already reads it.
		$woocommerce_loop['listtype'] = $list_name;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- see above; our own key inside WooCommerce's loop state.
		$woocommerce_loop['gtm4wp_list_identity'] = array(
			'name' => $list_name,
			'id'   => $list_id,
		);
	}

	/**
	 * Executed during woocommerce_related_products_columns.
	 *
	 * @param mixed $arg Not used by this hook, returned unchanged.
	 * @return mixed
	 */
	public function add_related_to_loop( $arg ) {
		$this->set_list_type( self::LIST_RELATED );

		return $arg;
	}

	/**
	 * Executed during woocommerce_cross_sells_columns.
	 *
	 * @param mixed $arg Not used by this hook, returned unchanged.
	 * @return mixed
	 */
	public function add_cross_sell_to_loop( $arg ) {
		$this->set_list_type( self::LIST_CROSSSELL );

		return $arg;
	}

	/**
	 * Executed during woocommerce_upsells_columns.
	 *
	 * @param mixed $arg Not used by this hook, returned unchanged.
	 * @return mixed
	 */
	public function add_upsells_to_loop( $arg ) {
		$this->set_list_type( self::LIST_UPSELL );

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
		$this->set_list_type( self::LIST_RECENT );
	}

	/**
	 * Sets the product list title for the sale products shortcode.
	 *
	 * @return void
	 */
	public function before_sale_products_loop(): void {
		$this->set_list_type( self::LIST_SALE );
	}

	/**
	 * Sets the product list title for the best selling products shortcode.
	 *
	 * @return void
	 */
	public function before_best_selling_products_loop(): void {
		$this->set_list_type( self::LIST_BESTSELLERS );
	}

	/**
	 * Sets the product list title for the top rated products shortcode.
	 *
	 * @return void
	 */
	public function before_top_rated_products_loop(): void {
		$this->set_list_type( self::LIST_TOPRATED );
	}

	/**
	 * Sets the product list title for the featured products shortcode.
	 *
	 * @return void
	 */
	public function before_featured_products_loop(): void {
		$this->set_list_type( self::LIST_FEATURED );
	}

	/**
	 * Sets the product list title for the related products shortcode.
	 *
	 * @return void
	 */
	public function before_related_products_loop(): void {
		$this->set_list_type( self::LIST_RELATED );
	}

	/**
	 * Executed during woocommerce_before_template_part.
	 * Starts output buffering in order to be able to add product data attributes to the link element
	 * of a product list (classic) widget.
	 *
	 * @return void
	 */
	public function before_template_part(): void {
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
	 * @param string $list_id Optional. The stable GA4 item_list_id of that list. Empty (the
	 *                        default) means derive it from the list name, which is what a
	 *                        caller-supplied or third-party list name gets.
	 * @return string|false|void A hidden <span> element that includes all product data needed for ecommerce reporting in product lists.
	 */
	public function get_product_list_item_extra_tag( $product, $listtype, $itemix, $permalink, string $list_id = '' ) {
		if ( ! isset( $product ) ) {
			return;
		}

		if ( ! ( $product instanceof \WC_Product ) ) {
			return false;
		}

		if ( is_search() ) {
			list( $list_id, $list_name ) = $this->list_identity( self::LIST_SEARCH );
		} elseif ( '' !== $listtype ) {
			// A caller-supplied name, so its id is whatever the caller paired with
			// it - or nothing, for a name that came from elsewhere (a widget title,
			// third party code writing $woocommerce_loop['listtype']).
			$list_name = $listtype;
		} else {
			list( $list_id, $list_name ) = $this->list_identity( self::LIST_GENERAL );
		}

		$paged          = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
		$posts_per_page = get_query_var( 'posts_per_page' );
		if ( $posts_per_page < 1 ) {
			$posts_per_page = 1;
		}

		$product_attributes = array(
			'productlink'    => $permalink,
			'item_list_name' => $list_name,
			'index'          => (int) $itemix + ( $posts_per_page * ( $paged - 1 ) ),
			'product_type'   => $product->get_type(),
		);

		// Left out when there is no stable id, so process_product() derives one from
		// the list name the way it always has.
		if ( '' !== $list_id ) {
			$product_attributes['item_list_id'] = $list_id;
		}

		$eec_product_array = $this->product_data->process_product(
			$product,
			$product_attributes,
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
		$list_id  = '';
		if ( isset( $woocommerce_loop['listtype'] ) && ( '' !== $woocommerce_loop['listtype'] ) ) {
			$listtype = $woocommerce_loop['listtype'];

			// Use the stable id only while it still belongs to the name in
			// listtype: anything else has written a list name of its own there and
			// gets its id derived from that name, not ours (see set_list_type()).
			$identity = $woocommerce_loop['gtm4wp_list_identity'] ?? null;
			if ( is_array( $identity ) && isset( $identity['name'], $identity['id'] ) && $identity['name'] === $listtype ) {
				$list_id = (string) $identity['id'];
			}
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
			),
			$list_id
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

		list( $list_id, $list_name ) = $this->list_identity( self::LIST_GROUPED );

		$eec_product_array = $this->product_data->process_product(
			$product,
			array(
				'productlink'    => $product->get_permalink(),
				'item_list_name' => $list_name,
				'item_list_id'   => $list_id,
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
	 * The empty list type and the index of 0 below are deliberate placeholders, not
	 * an oversight: this WooCommerce filter carries no block context, so PHP cannot
	 * tell a Handpicked Products grid from a Newest Products one. The grid container
	 * does carry the block name as a wp-block-{block_name} class, so the real
	 * identity is resolved in the browser - js/frontend/gtm4wp-woocommerce.js
	 * overwrites all three of item_list_name, item_list_id and index from that
	 * class. Keep the placeholder pair self-consistent (the generic list name and
	 * the id derived from it): when the class is unknown to the tracker, that pair
	 * is what the item reports, and a name that does not match its id would
	 * collapse every grid on the page onto one id in GA4.
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

		list( $list_id, $list_name ) = $this->list_identity( $this->product_collection_list_key( $collection ) );

		$index = 0;

		return (string) preg_replace_callback(
			'/<li\b[^>]*>/i',
			function ( $matches ) use ( $list_id, $list_name, &$index ) {
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
					$product->get_permalink(),
					$list_id
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
	 * Maps a Product Collection preset (the block's "collection" attribute) to one
	 * of the plugin's own list keys, so the block reports the same GA4 identity as
	 * the shortcode and legacy-grid rendering of the same list.
	 *
	 * The 14 slugs are WooCommerce's CoreCollectionNames enum, measured 2026-08-06
	 * against WC trunk and the 11.0.0 tag (upstream registry U26). A collection the
	 * plugin does not know - a newly added core one, or one a third party
	 * registered through the documented register_product_collection() API - falls
	 * back to the generic list rather than guessing a name from the slug.
	 *
	 * @param string $collection The block's collection attribute (e.g. woocommerce/product-collection/on-sale).
	 * @return string One of the LIST_* constants.
	 */
	private function product_collection_list_key( string $collection ): string {
		switch ( $collection ) {
			case 'woocommerce/product-collection/product-catalog':
				// The plain catalog query, i.e. what the classic shop loop renders.
				return self::LIST_GENERAL;

			case 'woocommerce/product-collection/on-sale':
				return self::LIST_SALE;

			case 'woocommerce/product-collection/best-sellers':
				return self::LIST_BESTSELLERS;

			case 'woocommerce/product-collection/top-rated':
				return self::LIST_TOPRATED;

			case 'woocommerce/product-collection/new-arrivals':
				return self::LIST_NEWARRIVALS;

			case 'woocommerce/product-collection/featured':
				return self::LIST_FEATURED;

			case 'woocommerce/product-collection/related':
				return self::LIST_RELATED;

			case 'woocommerce/product-collection/upsells':
				return self::LIST_UPSELL;

			case 'woocommerce/product-collection/cross-sells':
				return self::LIST_CROSSSELL;

			// The four below are the Product Collection successors of legacy grid
			// blocks the plugin already names, so they deliberately resolve to the
			// same list identity: a store migrating one of those blocks keeps its
			// GA4 list history instead of starting a new list.
			case 'woocommerce/product-collection/hand-picked':
				return self::LIST_HANDPICKED;

			case 'woocommerce/product-collection/by-category':
				return self::LIST_BYCATEGORY;

			case 'woocommerce/product-collection/by-tag':
				return self::LIST_BYTAG;

			case 'woocommerce/product-collection/by-brand':
				return self::LIST_BYBRAND;

			case 'woocommerce/product-collection/cart-contents':
				return self::LIST_CARTCONTENTS;

			default:
				return self::LIST_COLLECTION;
		}
	}
}
