<?php
/**
 * Easy Digital Downloads page load data layer content.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\EasyDigitalDownloads;

use GTM4WP\Ecommerce\Helpers as EcommerceHelpers;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles all Easy Digital Downloads related content of the page load data
 * layer: customer data, cart content, view_item / view_cart / begin_checkout
 * events and the purchase event on the purchase confirmation (success) page.
 * The EDD counterpart of the WooCommerce module's PageDataLayer.
 */
final class PageDataLayer {

	/**
	 * Constructor.
	 *
	 * @param Options      $options       The plugin options service.
	 * @param DownloadData $download_data The download data builder.
	 * @param DataLayer    $datalayer     The data layer service.
	 */
	public function __construct(
		private Options $options,
		private DownloadData $download_data,
		private DataLayer $datalayer
	) {
	}

	/**
	 * Function executed when the main GTM4WP data layer generation happens.
	 * Hooks into gtm4wp_compile_datalayer.
	 *
	 * @param array $data_layer An array of key-value pairs that will be converted into a JavaScript object on the frontend for GTM.
	 * @return array Extended data layer content with EDD data added.
	 */
	public function add_datalayer_data( $data_layer ) {
		if ( array_key_exists( 'HTTP_X_REQUESTED_WITH', $_SERVER ) ) {
			return $data_layer;
		}

		// Cache-safe data layer (issue #398): customer details and the cart are
		// visitor-specific and omitted; the content-driven events below are
		// URL-scoped or fire on cache-excluded pages, so they stay server-side.
		$cache_safe = (bool) $this->options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );

		if ( ! $cache_safe ) {
			$data_layer = $this->add_customer_data( $data_layer );
			$data_layer = $this->add_cart_content( $data_layer );
		}

		$is_success_page = function_exists( 'edd_is_success_page' ) && edd_is_success_page();

		if ( is_singular( 'download' ) ) {
			$data_layer = $this->add_download_view( $data_layer );
		} elseif ( $is_success_page ) {
			$data_layer = $this->add_success_page_data( $data_layer );
		} elseif ( function_exists( 'edd_is_checkout' ) && edd_is_checkout() ) {
			$this->add_begin_checkout();
		} elseif ( $this->is_cart_page() ) {
			$this->add_cart_view();
		}

