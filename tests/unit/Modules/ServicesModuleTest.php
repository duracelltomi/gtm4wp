<?php
/**
 * Unit tests for the services for agencies and freelancers module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\Services\AdminSchema;
use GTM4WP\Modules\Services\ServicesModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Pins that the module owns nothing and that its intro links to the services page.
 */
final class ServicesModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'esc_url_raw' )->returnArg();
		// Stands in for esc_url()'s attribute encoding, so the test sees whether it is applied.
		Functions\when( 'esc_url' )->alias( static fn ( $url ) => htmlspecialchars( (string) $url, ENT_QUOTES ) );
	}

	public function test_the_module_owns_no_option_and_registers_no_frontend_hook(): void {
		$module = new ServicesModule();

		$this->assertSame( 'services', $module->id() );
		$this->assertSame( array(), $module->defaults() );
		$this->assertTrue( $module->is_available() );

		Functions\when( 'get_option' )->justReturn( array() );
		$module->frontend( new Options( array() ) );

		$this->assertFalse( has_action( 'wp_head' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts' ) );
	}

	public function test_the_schema_declares_no_field_and_no_group(): void {
		$schema = new AdminSchema();

		$this->assertSame( array(), $schema->fields() );
		$this->assertSame( array(), $schema->groups() );
		$this->assertSame( 'gtm4wp-services', $schema->doc_url() );
	}

	public function test_the_intro_repeats_the_first_sentence_and_links_to_the_services_page(): void {
		$intro = ( new AdminSchema() )->intro();

		$this->assertStringStartsWith( 'Many agencies and freelancers use GTM4WP to build tracking for their clients. ', $intro );
		$this->assertStringContainsString( 'href="https://gtm4wp.com/gtm4wp-services"', $intro );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $intro );
	}

	public function test_a_filtered_url_is_attribute_escaped_in_the_intro(): void {
		Filters\expectApplied( 'gtm4wp_admin_doc_url' )->andReturn( 'https://example.com/"><img src=x>' );

		$intro = ( new AdminSchema() )->intro();

		$this->assertStringContainsString( 'https://example.com/&quot;&gt;&lt;img src=x&gt;', $intro );
		$this->assertStringNotContainsString( '"><img', $intro );
	}
}
