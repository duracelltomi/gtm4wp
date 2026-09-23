<?php
/**
 * Test fixture: a third-party module whose schema adds Site Health tests.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\ModuleInterface;
use GTM4WP\Options\Options;

/**
 * Registered by SiteHealthTestsTest; its id carries a hyphen on purpose so the
 * collector's id derivation is exercised.
 */
final class TestingThirdPartyModule implements ModuleInterface {

	public function id(): string {
		return 'acme-testing';
	}

	public function defaults(): array {
		return array( 'acme-testing-option' => true );
	}

	public function is_available(): bool {
		return true;
	}

	public function frontend( Options $options ): void {
	}

	public function admin_schema(): string {
		return TestingThirdPartySchema::class;
	}
}
