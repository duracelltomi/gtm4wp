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

use GTM4WP\Admin\SiteHealthRows;
use GTM4WP\Frontend\DefaultLanguage;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Module\StatusInfoInterface;
use GTM4WP\Options\Field;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the Easy Digital Downloads module, mirroring the
 * WooCommerce integration tab for EDD's digital-goods flow. Every field
 * starts at beta (or experimental) per the option maturity policy: a brand
 * new integration has no field usage yet, so nothing may claim stable.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface, StatusInfoInterface, SiteHealthInfoInterface {

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
	 * The EDD Google Ads guide (conversion tracking, dynamic remarketing,
	 * enhanced conversions), linked from a field description.
	 */
	private const DOC_GOOGLE_ADS = self::DOC_PAGE . '/google-ads-for-easy-digital-downloads';

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
				// Brand-usable taxonomies only (public + show_ui + non-builtin).
				if ( ! $taxonomy_object->public || ! $taxonomy_object->show_ui || $taxonomy_object->_builtin ) {
					continue;
				}

				$taxonomy_choices[ $taxonomy_slug ] = $taxonomy_object->label;
			}
		}

		// Order statuses from EDD's registry; core EDD 3.x statuses as the fallback.
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
				description: esc_html__( 'Splits the view_item_list data of a long download list into several events of this many items each, so the impressions do not exceed what one measurement request can hold. Enter 0 to send one event; 10 to 15 is a sensible floor.', 'duracelltomi-google-tag-manager' ),
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
				// Replaces the SELECT default on purpose: its allow-list reset would
				// blank a stored brand taxonomy whenever its plugin is momentarily
				// inactive during a save/import (see the WooCommerce schema).
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
				key: GTM4WP_OPTION_INTEGRATE_EDDMASTERLANGUAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Report downloads in the default language', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'On WPML or Polylang stores, reports GA4 ecommerce items in the store\'s default language, so a download sold in several languages is one item in your reports. Translated pages then send a different item_id: check feeds and remarketing that use it.', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_REFERENCE,
				unavailable: DefaultLanguage::unavailable_reason( false )
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Persist download list attribution across the funnel', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Remembers which list a download was clicked in (item_list_name, item_list_id) in a first-party cookie and adds it to the later ecommerce events up to the purchase. Leave it off if your container already does this with custom JavaScript.', 'duracelltomi-google-tag-manager' ),
				group: 'products',
				phase: Field::PHASE_EXPERIMENTAL,
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
					'<a href="https://gtm4wp.com/' . self::DOC_GOOGLE_ADS . '#dynamic-remarketing" target="_blank" rel="noopener">',
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
				description: esc_html__( 'Adds the Easy Digital Downloads cart content to the data layer on every page, for example for personalization tools. With the cache-safe data layer on, it arrives in the gtm4wp.cartData event instead.', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Customer data in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Adds the logged-in customer\'s name, email, hashed email and phone, order count and total value to the data layer, and the Enhanced Conversions user_data block to the purchase event. With the cache-safe data layer on, they arrive in gtm4wp.customerData.', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDORDERDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Order data in data layer', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Adds every order attribute to the data layer on the purchase confirmation page, even when the purchase event is not sent again. Needs "Track e-commerce"; the payment key of the order is never included.', 'duracelltomi-google-tag-manager' ),
				group: 'datalayer',
				phase: Field::PHASE_BETA,
				doc: self::DOC_REFERENCE
			),
			new Field(
				key: GTM4WP_OPTION_INTEGRATE_EDDORDERMAXAGE,
				type: Field::TYPE_INTEGER,
				default_value: 30,
				label: __( 'Only track orders younger than', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Orders older than this many minutes are not tracked when their confirmation page is viewed again, so a revisited receipt does not count the purchase twice.', 'duracelltomi-google-tag-manager' ),
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
				description: esc_html__( 'Text added before the transaction_id of the purchase event, for example to tell several stores apart in one GA4 property. Leave it empty to send the order number unchanged; orderData keeps the raw number.', 'duracelltomi-google-tag-manager' ),
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
				description: esc_html__( 'The purchase event is sent when the buyer reaches the confirmation page with an order in one of these statuses. Pending is included for offsite gateways like PayPal; remove Pending and Processing to count only completed orders.', 'duracelltomi-google-tag-manager' ),
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
				description: esc_html__( 'Measures the purchase on the buyer\'s next page view when the confirmation page was never reached, for example after an abandoned offsite payment redirect. Has no effect while "Do not flag orders as being tracked" is on.', 'duracelltomi-google-tag-manager' ),
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
						'Clears the ecommerce object before each new event is pushed, as %1$srecommended by Google%2$s, although GA4 tags do not need it. If the WooCommerce integration is active too, this setting overrides its own.',
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
				description: esc_html__( 'How long (in milliseconds) a click on a download in a grid waits for Google Tag Manager to fire the select_item tags before the download page opens. Enter 0 to open it at once; the event is still pushed.', 'duracelltomi-google-tag-manager' ),
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
	/**
	 * {@inheritDoc}
	 *
	 * The master switch is the e-commerce tracking option; the host is Easy Digital Downloads,
	 * reported from its version constant. Present says nothing about the
	 * version floor, which the module's is_available() enforces.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<string, mixed>
	 */
	public function status_info( Options $options ): array {
		return array(
			'enabled'     => (bool) $options->get( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE ),
			'integration' => array(
				'active'  => defined( 'EDD_VERSION' ),
				'version' => defined( 'EDD_VERSION' ) ? (string) constant( 'EDD_VERSION' ) : null,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The WooCommerce rows for the options EDD shares; text options set/empty.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<string, array<string, mixed>>
	 */
	public function site_health_info( Options $options ): array {
		$info     = $this->status_info( $options );
		$switches = array(
			GTM4WP_OPTION_INTEGRATE_EDDUSESKU,
			GTM4WP_OPTION_INTEGRATE_EDDUSEFULLCATEGORYPATH,
			GTM4WP_OPTION_INTEGRATE_EDDMASTERLANGUAGE,
			GTM4WP_OPTION_INTEGRATE_EDDLISTATTRIBUTION,
			GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL,
			GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA,
			GTM4WP_OPTION_INTEGRATE_EDDORDERDATA,
			GTM4WP_OPTION_INTEGRATE_EDDEXCLUDETAX,
			GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG,
			GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE,
			GTM4WP_OPTION_INTEGRATE_EDDCLEARECOMMERCEDL,
		);

		return array(
			'plugin'                  => SiteHealthRows::plugin( 'Easy Digital Downloads', $info['integration'], EasyDigitalDownloadsModule::MIN_EDD_VERSION ),
			'tracking'                => SiteHealthRows::on_off( __( 'E-commerce tracking', 'duracelltomi-google-tag-manager' ), $info['enabled'] ),
			'options'                 => SiteHealthRows::group( __( 'Options', 'duracelltomi-google-tag-manager' ), SiteHealthRows::states( $options, $switches ) ),
			'brand_taxonomy'          => SiteHealthRows::items( __( 'Brand taxonomy', 'duracelltomi-google-tag-manager' ), array( (string) $options->get( GTM4WP_OPTION_INTEGRATE_EDDBRANDTAXONOMY ) ) ),
			'business_vertical'       => SiteHealthRows::text( __( 'Google Ads business vertical', 'duracelltomi-google-tag-manager' ), (string) $options->get( GTM4WP_OPTION_INTEGRATE_EDDBUSINESSVERTICAL ) ),
			'products_per_impression' => SiteHealthRows::count( __( 'Products per impression', 'duracelltomi-google-tag-manager' ), (int) $options->get( GTM4WP_OPTION_INTEGRATE_EDDPRODPERIMPRESSION ) ),
			'order_max_age'           => SiteHealthRows::count( __( 'Maximum order age (minutes)', 'duracelltomi-google-tag-manager' ), (int) $options->get( GTM4WP_OPTION_INTEGRATE_EDDORDERMAXAGE ) ),
			'datalayer_timeout'       => SiteHealthRows::count( __( 'Data layer timeout (ms)', 'duracelltomi-google-tag-manager' ), (int) $options->get( GTM4WP_OPTION_INTEGRATE_EDDDLMAXTIMEOUT ) ),
			'purchase_statuses'       => SiteHealthRows::items( __( 'Order statuses that trigger the purchase event', 'duracelltomi-google-tag-manager' ), (array) $options->get( GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES ) ),
			'product_id_prefix'       => SiteHealthRows::set_or_empty( __( 'Product ID prefix', 'duracelltomi-google-tag-manager' ), (string) $options->get( GTM4WP_OPTION_INTEGRATE_EDDPRODIDPREFIX ) ),
			'transaction_id_prefix'   => SiteHealthRows::set_or_empty( __( 'Transaction ID prefix', 'duracelltomi-google-tag-manager' ), (string) $options->get( GTM4WP_OPTION_INTEGRATE_EDDTRANSACTIONIDPREFIX ) ),
		);
	}
}
