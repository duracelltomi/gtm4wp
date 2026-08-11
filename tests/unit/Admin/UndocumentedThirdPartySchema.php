<?php
/**
 * Test fixture: an admin schema written before DocumentedSchemaInterface existed.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

/**
 * Implements AdminSchemaInterface and nothing else, which is exactly the shape
 * of a third party schema registered through 'gtm4wp_register_modules' against
 * an earlier build of the plugin.
 *
 * A named class rather than an anonymous one because ModuleInterface::admin_schema()
 * returns a class NAME that the settings page instantiates, and an anonymous
 * class cannot be constructed from its name.
 */
final class UndocumentedThirdPartySchema implements AdminSchemaInterface {

	public function title(): string {
		return 'Third party';
	}

	public function intro(): string {
		return '';
	}

	public function groups(): array {
		return array( 'general' => 'General' );
	}

	public function fields(): array {
		return array(
			new Field(
				key: 'third-party-option',
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: 'Third party option',
				group: 'general'
			),
		);
	}

	public function unavailable_message(): string {
		return '';
	}
}
