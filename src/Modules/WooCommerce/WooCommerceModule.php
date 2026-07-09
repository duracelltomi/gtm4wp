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

		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = false;

		add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, array( $page_datalayer, 'add_datalayer_data' ) );

		add_filter( 'loop_end', array( $list_tracking, 'reset_loop' ) );
		add_action( 'woocommerce_after_shop_loop_item', array( $list_tracking, 'after_shop_loop_item' ) );
		add_action( 'woocommerce_after_add_to_cart_button', array( $list_tracking, 'single_add_to_cart_tracking' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array( $this, 'add_global_vars' ) );

		add_filter( 'woocommerce_blocks_product_grid_item_html', array( $list_tracking, 'add_productdata_to_wc_block' ), 10, 3 );

		add_action( 'woocommerce_thankyou', array( $purchase_tracking, 'on_thankyou' ) );

		add_action( 'woocommerce_before_template_part', array( $list_tracking, 'before_template_part' ) );
		add_action( 'woocommerce_after_template_part', array( $list_tracking, 'after_template_part' ) );
		add_filter( 'widget_title', array( $list_tracking, 'widget_title_filter' ) );
		add_action( 'wc_quick_view_before_single_product', array( $list_tracking, 'quick_view_before_single_product' ) );
		add_filter( 'woocommerce_grouped_product_list_column_label', array( $list_tracking, 'grouped_product_list_column_label' ), 10, 2 );

		add_filter( 'woocommerce_cart_item_product', array( $list_tracking, 'cart_item_product_filter' ) );
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

		return $return_vars;
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
		$this->enqueue_script( 'gtm4wp-woocommerce', 'gtm4wp-woocommerce.js', array( 'jquery' ), $in_footer, '' );
	}
}
