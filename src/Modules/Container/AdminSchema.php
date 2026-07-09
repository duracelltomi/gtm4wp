<?php
/**
 * Container module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Container;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the container module. Labels and descriptions are
 * ported from the 1.x General and Advanced admin tabs.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return esc_html__( 'Google Tag Manager container', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return sprintf(
			/* translators: 1: opening anchor tag linking to GTM's developer doc homepage. 2: Closing anchor tag. */
			esc_html__(
				'This plugin is intended to be used by IT and marketing staff. Please be sure you read the %1$sGoogle Tag Manager Help Center%2$s before you start using this plugin.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="https://developers.google.com/tag-manager/" target="_blank" rel="noopener">',
			'</a>'
		);
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'general'     => esc_html__( 'General', 'duracelltomi-google-tag-manager' ),
			'environment' => esc_html__( 'Container environment', 'duracelltomi-google-tag-manager' ),
			'advanced'    => esc_html__( 'Advanced', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		return array(
			new Field(
				key: GTM4WP_OPTION_GTM_CODE,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'Google Tag Manager ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter your Google Tag Manager ID here. Use comma without space (,) to enter multiple IDs.', 'duracelltomi-google-tag-manager' ),
				group: 'general',
				sanitizer: static function ( $value ) {
					$value = trim( (string) $value );

					if ( '' === $value ) {
						return '';
					}

					foreach ( explode( ',', $value ) as $one_gtm_id ) {
						if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id ) ) {
							return new \WP_Error(
								'gtm4wp_invalid_gtm_id',
								esc_html__( 'Invalid Google Tag Manager ID. Valid ID format: GTM-XXXXX. Use comma without additional space (,) to enter more than one container ID.', 'duracelltomi-google-tag-manager' )
							);
						}
					}

					return $value;
				}
			),
			new Field(
				key: GTM4WP_OPTION_GTM_PLACEMENT,
				type: Field::TYPE_SELECT,
				default_value: (string) GTM4WP_PLACEMENT_FOOTER,
				label: esc_html__( 'Container code placement', 'duracelltomi-google-tag-manager' ),
				description: wp_kses(
					__(
						'Decides where to put the second, so called <code>&lt;noscript&gt;</code> part of the GTM container code. The main GTM container code will always be placed into the <code>&lt;head&gt;</code> section of your webpages.<br />
						If you select "Manually coded", add <code>&lt;?php if ( function_exists( \'gtm4wp_the_gtm_tag\' ) ) { gtm4wp_the_gtm_tag(); } ?&gt;</code> just after the opening &lt;body&gt; tag in your template.<br />
						Selecting "Off" removes both parts of the container code but leaves data layer codes working.',
						'duracelltomi-google-tag-manager'
					),
					array(
						'code' => array(),
						'br'   => array(),
					)
				),
				group: 'general',
				choices: array(
					(string) GTM4WP_PLACEMENT_FOOTER        => esc_html__( 'Footer of the page (no visitor loss, no Search Console verification)', 'duracelltomi-google-tag-manager' ),
					(string) GTM4WP_PLACEMENT_BODYOPEN      => esc_html__( 'Manually coded after the opening body tag', 'duracelltomi-google-tag-manager' ),
					(string) GTM4WP_PLACEMENT_BODYOPEN_AUTO => esc_html__( 'Automatically after the opening body tag (theme must support wp_body_open)', 'duracelltomi-google-tag-manager' ),
					(string) GTM4WP_PLACEMENT_OFF           => esc_html__( 'Off - container code turned off, data layer only', 'duracelltomi-google-tag-manager' ),
				),
				sanitizer: static function ( $value ) {
					$value = (int) $value;

					if ( ( $value < GTM4WP_PLACEMENT_FOOTER ) || ( $value > GTM4WP_PLACEMENT_OFF ) ) {
						return GTM4WP_PLACEMENT_FOOTER;
					}

					return $value;
				}
			),
			new Field(
				key: GTM4WP_OPTION_ENV_GTM_AUTH,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'Environment gtm_auth parameter', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter the gtm_auth parameter of the Google Tag Manager environment that has to be activated on this site. Both gtm_auth and gtm_preview parameters are required to activate the desired environment.', 'duracelltomi-google-tag-manager' ),
				group: 'environment',
				sanitizer: static function ( $value ) {
					$value = trim( (string) $value );

					if ( ( '' !== $value ) && ( ! preg_match( '/^[a-zA-Z0-9-_]+$/', $value ) ) ) {
						return new \WP_Error(
							'gtm4wp_invalid_gtm_auth',
							esc_html__( "Invalid gtm_auth environment parameter value. It should only contain letters, numbers or the '-' and '_' characters.", 'duracelltomi-google-tag-manager' )
						);
					}

					return $value;
				}
			),
			new Field(
				key: GTM4WP_OPTION_ENV_GTM_PREVIEW,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'Environment gtm_preview parameter', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter the gtm_preview parameter of the Google Tag Manager environment that has to be activated on this site. Both gtm_auth and gtm_preview parameters are required to activate the desired environment.', 'duracelltomi-google-tag-manager' ),
				group: 'environment',
				sanitizer: static function ( $value ) {
					$value = trim( (string) $value );

					if ( ( '' !== $value ) && ( ! preg_match( '/^env-[0-9]+$/', $value ) ) ) {
						return new \WP_Error(
							'gtm4wp_invalid_gtm_preview',
							esc_html__( "Invalid gtm_preview environment parameter value. It should have the format 'env-NN' where NN is an integer number.", 'duracelltomi-google-tag-manager' )
						);
					}

					return $value;
				}
			),
			new Field(
				key: GTM4WP_OPTION_DATALAYER_NAME,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'dataLayer variable name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'In some cases you need to rename the dataLayer variable. You can enter your name here. Leave blank for default name: dataLayer', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				sanitizer: static function ( $value ) {
					$value = trim( (string) $value );

					if ( ( '' !== $value ) && ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/', $value ) ) ) {
						return new \WP_Error(
							'gtm4wp_invalid_datalayer_name',
							esc_html__( "Invalid dataLayer variable name. Please start with a character from a-z or A-Z followed by characters from a-z, A-Z, 0-9 or '_' or '-'!", 'duracelltomi-google-tag-manager' )
						);
					}

					return $value;
				}
			),
			new Field(
				key: GTM4WP_OPTION_LOADEARLY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Load GTM container as early as possible', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Turning on this option will load your Google Tag Manager container as early as possible during page load. This can cause issues if you are using jQuery in your custom HTML tags that fire on \'Page View\' events.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced'
			),
			new Field(
				key: GTM4WP_OPTION_NOCONSOLELOG,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Do not use console.log() messages on frontend', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'GTM4WP puts several useful messages into the console of your browser which can also help give proper support in some cases. If you see any issues regarding this functionality, you can disable it here.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced'
			),
			new Field(
				key: GTM4WP_OPTION_GTMDOMAIN,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'Container domain name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter your custom domain name if you are using a server side GTM container for tracking. Do not include https:// prefix. Leave this blank to use www.googletagmanager.com', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				sanitizer: static function ( $value ) {
					// Remove https:// prefix if used.
					$value = str_replace( 'https://', '', (string) $value );

					$value = filter_var( $value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
					if ( false === $value ) {
						$value = '';
					}

					return trim( (string) $value );
				}
			),
			new Field(
				key: GTM4WP_OPTION_GTMCUSTOMPATH,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'Container custom path', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Enter a custom path for your custom domain name if it is not gtm.js. Do not include the / prefix. Leave this blank to use gtm.js', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				sanitizer: static function ( $value ) {
					$value = trim( (string) $value, "\n\r\t\v\x00" );

					if ( ! preg_match( '/^[a-zA-Z0-9\.\-\_\/]*$/', $value ) ) {
						return new \WP_Error(
							'gtm4wp_invalid_custom_path',
							esc_html__( 'Invalid GTM custom domain path. Value can include anything between a-z, A-Z, 0-9 or any of the characters . - _', 'duracelltomi-google-tag-manager' )
						);
					}

					return $value;
				}
			),
			new Field(
				key: GTM4WP_OPTION_NOGTMFORLOGGEDIN,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'User roles to exclude', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Do not load GTM container on the frontend if role of the logged in user is any of this. Enter a comma separated list of role IDs (e.g. administrator,editor).', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				sanitizer: static function ( $value ) {
					// The admin UI submits an array of role ids; stored as comma separated string as in 1.x.
					if ( is_array( $value ) ) {
						return implode( ',', array_map( 'sanitize_key', $value ) );
					}

					return implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', (string) $value ) ) ) );
				}
			),
		);
	}

	/**
	 * The container module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
