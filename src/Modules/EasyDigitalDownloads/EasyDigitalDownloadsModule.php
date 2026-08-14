<?php
/**
 * Easy Digital Downloads module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\EasyDigitalDownloads;

use GTM4WP\Module\AbstractModule;
use GTM4WP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * GA4 e-commerce tracking for Easy Digital Downloads: view_item,
 * view_item_list, select_item, add_to_cart, remove_from_cart, view_cart,
 * begin_checkout, add_payment_info and purchase events.
 *
 * Mirrors the WooCommerce module for EDD's digital-goods flow: hook wiring
 * lives here, the implementation is split into DownloadData (item/order
 * arrays + purchase eligibility), PageDataLayer (page load data layer +
 * server-side events incl. the success-page purchase) and ListTracking
 * (product list / buy button / checkout cart markup). Requires EDD 3.0+
 * (the rewritten orders API).
 */
final class EasyDigitalDownloadsModule extends AbstractModule {

	/**
	 * Minimum supported Easy Digital Downloads version. Everything the module
	 * reads (EDD\Orders\Order, edd_get_order_by, edd_*_order_meta) is 3.x API.
	 */
	public const MIN_EDD_VERSION = '3.0';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'edd';
	}

	/**
	 * Option defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE      => false,
			GTM4WP_OPTION_INTEGRATE_EDDPRODPERIMPRESSION   => 10,
			GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL     => false,
			GTM4WP_OPTION_INTEGRATE_EDDBRANDTAXONOMY       => '',
			GTM4WP_OPTION_INTEGRATE_EDDBUSINESSVERTICAL    => 'retail',
			GTM4WP_OPTION_INTEGRATE_EDDUSESKU              => false,
			GTM4WP_OPTION_INTEGRATE_EDDUSEFULLCATEGORYPATH => false,
			GTM4WP_OPTION_INTEGRATE_EDDPRODIDPREFIX        => '',
			GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA        => false,
			GTM4WP_OPTION_INTEGRATE_EDDORDERDATA           => false,
			GTM4WP_OPTION_INTEGRATE_EDDORDERMAXAGE         => 30,
			GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX          => false,
			GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG  => false,
			GTM4WP_OPTION_INTEGRATE_EDDCLEARECOMMERCEDL    => false,
			GTM4WP_OPTION_INTEGRATE_EDDDLMAXTIMEOUT        => 2000,
			GTM4WP_OPTION_INTEGRATE_EDDTRANSACTIONIDPREFIX => '',
			GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES    => array( 'pending', 'processing', 'complete' ),
			GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE      => false,
			GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION     => false,
		);
	}

	/**
	 * Only activate the integration for EDD 3.0+, whose rewritten orders API
	 * (EDD\Orders\Order, edd_get_order_by, order meta functions) this module
	 * is built on.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'EDD' )
			&& defined( 'EDD_VERSION' )
			&& version_compare( EDD_VERSION, self::MIN_EDD_VERSION, '>=' );
	}

	/**
	 * Registers the frontend hooks. Everything is gated on the single
	 * "Track e-commerce" option, mirroring the WooCommerce module.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( true !== $this->opt( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE ) ) {
			return;
		}

		$frontend = Plugin::instance()->frontend();

		$download_data  = new DownloadData( $this->options );
		$page_datalayer = new PageDataLayer( $this->options, $download_data, $frontend->datalayer() );
		$list_tracking  = new ListTracking( $this->options, $download_data );

		add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, array( $page_datalayer, 'add_datalayer_data' ) );

		// Hidden product data markup: one span per item in the [downloads] grid
		// (view_item_list / select_item), a hidden input inside every purchase
		// form (add_to_cart) and a span in every checkout cart row
		// (remove_from_cart). All read client-side by gtm4wp-edd.js.
		add_action( 'edd_download_after', array( $list_tracking, 'after_download_shortcode_item' ) );
		add_action( 'edd_blocks_downloads_after_entry_title', array( $list_tracking, 'after_downloads_block_item' ), 10, 0 );
		add_action( 'edd_purchase_link_end', array( $list_tracking, 'purchase_link_data' ), 10, 2 );
		add_action( 'edd_checkout_cart_item_title_after', array( $list_tracking, 'checkout_cart_item_data' ), 10, 2 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array( $this, 'add_global_vars' ) );
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
	 * EDD related global JavaScript variables.
	 *
	 * The shared variable names (currency, chunk size, clear-ecommerce flag,
	 * select_item timeout, console logging) are the same ones the WooCommerce
	 * module sets, because gtm4wp-ecommerce-generic.js and the trackers read
	 * them under these names. In the unusual case that both store plugins are
	 * active with both integrations enabled, the module registered later (this
	 * one) wins - noted in the admin field descriptions.
	 *
	 * @param array $return_vars The already added variables as key-value pairs in an associative array.
	 * @return array The parameter with added global JavaScript variables as key-value pairs.
	 */
	public function add_global_vars( $return_vars ) {
		$return_vars['gtm4wp_use_sku_instead']        = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_EDDUSESKU );
		$return_vars['gtm4wp_list_attribution']       = (int) ( true === $this->opt( GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION ) );
		$return_vars['gtm4wp_currency']               = function_exists( 'edd_get_currency' ) ? edd_get_currency() : '';
		$return_vars['gtm4wp_product_per_impression'] = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_EDDPRODPERIMPRESSION );
		$return_vars['gtm4wp_clear_ecommerce']        = (bool) $this->opt( GTM4WP_OPTION_INTEGRATE_EDDCLEARECOMMERCEDL );
		$return_vars['gtm4wp_datalayer_max_timeout']  = (int) $this->opt( GTM4WP_OPTION_INTEGRATE_EDDDLMAXTIMEOUT );

		// Mirror the site-wide "Do not use console.log() messages on frontend"
		// option so the ecommerce tracker can log the events it pushes only when
		// the admin has left console output enabled.
		$return_vars['gtm4wp_console_log'] = ! (bool) $this->opt( GTM4WP_OPTION_NOCONSOLELOG );

		return $return_vars;
	}

	/**
	 * Loads the ecommerce frontend scripts on every page: EDD buy buttons and
	 * the [downloads] grid can appear anywhere via shortcodes, so per-page
	 * gating is not possible. The tracker keeps a jQuery dependency on
	 * purpose: EDD core fires its cart and gateway events
	 * (edd_cart_item_added, edd_cart_item_removed, edd_gateway_loaded) through
	 * jQuery's own event system which vanilla listeners can not observe, and
	 * EDD loads jQuery on those pages anyway.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE, true );

		$this->enqueue_script( 'gtm4wp-ecommerce-generic', 'gtm4wp-ecommerce-generic.js', array(), $in_footer );
		$this->enqueue_script( 'gtm4wp-edd', 'gtm4wp-edd.js', array( 'jquery', 'gtm4wp-ecommerce-generic' ), $in_footer, '' );
	}
}
