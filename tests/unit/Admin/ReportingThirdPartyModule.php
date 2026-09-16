<?php
/**
 * Test fixture: a third party module whose schema reports into Site Health.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\ModuleInterface;
use GTM4WP\Options\Options;

/**
 * The counterpart of UndocumentedThirdPartyModule: same registration path,
 * but its schema opts into SiteHealthInfoInterface.
 */
class ReportingThirdPartyModule implements ModuleInterface {

	public function id(): string {
		return 'reporting';
	}

	public function defaults(): array {
		return array( 'reporting-option' => true );
	}

	public function is_available(): bool {
		return true;
	}

	public function frontend( Options $options ): void {
	}

	public function admin_schema(): string {
		return ReportingThirdPartySchema::class;
	}
}
