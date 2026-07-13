<?php
/**
 * Client device data module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ClientDeviceData;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes browser, operating system and device data into the data layer.
 *
 * Replaces the server side WhichBrowser library of 1.x with a small
 * client side script based on User-Agent Client Hints. Behavior change
 * versus 1.x: the values arrive as a gtm4wp.deviceData data layer event
 * shortly after page load instead of being part of the initial data layer
 * object, and browsers without Client Hints support (Safari, Firefox)
 * expose less detail through the fallback detection.
 */
final class ClientDeviceDataModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'client-device-data';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_INCLUDE_BROWSERDATA => false,
			GTM4WP_OPTION_INCLUDE_OSDATA      => false,
			GTM4WP_OPTION_INCLUDE_DEVICEDATA  => false,
		);
	}

	/**
	 * Registers the frontend hooks.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if (
			! $this->opt( GTM4WP_OPTION_INCLUDE_BROWSERDATA ) &&
			! $this->opt( GTM4WP_OPTION_INCLUDE_OSDATA ) &&
			! $this->opt( GTM4WP_OPTION_INCLUDE_DEVICEDATA )
		) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}

	/**
	 * Loads the client side device detection script with its configuration.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$this->enqueue_script( 'gtm4wp-client-device-data', 'gtm4wp-client-device-data.js' );

		$config = array(
			'browser' => (bool) $this->opt( GTM4WP_OPTION_INCLUDE_BROWSERDATA ),
			'os'      => (bool) $this->opt( GTM4WP_OPTION_INCLUDE_OSDATA ),
			'device'  => (bool) $this->opt( GTM4WP_OPTION_INCLUDE_DEVICEDATA ),
		);

		wp_add_inline_script(
			'gtm4wp-client-device-data',
			'var gtm4wp_clientdevice_config = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
			'before'
		);
	}
}
