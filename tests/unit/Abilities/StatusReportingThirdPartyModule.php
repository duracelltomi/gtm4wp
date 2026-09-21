<?php
/**
 * Test fixture: a third party module whose schema reports into the plugin status.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Module\ModuleInterface;
use GTM4WP\Options\Options;

/**
 * Stands in for a module a third party registers through the
 * 'gtm4wp_register_modules' action, with a schema that opts into
 * StatusInfoInterface.
 */
final class StatusReportingThirdPartyModule implements ModuleInterface {

	public function id(): string {
		return 'acme-status';
	}

	public function defaults(): array {
		return array( 'acme-status-switch' => false );
	}

	public function is_available(): bool {
		return true;
	}

	public function frontend( Options $options ): void {
	}

	public function admin_schema(): string {
		return StatusReportingThirdPartySchema::class;
	}
}
