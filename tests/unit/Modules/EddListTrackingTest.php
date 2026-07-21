<?php
/**
 * Unit tests for the Easy Digital Downloads ListTracking markup.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\EasyDigitalDownloads\DownloadData;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Modules\EasyDigitalDownloads\ListTracking;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/edd-stubs.php';

/**
 * Covers the hidden product-data markup (grid spans, purchase-form input,
 * checkout cart row spans) the gtm4wp-edd tracker reads, including the
 * attribute-context escaping of hostile download titles.
 */
final class EddListTrackingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			static fn ( $data, $flags = 0 ) => json_encode( $data, $flags )
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $title ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ), '-' ) )
		);
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/downloads/my-ebook/' );

		Functions\when( 'edd_get_download_sku' )->justReturn( false );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => 'My eBook',
					'price' => 9.99,
				)
			)
		);
	}

	/**
	 * Builds a ListTracking instance with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ListTracking
	 */
	private function make_list_tracking( array $stored = array() ): ListTracking {
		Functions\when( 'get_option' )->justReturn( $stored );

		$options = new Options( ( new EasyDigitalDownloadsModule() )->defaults() );

		return new ListTracking( $options, new DownloadData( $options ) );
	}

	/**
	 * Captures the echoed output of a callback.
	 *
	 * @param callable $callback The output-producing callback.
	 * @return string
	 */
	private function capture_output( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}

	public function test_grid_span_carries_list_data_and_position(): void {
		Functions\when( 'get_the_ID' )->justReturn( 55 );

		$list_tracking = $this->make_list_tracking();

		$first  = $this->capture_output( array( $list_tracking, 'after_download_shortcode_item' ) );
		$second = $this->capture_output( array( $list_tracking, 'after_download_shortcode_item' ) );

		$this->assertStringContainsString( 'class="gtm4wp_edd_productdata"', $first );
		$this->assertStringContainsString( 'Downloads List', $first );
		$this->assertStringContainsString( 'productlink', $first );
		$this->assertStringContainsString( '&quot;index&quot;:1', $first );
		$this->assertStringContainsString( '&quot;index&quot;:2', $second, 'Every item gets its running list position.' );
	}

	public function test_grid_span_escapes_a_hostile_title_for_the_attribute_context(): void {
		Functions\when( 'get_the_ID' )->justReturn( 55 );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => '"><script>alert(1)</script>',
					'price' => 9.99,
				)
			)
		);

		$markup = $this->capture_output(
			array( $this->make_list_tracking(), 'after_download_shortcode_item' )
		);

		// Both directions (TS-2): the escaped forms are present and no raw
		// break-out sequence survives inside the attribute.
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
		$this->assertStringContainsString( '&quot;', $markup );
		$this->assertStringNotContainsString( '<script', $markup );
		$this->assertStringNotContainsString( '"><script>alert(1)</script>', $markup );
	}

	public function test_purchase_form_input_includes_the_variable_price_options(): void {
		Functions\when( 'edd_has_variable_prices' )->justReturn( true );
		Functions\when( 'edd_get_lowest_price_option' )->justReturn( 5.0 );
		Functions\when( 'edd_get_variable_prices' )->justReturn(
			array(
				1 => array(
					'name'   => 'Personal',
					'amount' => 5.0,
				),
				2 => array(
					'name'   => 'Professional',
					'amount' => 15.0,
				),
			)
		);

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->purchase_link_data( 55, array() )
		);

		$this->assertStringContainsString( '<input type="hidden" name="gtm4wp_product_data"', $markup );
		$this->assertStringContainsString( 'price_options', $markup );
		$this->assertStringContainsString( 'Professional', $markup );
	}

	public function test_checkout_cart_row_span_uses_its_own_class_and_carries_the_cart_key(): void {
		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->checkout_cart_item_data(
				array(
					'id'       => 55,
					'quantity' => 2,
					'price'    => 19.98,
					'tax'      => 0.0,
					'discount' => 0.0,
				),
				3
			)
		);

		// A dedicated class keeps cart rows out of the view_item_list impressions.
		$this->assertStringContainsString( 'class="gtm4wp_edd_cartitemdata"', $markup );
		$this->assertStringNotContainsString( 'gtm4wp_edd_productdata', $markup );
		$this->assertStringContainsString( '&quot;cart_key&quot;:3', $markup );
		$this->assertStringNotContainsString( 'internal_id', $markup );
	}

	public function test_invalid_inputs_emit_no_markup(): void {
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		$list_tracking = $this->make_list_tracking();

		$this->assertSame( '', $this->capture_output( array( $list_tracking, 'after_download_shortcode_item' ) ) );
		$this->assertSame( '', $this->capture_output( fn () => $list_tracking->purchase_link_data( 0 ) ) );
		$this->assertSame( '', $this->capture_output( fn () => $list_tracking->checkout_cart_item_data( array(), 0 ) ) );
	}
}
