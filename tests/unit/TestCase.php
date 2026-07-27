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
