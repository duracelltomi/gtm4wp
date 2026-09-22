<?php
/**
 * Unit tests for the Easy Digital Downloads admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Module\StatusInfoInterface;
use GTM4WP\Modules\EasyDigitalDownloads\AdminSchema;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Options\Field;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * The EDD schema was written to WooCommerce parity, so it carries the same
 * behaviours its sibling pins in WooCommerceAdminSchemaTest (TS-18, T103): the
 * brand-taxonomy choice list keeps only the taxonomies a store owner could use
 * as a brand, its sanitizer replaces the SELECT allow-list on purpose, and the
 * purchase-status choices come from EDD's registry with a built-in fallback.
 * Plus what the schema tells the status ability about the module.
 *
 * Two of the collaborators are process-global (TS-16): EDD_VERSION is defined
 * order-stickily by the module test, and a Brain Monkey stub of
 * edd_get_payment_statuses() would stay defined for every later file - so the
 * legs that need either run in a process of their own, and the in-process
 * legs assert the precondition they rely on rather than assume it.
 */
final class EasyDigitalDownloadsAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
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

		$this->fail( "Field '{$key}' not found in the Easy Digital Downloads admin schema." );
	}

	// ---- Brand taxonomy ----------------------------------------------------

	public function test_brand_taxonomy_choices_only_list_public_download_taxonomies(): void {
		Functions\when( 'get_object_taxonomies' )->justReturn(
			array(
				'download_category'    => $this->taxonomy( 'Categories', true, true ),
				'brand'                => $this->taxonomy( 'Brand', true, true ),
				'edd_log_type'         => $this->taxonomy( '', false, false ),
				'translation_priority' => $this->taxonomy( 'Translation Priorities', false, true ),
				'category'             => $this->taxonomy( 'Categories', true, true, true ),
			)
		);

		$field = $this->field( GTM4WP_OPTION_INTEGRATE_EDDBRANDTAXONOMY );

		$this->assertSame(
			array(
				''                  => '(not used)',
				'download_category' => 'Categories',
				'brand'             => 'Brand',
			),
			$field->choices,
			'"(not used)" plus the public, UI-visible, non-builtin download taxonomies, in registration order.'
		);
		$this->assertArrayNotHasKey( 'edd_log_type', $field->choices, 'An internal taxonomy is not a brand.' );
		$this->assertArrayNotHasKey( 'translation_priority', $field->choices, 'A non-public taxonomy attached to downloads (WPML) must not be offered as a brand.' );
		$this->assertArrayNotHasKey( 'category', $field->choices, 'Built-in taxonomies are excluded, as in the WooCommerce schema.' );
	}

	public function test_brand_taxonomy_choices_degrade_to_not_used_only_without_taxonomies(): void {
		$this->assertSame( array( '' => '(not used)' ), $this->field( GTM4WP_OPTION_INTEGRATE_EDDBRANDTAXONOMY )->choices );
	}

	public function test_brand_taxonomy_sanitizer_trims_and_coerces_to_string(): void {
		// A custom sanitizer REPLACES the SELECT allow-list branch on purpose, so
		// a stored brand taxonomy is not reset while its plugin is momentarily
		// inactive during a save or import; that makes the sanitizer responsible
		// for its own type defence.
		$field = $this->field( GTM4WP_OPTION_INTEGRATE_EDDBRANDTAXONOMY );

		$this->assertSame( Field::TYPE_SELECT, $field->type );
		$this->assertNotNull( $field->sanitizer, 'The allow-list reset of the SELECT default is deliberately replaced.' );
		$this->assertSame( 'x', $field->sanitize( '  x  ' ), 'Leading/trailing whitespace is trimmed from the stored taxonomy slug.' );
		$this->assertSame( '42', $field->sanitize( 42 ), 'A non-string value is coerced to a string.' );
		$this->assertSame( '', $field->sanitize( array( 'x' ) ), 'A non-scalar import value must collapse to "" (Field::to_string()), never "Array" plus a PHP warning.' );
	}

	// ---- Purchase statuses -------------------------------------------------

	public function test_purchase_status_choices_fall_back_to_the_core_edd_statuses_without_the_registry(): void {
		$this->assertFalse( function_exists( 'edd_get_payment_statuses' ), 'Precondition: EDD\'s registry function is undefined in-process (TS-16); the registry leg runs in its own process below.' );

		$field = $this->field( GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES );

		$this->assertSame( Field::TYPE_MULTISELECT, $field->type );
		$this->assertSame(
			array( 'pending', 'processing', 'complete', 'refunded', 'partially_refunded', 'revoked', 'failed', 'abandoned' ),
			array_keys( $field->choices ),
			'The core EDD 3.x statuses, so the field is usable while EDD is inactive.'
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_purchase_status_choices_come_from_edds_registry_when_it_is_loaded(): void {
		Functions\when( 'edd_get_payment_statuses' )->justReturn(
			array(
				'complete'    => 'Completed',
				'on_hold'     => 'On hold',
				'preapproval' => 'Preapproved',
			)
		);

		$field = $this->field( GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES );

		$this->assertSame(
			array(
				'complete'    => 'Completed',
				'on_hold'     => 'On hold',
				'preapproval' => 'Preapproved',
			),
			$field->choices,
			'A status an extension registered is offered; the built-in fallback list is not merged in.'
		);
	}

	// ---- Status info -------------------------------------------------------

	/**
	 * What the schema tells the status ability about the module. EDD_VERSION
	 * is defined by the module test in whichever order it ran, so both legs
	 * run in a process of their own and assert literals (TS-21, T98).
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_the_schema_reports_its_master_switch_and_an_absent_host(): void {
		$schema = new AdminSchema();
		$this->assertInstanceOf( StatusInfoInterface::class, $schema );
		$this->assertFalse( defined( 'EDD_VERSION' ), 'Precondition: a fresh process has no host constant.' );

		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => true ) );
		$info = $schema->status_info( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );

		$this->assertSame(
			array(
				'enabled'     => true,
				'integration' => array(
					'active'  => false,
					'version' => null,
				),
			),
			$info,
			'The e-commerce tracking option is the master switch; the host is not installed.'
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_the_schema_reports_the_installed_host_and_its_version(): void {
		define( 'EDD_VERSION', '3.7.0' );
		Functions\when( 'get_option' )->justReturn( array() );

		$info = ( new AdminSchema() )->status_info( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );

		$this->assertSame(
			array(
				'active'  => true,
				'version' => '3.7.0',
			),
			$info['integration']
		);
	}

	public function test_the_master_switch_is_off_by_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertFalse( ( new AdminSchema() )->status_info( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) )['enabled'] );
	}
}
