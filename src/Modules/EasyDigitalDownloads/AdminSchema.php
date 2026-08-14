<?php
/**
 * Easy Digital Downloads module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\EasyDigitalDownloads;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the Easy Digital Downloads module, mirroring the
 * WooCommerce integration tab for EDD's digital-goods flow. Every field
 * starts at beta (or experimental) per the option maturity policy: a brand
 * new integration has no field usage yet, so nothing may claim stable.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * Documentation hub of this module on gtm4wp.com. The page does not exist
	 * yet - it ships with the 2.1 release, and tests/network/DocLinksTest.php
	 * verifies it resolves before that release goes out.
	 */
	private const DOC_PAGE = 'google-tag-manager-for-easy-digital-downloads';

	/**
	 * The per-option reference every field of this module deep links into,
	 * following the WooCommerce module's convention (an `<a name="…">` anchor
	 * per option key).
	 */
	private const DOC_REFERENCE = self::DOC_PAGE . '/easy-digital-downloads-settings-reference';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_PAGE;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Easy Digital Downloads', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return sprintf(
			/* translators: 1: anchor element linking to GA4 Ecommerce docs. 2: closing anchor element. */
			esc_html__(
				'Track Easy Digital Downloads e-commerce data using %1$sGA4 ecommerce tracking%2$s. Easy Digital Downloads 3.0+ is required to use this integration.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm" target="_blank" rel="noopener">',
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
			foreach ( get_object_taxonomies( 'download', 'objects' ) as $taxonomy_slug => $taxonomy_object ) {
				// Only offer taxonomies a store owner would use as a brand: those
				// with a public archive and an admin UI, matching the WooCommerce
				// module's filter ( public + show_ui + non-builtin ).
				if ( ! $taxonomy_object->public || ! $taxonomy_object->show_ui || $taxonomy_object->_builtin ) {
					continue;
				}

				$taxonomy_choices[ $taxonomy_slug ] = $taxonomy_object->label;
			}
		}

		// Order-status choices for the "statuses that trigger the purchase event"
		// field, from EDD's own status registry. Fall back to the core EDD 3.x
		// statuses when EDD is not loaded so the field stays usable in the admin.
		$order_status_choices = array();
		if ( function_exists( 'edd_get_payment_statuses' ) ) {
			foreach ( edd_get_payment_statuses() as $status_key => $status_label ) {
				$order_status_choices[ (string) $status_key ] = (string) $status_label;
			}
		}
		if ( array() === $order_status_choices ) {
			$order_status_choices = array(
				'pending'            => __( 'Pending', 'duracelltomi-google-tag-manager' ),
				'processing'         => __( 'Processing', 'duracelltomi-google-tag-manager' ),
				'complete'           => __( 'Completed', 'duracelltomi-google-tag-manager' ),
				'refunded'           => __( 'Refunded', 'duracelltomi-google-tag-manager' ),
				'partially_refunded' => __( 'Partially refunded', 'duracelltomi-google-tag-manager' ),
				'revoked'            => __( 'Revoked', 'duracelltomi-google-tag-manager' ),
				'failed'             => __( 'Failed', 'duracelltomi-google-tag-manager' ),
				'abandoned'          => __( 'Abandoned', 'duracelltomi-google-tag-manager' ),
			);
		}

		// Business vertical labels are Google product terms and are intentionally not translated (WooCommerce module parity).
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
				key: GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Track e-commerce', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Choose this option if you would like to track Easy Digital Downloads e-commerce data with GA4 ecommerce tracking.', 'duracelltomi-google-tag-manager' ),
				group: 'general',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDPRODPERIMPRESSION,
				type: Field::TYPE_INTEGER,
				default_value: 10,
				label: __( 'Products per impression', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'If you have many downloads shown in your download grids, you could miss pageviews in Google Analytics due to the amount of data that is needed to be sent. To prevent this, you can split product impression data into multiple Google Analytics events by entering a number here (minimum 10-15 recommended). Leave this value 0 to include product impression data in one single event.', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDUSESKU,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Use SKU instead of ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this to use the download SKU instead of the ID of the downloads for remarketing and ecommerce tracking. Will fallback to ID if no SKU is set. Note: SKUs need to be enabled in the Easy Digital Downloads settings.', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDBRANDTAXONOMY,
				type: Field::TYPE_SELECT,
				default_value: '',
				label: __( 'Taxonomy to be used for product brands', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select which custom taxonomy is being used to add the brand of downloads. Easy Digital Downloads has no brand taxonomy of its own, so this only lists taxonomies added by other plugins or custom code.', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_BETA,
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
				},
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDUSEFULLCATEGORYPATH,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Include full category path.', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this to include the full download category path of each download in ecommerce tracking. WARNING! This can lead to performance issues on large sites with lots of traffic!', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDBUSINESSVERTICAL,
				type: Field::TYPE_SELECT,
				default_value: 'retail',
				label: __( 'Google Ads Business Vertical', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: anchor element linking to GTM4WP setup guide for Google Ads dynamic remarketing. 2: closing anchor element. */
					esc_html__(
						'Select which vertical category to add next to each download to utilize dynamic remarketing for Google Ads. Use the plugin\'s %1$sofficial setup guide for dynamic remarketing%2$s to setup your Google Tag Manager container.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://gtm4wp.com/how-to-articles/how-to-setup-dynamic-remarketing-in-google-ads-adwords" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'products',
				phase: Field::PHASE_BETA,
				choices: $business_verticals,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDPRODIDPREFIX,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Product ID prefix', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Some product feed generator plugins prefix product IDs with a fixed text. You can enter this prefix here so that tags in your website include this prefix as well.', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Cart content in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this option to include the content of the Easy Digital Downloads cart in the data layer on each page. Especially useful for site personalization tools. Not included on cacheable pages when the cache-safe data layer mode is on.', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Customer data in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to add data of the logged in customer (name, email and hashed email, total number of orders and order value) into the data layer, and the Enhanced Conversions user_data block onto the purchase event. Not included on cacheable pages when the cache-safe data layer mode is on.', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDORDERDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Order data in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to add all order attributes into the data layer on the purchase confirmation page regardless and independently from ecommerce tracking. The payment key of the order is never included, as it authorizes viewing the receipt.', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDORDERMAXAGE,
				type: Field::TYPE_INTEGER,
				default_value: 30,
				label: __( 'Only track orders younger than', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'To prevent duplicate transaction tracking on the purchase confirmation page, enter the maximum age (in minutes) of the order for the transaction to be measured. Viewing the confirmation page of older orders will be ignored from transaction tracking, as it is considered to be measured in an earlier session.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Exclude tax from revenue', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to exclude tax from the revenue variable while generating the purchase data', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDTRANSACTIONIDPREFIX,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Transaction ID prefix', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Text to prepend to the transaction_id sent with the purchase event, for example to tell several stores apart in one GA4 property. Leave this empty to send the Easy Digital Downloads order number unchanged. Only the purchase event is affected: the order number in the orderData variable and the duplicate tracking guards of the plugin keep using the raw order number.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Do not flag orders as being tracked', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Turn this on to prevent the plugin from flagging orders as being already tracked. Leaving this unchecked ensures that no order data will be tracked multiple times in any ad or measurement system. Please only turn this feature on if you really need it!', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES,
				type: Field::TYPE_MULTISELECT,
				default_value: array( 'pending', 'processing', 'complete' ),
				label: __( 'Order statuses that trigger the purchase event', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'The purchase event is sent when the buyer reaches the confirmation page with an order in one of these statuses. Offsite gateways like PayPal can land the buyer there while the order is still Pending, which is why Pending is included by default - the duplicate tracking guards ensure the order is not counted again once it completes. Remove Pending and Processing to only ever count completed orders.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_BETA,
				choices: $order_status_choices,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Reliable purchase tracking', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enable this to measure the purchase on the next page the buyer visits when the confirmation page was never reached - for example an abandoned offsite payment redirect. The order is resolved from the buyer\'s own purchase session, every duplicate-prevention check still applies, and the raw order data block stays exclusive to the confirmation page. Has no effect while "Do not flag orders as being tracked" is enabled or, on cacheable pages, while the cache-safe data layer mode is on.', 'duracelltomi-google-tag-manager' ),
				group: 'purchase',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDCLEARECOMMERCEDL,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Clear ecommerce object before new event', 'duracelltomi-google-tag-manager' ),
				description: sprintf(
					/* translators: 1: anchor element linking to the official GA4 doc about clearing the ecommerce object. 2: closing anchor element. */
					esc_html__(
						'Clear the ecommerce object before any new event being pushed into the data layer. Although it is %1$srecommended by Google%2$s, it is not mandatory to activate this feature as the GA4 event tag reads only the last pushed ecommerce data on any new event. Use it if you encounter issues with your GTM implementation. If the WooCommerce integration is also active, its setting for this behavior is overridden by this one.',
						'duracelltomi-google-tag-manager'
					),
					'<a href="https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm#clear_the_ecommerce_object" target="_blank" rel="noopener">',
					'</a>'
				),
				group: 'advanced',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDDLMAXTIMEOUT,
				type: Field::TYPE_INTEGER,
				default_value: 2000,
				label: __( 'Set maximum timeout for select_item event', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'When a user clicks on a download in a download grid, the select_item event uses a callback function with Google Tag Manager (GTM). This ensures that GTM can fire all related tags before the browser navigates to the download detail page. You can customize a timeout period (in milliseconds). Set this to 0 to open the download immediately without waiting for GTM: the select_item event is still pushed to the data layer, but the click is no longer held back.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
		);
	}

	/**
	 * Explanation shown when Easy Digital Downloads is not active or too old.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return __( 'Easy Digital Downloads 3.0 or newer needs to be installed and activated to use this module.', 'duracelltomi-google-tag-manager' );
	}
}
