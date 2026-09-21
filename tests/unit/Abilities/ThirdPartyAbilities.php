<?php
/**
 * Test fixture: the ability provider of a third party module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Abilities\Meta;
use GTM4WP\Abilities\ProviderInterface;
use GTM4WP\Abilities\Registrar;
use GTM4WP\Capability;

/**
 * Registers one read the way a third party would: its own namespace half,
 * the plugin's category. Counts its register() calls so the registry walk
 * can be shown to reach it exactly once.
 */
final class ThirdPartyAbilities implements ProviderInterface {

	public const GET_WIDGETS = 'acme/get-widgets';

	/**
	 * How many times register() ran since the test reset it.
	 *
	 * @var int
	 */
	public static int $registered = 0;

	public function register(): void {
		++self::$registered;

		wp_register_ability(
			self::GET_WIDGETS,
			array(
				'label'               => 'Get widgets',
				'description'         => 'Lists the widgets.',
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn () => array( 'widgets' => array() ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);
	}
}
