<?php
/**
 * Test fixture: an admin schema that opts into the Site Health Info section.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Options\Field;
use GTM4WP\Options\Options;

/**
 * Reports one option state and one list, under module-local keys, so the
 * collector's prefixing and pass-through can be asserted on something that
 * is not the Data Manager.
 */
final class ReportingThirdPartySchema implements AdminSchemaInterface, SiteHealthInfoInterface {

	/**
	 * The Options instance the last site_health_info() call received.
	 *
	 * @var Options|null
	 */
	public static ?Options $received = null;

	public function title(): string {
		return 'Reporting';
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
				key: 'reporting-option',
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: 'Reporting option',
				group: 'general'
			),
		);
	}

	public function unavailable_message(): string {
		return '';
	}

	public function site_health_info( Options $options ): array {
		self::$received = $options;

		return array(
			'status' => array(
				'label' => 'Reporting',
				'value' => $options->get( 'reporting-option' ) ? 'on' : 'off',
			),
			'items'  => array(
				'label' => 'Items',
				'value' => array( 'one', 'two' ),
			),
			// Special characters on purpose: core's Site Health screen runs
			// esc_html() over every label and value, so the collector must
			// hand them over RAW - a pre-escape here would show as `&amp;amp;`.
			'raw'    => array(
				'label' => 'A & "B" <C>',
				'value' => "Ties & Shirts <b>\"quoted\"</b> 'apos'",
			),
		);
	}
}
