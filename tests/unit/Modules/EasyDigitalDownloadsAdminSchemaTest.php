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
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * What the schema tells the status ability about the module. The fields
 * themselves are covered by ModuleConsistencyTest and the module test.
 *
 * The host's version constant is process-global (the EDD stub file of the
 * module test defines it), so under a random order it may or may not be
 * present here. What is pinned is the mapping against the process state at
 * the time of the call (TS-16).
 */
final class EasyDigitalDownloadsAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
	}

	public function test_the_schema_reports_its_master_switch_and_host_plugin(): void {
		$schema = new AdminSchema();
		$this->assertInstanceOf( StatusInfoInterface::class, $schema );

		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => true ) );
		$info = $schema->status_info( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );

		$this->assertTrue( $info['enabled'], 'The e-commerce tracking option is the master switch.' );
		$this->assertSame( defined( 'EDD_VERSION' ), $info['integration']['active'] );
		$this->assertSame( defined( 'EDD_VERSION' ) ? (string) constant( 'EDD_VERSION' ) : null, $info['integration']['version'] );
	}

	public function test_the_master_switch_is_off_by_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertFalse( ( new AdminSchema() )->status_info( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) )['enabled'] );
	}
}
