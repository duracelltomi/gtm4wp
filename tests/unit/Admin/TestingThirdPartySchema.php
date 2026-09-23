<?php
/**
 * Test fixture: an admin schema that opts into Site Health tests.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\SiteHealthTestsInterface;
use GTM4WP\Options\Options;

/**
 * Three entries: a well-formed test whose description carries raw HTML, a
 * non-callable that must be dropped, and one that sets its own badge and a
 * test id of its own so the collector's precedence rule is observable.
 */
final class TestingThirdPartySchema implements AdminSchemaInterface, SiteHealthTestsInterface {

	/**
	 * The options the last site_health_tests() call received.
	 *
	 * @var Options|null
	 */
	public static ?Options $received = null;

	public function title(): string {
		return 'Acme testing';
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
		self::$received = $options;

		return array(
			'checks-out' => static fn (): array => array(
				'status'      => 'recommended',
				'label'       => 'Acme could be better',
				// Raw HTML on purpose: core prints a description and its
				// actions unescaped, so the collector must not escape them.
				'description' => '<p>Ties & "Shirts" <b>bold</b></p>',
				'actions'     => '<p><a href="https://example.com/?a=1&b=2">Fix & go</a></p>',
			),
			'junk'       => 'not a callable',
			'overrides'  => static fn (): array => array(
				'status' => 'critical',
				'badge'  => array(
					'label' => 'Acme',
					'color' => 'red',
				),
				'test'   => 'acme_own_id',
			),
		);
	}
}
