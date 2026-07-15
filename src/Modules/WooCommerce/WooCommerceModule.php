<?php
/**
 * WooCommerce module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Module\AbstractModule;
use GTM4WP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * GA4 e-commerce tracking for WooCommerce: view_item, view_item_list,
 * select_item, add_to_cart, remove_from_cart, view_cart, begin_checkout,
 * add_shipping_info, add_payment_info and purchase events.
 *
 * Port of integration/woocommerce.php from 1.x: hook wiring lives here,
 * the implementation is split into ProductData (item/order arrays),
 * PageDataLayer (page load data layer + events), ListTracking (product
 * list markup) and PurchaseTracking (thankyou fallback + dedupe).
 */
final class WooCommerceModule extends AbstractModule {

	/**
	 * Minimum supported WooCommerce version.
	 */
	public const MIN_WC_VERSION = '5.0';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE      => false,
			GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION   => 10,
			GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL    => false,
			GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY    => '',
			GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL    => 'retail',
			GTM4WP_OPTION_INTEGRATE_WCUSESKU              => false,
			GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT    => false,
			GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH => false,
			GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX     => '',
			GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA        => false,
			GTM4WP_OPTION_INTEGRATE_WCORDERDATA           => false,
			GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE         => 30,
			GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX          => false,
			GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING     => false,
			GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG  => false,
			GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL    => false,
			GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT        => 2000,
			GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES    => array( 'processing', 'on-hold', 'completed' ),
			GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE   => false,
			GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '',
		);
	}

	/**
	 * Only activate the WooCommerce integration for the minimum supported
	 * WooCommerce version, mirroring the 1.x load condition.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return isset( $GLOBALS['woocommerce'] )
			&& function_exists( 'WC' )
			&& version_compare( WC()->version, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Registers the frontend hooks. In 1.x the whole integration file only
	 * loaded when e-commerce tracking was enabled; the same gate applies here.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( true !== $this->opt( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			return;
		}

		$frontend = Plugin::instance()->frontend();

		$product_data      = new ProductData( $this->options );
		$page_datalayer    = new PageDataLayer( $this->options, $product_data, $frontend->datalayer() );
		$list_tracking     = new ListTracking( $this->options, $product_data );
		$purchase_tracking = new PurchaseTracking( $this->options, $product_data, $frontend->datalayer(), $frontend->script_tag() );
		$store_api_data    = new StoreApiData( $product_data );

		// Expose the GA4 item array on the Store API so the Cart & Checkout blocks
		// carry the same product data as the classic path (consumed by the
		// gtm4wp-woocommerce-blocks tracker).
		add_action( 'woocommerce_blocks_loaded', array( $store_api_data, 'register' ) );

		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = false;

		add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, array( $page_datalayer, 'add_datalayer_data' ) );

		add_filter( 'loop_end', array( $list_tracking, 'reset_loop' ) );
		add_action( 'woocommerce_after_shop_loop_item', array( $list_tracking, 'after_shop_loop_item' ) );
		add_action( 'woocommerce_after_add_to_cart_button', array( $list_tracking, 'single_add_to_cart_tracking' ) );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $list_tracking, 'add_to_cart_link_filter' ), 10, 2 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array( $this, 'add_global_vars' ) );

		add_filter( 'woocommerce_blocks_product_grid_item_html', array( $list_tracking, 'add_productdata_to_wc_block' ), 10, 3 );
		add_filter( 'render_block', array( $list_tracking, 'add_productdata_to_product_collection_block' ), 10, 2 );

		add_action( 'woocommerce_thankyou', array( $purchase_tracking, 'on_thankyou' ) );

		// Reliable purchase tracking and the custom order-received page both rely on
		// the placed order being remembered in the session, so register the seed
		// hooks when either feature is enabled. The union of these three hooks fires
		// for every payment method: payment_complete for instant gateways and the
		// order-pay flow, the status change for Cash on Delivery / bank transfer,
		// and the thank-you render as a final safety net.
		$purchase_on_any_page = ( true === $this->opt( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE ) );
		$custom_received_page = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE );

		if ( $purchase_on_any_page || $custom_received_page > 0 ) {
			add_action( 'woocommerce_payment_complete', array( $purchase_tracking, 'remember_order' ) );
			add_action( 'woocommerce_order_status_changed', array( $purchase_tracking, 'remember_order' ) );
			add_action( 'woocommerce_thankyou', array( $purchase_tracking, 'remember_order' ) );
		}

		if ( $custom_received_page > 0 ) {
			add_filter( 'woocommerce_is_order_received_page', array( $this, 'filter_is_order_received_page' ) );
		}

		add_action( 'woocommerce_before_template_part', array( $list_tracking, 'before_template_part' ) );
		add_action( 'woocommerce_after_template_part', array( $list_tracking, 'after_template_part' ) );
		add_filter( 'widget_title', array( $list_tracking, 'widget_title_filter' ) );
		add_action( 'wc_quick_view_before_single_product', array( $list_tracking, 'quick_view_before_single_product' ) );
		add_filter( 'woocommerce_grouped_product_list_column_label', array( $list_tracking, 'grouped_product_list_column_label' ), 10, 2 );

		add_filter( 'woocommerce_cart_item_product', array( $list_tracking, 'cart_item_product_filter' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $list_tracking, 'cart_item_remove_link_filter' ) );
		add_action( 'woocommerce_cart_item_restored', array( $list_tracking, 'cart_item_restored' ) );

		add_filter( 'woocommerce_related_products_args', array( $list_tracking, 'add_related_to_loop' ) );
		add_filter( 'woocommerce_related_products_columns', array( $list_tracking, 'add_related_to_loop' ) );
		add_filter( 'woocommerce_cross_sells_columns', array( $list_tracking, 'add_cross_sell_to_loop' ) );
		add_filter( 'woocommerce_upsells_columns', array( $list_tracking, 'add_upsells_to_loop' ) );

		add_action( 'woocommerce_shortcode_before_recent_products_loop', array( $list_tracking, 'before_recent_products_loop' ) );
		add_action( 'woocommerce_shortcode_before_sale_products_loop', array( $list_tracking, 'before_sale_products_loop' ) );
		add_action( 'woocommerce_shortcode_before_best_selling_products_loop', array( $list_tracking, 'before_best_selling_products_loop' ) );
		add_action( 'woocommerce_shortcode_before_top_rated_products_loop', array( $list_tracking, 'before_top_rated_products_loop' ) );
		add_action( 'woocommerce_shortcode_before_featured_products_loop', array( $list_tracking, 'before_featured_products_loop' ) );
		add_action( 'woocommerce_shortcode_before_related_products_loop', array( $list_tracking, 'before_related_products_loop' ) );
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}

	/**
	 * Function to be called on the gtm4wp_add_global_vars_array hook to output
	 * WooCommerce related global JavaScript variables.
	 *
	 * @param array $return_vars The already added variables as key-value pairs in an associative array.
	 * @return array The parameter with added global JavaScript variables as key-value pairs.
	 */
	public function add_global_vars( $return_vars ) {
		$return_vars['gtm4wp_use_sku_instead']        = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_WCUSESKU );
		$return_vars['gtm4wp_currency']               = get_woocommerce_currency();
		$return_vars['gtm4wp_product_per_impression'] = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION );
		$return_vars['gtm4wp_clear_ecommerce']        = (bool) $this->opt( GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL );
		$return_vars['gtm4wp_datalayer_max_timeout']  = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT );

		// Mirror the site-wide "Do not use console.log() messages on frontend"
		// option so the ecommerce tracker can log the events it pushes only when
		// the admin has left console output enabled.
		$return_vars['gtm4wp_console_log'] = ! (bool) $this->opt( GTM4WP_OPTION_NOCONSOLELOG );

		// The dynamic-remarketing product-id prefix, so the found_variation handler can
		// re-apply it to a selected variation's id (it is otherwise dropped, #383).
		$return_vars['gtm4wp_remarketing_prod_id_prefix'] = (string) $this->opt( GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX );

		return $return_vars;
	}

	/**
	 * Forces WooCommerce's is_order_received_page() to return true on the page
	 * selected in the "Custom order received page" option, so a bespoke thank-you
	 * page fires the purchase event through the standard order-received data layer
	 * path (which resolves the order from the session on such pages). Only hooked
	 * to woocommerce_is_order_received_page when that option is set.
	 *
	 * @param bool $is_order_received_page Whether WooCommerce already considers this the order-received page.
	 * @return bool
	 */
	public function filter_is_order_received_page( $is_order_received_page ): bool {
		if ( $is_order_received_page ) {
			return true;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		$page_id = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE );

		return $page_id > 0 && function_exists( 'is_page' ) && is_page( $page_id );
	}

	/**
	 * Loads the ecommerce frontend scripts.
	 *
	 * The WooCommerce tracker keeps its jQuery dependency on purpose:
	 * WooCommerce core fires the found_variation and checkout_place_order
	 * events and the Quick View AJAX completion through jQuery's own event
	 * system which vanilla listeners can not observe. WooCommerce loads
	 * jQuery on these pages anyway, so no extra request is caused.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE, true );

		$this->enqueue_script( 'gtm4wp-ecommerce-generic', 'gtm4wp-ecommerce-generic.js', array(), $in_footer );

		if ( $this->is_block_cart_or_checkout() ) {
			// The Cart & Checkout blocks are React-based and never fire the classic
			// jQuery events the gtm4wp-woocommerce tracker listens for. On those
			// pages load the block tracker (which reads the WooCommerce data stores)
			// and skip the classic one so cart/checkout events are not tracked twice.
			$this->enqueue_blocks_tracker( 'cartcheckout', $in_footer );
		} else {
			$this->enqueue_script( 'gtm4wp-woocommerce', 'gtm4wp-woocommerce.js', array( 'jquery' ), $in_footer, '' );

			// A block-based store usually renders the Mini-Cart block in its header on
			// every page. Removing an item (or changing its quantity) in the Mini-Cart
			// drawer is a React-only interaction the classic tracker never sees, so load
			// the block tracker in "minicart" mode alongside the classic one. In that
			// mode it fires remove_from_cart only, from the net cart diff; the classic
			// tracker keeps sole ownership of add_to_cart, so nothing is counted twice.
			if ( $this->store_uses_cart_blocks() ) {
				$this->enqueue_blocks_tracker( 'minicart', $in_footer );
			}
		}
	}

	/**
	 * Enqueues the WooCommerce block tracker and tells it which surface it runs on.
	 *
	 * The context decides which events the tracker owns (see gtm4wp-woocommerce-blocks.js):
	 * "cartcheckout" fires the full add/remove/checkout/cross-sell set, "minicart"
	 * fires remove_from_cart only so it can coexist with the classic tracker without
	 * double counting.
	 *
	 * @param string $context   Either 'cartcheckout' or 'minicart'.
	 * @param bool   $in_footer Whether to print the script in the footer.
	 * @return void
	 */
	private function enqueue_blocks_tracker( string $context, bool $in_footer ): void {
		$this->enqueue_script(
			'gtm4wp-woocommerce-blocks',
			'gtm4wp-woocommerce-blocks.js',
			array( 'wp-data', 'gtm4wp-ecommerce-generic' ),
			$in_footer
		);

		wp_add_inline_script(
			'gtm4wp-woocommerce-blocks',
			'window.gtm4wp_blocks_context = ' . wp_json_encode(
				$context,
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
			) . ';',
			'before'
		);
	}

	/**
	 * Whether the store's cart or checkout is backed by the WooCommerce block (a
	 * site-level, cache-safe signal). Block-based stores render the Mini-Cart block
	 * in the header, so this gates loading the block tracker in "minicart" mode on
	 * ordinary pages. Prefers WooCommerce's canonical CartCheckoutUtils checks.
	 *
	 * @return bool
	 */
	private function store_uses_cart_blocks(): bool {
		$utils = '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';

		if ( method_exists( $utils, 'is_cart_block_default' ) && $utils::is_cart_block_default() ) {
			return true;
		}

		if ( method_exists( $utils, 'is_checkout_block_default' ) && $utils::is_checkout_block_default() ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the current page is the Cart or Checkout page rendered with the
	 * WooCommerce block (as opposed to the classic shortcode). Detection is
	 * content-driven, never visitor-driven, so it is safe under full-page caching.
	 * The order-received endpoint is excluded (its purchase event is server-side).
	 *
	 * @return bool
	 */
	public function is_block_cart_or_checkout(): bool {
		if (
			function_exists( 'is_checkout' ) && is_checkout()
			&& ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
		) {
			return $this->page_uses_block( 'woocommerce/checkout' );
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return $this->page_uses_block( 'woocommerce/cart' );
		}

		return false;
	}

	/**
	 * Whether the given WooCommerce block backs the current cart/checkout page.
	 * Prefers WooCommerce's canonical CartCheckoutUtils check and falls back to
	 * scanning the current page content for the block.
	 *
	 * @param string $block_name The block name (woocommerce/cart or woocommerce/checkout).
	 * @return bool
	 */
	private function page_uses_block( string $block_name ): bool {
		$utils = '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';

		if ( 'woocommerce/checkout' === $block_name && method_exists( $utils, 'is_checkout_block_default' ) ) {
			return (bool) $utils::is_checkout_block_default();
		}

		if ( 'woocommerce/cart' === $block_name && method_exists( $utils, 'is_cart_block_default' ) ) {
			return (bool) $utils::is_cart_block_default();
		}

		$post = function_exists( 'get_post' ) ? get_post() : null;

		return ( $post instanceof \WP_Post ) && function_exists( 'has_block' ) && has_block( $block_name, $post );
	}
}
