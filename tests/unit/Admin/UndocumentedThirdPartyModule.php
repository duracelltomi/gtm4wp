<?php
/**
 * Test fixture: a third party module carrying an undocumented admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\ModuleInterface;
use GTM4WP\Options\Options;

/**
 * Stands in for a module a third party registers through the
 * 'gtm4wp_register_modules' action.
 */
final class UndocumentedThirdPartyModule implements ModuleInterface {

	public function id(): string {
		return 'third-party';
	}

	public function defaults(): array {
		return array( 'third-party-option' => false );
	}

	public function is_available(): bool {
		return true;
	}

	public function frontend( Options $options ): void {
	}

	public function admin_schema(): string {
		return UndocumentedThirdPartySchema::class;
	}
}
