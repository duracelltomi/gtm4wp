<?php
/**
 * Base test case with Brain Monkey integration.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * All GTM4WP unit tests extend this class. Brain Monkey provides WordPress
 * function stubs (add_action, apply_filters, esc_*, ...) and hook assertions.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Sets up Brain Monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Defined for every test, so a first mock elsewhere cannot change which
		// branch production code takes (TS-16); a test may re-stub it.
		Monkey\Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Same reason: EDD's tax switch decides whether a price is converted (#519).
		Monkey\Functions\when( 'edd_use_taxes' )->justReturn( false );
		// And whether a refund line's original order item is found (#417).
		Monkey\Functions\when( 'edd_get_order_item' )->justReturn( false );
	}

	/**
	 * Tears down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