		// Reliable purchase tracking: a purchase whose confirmation page was
		// never reached is delivered on the buyer's next visit from their own
		// purchase session. Skipped under cache-safe mode and while order-tracked
		// flags are disabled (the event would repeat on every page view).
		if (
			! $is_success_page
			&& ! $cache_safe
			&& $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE )
			&& ! $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG )
		) {
			$data_layer = $this->add_missed_purchase( $data_layer );
		}

		$this->datalayer->flush_pushes();

		return apply_filters( GTM4WP_WPFILTER_EDD_DATALAYER_PAGELOAD, $data_layer );
	}

	/**
	 * Whether the current request renders a page carrying the [download_cart]
	 * shortcode or a full EDD cart block - EDD has no dedicated is_cart
	 * conditional, so the page content is the cart page signal. Content-driven,
	 * never visitor-driven, so it is safe under full-page caching.
	 *
	 * @return bool
	 */
	private function is_cart_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! ( $post instanceof \WP_Post ) ) {
			return false;
		}

		$content = (string) $post->post_content;

		return has_shortcode( $content, 'download_cart' ) || $this->has_full_cart_block( $content );
	}

	/**
	 * Whether the content contains an EDD cart block rendering the full cart.
	 * The block's mini attribute defaults to true (an icon/total link with no
	 * cart rows), so only an explicit mini=false marks a cart page.
	 *
	 * @param string $content The post content.
	 * @return bool
	 */
	private function has_full_cart_block( string $content ): bool {
		if ( ! has_block( 'edd/cart', $content ) ) {
			return false;
		}

		return $this->blocks_contain_full_cart( parse_blocks( $content ) );
	}

	/**
	 * Recursively searches parsed block structures for an edd/cart block with
	 * the mini attribute explicitly disabled.
	 *
	 * @param array<int, mixed> $blocks Parsed block structures.
	 * @return bool
	 */
	private function blocks_contain_full_cart( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( 'edd/cart' === ( $block['blockName'] ?? '' ) && false === ( $block['attrs']['mini'] ?? true ) ) {
				return true;
			}

			if ( is_array( $block['innerBlocks'] ?? null ) && $this->blocks_contain_full_cart( $block['innerBlocks'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds the logged-in customer's EDD data to the data layer when the
	 * customer-data feature is enabled. Present on every page view. Only
	 * fields the EDD customer record actually carries are exposed: name,
	 * email (+hash) and the lifetime purchase count/value.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function add_customer_data( array $data_layer ): array {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA ) ) {
			return $data_layer;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! function_exists( 'edd_get_customer_by' ) ) {
			return $data_layer;
		}

		$customer = edd_get_customer_by( 'user_id', $user_id );
		if ( ! is_object( $customer ) ) {
			return $data_layer;
		}

		$name       = (string) DownloadData::row_prop( $customer, 'name' );
		$name_parts = explode( ' ', $name, 2 );
		$email      = (string) DownloadData::row_prop( $customer, 'email' );

		$data_layer['customerTotalOrders']     = (int) DownloadData::row_prop( $customer, 'purchase_count', 0 );
		$data_layer['customerTotalOrderValue'] = (float) DownloadData::row_prop( $customer, 'purchase_value', 0 );
		$data_layer['customerFirstName']       = $name_parts[0];
		$data_layer['customerLastName']        = $name_parts[1] ?? '';
		$data_layer['customerEmail']           = $email;
		$data_layer['customerEmailHash']       = EcommerceHelpers::normalize_and_hash_email_address( 'sha256', $email );

		return $data_layer;
	}

	/**
	 * Builds the GA4 items of the current EDD cart together with the summed
	 * value, applying the cart item exclusion filter on every line.
	 *
	 * @param string $context The item placement context (cart or checkout).
	 * @return array{items: array, value: float}
	 */
	private function build_cart_items( string $context ): array {
		$items = array();
		$value = 0.0;

		if ( ! function_exists( 'edd_get_cart_content_details' ) ) {
			return array(
				'items' => $items,
				'value' => $value,
			);
		}

		$cart_details = edd_get_cart_content_details();
		if ( ! is_array( $cart_details ) ) {
			return array(
				'items' => $items,
				'value' => $value,
			);
		}

		foreach ( $cart_details as $cart_item_details ) {
			if ( ! is_array( $cart_item_details ) ) {
				continue;
			}

			/**
			 * This filter allows 3rd party code to exclude specific downloads from reporting.
			 *
			 * @param bool  true               Constant value telling 3rd party code that the cart item will be included in reporting if not changed by the filter.
			 * @param array $cart_item_details Associative array as returned by edd_get_cart_content_details().
			 *
			 * return bool If the filter returns false, the cart item will be omitted from processing.
			 */
			if ( ! apply_filters( GTM4WP_WPFILTER_EEC_EDD_CART_ITEM, true, $cart_item_details ) ) {
				continue;
			}

			$eec_product_array = $this->download_data->process_download(
				(int) ( $cart_item_details['id'] ?? 0 ),
				$this->download_data->cart_line_attributes( $cart_item_details ),
				$context,
				$cart_item_details,
				DownloadData::cart_item_price_id( $cart_item_details )
			);

			if ( ! $eec_product_array ) {
				continue;
			}

			unset( $eec_product_array['internal_id'] );

			$items[] = $eec_product_array;
			$value  += $eec_product_array['price'] * $eec_product_array['quantity'];
		}

		return array(
			'items' => $items,
			'value' => $value,
		);
	}

	/**
	 * Adds the current cart content (totals + items) to the data layer when
	 * the cart-content feature is enabled. Present on every page view.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function add_cart_content( array $data_layer ): array {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL ) ) {
			return $data_layer;
		}

		$cart = $this->build_cart_items( 'cart' );

		$data_layer['cartContent'] = array(
			'totals' => array(
				'subtotal' => function_exists( 'edd_get_cart_subtotal' ) ? (float) edd_get_cart_subtotal() : 0.0,
				'total'    => function_exists( 'edd_get_cart_total' ) ? (float) edd_get_cart_total() : 0.0,
			),
			'items'  => $cart['items'],
		);

		return $data_layer;
	}

	/**
	 * Builds the download-detail (view_item) data layer content and fires the
	 * view_item event. A variable-priced download is reported with its lowest
	 * price option and no item_variant, since no option is selected yet; the
	 * frontend tracker re-fires view_item with the picked option once the
	 * buyer selects one, scoped to this page by the flag printed here.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function add_download_view( array $data_layer ): array {
		$download = edd_get_download( (int) get_the_ID() );
		if ( ! ( $download instanceof \EDD_Download ) ) {
			return $data_layer;
		}

		// GA4 expects a quantity on the view_item item; it defaults to 1 for a
		// single download view. Making it explicit keeps the payload spec-complete.
		$eec_product_array = $this->download_data->process_download(
			$download,
			array( 'quantity' => 1 ),
			'productdetail'
		);

		if ( ! $eec_product_array ) {
			return $data_layer;
		}

		$data_layer['productType']              = (string) $download->get_type();
		$data_layer['productHasVariablePrices'] = $download->has_variable_prices() ? 1 : 0;

		// GA4 list attribution (#405): the download page is cacheable, so the
		// push is wrapped in a JS call that merges the list from the first-party
		// cookie, keyed by the download id internal_id carries.
		$list_wrapper      = '';
		$list_wrapper_args = array();
		if ( true === $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION ) ) {
			$list_wrapper      = EcommerceHelpers::LIST_ATTRIBUTION_JS_WRAPPER;
			$list_wrapper_args = array( (int) ( $eec_product_array['internal_id'] ?? $download->get_ID() ) );
		}

		unset( $eec_product_array['internal_id'] );

		$this->datalayer->queue_push(
			'view_item',
			array(
				'ecommerce' => array(
					'currency' => edd_get_currency(),
					'value'    => $eec_product_array['price'],
					'items'    => array( $eec_product_array ),
				),
			),
			'',
			'',
			$list_wrapper,
			$list_wrapper_args
		);

		// The tracker re-fires view_item when the buyer picks a price option;
		// this flag scopes its change listener to the download's own detail
		// page, so option clicks inside download grids stay list events.
		if ( $download->has_variable_prices() ) {
			wp_add_inline_script(
				'gtm4wp-edd',
				'window.gtm4wp_edd_variable_view_item = true;',
				'before'
			);
		}

		return $data_layer;
	}

	/**
	 * Fires the GA4 view_cart event for the current cart. No event is fired
	 * for an empty cart.
	 *
	 * @return void
	 */
	private function add_cart_view(): void {
		$cart = $this->build_cart_items( 'cart' );

		if ( count( $cart['items'] ) > 0 ) {
			$this->datalayer->queue_push(
				'view_cart',
				array(
					'ecommerce' => array(
						'currency' => edd_get_currency(),
						'value'    => $cart['value'],
						'items'    => $cart['items'],
					),
				)
			);
		}
	}

	/**
	 * Fires the GA4 begin_checkout event for the current cart and exposes the
	 * cart products to the checkout tracker (add_payment_info) as an inline
	 * script. No event is fired for an empty cart.
	 *
	 * @return void
	 */
	private function add_begin_checkout(): void {
		$cart = $this->build_cart_items( 'checkout' );

		if ( count( $cart['items'] ) > 0 ) {
			$this->datalayer->queue_push(
				'begin_checkout',
				array(
					'ecommerce' => array(
						'currency' => edd_get_currency(),
						'value'    => $cart['value'],
						'items'    => $cart['items'],
					),
				)
			);
		}

		wp_add_inline_script(
			'gtm4wp-edd',
			'
			window.gtm4wp_checkout_products = ' . ScriptTag::json_literal( $cart['items'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';
			window.gtm4wp_checkout_value    = ' . (float) $cart['value'] . ';',
			'before'
		);
	}

	/**
	 * Builds the purchase confirmation (success) page data layer. The order is
	 * resolved through EDD's own receipt chain (resolve_payment_key()), which
	 * decides WHICH order; edd_can_view_receipt() decides whether THIS visitor
	 * may see the buyer's identity. The purchase event fires on key possession;
	 * the identity blocks are withheld whenever EDD itself would not render the
	 * receipt (a leaked or shared success URL).
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function add_success_page_data( array $data_layer ): array {
		$payment_key = self::resolve_payment_key();
		if ( '' === $payment_key ) {
			return $data_layer;
		}

		$order = edd_get_order_by( 'payment_key', $payment_key );
		if ( ! ( $order instanceof \EDD\Orders\Order ) ) {
			return $data_layer;
		}

		// Identity blocks withheld unless EDD itself would show this visitor the
		// receipt (edd_can_view_receipt()); where the gate cannot be read the
		// direction is withhold. Mirrors the WooCommerce module (upstream parity).
		$withhold_customer_data = ! function_exists( 'edd_can_view_receipt' )
			|| ! edd_can_view_receipt( $payment_key );

		return $this->add_purchase_for_order( $data_layer, $order, true, $withhold_customer_data );
	}

	/**
	 * Resolves the payment key of the order being confirmed, mirroring EDD's
	 * own receipt chain: the payment_key query arg, then the order id only when
	 * the ?order= verification hash matches (a bare id would be an IDOR), then
	 * the buyer's own purchase session. WHICH order only; the identity gate is
	 * the caller's. Static and shared with the Data Manager receipt detection
	 * so the hash check exists once.
	 *
	 * @return string The payment key, or an empty string when none is present or the hash does not match.
	 */
	public static function resolve_payment_key(): string {
		// Suppressing 'Processing form data without nonce verification.' - these are
		// the query args EDD itself places on the success page redirect; the payment
		// key is the authorization.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['payment_key'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['payment_key'] ) );
		}

		if ( ! empty( $_GET['order'] ) && ! empty( $_GET['id'] ) && function_exists( 'edd_get_order' ) ) {
			// The key is released only when the ?order= verification hash matches,
			// as EDD's own resolver does (EDD\Blocks\Orders\get_payment_key());
			// a bare edd_get_payment_key( $id ) would map sequential ids to keys.
			$order = edd_get_order( absint( wp_unslash( $_GET['id'] ) ) );
			if ( ! ( $order instanceof \EDD\Orders\Order ) ) {
				return '';
			}

			$order_hash = sanitize_text_field( wp_unslash( $_GET['order'] ) );

			return self::receipt_hash_matches( $order, $order_hash )
				? (string) DownloadData::row_prop( $order, 'payment_key' )
				: '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( function_exists( 'edd_get_purchase_session' ) ) {
			$session = edd_get_purchase_session();
			if ( is_array( $session ) && ! empty( $session['purchase_key'] ) ) {
				return (string) $session['purchase_key'];
			}
		}

		return '';
	}

	/**
	 * Whether a receipt-link verification hash (`?order=`) belongs to an
	 * order. One definition for the confirmation page and the Data Manager
	 * backfill route.
	 *
	 * The hash is EDD's, and its formula changed once (registry row U159):
	 * EDD 3.7.1 signs `id . payment_key . email` with a per-site secret and
	 * verifies it through Order::is_receipt_hash_valid(); EDD 3.0 - 3.7.0
	 * compared md5() of the same three fields inline in its block resolver
	 * (EDD\Blocks\Orders\get_payment_key()). The signed form cannot be
	 * recomputed here, so an order that verifies its own hash is asked,
	 * and the digest is only computed for the releases that have no verifier.
	 *
	 * @param \EDD\Orders\Order $order The order the hash is claimed for.
	 * @param string            $hash  The hash from the URL or the request.
	 * @return bool
	 */
	public static function receipt_hash_matches( \EDD\Orders\Order $order, string $hash ): bool {
		if ( '' === $hash ) {
			return false;
		}

		if ( method_exists( $order, 'is_receipt_hash_valid' ) ) {
			return (bool) $order->is_receipt_hash_valid( $hash );
		}

		$expected = md5(
			DownloadData::row_prop( $order, 'id' )
			. DownloadData::row_prop( $order, 'payment_key' )
			. DownloadData::row_prop( $order, 'email' )
		);

		// hash_equals(): the comparison is against a secret's digest, so it
		// must not leak through timing.
		return hash_equals( $expected, $hash );
	}

	/**
	 * Reliable purchase tracking fallback: the buyer's most recent order from
	 * their own EDD purchase session (no request parameter is trusted), through
	 * the same gauntlet as the confirmation page; the raw order data block is
	 * left to the confirmation page.
	 *
	 * @param array<string, mixed> $data_layer The data layer collected so far.
	 * @return array<string, mixed>
	 */
	private function add_missed_purchase( array $data_layer ): array {
		if ( ! function_exists( 'edd_get_purchase_session' ) || ! function_exists( 'edd_get_order_by' ) ) {
			return $data_layer;
		}

		$session = edd_get_purchase_session();
		if ( ! is_array( $session ) || empty( $session['purchase_key'] ) ) {
			return $data_layer;
		}

		$order = edd_get_order_by( 'payment_key', (string) $session['purchase_key'] );
		if ( ! ( $order instanceof \EDD\Orders\Order ) ) {
			return $data_layer;
		}

		return $this->add_purchase_for_order( $data_layer, $order, false );
	}

	/**
	 * Runs the purchase eligibility gauntlet on a resolved order and, when it
	 * passes, adds the raw order data, queues the GA4 purchase event wrapped
	 * in the browser-side duplicate guard and flags the order as tracked.
	 *
	 * @param array<string, mixed> $data_layer             The data layer collected so far.
	 * @param \EDD\Orders\Order    $order                  The resolved order.
	 * @param bool                 $with_raw_order_data    Whether the raw orderData block may be added (confirmation page only).
	 * @param bool                 $withhold_customer_data Whether to leave out the customer identity blocks (EDD would not show this visitor the receipt).
	 * @return array<string, mixed>
	 */
	private function add_purchase_for_order( array $data_layer, \EDD\Orders\Order $order, bool $with_raw_order_data = true, bool $withhold_customer_data = false ): array {
		if ( $this->download_data->is_order_older_than_max_age( $order ) ) {
			return $data_layer;
		}

		$order_items = null;

		// Raw order data will be output regardless of whether the purchase has been
		// already tracked previously, since this data is not meant to track using GA.
		if ( $with_raw_order_data && $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDORDERDATA ) ) {
			$order_items             = $this->download_data->process_order_items( $order );
			$data_layer['orderData'] = $this->download_data->get_raw_order_datalayer( $order, $order_items );

			// Identity line, as in the WooCommerce module: dropped after the filter
			// so third-party code sees the usual shape; only 'customer' is withheld.
			if ( $withhold_customer_data ) {
				unset( $data_layer['orderData']['customer'] );
			}
		}

		if ( $this->download_data->is_purchase_already_tracked( $order ) ) {
			return $data_layer;
		}

		if ( ! $this->download_data->is_order_status_trackable( $order ) ) {
			return $data_layer;
		}

		// new_customer / customer_type describe the BUYER: withheld with the
		// identity block, omitted rather than emitted falsy (RI-13).
		if ( ! $withhold_customer_data ) {
			$data_layer = array_merge( $data_layer, $this->download_data->customer_signals( $order ) );
		}

		$purchase_data_layer = $this->download_data->get_purchase_datalayer( $order, $order_items );

		// user_data is the purchase event's own copy of the customer identity.
		if ( $withhold_customer_data ) {
			unset( $purchase_data_layer['user_data'] );
		}

		// "Do not flag orders as being tracked" skips the browser guard as well.
		if ( (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG ) ) {
			$before_purchase_dl_push = '';
			$after_purchase_dl_push  = '';
		} else {
			list( $before_purchase_dl_push, $after_purchase_dl_push ) = EcommerceHelpers::purchase_dedupe_guard( (string) $order->get_number() );
		}

		$this->datalayer->queue_push(
			$purchase_data_layer['event'],
			$purchase_data_layer,
			$before_purchase_dl_push,
			$after_purchase_dl_push
		);

		$this->download_data->flag_order_tracked( $order );

		return $data_layer;
	}
}
