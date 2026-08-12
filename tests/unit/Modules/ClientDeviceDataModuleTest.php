<?php
/**
 * Unit tests for the ClientDeviceData module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\ClientDeviceData\ClientDeviceDataModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers enqueue_scripts(): the client-device config is emitted as an inline
 * script attached to the tracker handle.
 *
 * Note on the hex-flag sink (finding #11): the JSON here encodes only the three
 * boolean flags (browser/os/device), so the value can never carry a `<`/`"`/`&`
 * break-out character — a hostile-input regression is not applicable (the flags
 * are defense-in-depth uniformity). This test pins the emitted structure and the
 * `wp_add_inline_script` (not raw-echo) delivery path instead.
 */
final class ClientDeviceDataModuleTest extends TestCase {

	/**
	 * Concatenated code of every wp_add_inline_script() call.
	 *
	 * @var array<int, array{handle: string, code: string, position: string}>
	 */
	private array $inline_scripts = array();

	protected function setUp(): void {
		parent::setUp();

		$this->inline_scripts = array();

		Functions\when( 'plugins_url' )->alias( static fn ( $path, $plugin ) => 'https://example.com/' . $path ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- mock matches the real plugins_url() signature
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_scripts[] = array(
					'handle'   => $handle,
					'code'     => $code,
					'position' => $position,
				);
				return true;
			}
		);
	}

	/**
	 * Boots a ClientDeviceData module with the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 */
	private function make_module( array $stored ): ClientDeviceDataModule {
		Functions\when( 'get_option' )->justReturn( $stored );

		$module = new ClientDeviceDataModule();
		$module->frontend( new Options( $module->defaults() ) );

		return $module;
	}

	public function test_enqueue_scripts_emits_config_inline_before_the_handle(): void {
		$module = $this->make_module(
			array(
				GTM4WP_OPTION_INCLUDE_BROWSERDATA => true,
				GTM4WP_OPTION_INCLUDE_OSDATA      => false,
				GTM4WP_OPTION_INCLUDE_DEVICEDATA  => true,
			)
		);

		$module->enqueue_scripts();

		$this->assertCount( 1, $this->inline_scripts );
		$inline = $this->inline_scripts[0];

		$this->assertSame( 'gtm4wp-client-device-data', $inline['handle'] );
		$this->assertSame( 'before', $inline['position'] );
		$this->assertStringContainsString(
			'var gtm4wp_clientdevice_config = {"browser":true,"os":false,"device":true};',
			$inline['code']
		);
	}
}
