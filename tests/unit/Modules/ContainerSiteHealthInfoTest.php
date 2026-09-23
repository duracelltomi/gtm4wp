<?php
/**
 * Unit tests for the Container module's Site Health rows.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\Container\ContainerModule;
use GTM4WP\Modules\Container\ContainerRows;

/**
 * The rows are StatusReport's facts; the property pinned here is that the
 * environment tokens (gtm_auth, gtm_preview) never leave as values.
 */
final class ContainerSiteHealthInfoTest extends ModuleSiteHealthTestCase {

	public function test_a_default_site_reports_no_container_and_the_defaults(): void {
		$rows = $this->rows( new ContainerModule() );

		$this->assertSame(
			array( 'containers', 'placement', 'container_code_output', 'datalayer_name', 'hardcoded', 'hardcoded_errors', 'options', 'disabled_for_roles' ),
			array_keys( $rows )
		);
		$this->assertSame( 'none', $rows['containers']['debug'] );
		$this->assertSame( 'footer', $rows['placement']['debug'] );
		$this->assertSame( 'yes', $rows['container_code_output']['debug'] );
		$this->assertSame( 'dataLayer', $rows['datalayer_name']['debug'] );
		$this->assertSame( 'off', $rows['hardcoded']['debug'] );
		$this->assertSame( 'none', $rows['hardcoded_errors']['debug'] );
		$this->assertSame( 'off', $rows['options']['debug'][ GTM4WP_OPTION_LOADEARLY ] );
		$this->assertSame( 'none', $rows['disabled_for_roles']['debug'] );
	}

	public function test_a_container_is_listed_by_id_with_its_environment_as_a_boolean(): void {
		$rows = $this->rows(
			new ContainerModule(),
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID      => 'GTM-ABC123',
						ContainerRows::COLUMN_AUTH    => 'auth-token-value',
						ContainerRows::COLUMN_PREVIEW => 'env-3',
						ContainerRows::COLUMN_DOMAIN  => 'gtm.example.com',
					),
					array( ContainerRows::COLUMN_ID => 'GTM-DEF456' ),
				),
			)
		);

		$this->assertSame(
			array(
				'GTM-ABC123' => 'environment: yes, domain: gtm.example.com, path: -, ID omitted: no',
				'GTM-DEF456' => 'environment: no, domain: -, path: -, ID omitted: no',
			),
			$rows['containers']['debug']
		);
		$this->assertStringNotContainsString( 'auth-token-value', $this->text( $rows ) );
		$this->assertStringNotContainsString( 'env-3', $this->text( $rows ) );
	}

	public function test_placement_off_and_an_invalid_data_layer_name_are_reported(): void {
		$rows = $this->rows(
			new ContainerModule(),
			array(
				GTM4WP_OPTION_GTM_PLACEMENT    => GTM4WP_PLACEMENT_OFF,
				GTM4WP_OPTION_DATALAYER_NAME   => 'my-layer',
				GTM4WP_OPTION_NOGTMFORLOGGEDIN => 'administrator,editor',
			)
		);

		$this->assertSame( 'off', $rows['placement']['debug'] );
		$this->assertSame( 'no', $rows['container_code_output']['debug'] );
		$this->assertSame( 'configured "my-layer", using "dataLayer" (invalid)', $rows['datalayer_name']['debug'] );
		$this->assertSame( 'administrator, editor', $rows['disabled_for_roles']['debug'] );
	}
}
