<?php
/**
 * Shared base for frontend service tests.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Functions;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Provides common WordPress stubs and an Options factory for frontend tests.
 */
abstract class FrontendTestCase extends TestCase {

	/**
	 * Default option values covering everything the frontend core reads.
	 *
	 * @var array<string, mixed>
	 */
	protected const OPTION_DEFAULTS = array(
		GTM4WP_OPTION_GTM_CONTAINERS                     => array(),
		GTM4WP_OPTION_GTM_CODE                           => '',
		GTM4WP_OPTION_DATALAYER_NAME                     => '',
		GTM4WP_OPTION_GTM_PLACEMENT                      => GTM4WP_PLACEMENT_FOOTER,
		GTM4WP_OPTION_ENV_GTM_AUTH                       => '',
		GTM4WP_OPTION_ENV_GTM_PREVIEW                    => '',
		GTM4WP_OPTION_LOADEARLY                          => false,
		GTM4WP_OPTION_GTMDOMAIN                          => '',
		GTM4WP_OPTION_GTMCUSTOMPATH                      => '',
		GTM4WP_OPTION_NOGTMFORLOGGEDIN                   => '',
		GTM4WP_OPTION_NOCONSOLELOG                       => false,
		GTM4WP_OPTION_PRODUCTIONONLY                     => false,
		GTM4WP_OPTION_INTEGRATE_COOKIEBOT                => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE              => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS          => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO     => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS    => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO        => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC         => false,
		GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY     => false,
		GTM4WP_OPTION_BLACKLIST_STATUS                   => '',
	);

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) {
				return $content;
			}
		);

		Functions\when( 'current_theme_supports' )->justReturn( true );

		unset(
			$GLOBALS['gtm4wp_container_code_written'],
			$GLOBALS['gtm4wp_datalayer_data'],
			$GLOBALS['gtm4wp_additional_datalayer_pushes']
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['gtm4wp_container_code_written'],
			$GLOBALS['gtm4wp_datalayer_data'],
			$GLOBALS['gtm4wp_additional_datalayer_pushes']
		);

		parent::tearDown();
	}

	/**
	 * Builds an Options instance with the given overrides.
	 *
	 * @param array<string, mixed> $overrides Option values overriding the defaults.
	 * @return Options
	 */
	protected function make_options( array $overrides = array() ): Options {
		Functions\when( 'get_option' )->justReturn( $overrides );

		return new Options( self::OPTION_DEFAULTS );
	}
}
