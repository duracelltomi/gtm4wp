<?php
/**
 * Unit tests for the module registry.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Module;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use GTM4WP\Module\ModuleInterface;
use GTM4WP\Module\Registry;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Registry behavior: registration, default merging, frontend gating and
 * the third party extension point.
 */
final class RegistryTest extends TestCase {

	/**
	 * Builds a minimal module test double.
	 *
	 * @param string               $id        Module id.
	 * @param array<string, mixed> $defaults  Module option defaults.
	 * @param bool                 $available Whether the module reports itself available.
	 * @return ModuleInterface
	 */
	private function make_module( string $id, array $defaults = array(), bool $available = true ): ModuleInterface {
		return new class( $id, $defaults, $available ) implements ModuleInterface {
			/**
			 * Number of frontend() invocations, readable by the test.
			 *
			 * @var int
			 */
			public int $frontend_calls = 0;

			/**
			 * Constructor.
			 *
			 * @param string $module_id Module id.
			 * @param array  $defaults  Module option defaults.
			 * @param bool   $available Availability flag.
			 */
			public function __construct(
				private string $module_id,
				private array $module_defaults,
				private bool $available
			) {
			}

			public function id(): string {
				return $this->module_id;
			}

			public function defaults(): array {
				return $this->module_defaults;
			}

			public function is_available(): bool {
				return $this->available;
			}

			public function frontend( Options $options ): void {
				++$this->frontend_calls;
			}

			public function admin_schema(): string {
				return 'GTM4WP\\Tests\\NonExistentSchema';
			}
		};
	}

	public function test_add_and_get_modules(): void {
		$registry = new Registry();
		$module   = $this->make_module( 'container' );

		$registry->add( $module );

		$this->assertSame( $module, $registry->get( 'container' ) );
		$this->assertSame( array( 'container' => $module ), $registry->all() );
		$this->assertNull( $registry->get( 'unknown' ) );
	}

	public function test_defaults_are_merged_across_modules(): void {
		$registry = new Registry();
		$registry->add( $this->make_module( 'a', array( 'opt-a' => 1 ) ) );
		$registry->add( $this->make_module( 'b', array( 'opt-b' => true ) ) );

		$this->assertSame(
			array(
				'opt-a' => 1,
				'opt-b' => true,
			),
			$registry->defaults()
		);
	}

	public function test_frontend_boots_only_available_modules(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$available   = $this->make_module( 'on', array(), true );
		$unavailable = $this->make_module( 'off', array(), false );

		$registry = new Registry();
		$registry->add( $available );
		$registry->add( $unavailable );

		$registry->frontend( new Options( array() ) );

		$this->assertSame( 1, $available->frontend_calls );
		$this->assertSame( 0, $unavailable->frontend_calls );
	}

	public function test_module_with_same_id_replaces_earlier_registration(): void {
		$first  = $this->make_module( 'dup', array( 'x' => 1 ) );
		$second = $this->make_module( 'dup', array( 'x' => 2 ) );

		$registry = new Registry();
		$registry->add( $first );
		$registry->add( $second );

		$this->assertSame( $second, $registry->get( 'dup' ) );
		$this->assertCount( 1, $registry->all() );
	}

	public function test_with_default_modules_fires_extension_action(): void {
		Actions\expectDone( 'gtm4wp_register_modules' )
			->once()
			->with( \Mockery::type( Registry::class ) );

		Registry::with_default_modules();
	}
}
