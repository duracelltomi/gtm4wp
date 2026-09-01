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

		// Cache-safe data layer (issue #398): when the mode is on, the customer/cart
		// block is omitted from the cacheable HTML (in add_datalayer_data) and carried
		// instead on the existing cart-fragments AJAX, so no new per-page request is
		// added. The gtm4wp-visitor-data runtime (enqueued below) reads it and pushes
		// each half as its own event — gtm4wp.customerData and gtm4wp.cartData.
		if ( PageDataLayer::delivers_visitor_cart_client_side( $this->options ) ) {
			add_action( 'wp_footer', array( $page_datalayer, 'output_visitor_cart_placeholder' ) );
			add_filter( 'woocommerce_add_to_cart_fragments', array( $page_datalayer, 'add_visitor_cart_fragment' ) );
		}

		// Cache-safe data layer (issue #398, Phase 3): declare the two WooCommerce
		// one-shot EVENTS (re-added-to-cart and the reliable-purchase fallback) so the
		// session endpoint resolves them for the current request and the client
		// runtime fires them once, cookie-gated. The declare method no-ops unless the
		// cache-safe mode is on, so this is safe to register unconditionally.
		add_filter( GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS, array( $page_datalayer, 'declare_visitor_scoped_fields' ) );

		// Cache-safe data layer (issue #398): the one-shot events are delivered over a
		// public, unauthenticated GET, which must not change state. Register the
		// authenticated POST beacon routes that perform every state change instead —
		// consuming each delivered event's session marker, and flagging the fallback
		// order _ga_tracked (closing the cross-device double-count). Each id comes
		// solely from the session marker, so there is no IDOR, and both routes verify
		// the wp_rest nonce. The purchase route gates itself on the reliable-purchase
		// feature; the re-add route ships with the mode.
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

		add_action( 'wc_quick_view_before_single_product', array( $list_tracking, 'quick_view_before_single_product' ) );
		add_filter( 'woocommerce_grouped_product_list_column_label', array( $list_tracking, 'grouped_product_list_column_label' ), 10, 2 );

		add_filter( 'woocommerce_cart_item_product', array( $list_tracking, 'cart_item_product_filter' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $list_tracking, 'cart_item_remove_link_filter' ) );
		add_action( 'woocommerce_cart_item_restored', array( $list_tracking, 'cart_item_restored' ) );

		// Only _columns: `woocommerce_related_products_args` does not exist in any
		// WooCommerce release inside our supported range (checked 5.0.0, 8.0.0,
		// 10.6.1, 11.0.0), so registering on it never fired. The modern spelling
		// is `woocommerce_output_related_products_args`, but it is absent in 5.0
		// and would need a version guard for no gain: _columns already fires
		// before the loop renders, which is when the list name must be set.
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

		// Whether to persist GA4 list attribution across the funnel via a first-party
		// cookie (#405). Off by default (opt-in); when off the tracker never writes or
		// reads the cookie, so stores already doing this in GTM are untouched.
		$return_vars['gtm4wp_list_attribution'] = (int) ( true === $this->opt( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION ) );

		// Whether to also bind the checkout-step events (add_shipping_info /
		// add_payment_info) to CheckoutWC's own cfw_step_changed event (#385).
		// CheckoutWC replaces the checkout template, so the classic markers the
		// tracker gates on are not reliably present. Off by default (opt-in).
		$return_vars['gtm4wp_checkoutwc'] = (int) ( true === $this->opt( GTM4WP_OPTION_INTEGRATE_WC_CHECKOUTWC ) );

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

		// Cache-safe data layer (issue #398): when the customer/cart block is being
		// delivered client-side, load both ends of the cart-fragments channel.
		if ( PageDataLayer::delivers_visitor_cart_client_side( $this->options ) ) {
			$this->enqueue_visitor_cart_channel();
		}

		// #405: on a product page with list attribution on, the server-rendered
		// view_item push is wrapped in a function this file defines. That push is an
		// inline script with no src, so it executes while the document is parsed -
		// ahead of every deferred bundle - and the helper has to already be there.
		// Dropping the defer on this one page is what puts it there; everywhere else
		// (and with the option off) it stays deferred exactly as before.
		$this->enqueue_script(
			'gtm4wp-ecommerce-generic',
			'gtm4wp-ecommerce-generic.js',
			array(),
			$in_footer,
			$this->wraps_product_view_item() ? '' : 'defer'
		);

		$block_context = $this->block_cart_or_checkout_context();
		if ( '' !== $block_context ) {
			// The Cart & Checkout blocks are React-based and never fire the classic
			// jQuery events the gtm4wp-woocommerce tracker listens for. On those
			// pages load the block tracker (which reads the WooCommerce data stores)
			// and skip the classic one so cart/checkout events are not tracked twice.
			$this->enqueue_blocks_tracker( $block_context, $in_footer );
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
	 * Whether this request renders a product-detail view_item whose push is wrapped
	 * in the client-side list-attribution helper (#405) - which is what makes the
	 * load order of gtm4wp-ecommerce-generic.js matter on this page.
	 *
	 * Mirrors the conditions PageDataLayer::add_product_view() pushes under, so the
	 * two never disagree about whether a wrapped push is on the page.
	 *
	 * @return bool
	 */
	private function wraps_product_view_item(): bool {
		// Options first: both are a cheap array read, while is_product() is a
		// WooCommerce conditional tag that inspects the main query. With the feature
		// off - which is the default, and every non-store page - the tag is never
		// consulted at all.
		return true === $this->opt( GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION )
			&& (bool) $this->opt( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE )
			&& function_exists( 'is_product' )
			&& is_product();
	}

	/**
	 * Declares gtm4wp-ecommerce-generic as a dependency of the data layer push
	 * handle, so WordPress prints the helper before the inline push that calls it.
	 *
	 * Only on the pages that actually carry a wrapped push - a dependency is a
	 * loading constraint, and there is no reason to impose one anywhere else.
	 * DataLayer::add_push_handle_dependency() refuses to add it unless both handles
	 * are registered, because a dependency on an unregistered handle makes WordPress
	 * drop the dependent script and would take every data layer push with it.
	 *
	 * Belt and braces: even if this never runs, the emitted call resolves the helper
	 * off window with an identity fallback, so the worst case is an unenriched
	 * view_item rather than a broken one.
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
	 * layer (issue #398). Called only when the customer/cart block is being delivered
	 * client-side; the two handles are useless apart.
	 *
	 * The reading end is our own visitor-data runtime, needed even on pages where the
	 * PageVariables module declared no visitor fields of its own. Enqueuing that handle
	 * is idempotent, since VisitorDataModule may also enqueue it — but note this adds it
	 * WITHOUT the inline config, which only that module emits. In practice the config is
	 * always present, because declare_visitor_scoped_fields() always declares the
	 * re-added-to-cart one-shot in this mode so the config is never null; that is
	 * incidental though (a third party can filter those fields away), which is why the
	 * runtime keeps its own fallbacks for all three event names.
	 *
	 * The delivering end is WooCommerce's own cart-fragments script, which is what
	 * actually fetches the fragments response our filter writes into. WooCommerce
	 * enqueues that handle from exactly one frontend path — the render of its legacy Cart
	 * widget — and that path bails out when the woocommerce_widget_cart_is_hidden filter
	 * is true, whose default is "the cart or the checkout page". The Mini-Cart block
	 * never enqueues it at all. So without this the block silently never arrived on a
	 * store with no legacy mini-cart widget, nor on the cart and checkout pages of one
	 * that has it. An AJAX add-to-cart did still deliver it, because the wc-add-to-cart
	 * script replaces the fragment nodes itself, so it was page-load delivery that was
	 * missing. The cost on a store that was not loading the script: WooCommerce's
	 * refresh request, once per browser tab (its cache is per-tab sessionStorage), and
	 * once per page view for a visitor who has blocked Web Storage.
	 *
	 * That enqueue does not check whether WooCommerce has registered the handle yet.
	 * WooCommerce registers it on every frontend request, from a callback on the same
	 * wp_enqueue_scripts priority as this one, so ours can run first and a "is it
	 * registered yet" check would be false and skip the enqueue — a race, not a safety
	 * net. The handle-only form merely appends to the queue, and WordPress resolves the
	 * queue against its registry when scripts are printed, long after every enqueue
	 * callback has run. It is not declared as a dependency of our runtime either: the
	 * runtime reads the placeholder once and then observes it, so it does not need that
	 * script to have run first, and a dependency would couple our loading to
	 * WooCommerce's on every page our runtime loads — including pages with no
	 * WooCommerce at all. Localization needs no help, because WooCommerce localizes each
	 * of its registered handles for whoever has it enqueued, and the jquery and cookie
	 * dependencies come from its own registration.
	 *
	 * It IS gated on the visitor having WooCommerce state, though, and only that half is
	 * gated — our own runtime always loads. WooCommerce's cart-fragments script has no
	 * empty-cart bail-out (verified against its own source), so an ungated enqueue makes
	 * every visitor of the store pay an uncached wc-ajax round trip per browser tab,
	 * including one who has never touched the shop — which is what WooCommerce itself
	 * stopped doing in 7.8, and what PageDataLayer::oneshot_wc() already refuses to do on
	 * the other delivery channel for the same reason.
	 *
	 * Nothing is lost by waiting. A visitor with no WooCommerce state has an empty cart
	 * and blank customer fields, so page-load delivery would carry nothing a tag could
	 * use; and the moment they DO add something, WooCommerce's own wc-add-to-cart script
	 * splices our fragment into the page from the add-to-cart response (the paragraph
	 * above), so the very first cartData/customerData still fires on that same page
	 * without this script. From their next page view onward the cookie exists and the
	 * gate is open.
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

		wp_add_inline_script(
			'gtm4wp-woocommerce-blocks',
			'window.gtm4wp_blocks_context = ' . ScriptTag::json_literal(
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
	 * WooCommerce block (as opposed to the classic shortcode).
	 *
	 * @return bool
	 */
	public function is_block_cart_or_checkout(): bool {
		return '' !== $this->block_cart_or_checkout_context();
	}

	/**
	 * Which block surface the current page is: 'checkout' (block Checkout page),
	 * 'cart' (block Cart page) or '' (neither). Detection is content-driven, never
	 * visitor-driven, so it is safe under full-page caching. The order-received
	 * endpoint is excluded (its purchase event is server-side).
	 *
	 * The two pages must stay distinct here: the tracker used to receive one merged
	 * context and told the pages apart by the presence of the wc/store/payment data
	 * store, but WooCommerce registers that store on the Cart page too, which made
	 * add_shipping_info / add_payment_info fire there with no interaction (#463).
	 *
	 * @return string
	 */
	private function block_cart_or_checkout_context(): string {
		if (
			function_exists( 'is_checkout' ) && is_checkout()
			&& ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
		) {
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
