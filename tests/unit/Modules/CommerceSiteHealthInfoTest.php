<?php
/**
 * Unit tests for the Site Health rows of the commerce and form integrations.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\ContactForm7\ContactForm7Module;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;

/**
 * The three integrating modules: the host plugin's version (or its absence),
 * the master switch, every checkbox as a state, numbers and lists as such,
 * and the free-text options as set/empty. The host constants are process
 * facts (T98), so the installed leg runs in a process of its own and the
 * WooCommerce utilities are stubbed in for the "known" legs.
 */
final class CommerceSiteHealthInfoTest extends ModuleSiteHealthTestCase {

	public function test_contact_form_7_rows(): void {
		$this->assertFalse( defined( 'WPCF7_VERSION' ), 'Precondition (TS-16): nothing in the suite defines the version in-process.' );

		$rows = $this->rows( new ContactForm7Module(), array( GTM4WP_OPTION_INTEGRATE_WPCF7 => true ) );

		$this->assertSame( array( 'plugin', 'tracking', 'inputs', 'options' ), array_keys( $rows ) );
		$this->assertSame( 'not installed', $rows['plugin']['debug'] );
		$this->assertSame( 'on', $rows['tracking']['debug'] );
		$this->assertSame( 'full', $rows['inputs']['debug'] );
		$this->assertSame( 'off', $rows['options']['debug'][ GTM4WP_OPTION_INTEGRATE_WPCF7_GA4EVENTS ] );
	}

	public function test_woocommerce_rows_over_the_defaults(): void {
		$this->assertFalse( defined( 'WC_VERSION' ), 'Precondition (TS-16): nothing in the suite defines the version in-process.' );

		$rows = $this->rows(
			new WooCommerceModule(),
			array(
				GTM4WP_OPTION_INTEGRATE_WCUSESKU           => true,
				GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY => 'product_brand',
				GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX => 'shop-',
				GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE => '42',
			)
		);

		$this->assertSame( 'not installed', $rows['plugin']['debug'] );
		$this->assertSame( 'off', $rows['tracking']['debug'] );
		$this->assertCount( 14, $rows['options']['debug'] );
		$this->assertSame( 'on', $rows['options']['debug'][ GTM4WP_OPTION_INTEGRATE_WCUSESKU ] );
		$this->assertSame( 'product_brand', $rows['brand_taxonomy']['debug'] );
		$this->assertSame( 'retail', $rows['business_vertical']['debug'] );
		$this->assertSame( '10', $rows['products_per_impression']['debug'] );
		$this->assertSame( '30', $rows['order_max_age']['debug'] );
		$this->assertSame( '2000', $rows['datalayer_timeout']['debug'] );
		$this->assertSame( 'processing, on-hold, completed', $rows['purchase_statuses']['debug'] );
		$this->assertSame( 'empty', $rows['product_id_prefix']['debug'] );
		$this->assertSame( 'set', $rows['transaction_id_prefix']['debug'] );
		$this->assertSame( 'set', $rows['custom_order_received_page']['debug'] );

		$text = $this->text( $rows );
		$this->assertStringNotContainsString( 'shop-', $text, 'A prefix is configuration, not a state.' );
		$this->assertStringNotContainsString( '42', $text, 'A page id is configuration, not a state.' );
	}

	public function test_woocommerce_reads_the_page_kinds_and_hpos_from_its_utilities_when_present(): void {
		require_once __DIR__ . '/wc-blocks-stub.php';
		require_once __DIR__ . '/wc-orderutil-stub.php';

		\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::$cart_block     = true;
		\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::$checkout_block = false;
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos                      = true;

		$rows = $this->rows( new WooCommerceModule() );

		$this->assertSame(
			array(
				'cart'     => 'block',
				'checkout' => 'classic',
			),
			$rows['cart_checkout_pages']['debug']
		);
		$this->assertSame( 'on', $rows['hpos']['debug'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_woocommerce_reports_unknown_where_its_utilities_are_absent(): void {
		$this->assertFalse( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ), 'Precondition: no WooCommerce in this process.' );

		$rows = $this->rows( new WooCommerceModule() );

		$this->assertSame( 'unknown', $rows['cart_checkout_pages']['debug']['cart'] );
		$this->assertSame( 'unknown', $rows['hpos']['debug'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_an_installed_host_is_reported_by_version_and_a_version_under_the_floor_says_so(): void {
		define( 'WC_VERSION', '4.9.0' );
		define( 'EDD_VERSION', '3.7.0' );
		define( 'WPCF7_VERSION', '6.1.2' );

		$this->assertSame( '4.9.0 (below the 5.0 floor)', $this->rows( new WooCommerceModule() )['plugin']['debug'] );
		$this->assertSame( '3.7.0', $this->rows( new EasyDigitalDownloadsModule() )['plugin']['debug'] );
		$this->assertSame( '6.1.2', $this->rows( new ContactForm7Module() )['plugin']['debug'] );
	}

	public function test_easy_digital_downloads_mirrors_the_woocommerce_rows_for_its_options(): void {
		$rows = $this->rows(
			new EasyDigitalDownloadsModule(),
			array(
				GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_EDDPRODIDPREFIX   => 'dl-',
			)
		);

		$this->assertSame(
			array( 'plugin', 'tracking', 'options', 'brand_taxonomy', 'business_vertical', 'products_per_impression', 'order_max_age', 'datalayer_timeout', 'purchase_statuses', 'product_id_prefix', 'transaction_id_prefix' ),
			array_keys( $rows ),
			'No cart/checkout-page or HPOS rows: those are WooCommerce facts.'
		);
		$this->assertSame( 'on', $rows['tracking']['debug'] );
		$this->assertCount( 11, $rows['options']['debug'] );
		$this->assertSame( 'none', $rows['brand_taxonomy']['debug'] );
		$this->assertSame( 'pending, processing, complete', $rows['purchase_statuses']['debug'] );
		$this->assertSame( 'set', $rows['product_id_prefix']['debug'] );
		$this->assertStringNotContainsString( 'dl-', $this->text( $rows ) );
	}
}
