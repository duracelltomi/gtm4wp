<?php
/**
 * Unit tests for the WooCommerce module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\WooCommerce\AdminSchema;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the "Taxonomy to be used for product brands" field. Its choice list is
 * built from the taxonomies registered against the `product` post type, but only
 * the ones a store owner could actually use as a brand are offered: public,
 * UI-visible, non-builtin taxonomies (1.x parity). WooCommerce's internal
 * product_type / product_visibility taxonomies and cross-cutting ones other
 * plugins attach to products (e.g. WPML's non-public "translation_priority")
 * must be filtered out.
 */
final class WooCommerceAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// fields() reads these to build dynamic choices; default them to empty so
		// every test has an expectation (individual tests override as needed). Once
		// Brain Monkey defines a function it stays defined process-wide, so a later
		// test would otherwise hit an un-mocked call.
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
	}

	/**
	 * Builds a WP_Taxonomy-like stub carrying only the properties the schema reads.
	 *
	 * @param string $label     Human label shown in the dropdown.
	 * @param bool   $is_public Whether the taxonomy has a public archive.
	 * @param bool   $show_ui   Whether the taxonomy has an admin UI.
	 * @param bool   $builtin   Whether the taxonomy is a WordPress core taxonomy.
	 * @return object
	 */
	private function taxonomy( string $label, bool $is_public, bool $show_ui, bool $builtin = false ): object {
		return (object) array(
			'label'    => $label,
			'public'   => $is_public,
			'show_ui'  => $show_ui,
			'_builtin' => $builtin,
		);
	}

	/**
	 * Returns the field owning the given option key.
	 *
	 * @param string $key Option key.
	 * @return Field
	 */
	private function field( string $key ): Field {
		foreach ( ( new AdminSchema() )->fields() as $field ) {
			if ( $key === $field->key ) {
				return $field;
			}
		}

		$this->fail( "Field '{$key}' not found in the WooCommerce admin schema." );
	}

	public function test_brand_taxonomy_choices_only_list_public_product_taxonomies(): void {
		Functions\when( 'get_object_taxonomies' )->justReturn(
			array(
				'product_cat'          => $this->taxonomy( 'Categories', true, true ),
				'pa_color'             => $this->taxonomy( 'Color', true, true ),
				'product_type'         => $this->taxonomy( '', false, false ),
				'product_visibility'   => $this->taxonomy( '', false, false ),
				'translation_priority' => $this->taxonomy( 'Translation Priorities', false, true ),
				'category'             => $this->taxonomy( 'Categories', true, true, true ),
			)
		);

		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY );

		// The list keeps "(not used)" plus the two public product taxonomies, in
		// registration order.
		$this->assertSame(
			array(
				''            => '(not used)',
				'product_cat' => 'Categories',
				'pa_color'    => 'Color',
			),
			$field->choices
		);

		// The non-brand candidates must be absent: WooCommerce's internal
		// taxonomies, WPML's non-public translation_priority, and any builtin.
		$this->assertArrayNotHasKey( 'product_type', $field->choices );
		$this->assertArrayNotHasKey( 'product_visibility', $field->choices );
		$this->assertArrayNotHasKey(
			'translation_priority',
			$field->choices,
			'A non-public taxonomy attached to products (WPML translation_priority) must not be offered as a brand.'
		);
		$this->assertArrayNotHasKey(
			'category',
			$field->choices,
			'Built-in taxonomies are excluded, matching the 1.x filter.'
		);
	}

	public function test_brand_taxonomy_choices_degrade_to_not_used_only_without_taxonomies(): void {
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY );

		$this->assertSame( array( '' => '(not used)' ), $field->choices );
	}

	public function test_purchase_status_field_defaults_to_the_placement_statuses(): void {
		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES );

		$this->assertSame( Field::TYPE_MULTISELECT, $field->type );
		$this->assertSame( array( 'processing', 'on-hold', 'completed' ), $field->default_value, 'The purchase fires at placement: processing (COD), on-hold (bank transfer) and completed by default.' );
	}

	public function test_purchase_status_choices_strip_the_wc_prefix(): void {
		// wc_get_order_statuses() keys are prefixed "wc-" while WC_Order::get_status()
		// returns the bare slug, so the choices must strip the prefix to stay comparable.
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
			)
		);

		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES );

		$this->assertArrayHasKey( 'processing', $field->choices );
		$this->assertArrayHasKey( 'completed', $field->choices );
		$this->assertArrayNotHasKey( 'wc-processing', $field->choices, 'The wc- prefix must be stripped so the value matches WC_Order::get_status().' );
	}

	public function test_reliable_purchase_tracking_is_an_off_by_default_experimental_checkbox(): void {
		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE );

		$this->assertSame( Field::TYPE_CHECKBOX, $field->type );
		$this->assertFalse( $field->default_value, 'Reliable purchase tracking changes when the event fires, so it is off by default.' );
		$this->assertSame( Field::PHASE_EXPERIMENTAL, $field->phase );
	}

	public function test_custom_order_received_page_is_a_select_defaulting_to_the_standard_page(): void {
		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE );

		$this->assertSame( Field::TYPE_SELECT, $field->type );
		$this->assertSame( '', $field->default_value );
		$this->assertArrayHasKey( '', $field->choices, 'The empty choice keeps the standard WooCommerce order received page.' );
	}

	public function test_brand_taxonomy_sanitizer_trims_and_coerces_to_string(): void {
		// The field is a TYPE_SELECT but carries a custom sanitizer, which Field::sanitize()
		// applies before the choice validation; it must trim surrounding whitespace and
		// coerce a non-string to a string.
		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY );

		$this->assertSame( 'x', $field->sanitize( '  x  ' ), 'Leading/trailing whitespace is trimmed from the stored taxonomy slug.' );
		$this->assertSame( '42', $field->sanitize( 42 ), 'A non-string value is coerced to a string.' );
	}

	public function test_transaction_id_prefix_is_an_empty_text_field_with_a_trimming_sanitizer(): void {
		// Empty by default so an upgrade keeps sending the plain WooCommerce order
		// number as the transaction id.
		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX );

		$this->assertSame( Field::TYPE_TEXT, $field->type );
		$this->assertSame( '', $field->default_value );
		$this->assertSame( 'purchase', $field->group );
		$this->assertSame( 'x-', $field->sanitize( '  x-  ' ), 'Leading/trailing whitespace is trimmed from the prefix.' );
		$this->assertSame( '42', $field->sanitize( 42 ), 'A non-string value is coerced to a string.' );
	}

	public function test_remarketing_id_prefix_sanitizer_trims_and_coerces_to_string(): void {
		// The product-ID-prefix field defines the same trim((string)$value) sanitizer.
		$field = $this->field( GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX );

		$this->assertSame( 'x', $field->sanitize( '  x  ' ), 'Leading/trailing whitespace is trimmed from the product ID prefix.' );
		$this->assertSame( '42', $field->sanitize( 42 ), 'A non-string value is coerced to a string.' );
	}
}
