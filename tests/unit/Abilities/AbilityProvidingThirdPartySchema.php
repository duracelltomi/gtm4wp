<?php
/**
 * Test fixture: an admin schema that opts into the abilities surface.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Abilities\ProviderInterface;
use GTM4WP\Module\AbilitiesInterface;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

/**
 * The counterpart of Admin\UndocumentedThirdPartySchema: the same shape, plus
 * AbilitiesInterface. Counts how often the Registrar asked for the provider.
 */
final class AbilityProvidingThirdPartySchema implements AdminSchemaInterface, AbilitiesInterface {

	/**
	 * How many times abilities() ran since the test reset it.
	 *
	 * @var int
	 */
	public static int $handed_over = 0;

	public function title(): string {
		return 'Acme';
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
				key: 'acme-option',
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: 'Acme option',
				group: 'general'
			),
		);
	}

	public function unavailable_message(): string {
		return '';
	}

	public function abilities(): ProviderInterface {
		++self::$handed_over;

		return new ThirdPartyAbilities();
	}
}
