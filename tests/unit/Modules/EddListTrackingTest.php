<?php
/**
 * Unit tests for the Easy Digital Downloads ListTracking markup.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
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

		// The synthesized checkout line names its download (#547).
		Functions\when( 'get_the_title' )->justReturn( '' );

		// TS-16 / UC-3: stubbed in this file's own setUp, and no more permissive
		// than the real function. WordPress encodes a term name with
		// _wp_specialchars() when it is saved, and wp_specialchars_decode() with
		// ENT_QUOTES reverses exactly those five entities and nothing else.
		Functions\when( 'wp_specialchars_decode' )->alias(
			static fn ( $value, $quote_style = ENT_NOQUOTES ) => str_replace(
				array( '&lt;', '&gt;', '&quot;', '&#039;', '&amp;' ),
				array( '<', '>', '"', "'", '&' ),
				(string) $value
			)
		);

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

		return new ListTracking( new DownloadData( $options ) );
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

	public function test_downloads_block_items_emit_the_same_grid_span(): void {
		Functions\when( 'get_the_ID' )->justReturn( 55 );

		$list_tracking = $this->make_list_tracking();

		// The block hook shares the running list position with the shortcode.
		$first  = $this->capture_output( array( $list_tracking, 'after_download_shortcode_item' ) );
		$second = $this->capture_output( array( $list_tracking, 'after_download_shortcode_item' ) );

		$this->assertStringContainsString( 'class="gtm4wp_edd_productdata"', $first );
		$this->assertStringContainsString( '&quot;index&quot;:1', $first );
		$this->assertStringContainsString( '&quot;index&quot;:2', $second );
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

	public function test_variable_price_options_are_on_the_purchase_basis(): void {
		// #519: the client prices a variable add_to_cart from this map, so it is converted here.
		Functions\when( 'edd_has_variable_prices' )->justReturn( true );
		Functions\when( 'edd_get_lowest_price_option' )->justReturn( 5.0 );
		Functions\when( 'edd_get_variable_prices' )->justReturn(
			array(
				2 => array(
					'name'   => 'Professional',
					'amount' => 15.0,
				),
			)
		);
		Functions\when( 'edd_use_taxes' )->justReturn( true );
		Functions\when( 'edd_download_is_tax_exclusive' )->justReturn( false );
		Functions\when( 'edd_get_shop_country' )->justReturn( 'DE' );
		Functions\when( 'edd_get_shop_state' )->justReturn( '' );
		Functions\when( 'edd_get_tax_rate' )->justReturn( 0.2 );
		Functions\when( 'edd_prices_include_tax' )->justReturn( false );
		// Both cart roundings (#533): which one runs depends on EDD_VERSION,
		// defined order-stickily elsewhere (TS-16); both give 2 decimals here.
		Functions\when( 'edd_currency_decimal_filter' )->justReturn( 2 );
		Functions\when( 'edd_format_amount' )->alias( static fn ( $amount ) => number_format( round( (float) $amount, 2 ), 2, '.', '' ) );
		Functions\when( 'edd_calculate_tax' )->alias( static fn ( $amount, $c, $r, $f, $rate ) => $amount * $rate );

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->purchase_link_data( 55, array() )
		);

		$this->assertStringContainsString( '&quot;price&quot;:18', $markup );
		$this->assertStringNotContainsString( '&quot;price&quot;:15', $markup );
	}

	/**
	 * The hook's item is an edd_get_cart_contents() row (U115); the money is
	 * on the matching edd_get_cart_content_details() line.
	 *
	 * @param int   $key      Cart key.
	 * @param int   $quantity Quantity.
	 * @param float $price    Line total.
	 * @param array $options  Item options.
	 * @return array{0: array, 1: array} The contents row and the details list.
	 */
	private static function cart_row( int $key, int $quantity, float $price, array $options = array() ): array {
		$contents = array(
			'id'       => 55,
			'options'  => $options,
			'quantity' => $quantity,
		);

		return array(
			$contents,
			array(
				$key => array(
					'id'          => 55,
					'item_number' => $contents,
					'quantity'    => $quantity,
					'price'       => $price,
					'tax'         => 0.0,
					'discount'    => 0.0,
				),
			),
		);
	}

	/**
	 * A download with two price options.
	 *
	 * @return void
	 */
	private function stub_variable_prices(): void {
		Functions\when( 'edd_has_variable_prices' )->justReturn( true );
		Functions\when( 'edd_get_variable_prices' )->justReturn(
			array(
				1 => array(
					'name'   => 'Basic',
					'amount' => 12.5,
				),
				2 => array(
					'name'   => 'Professional',
					'amount' => 15.0,
				),
			)
		);
	}

	public function test_a_checkout_row_the_cart_item_filter_excludes_prints_nothing(): void {
		// #516: the cart surface's filter, asked with the details line it documents.
		list( $row, $details ) = self::cart_row( 3, 2, 19.98 );
		Functions\when( 'edd_get_cart_content_details' )->justReturn( $details );
		Filters\expectApplied( GTM4WP_WPFILTER_EEC_EDD_CART_ITEM )
			->once()
			->with( true, $details[3] )
			->andReturn( false );

		$this->assertSame( '', $this->capture_output( fn () => $this->make_list_tracking()->checkout_cart_item_data( $row, 3 ) ) );
	}

	public function test_checkout_cart_row_span_uses_its_own_class_and_carries_the_cart_key(): void {
		list( $row, $details ) = self::cart_row( 3, 2, 19.98 );
		Functions\when( 'edd_get_cart_content_details' )->justReturn( $details );

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->checkout_cart_item_data( $row, 3 )
		);

		// A dedicated class keeps cart rows out of the view_item_list impressions.
		$this->assertStringContainsString( 'class="gtm4wp_edd_cartitemdata"', $markup );
		$this->assertStringNotContainsString( 'gtm4wp_edd_productdata', $markup );
		$this->assertStringContainsString( '&quot;cart_key&quot;:3', $markup );
		$this->assertStringNotContainsString( 'internal_id', $markup );
		$this->assertStringContainsString( '&quot;price&quot;:9.99', $markup, 'The unit price comes from the details line (#470).' );
	}

	/**
	 * #470: a variable-price row is priced and named from its own option, and
	 * a details line for the same download under another price id is not used.
	 */
	public function test_a_variable_price_checkout_row_carries_its_price_and_variant(): void {
		$this->stub_variable_prices();
		list( $row, $details ) = self::cart_row( 0, 2, 30.0, array( 'price_id' => 2 ) );
		Functions\when( 'edd_get_cart_content_details' )->justReturn( $details );

		$item = json_decode( html_entity_decode( (string) preg_replace( '/.*data-gtm4wp_product_data="([^"]*)".*/s', '$1', $this->capture_output( fn () => $this->make_list_tracking()->checkout_cart_item_data( $row, 0 ) ) ) ), true );

		$this->assertSame( 15.0, (float) $item['price'] );
		$this->assertSame( 'Professional', $item['item_variant'] );
		$this->assertSame( 2, $item['quantity'] );
	}

	/**
	 * A row with no matching details line (the block editor's preview cart)
	 * is priced from the catalogue rather than reported at zero.
	 */
	public function test_a_checkout_row_without_a_details_line_is_priced_from_the_catalogue(): void {
		$this->stub_variable_prices();
		list( $row, $details )                = self::cart_row( 0, 1, 9.99, array( 'price_id' => 2 ) );
		$details[0]['item_number']['options'] = array( 'price_id' => 1 );
		Functions\when( 'edd_get_cart_content_details' )->justReturn( $details );
		Functions\when( 'edd_get_cart_item_price' )->justReturn( 12.5 );

		$markup = $this->capture_output( fn () => $this->make_list_tracking()->checkout_cart_item_data( $row, 0 ) );

		$this->assertStringContainsString( '&quot;price&quot;:12.5', $markup );
	}

	/**
	 * #547: the synthesized line hands the cart-item filter EDD's content-details
	 * keys, and no tax key (it carries no tax split; 0 would be a false claim).
	 */
	public function test_a_synthesized_checkout_line_carries_the_content_details_keys(): void {
		$this->stub_variable_prices();
		list( $row, $details )                = self::cart_row( 0, 1, 9.99, array( 'price_id' => 2 ) );
		$details[0]['item_number']['options'] = array( 'price_id' => 1 );
		Functions\when( 'edd_get_cart_content_details' )->justReturn( $details );
		Functions\when( 'edd_get_cart_item_price' )->justReturn( 12.5 );
		Functions\when( 'get_the_title' )->justReturn( 'My eBook' );

		$seen = null;
		\Brain\Monkey\Filters\expectApplied( GTM4WP_WPFILTER_EEC_EDD_CART_ITEM )->andReturnUsing(
			static function ( $keep, $line ) use ( &$seen ) {
				$seen = $line;
				return $keep;
			}
		);

		$this->capture_output( fn () => $this->make_list_tracking()->checkout_cart_item_data( $row, 0 ) );

		$this->assertIsArray( $seen );
		$this->assertSame( 'My eBook', $seen['name'] );
		$this->assertSame( 12.5, $seen['item_price'] );
		$this->assertSame( 12.5, $seen['subtotal'] );
		$this->assertSame( 0.0, $seen['discount'] );
		$this->assertArrayNotHasKey( 'tax', $seen );
	}

	/**
	 * #470: a details line under the same key and price id but for another
	 * download is not this row's line.
	 */
	public function test_a_details_line_of_another_download_is_not_used(): void {
		list( $row, $details ) = self::cart_row( 0, 2, 30.0 );
		$details[0]['id']      = 56;
		Functions\when( 'edd_get_cart_content_details' )->justReturn( $details );
		Functions\when( 'edd_get_cart_item_price' )->justReturn( 12.5 );

		$markup = $this->capture_output( fn () => $this->make_list_tracking()->checkout_cart_item_data( $row, 0 ) );

		$this->assertStringContainsString( '&quot;price&quot;:12.5', $markup );
		$this->assertStringNotContainsString( '&quot;price&quot;:15', $markup );
	}

	public function test_invalid_inputs_emit_no_markup(): void {
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		$list_tracking = $this->make_list_tracking();

		$this->assertSame( '', $this->capture_output( array( $list_tracking, 'after_download_shortcode_item' ) ) );
		$this->assertSame( '', $this->capture_output( fn () => $list_tracking->purchase_link_data( 0 ) ) );
		$this->assertSame( '', $this->capture_output( fn () => $list_tracking->checkout_cart_item_data( array(), 0 ) ) );
	}

	public function test_purchase_form_input_escapes_a_hostile_title_and_price_option_name(): void {
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => '"><script>alert(1)</script>',
					'price' => 9.99,
				)
			)
		);
		Functions\when( 'edd_has_variable_prices' )->justReturn( true );
		Functions\when( 'edd_get_lowest_price_option' )->justReturn( 5.0 );
		Functions\when( 'edd_get_variable_prices' )->justReturn(
			array(
				1 => array(
					'name'   => '"><img src=x onerror=alert(2)>',
					'amount' => 5.0,
				),
			)
		);

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->purchase_link_data( 55, array() )
		);

		// Both directions (TS-2), mirroring the grid-span sibling: the escaped
		// forms are present and no raw break-out sequence survives inside the
		// value="..." attribute — neither from the download title nor from a
		// variable price-option name.
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
		$this->assertStringContainsString( '&lt;img', $markup );
		$this->assertStringContainsString( '&quot;', $markup );
		$this->assertStringNotContainsString( '<script', $markup );
		$this->assertStringNotContainsString( '<img', $markup );
		$this->assertStringNotContainsString( '"><script>alert(1)</script>', $markup );
	}

	public function test_checkout_cart_row_span_escapes_a_hostile_title_for_the_attribute_context(): void {
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => '"><script>alert(1)</script>',
					'price' => 9.99,
				)
			)
		);

		list( $row, $details ) = self::cart_row( 0, 1, 9.99 );
		Functions\when( 'edd_get_cart_content_details' )->justReturn( $details );

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->checkout_cart_item_data( $row, 0 )
		);

		// Both directions (TS-2), completing the sibling symmetry: the grid
		// span and the purchase form carry this case, so the cart row does too.
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
		$this->assertStringContainsString( '&quot;', $markup );
		$this->assertStringNotContainsString( '<script', $markup );
		$this->assertStringNotContainsString( '"><script>alert(1)</script>', $markup );
	}

	public function test_search_results_use_their_own_list_name(): void {
		Functions\when( 'get_the_ID' )->justReturn( 55 );
		Functions\when( 'is_search' )->justReturn( true );

		$markup = $this->capture_output(
			array( $this->make_list_tracking(), 'after_download_shortcode_item' )
		);

		$this->assertStringContainsString( 'Search Results', $markup );
		$this->assertStringNotContainsString( 'Downloads List', $markup );
	}

	public function test_fixed_price_purchase_form_omits_the_price_options_key(): void {
		Functions\when( 'edd_has_variable_prices' )->justReturn( false );

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->purchase_link_data( 55, array() )
		);

		$this->assertStringContainsString( '<input type="hidden" name="gtm4wp_product_data"', $markup );
		// Omission, not an empty placeholder: the tracker treats key presence
		// as "this download has variable prices".
		$this->assertStringNotContainsString( 'price_options', $markup );
	}

	public function test_malformed_variable_price_data_degrades_to_no_price_options(): void {
		Functions\when( 'edd_has_variable_prices' )->justReturn( true );
		Functions\when( 'edd_get_lowest_price_option' )->justReturn( 5.0 );
		// EDD returning a non-array (corrupt meta) must not warn or emit a key.
		Functions\when( 'edd_get_variable_prices' )->justReturn( false );

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->purchase_link_data( 55, array() )
		);

		$this->assertStringContainsString( 'name="gtm4wp_product_data"', $markup );
		$this->assertStringNotContainsString( 'price_options', $markup );
	}

	public function test_non_array_price_option_rows_are_skipped(): void {
		Functions\when( 'edd_has_variable_prices' )->justReturn( true );
		Functions\when( 'edd_get_lowest_price_option' )->justReturn( 5.0 );
		Functions\when( 'edd_get_variable_prices' )->justReturn(
			array(
				1 => 'corrupt-row',
				2 => array(
					'name'   => 'Professional',
					'amount' => 15.0,
				),
			)
		);

		$markup = $this->capture_output(
			fn () => $this->make_list_tracking()->purchase_link_data( 55, array() )
		);

		// The corrupt row is dropped, the valid sibling survives.
		$this->assertStringContainsString( 'Professional', $markup );
		$this->assertStringNotContainsString( 'corrupt-row', $markup );
	}
}
