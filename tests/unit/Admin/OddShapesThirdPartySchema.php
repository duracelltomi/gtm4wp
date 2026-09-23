<?php
/**
 * Test fixture: an admin schema whose Site Health tests have odd shapes.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\SiteHealthTestsInterface;
use GTM4WP\Options\Options;

/**
 * The shapes the collector's wrapper and id derivation must absorb (T113): a
 * callable returning a scalar, one returning only a label, a key core could
 * not select, and a description built from every block boundary the ability's
 * plain-text form must turn into a line break (T114).
 */
final class OddShapesThirdPartySchema implements AdminSchemaInterface, SiteHealthTestsInterface {

	public function title(): string {
		return 'Acme odd';
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

	public function site_health_tests( Options $options ): array {
		return array(
			'scalar'     => static fn () => 'nope',
			'label-only' => static fn (): array => array( 'label' => 'Only a label' ),
			'Odd.Key v2' => static fn (): array => array(
				'status'      => 'recommended',
				'label'       => 'Odd key',
				'description' => '<p>a</p>b<br>c<br><ul><li>d</li><li>e</li></ul><div>f</div>',
			),
		);
	}
}
