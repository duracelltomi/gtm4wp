<?php
/**
 * Test fixture: a reporting schema that returns one row that is not a row.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Options\Options;

/**
 * Its rows include a scalar and a null where a row array belongs - the shape
 * a buggy third party would hand the collector, which must not reach
 * WordPress' Info tab.
 */
final class MalformedReportingSchema implements AdminSchemaInterface, SiteHealthInfoInterface {

	public function title(): string {
		return 'Malformed';
	}

	public function intro(): string {
		return '';
	}

	public function groups(): array {
		return array( 'general' => 'General' );
	}

	public function fields(): array {
		return array();
	}

	public function unavailable_message(): string {
		return '';
	}

	public function site_health_info( Options $options ): array {
		return array(
			'ok'    => array(
				'label' => 'Fine',
				'value' => 'yes',
			),
			'oops'  => 'not a row',
			'oops2' => null,
		);
	}
}
