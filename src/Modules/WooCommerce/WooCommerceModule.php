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

use GTM4WP\Frontend\ScriptTag;
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
			GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE      => false,
			GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX     => '',
			GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA        => false,
			GTM4WP_OPTION_INTEGRATE_WCORDERDATA           => false,
			GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE         => 30,
			GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX          => false,
			GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING     => false,
			GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG  => false,
			GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL    => false,
			GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT        => 2000,
			GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => '',
			GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES    => array( 'processing', 'on-hold', 'completed' ),
			GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE   => false,
			GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '',
			GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION     => false,
			GTM4WP_OPTION_INTEGRATE_WC_CHECKOUTWC         => false,
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
		$page_datalayer    = new PageDataLayer( $this->options, $product_data, $frontend->datalayer(), $frontend->script_tag() );
		$list_tracking     = new ListTracking( $this->options, $product_data );
		$purchase_tracking = new PurchaseTracking( $this->options, $product_data, $frontend->datalayer(), $frontend->script_tag() );
		$store_api_data    = new StoreApiData( $product_data );

		// Expose the GA4 item array on the Store API so the Cart & Checkout blocks
		// carry the same product data as the classic path (consumed by the
		// gtm4wp-woocommerce-blocks tracker).
		add_action( 'woocommerce_blocks_loaded', array( $store_api_data, 'register' ) );

		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = false;

		add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, array( $page_datalayer, 'add_datalayer_data' ) );

		// Cache-safe data layer (issue #398): the customer/cart block rides the
		// cart-fragments AJAX instead of the cacheable HTML; the gtm4wp-visitor-data
		// runtime pushes each half as gtm4wp.customerData / gtm4wp.cartData.
		if ( PageDataLayer::delivers_visitor_cart_client_side( $this->options ) ) {
			add_action( 'wp_footer', array( $page_datalayer, 'output_visitor_cart_placeholder' ) );
			add_filter( 'woocommerce_add_to_cart_fragments', array( $page_datalayer, 'add_visitor_cart_fragment' ) );
		}

		// The two WooCommerce one-shot events for the session endpoint; the declare
		// method no-ops unless the cache-safe mode is on.
		add_filter( GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS, array( $page_datalayer, 'declare_visitor_scoped_fields' ) );

		// The POST beacon routes that perform the state changes the read-only GET
		// session endpoint must not (see PageDataLayer::register_confirm_purchase_route).
		if ( (bool) $this->opt( GTM4WP_OPTION_CACHE_SAFE_DATALAYER ) ) {
			add_action( 'rest_api_init', array( $page_datalayer, 'register_confirm_purchase_route' ) );
		}

		add_filter( 'loop_end', array( $list_tracking, 'reset_loop' ) );
		add_action( 'woocommerce_after_shop_loop_item', array( $list_tracking, 'after_shop_loop_item' ) );
		add_action( 'woocommerce_after_add_to_cart_button', array( $list_tracking, 'single_add_to_cart_tracking' ) );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $list_tracking, 'add_to_cart_link_filter' ), 10, 2 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Runs after every enqueue callback on the default priority, so both handles
		// it orders are registered by then. See order_generic_before_pushes().
		add_action( 'wp_enqueue_scripts', array( $this, 'order_generic_before_pushes' ), 20 );

		add_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array( $this, 'add_global_vars' ) );

		add_filter( 'woocommerce_blocks_product_grid_item_html', array( $list_tracking, 'add_productdata_to_wc_block' ), 10, 3 );
		add_filter( 'render_block', array( $list_tracking, 'add_productdata_to_product_collection_block' ), 10, 2 );

		add_action( 'woocommerce_thankyou', array( $purchase_tracking, 'on_thankyou' ) );

		// Reliable purchase tracking and the custom order-received page both need the
		// placed order remembered in the session. The three hooks together cover every
		// payment method: payment_complete (instant gateways, order-pay), the status
		// change (COD / bank transfer) and the thank-you render as a safety net.
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

		add_action( 'wc_quick_view_before_single_product', array( $list_tracking, 'quick_view_before_single_product' ) );
		add_filter( 'woocommerce_grouped_product_list_column_label', array( $list_tracking, 'grouped_product_list_column_label' ), 10, 2 );

		add_filter( 'woocommerce_cart_item_product', array( $list_tracking, 'cart_item_product_filter' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $list_tracking, 'cart_item_remove_link_filter' ) );
		add_action( 'woocommerce_cart_item_restored', array( $list_tracking, 'cart_item_restored' ) );

		// Only _columns: `woocommerce_related_products_args` exists in no supported
		// WooCommerce release, and `woocommerce_output_related_products_args` is
		// absent in 5.0; _columns already fires before the loop renders.
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

		// Mirrors the site-wide "Do not use console.log()" option.
		$return_vars['gtm4wp_console_log'] = ! (bool) $this->opt( GTM4WP_OPTION_NOCONSOLELOG );

		// So the found_variation handler can re-apply the prefix to a variation id (#383).
		$return_vars['gtm4wp_remarketing_prod_id_prefix'] = (string) $this->opt( GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX );

		// Opt-in list attribution cookie (#405); when off the tracker never touches it.
		$return_vars['gtm4wp_list_attribution'] = (int) ( true === $this->opt( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION ) );

		// Opt-in: also bind the checkout-step events to CheckoutWC's cfw_step_changed
		// event, since it replaces the checkout template the tracker gates on (#385).
		$return_vars['gtm4wp_checkoutwc'] = (int) ( true === $this->opt( GTM4WP_OPTION_INTEGRATE_WC_CHECKOUTWC ) );

		return $return_vars;
	}

	/**
	 * Makes is_order_received_page() true on the "Custom order received page", so a
	 * bespoke thank-you page takes the standard purchase path (which resolves the
	 * order from the session there). Hooked only when that option is set.
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
	 * Loads the ecommerce frontend scripts. The WooCommerce tracker keeps its jQuery
	 * dependency on purpose: found_variation, checkout_place_order and the Quick
	 * View AJAX completion are jQuery-only events. WooCommerce loads jQuery on
	 * these pages anyway.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE, true );

		// Cache-safe data layer (issue #398): both ends of the cart-fragments channel.
		if ( PageDataLayer::delivers_visitor_cart_client_side( $this->options ) ) {
			$this->enqueue_visitor_cart_channel();
		}

		// #405: on a product page with list attribution on, the inline view_item push
		// calls a helper this file defines, so it must not be deferred there;
		// everywhere else it stays deferred.
		$this->enqueue_script(
			'gtm4wp-ecommerce-generic',
			'gtm4wp-ecommerce-generic.js',
			array(),
			$in_footer,
			$this->wraps_product_view_item() ? '' : 'defer'
		);

		$block_context = $this->block_cart_or_checkout_context();
		if ( '' !== $block_context ) {
			// The Cart & Checkout blocks never fire the classic jQuery events: load
			// the block tracker instead of the classic one, so nothing is tracked twice.
			$this->enqueue_blocks_tracker( $block_context, $in_footer );
		} else {
			$this->enqueue_script( 'gtm4wp-woocommerce', 'gtm4wp-woocommerce.js', array( 'jquery' ), $in_footer, '' );
			$this->inline_store_api_cart_url( 'gtm4wp-woocommerce' );

			// The Mini-Cart drawer is React-only, so the block tracker also loads in
			// "minicart" mode: remove_from_cart only, from the net cart diff, while
			// the classic tracker keeps sole ownership of add_to_cart.
			if ( $this->store_uses_cart_blocks() ) {
				$this->enqueue_blocks_tracker( 'minicart', $in_footer );
			}
		}
	}

	/**
	 * Whether this request renders a view_item push wrapped in the client-side
	 * list-attribution helper (#405). Mirrors the conditions
	 * PageDataLayer::add_product_view() pushes under.
	 *
	 * @return bool
	 */
	private function wraps_product_view_item(): bool {
		// Cheap option reads first; is_product() inspects the main query.
		return true === $this->opt( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION )
			&& (bool) $this->opt( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE )
			&& function_exists( 'is_product' )
			&& is_product();
	}

	/**
	 * Declares gtm4wp-ecommerce-generic as a dependency of the data layer push
	 * handle, only on pages carrying a wrapped push, so the helper prints before
	 * the inline push that calls it. Even if this never runs, the emitted call has
	 * an identity fallback, so the worst case is an unenriched view_item.
	 *
	 * @return void
	 */
	public function order_generic_before_pushes(): void {
		if ( ! $this->wraps_product_view_item() ) {
			return;
		}

		Plugin::instance()->frontend()->datalayer()->add_push_handle_dependency( 'gtm4wp-ecommerce-generic' );
	}

	/**
	 * Loads both ends of the cart-fragments delivery channel for the cache-safe data
	 * layer (issue #398); the two handles are useless apart.
	 *
	 * The reading end is our visitor-data runtime, enqueued here WITHOUT the inline
	 * config only VisitorDataModule emits (idempotent; the runtime keeps fallbacks
	 * for the event names). The delivering end is WooCommerce's wc-cart-fragments,
	 * which WooCommerce only enqueues from its legacy Cart widget and never on the
	 * cart/checkout pages or from the Mini-Cart block, so without this the block
	 * never arrived on page load. Do NOT guard it with a "registered yet" check
	 * (WooCommerce registers it on the same priority, so that is a race) and do NOT
	 * declare it a dependency of our runtime (it would couple our loading to
	 * WooCommerce's on every page).
	 *
	 * Gated on the visitor having WooCommerce state: the script has no empty-cart
	 * bail-out, so an ungated enqueue costs every visitor an uncached wc-ajax
	 * round trip per tab, which WooCommerce itself stopped doing in 7.8. Nothing is
	 * lost by waiting: the first add-to-cart response splices our fragment in
	 * itself, and from the next page view the cookie exists.
	 *
	 * @return void
	 */
	private function enqueue_visitor_cart_channel(): void {
		$this->enqueue_script( 'gtm4wp-visitor-data', 'gtm4wp-visitor-data.js' );

		if ( Helpers::visitor_has_wc_state() ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}
	}

	/**
	 * Enqueues the WooCommerce block tracker and tells it which surface it runs on.
	 *
	 * The context decides which events the tracker owns (see gtm4wp-woocommerce-blocks.js):
	 * "cart" fires the add/remove/cross-sell set, "checkout" additionally owns the
	 * add_shipping_info / add_payment_info steps, "minicart" fires remove_from_cart
	 * only so it can coexist with the classic tracker without double counting.
	 *
	 * @param string $context   One of 'cart', 'checkout' or 'minicart'.
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

		$inline = 'window.gtm4wp_blocks_context = ' . ScriptTag::json_literal(
			$context,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		) . ';';

		wp_add_inline_script( 'gtm4wp-woocommerce-blocks', $inline, 'before' );
		$this->inline_store_api_cart_url( 'gtm4wp-woocommerce-blocks' );
	}

	/**
	 * Tells a tracker where the Store API cart lives. Both trackers read the cart
	 * from there on an Interactivity API store (no wc/store/cart data store; the
	 * interactive form publishes no variation data). Built server-side because a
	 * site can move the REST root and a guessed URL would 404 in silence.
	 *
	 * @param string $handle The script handle to attach it to.
	 * @return void
	 */
	private function inline_store_api_cart_url( string $handle ): void {
		wp_add_inline_script(
			$handle,
			'window.gtm4wp_store_api_cart_url = ' . ScriptTag::json_literal(
				rest_url( 'wc/store/v1/cart' ),
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
	 * WooCommerce block (as opposed to the classic shortcode).
	 *
	 * @return bool
	 */
	public function is_block_cart_or_checkout(): bool {
		return '' !== $this->block_cart_or_checkout_context();
	}

	/**
	 * Which block surface the current page is: 'checkout', 'cart' or '' (neither).
	 * Content-driven, never visitor-driven, so safe under full-page caching. The
	 * two pages must stay distinct (the tracker cannot tell them apart by the
	 * wc/store/payment store, which exists on the Cart page too, #463), and the
	 * order-received page is excluded ahead of both arms because is_checkout() and
	 * on some stores is_cart() are true there (see PageDataLayer::add_datalayer_data()).
	 *
	 * @return string
	 */
	private function block_cart_or_checkout_context(): string {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return '';
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return $this->page_uses_block( 'woocommerce/checkout' ) ? 'checkout' : '';
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return $this->page_uses_block( 'woocommerce/cart' ) ? 'cart' : '';
		}

		return '';
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
