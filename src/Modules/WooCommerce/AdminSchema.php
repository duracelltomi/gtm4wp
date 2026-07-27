<?php
/**
 * WooCommerce module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the WooCommerce module, ported from the 1.x
 * Integration tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'WooCommerce', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return sprintf(
			/* translators: 1: anchor element linking to GA4 Ecommerce docs. 2: closing anchor element. 3: anchor element linking to GTM4WP setup guide for Google Analytics 4. 4: closing anchor element. */
			esc_html__(
				'Track e-commerce data using %1$sGA4 ecommerce tracking%2$s. Use the plugin\'s %3$sofficial guides%4$s to setup your Google Tag Manager container. WooCommerce 5.0+ is required to use this integration.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm" target="_blank" rel="noopener">',
			'</a>',
			'<a href="https://gtm4wp.com/how-to-articles/how-to-setup-enhanced-ecommerce-tracking-google-analytics-4-ga4-version" target="_blank" rel="noopener">',
			'</a>'
		);
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'general'   => __( 'General', 'duracelltomi-google-tag-manager' ),
			'products'  => __( 'Product data', 'duracelltomi-google-tag-manager' ),
			'datalayer' => __( 'Data layer content', 'duracelltomi-google-tag-manager' ),
			'purchase'  => __( 'Purchase tracking', 'duracelltomi-google-tag-manager' ),
			'advanced'  => __( 'Advanced', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		$taxonomy_choices = array(
			'' => __( '(not used)', 'duracelltomi-google-tag-manager' ),
		);
		if ( function_exists( 'get_object_taxonomies' ) ) {
			foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy_slug => $taxonomy_object ) {
				// Only offer taxonomies a store owner would use as a brand: those
				// with a public archive and an admin UI, matching the 1.x filter
				// ( public + show_ui + non-builtin ). This drops WooCommerce's
				// internal product_type / product_visibility taxonomies and
				// cross-cutting ones other plugins attach to the product post
				// type (e.g. WPML's non-public "translation_priority").
				if ( ! $taxonomy_object->public || ! $taxonomy_object->show_ui || $taxonomy_object->_builtin ) {
					continue;
				}

				$taxonomy_choices[ $taxonomy_slug ] = $taxonomy_object->label;
			}
		}

		// Order-status choices for the "statuses that trigger the purchase event"
		// field. wc_get_order_statuses() keys are prefixed with "wc-" while
		// WC_Order::get_status() returns the bare slug, so strip the prefix to
		// keep the stored values comparable. Fall back to the core statuses when
		// WooCommerce is not loaded (so the field is always usable in the admin).
		$order_status_choices = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $status_key => $status_label ) {
				$status_slug                          = ( 'wc-' === substr( (string) $status_key, 0, 3 ) ) ? substr( (string) $status_key, 3 ) : (string) $status_key;
				$order_status_choices[ $status_slug ] = $status_label;
			}
		}
		if ( array() === $order_status_choices ) {
			$order_status_choices = array(
				'pending'    => __( 'Pending payment', 'duracelltomi-google-tag-manager' ),
				'processing' => __( 'Processing', 'duracelltomi-google-tag-manager' ),
				'on-hold'    => __( 'On hold', 'duracelltomi-google-tag-manager' ),
				'completed'  => __( 'Completed', 'duracelltomi-google-tag-manager' ),
				'cancelled'  => __( 'Cancelled', 'duracelltomi-google-tag-manager' ),
				'refunded'   => __( 'Refunded', 'duracelltomi-google-tag-manager' ),
				'failed'     => __( 'Failed', 'duracelltomi-google-tag-manager' ),
			);
		}

		// Page choices for the custom order-received page selector.
		$order_received_page_choices = array(
			'' => __( '(use the standard WooCommerce order received page)', 'duracelltomi-google-tag-manager' ),
		);
		if ( function_exists( 'get_pages' ) ) {
			foreach ( (array) get_pages() as $page ) {
				$order_received_page_choices[ (string) $page->ID ] = $page->post_title;
			}
		}

		// Business vertical labels are Google product terms and are intentionally not translated (1.x parity).
		$business_verticals = array(
			'retail'       => 'Retail',
			'education'    => 'Education',
			'flights'      => 'Flights',
			'hotel_rental' => 'Hotel rental',
			'jobs'         => 'Jobs',
			'local'        => 'Local deals',
			'real_estate'  => 'Real estate',
			'travel'       => 'Travel',
			'custom'       => 'Custom',
		);

		return array(
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Track e-commerce', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Choose this option if you would like to track e-commerce data with GA4 ecommerce tracking.', 'duracelltomi-google-tag-manager' ),
				group: 'general'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION,
				type: Field::TYPE_INTEGER,
				default_value: 10,
				label: __( 'Products per impression', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'If you have many products shown on product category pages and/or on your site home, you could miss pageviews in Google Analytics due to the amount of data that is needed to be sent. To prevent this, you can split product impression data into multiple Google Analytics events by entering a number here (minimum 10-15 recommended). Leave this value 0 to include product impression data in your pageview hit.', 'duracelltomi-google-tag-manager' ),
				group: 'products'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCUSESKU,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Use SKU instead of ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this to use product SKU instead of the ID of the products for remarketing and ecommerce tracking. Will fallback to ID if no SKU is set.', 'duracelltomi-google-tag-manager' ),
				group: 'products'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY,
				type: Field::TYPE_SELECT,
				default_value: '',
				label: __( 'Taxonomy to be used for product brands', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select which custom taxonomy is being used to add the brand of products', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				choices: $taxonomy_choices,
				// Replaces the SELECT default on purpose: the choice list is built
				// from the taxonomies registered on THIS request, so the default's
				// allow-list reset would silently blank a stored brand taxonomy
				// whenever its plugin is momentarily inactive during a save/import.
				// Field::to_string() keeps the cast warning-free on non-scalar
				// import values (Field::sanitize() runs a custom sanitizer INSTEAD
				// of the type-defensive default, never before it).
				sanitizer: static function ( $value ) {
					return sanitize_text_field( Field::to_string( $value ) );
				}
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Include full category path.', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this to include the full category path of each product in ecommerce tracking. WARNING! This can lead to performance issues on large sites with lots of traffic!', 'duracelltomi-google-tag-manager' ),
				group: 'products'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL,
				type: Field::TYPE_SELECT,
				default_value: 'retail',
				label: __( 'Google Ads Business Vertical', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: anchor element linking to GTM4WP setup guide for Google Ads dynamic remarketing. 2: closing anchor element. */
					esc_html__(
						'Select which vertical category to add next to each product to utilize dynamic remarketing for Google Ads. Use the plugin\'s %1$sofficial setup guide for dynamic remarketing%2$s to setup your Google Tag Manager container.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://gtm4wp.com/how-to-articles/how-to-setup-dynamic-remarketing-in-google-ads-adwords" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'products',
				choices: $business_verticals
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Product ID prefix', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( "Some product feed generator plugins prefix product IDs with a fixed text like 'woocommerce_gpf'. You can enter this prefix here so that tags in your website include this prefix as well.", 'duracelltomi-google-tag-manager' ),
				group: 'products'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Fire view_item on parent product', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'If this option is turned on, the view_item event will be also fired during page load of a variable product using parent product data.', 'duracelltomi-google-tag-manager' ),
				group: 'products'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Persist product list attribution across the funnel', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'When a visitor clicks a product in a list, remember which list it was (item_list_name / item_list_id) in a first-party cookie and carry it onto the later add_to_cart, begin_checkout, add_shipping_info, add_payment_info and purchase events, so GA4 can attribute the whole funnel to the originating list. Only enable this if you are NOT already doing the same with custom JavaScript in Google Tag Manager, otherwise the attribution would be set twice. Note: a simple product\'s view_item may still miss the list name when the product page is served from a full-page cache.', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Cart content in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this option to include the content of the cart in the data layer on each page. Especially useful for site personalization tools.', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Customer data in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to add all customer data (billing and shipping data, total number of orders and order value) into the data layer', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCORDERDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Order data in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to add all order attributes into the data layer on the order received page regardless and independently from ecommerce tracking', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE,
				type: Field::TYPE_INTEGER,
				default_value: 30,
				label: __( 'Only track orders younger than', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'To prevent duplicate transaction tracking at the order received page, enter the maximum age (in minutes) of the order or its payment for the transaction to be measured. Viewing the order received page of older orders will be ignored from transaction tracking, as it is considered to be measured in an earlier session.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Exclude tax from revenue', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to exclude tax from the revenue variable while generating the purchase data', 'duracelltomi-google-tag-manager' ),
				group: 'purchase'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Exclude shipping from revenue', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to exclude shipping costs from the revenue variable while generating the purchase data', 'duracelltomi-google-tag-manager' ),
				group: 'purchase'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Transaction ID prefix', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Text to prepend to the transaction_id sent with the purchase event, for example to tell several stores apart in one GA4 property or to match the order id format of another system. Leave this empty to send the WooCommerce order number unchanged. Only the purchase event is affected: the order number in the orderData variable and the duplicate tracking guards of the plugin keep using the raw order number.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Do not flag orders as being tracked', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Turn this on to prevent the plugin from flagging orders as being already tracked. Leaving this unchecked ensures that no order data will be tracked multiple times in any ad or measurement system. Please only turn this feature on if you really need it!', 'duracelltomi-google-tag-manager' ),
				group: 'purchase'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES,
				type: Field::TYPE_MULTISELECT,
				default_value: array( 'processing', 'on-hold', 'completed' ),
				label: __( 'Order statuses that trigger the purchase event', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'The purchase event is sent when an order first reaches one of these statuses, i.e. at order placement - not when payment physically clears. This is why Cash on Delivery (Processing) and bank transfer (On hold) orders are tracked at checkout even though the money arrives later. Leave Failed, Cancelled and Pending payment unchecked so unpaid orders are not counted as sales.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_BETA,
				choices: $order_status_choices
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Reliable purchase tracking', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Turn this on if some purchases are missing. When the customer lands on a heavily customized thank-you page, or on the order-pay page instead of the order received page, the purchase event is emitted on the next page they view in the same browser session instead. The order tracked flag and browser cookie still prevent double counting. Note: this cannot capture orders where the buyer pays via an asynchronous gateway and never returns to the site - that case needs server side tracking.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE,
				type: Field::TYPE_SELECT,
				default_value: '',
				label: __( 'Custom order received (thank-you) page', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'If your theme or another plugin shows the order confirmation on a custom page instead of the standard WooCommerce order received endpoint, select it here so the purchase event fires on it. The order is resolved from the current browser session.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_BETA,
				choices: $order_received_page_choices
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Clear ecommerce object before new event', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: anchor element linking to the official GA4 doc about clearing the ecommerce object. 2: closing anchor element. */
					esc_html__(
						'Clear the ecommerce object before any new event being pushed into the data layer. Although it is %1$srecommended by Google%2$s, it is not mandatory to activate this feature as the GA4 event tag reads only the last pushed ecommerce data on any new event. Use it if you encounter issues with your GTM implementation.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm#clear_the_ecommerce_object" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'advanced'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT,
				type: Field::TYPE_INTEGER,
				default_value: 2000,
				label: __( 'Set maximum timeout for select_item event', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'When a user clicks on a product in a list, the select_item event uses a callback function with Google Tag Manager (GTM). This ensures that GTM can fire all related tags before the browser navigates to the product detail page. You can customize a timeout period (in milliseconds). This timeout only comes into play if a tag fails to fire within the specified time for some reason. Set this to 0 to open the product immediately without waiting for GTM: the select_item event is still pushed to the data layer, but the click is no longer held back. Use 0 (or a lower value than 2000) if product links feel slow to open - for example when a consent tool blocks GTM so the callback never returns.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced'
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_WC_CHECKOUTWC,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'CheckoutWC compatibility', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: opening anchor tag linking to the CheckoutWC website. 2: closing anchor tag. */
					esc_html__(
						'Enable this if your store uses %1$sCheckoutWC%2$s, which replaces the standard WooCommerce checkout with its own multi-step template. The classic checkout markers the tracker relies on are not reliably present there, so the add_shipping_info and add_payment_info events are missed. With this on, the tracker also binds those events to CheckoutWC\'s own cfw_step_changed event.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://www.checkoutwc.com/" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'advanced',
				phase: Field::PHASE_EXPERIMENTAL
			),
		);
	}

	/**
	 * Explanation shown when WooCommerce is not active or too old.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return __( 'WooCommerce 5.0 or newer needs to be installed and activated to use this module.', 'duracelltomi-google-tag-manager' );
	}
}
