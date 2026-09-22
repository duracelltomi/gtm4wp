<?php
/**
 * Test fixture: an admin schema whose status facts have the wrong types.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\StatusInfoInterface;
use GTM4WP\Options\Options;

/**
 * Opts into StatusInfoInterface and answers with a string where a bool is
 * promised and a string where an array is - what a third party gets wrong
 * first. The status ability's row must degrade to "unknown" (null), never
 * carry the junk into a transcript or into the output schema's types.
 */
final class JunkStatusThirdPartySchema implements AdminSchemaInterface, StatusInfoInterface {

	public function title(): string {
		return 'Acme junk';
	}

	public function intro(): string {
		return '';
	}

	public function groups(): array {
		return array();
	}

	public function fields(): array {
		return array();
	}

	public function unavailable_message(): string {
		return '';
	}

	public function status_info( Options $options ): array {
		return array(
			'enabled'     => 'yes',
			'integration' => 'WooCommerce 9.9.0',
		);
	}
}
