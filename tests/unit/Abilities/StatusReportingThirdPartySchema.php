<?php
/**
 * Test fixture: an admin schema that reports into the plugin status.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Module\StatusInfoInterface;
use GTM4WP\Options\Field;
use GTM4WP\Options\Options;

/**
 * The counterpart of Admin\UndocumentedThirdPartySchema: the same shape, plus
 * StatusInfoInterface. Reports a master switch read from the options it is
 * given and a host plugin, so the status ability's walk and pass-through can
 * be asserted on something that is not a built-in module. Also reports two
 * Site Health rows, one of them `private` - the row contract of
 * SiteHealthInfoInterface that no built-in module uses, so the get-site-health
 * ability's exclusion of it is observable (T99).
 */
final class StatusReportingThirdPartySchema implements AdminSchemaInterface, StatusInfoInterface, SiteHealthInfoInterface {

	/**
	 * The value of the private row: must never reach a transcript.
	 */
	public const PRIVATE_VALUE = 'ACME-PRIVATE-ROW-VALUE';

	/**
	 * The value of the public row: must.
	 */
	public const PUBLIC_VALUE = 'ACME-PUBLIC-ROW-VALUE';

	/**
	 * The Options instance the last status_info() call received.
	 *
	 * @var Options|null
	 */
	public static ?Options $received = null;

	public function title(): string {
		return 'Acme status';
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
				key: 'acme-status-switch',
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: 'Acme switch',
				group: 'general'
			),
		);
	}

	public function unavailable_message(): string {
		return '';
	}

	public function status_info( Options $options ): array {
		self::$received = $options;

		return array(
			'enabled'     => (bool) $options->get( 'acme-status-switch' ),
			'integration' => array(
				'active'  => true,
				'version' => '9.9.0',
			),
		);
	}

	public function site_health_info( Options $options ): array {
		return array(
			'public'  => array(
				'label' => 'Acme public',
				'value' => self::PUBLIC_VALUE,
			),
			'private' => array(
				'label'   => 'Acme private',
				'value'   => self::PRIVATE_VALUE,
				'private' => true,
			),
		);
	}
}
