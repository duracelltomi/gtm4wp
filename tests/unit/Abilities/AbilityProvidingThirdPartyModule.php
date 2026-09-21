<?php
/**
 * Test fixture: a third party module whose schema registers abilities.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Module\ModuleInterface;
use GTM4WP\Options\Options;

/**
 * Stands in for a module a third party registers through the
 * 'gtm4wp_register_modules' action. Not final: a test derives a variant
 * naming a schema class that does not exist.
 */
class AbilityProvidingThirdPartyModule implements ModuleInterface {

	public function id(): string {
		return 'acme';
	}

	public function defaults(): array {
		return array( 'acme-option' => true );
	}

	public function is_available(): bool {
		return true;
	}

	public function frontend( Options $options ): void {
	}

	public function admin_schema(): string {
		return AbilityProvidingThirdPartySchema::class;
	}
}
