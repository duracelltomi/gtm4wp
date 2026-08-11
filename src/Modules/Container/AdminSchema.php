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
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the container module. Labels and descriptions are
 * ported from the 1.x General and Advanced admin tabs.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * Documentation page of this module on gtm4wp.com.
	 */
	private const DOC_PAGE = 'setup-gtm4wp-features/container-settings-reference';

	/**
	 * The excluded roles option predates the reference page and has a guide of
	 * its own, which answers more than a reference entry could.
	 */
	private const DOC_EXCLUDE_ROLES = 'setup-gtm4wp-features/how-to-exclude-admin-users-from-being-tracked';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_PAGE;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Google Tag Manager container', 'duracelltomi-google-tag-manager' );
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
			'general'  => __( 'General', 'duracelltomi-google-tag-manager' ),
			'advanced' => __( 'Advanced', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		// Build the list of user roles as checkboxes, as in 1.x. wp_roles()
		// lives in wp-includes so it is available both on the settings page
		// and during REST saves; the guard keeps unit tests (which never load
		// WordPress) working with an empty choice list.
		$role_choices = array();
		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->get_names() as $role_slug => $role_name ) {
				$role_choices[ $role_slug ] = translate_user_role( $role_name );
			}
		}

		// Every column a valid GTM4WP_HARDCODED_* constant takes over is rendered
		// read-only, and the row set is frozen when the constants also decide
		// which containers are loaded. Without this the screen would show - and
		// happily save - a container setup the frontend silently overrides.
		$locks   = HardcodedContainers::locks();
		$columns = array(
			array(
				'key'         => ContainerRows::COLUMN_ID,
				'label'       => __( 'Container ID', 'duracelltomi-google-tag-manager' ),
				'placeholder' => 'GTM-XXXXXX',
			),
			array(
				'key'         => ContainerRows::COLUMN_AUTH,
				'label'       => __( 'Environment gtm_auth', 'duracelltomi-google-tag-manager' ),
				'placeholder' => '',
			),
			array(
				'key'         => ContainerRows::COLUMN_PREVIEW,
				'label'       => __( 'Environment gtm_preview', 'duracelltomi-google-tag-manager' ),
				'placeholder' => 'env-NN',
			),
			array(
				'key'         => ContainerRows::COLUMN_DOMAIN,
				'label'       => __( 'Custom domain', 'duracelltomi-google-tag-manager' ),
				'placeholder' => 'www.googletagmanager.com',
			),
			array(
				'key'         => ContainerRows::COLUMN_PATH,
				'label'       => __( 'Custom path', 'duracelltomi-google-tag-manager' ),
				'placeholder' => 'gtm.js',
			),
			array(
				'key'        => ContainerRows::COLUMN_NO_ID,
				'label'      => __( 'Omit container ID', 'duracelltomi-google-tag-manager' ),
				'type'       => 'checkbox',
				'depends_on' => ContainerRows::COLUMN_PATH,
			),
		);

		foreach ( $columns as $index => $column ) {
			if ( isset( $locks['columns'][ $column['key'] ] ) ) {
				$columns[ $index ]['readonly'] = true;
			}
		}

		return array(
			new Field(
				key: GTM4WP_OPTION_GTM_CONTAINERS,
				type: Field::TYPE_TABLE,
				default_value: array(),
				label: __( 'Google Tag Manager containers', 'duracelltomi-google-tag-manager' ),
				description: $this->containers_description( $locks ),
				group: 'general',
				columns: $columns,
				sanitizer: static function ( $value ) {
					if ( ! is_array( $value ) ) {
						$value = array();
					}

					$rows     = array();
					$seen_ids = array();

					foreach ( $value as $raw_row ) {
						if ( ! is_array( $raw_row ) ) {
							continue;
						}

						$row = ContainerRows::normalize_row( $raw_row );

						// Rows with every cell empty are dropped silently.
						if ( '' === implode( '', $row ) ) {
							continue;
						}

						$one_gtm_id = $row[ ContainerRows::COLUMN_ID ];

						if ( ! preg_match( ContainerRows::GTM_ID_PATTERN, $one_gtm_id ) ) {
							return new \WP_Error(
								'gtm4wp_invalid_gtm_id',
								sprintf(
									/* translators: %s: the invalid container ID as entered by the user. */
									__( 'Invalid or missing Google Tag Manager ID in one of the container rows: "%s". Valid ID format: GTM-XXXXX.', 'duracelltomi-google-tag-manager' ),
									$one_gtm_id
								)
							);
						}

						if ( isset( $seen_ids[ $one_gtm_id ] ) ) {
							return new \WP_Error(
								'gtm4wp_duplicate_gtm_id',
								sprintf(
									/* translators: %s: the duplicated container ID. */
									__( 'The Google Tag Manager ID "%s" is listed more than once. Every container ID can only be entered in one row.', 'duracelltomi-google-tag-manager' ),
									$one_gtm_id
								)
							);
						}
						$seen_ids[ $one_gtm_id ] = true;

						if ( ( '' !== $row[ ContainerRows::COLUMN_AUTH ] ) && ( ! preg_match( ContainerRows::AUTH_PATTERN, $row[ ContainerRows::COLUMN_AUTH ] ) ) ) {
							return new \WP_Error(
								'gtm4wp_invalid_gtm_auth',
								sprintf(
									/* translators: %s: the container ID of the row with the invalid value. */
									__( "Invalid gtm_auth environment parameter value in the row of container %s. It should only contain letters, numbers or the '-' and '_' characters.", 'duracelltomi-google-tag-manager' ),
									$one_gtm_id
								)
							);
						}

						if ( ( '' !== $row[ ContainerRows::COLUMN_PREVIEW ] ) && ( ! preg_match( ContainerRows::PREVIEW_PATTERN, $row[ ContainerRows::COLUMN_PREVIEW ] ) ) ) {
							return new \WP_Error(
								'gtm4wp_invalid_gtm_preview',
								sprintf(
									/* translators: %s: the container ID of the row with the invalid value. */
									__( "Invalid gtm_preview environment parameter value in the row of container %s. It should have the format 'env-NN' where NN is an integer number.", 'duracelltomi-google-tag-manager' ),
									$one_gtm_id
								)
							);
						}

						// Remove https:// prefix if used.
						$domain = str_replace( 'https://', '', $row[ ContainerRows::COLUMN_DOMAIN ] );
						$domain = trim( $domain, "/ \n\r\t\v\x00" );
						if ( '' !== $domain ) {
							$domain = filter_var( $domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );

							if ( false === $domain ) {
								return new \WP_Error(
									'gtm4wp_invalid_gtm_domain',
									sprintf(
										/* translators: %s: the container ID of the row with the invalid value. */
										__( 'Invalid custom domain name in the row of container %s. Enter a valid domain name without the https:// prefix.', 'duracelltomi-google-tag-manager' ),
										$one_gtm_id
									)
								);
							}
						}
						$row[ ContainerRows::COLUMN_DOMAIN ] = (string) $domain;

						$path = ltrim( $row[ ContainerRows::COLUMN_PATH ], '/' );
						if ( ! preg_match( ContainerRows::PATH_PATTERN, $path ) ) {
							return new \WP_Error(
								'gtm4wp_invalid_custom_path',
								sprintf(
									/* translators: %s: the container ID of the row with the invalid value. */
									__( 'Invalid custom domain path in the row of container %s. Value can include anything between a-z, A-Z, 0-9 or any of the characters . - _ /', 'duracelltomi-google-tag-manager' ),
									$one_gtm_id
								)
							);
						}
						$row[ ContainerRows::COLUMN_PATH ] = $path;

						// The "omit container ID" flag is stored as '1' / '' and
						// only kept when a custom path is present: it has no
						// meaning for the default www.googletagmanager.com loader.
						$omit_id                            = ( '' !== $row[ ContainerRows::COLUMN_NO_ID ] ) && ( '0' !== $row[ ContainerRows::COLUMN_NO_ID ] );
						$row[ ContainerRows::COLUMN_NO_ID ] = ( $omit_id && ( '' !== $path ) ) ? '1' : '';

						$rows[] = $row;
					}

					return $rows;
				},
				derive: static fn ( $rows ) => ContainerRows::legacy_values( is_array( $rows ) ? $rows : array() ),
				rows_locked: array() !== $locks['rows'],
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_GTM_PLACEMENT,
				type: Field::TYPE_SELECT,
				default_value: (string) GTM4WP_PLACEMENT_FOOTER,
				label: __( 'Container code placement', 'duracelltomi-google-tag-manager' ),
				description: wp_kses(
					__(
						'Decides where to put the second, so called <code>&lt;noscript&gt;</code> part of the GTM container code. The main GTM container code will always be placed into the <code>&lt;head&gt;</code> section of your webpages.<br />
						If you select "Manually coded", add the following code just after the opening &lt;body&gt; tag in your template:<br />
						<code>&lt;?php if ( function_exists( \'gtm4wp_the_gtm_tag\' ) ) { gtm4wp_the_gtm_tag(); } ?&gt;</code><br />
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
					(string) GTM4WP_PLACEMENT_FOOTER   => __( 'Footer of the page (compatible with all WP themes, no Search Console verification)', 'duracelltomi-google-tag-manager' ),
					(string) GTM4WP_PLACEMENT_BODYOPEN => __( 'Manually coded after the opening body tag', 'duracelltomi-google-tag-manager' ),
					(string) GTM4WP_PLACEMENT_BODYOPEN_AUTO => __( 'Automatically after the opening body tag (theme must support wp_body_open)', 'duracelltomi-google-tag-manager' ),
					(string) GTM4WP_PLACEMENT_OFF      => __( 'Off - container code turned off, data layer only', 'duracelltomi-google-tag-manager' ),
				),
				sanitizer: static function ( $value ) {
					$value = (int) $value;

					if ( ( $value < GTM4WP_PLACEMENT_FOOTER ) || ( $value > GTM4WP_PLACEMENT_OFF ) ) {
						return GTM4WP_PLACEMENT_FOOTER;
					}

					return $value;
				},
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_DATALAYER_NAME,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'dataLayer variable name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'In some cases you need to rename the dataLayer variable. You can enter your name here. Leave blank for default name: dataLayer', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				sanitizer: static function ( $value ) {
					// Field::to_string() keeps the cast warning-free on non-scalar
					// import values (a custom sanitizer replaces the type-defensive
					// default in Field::sanitize(), it does not run in front of it).
					$value = trim( Field::to_string( $value ) );

					// The reader's own predicate, not a second copy of the rule
					// (PA-2). The name is emitted UNQUOTED into a <script> body,
					// so this allow-list has to be the JavaScript identifier
					// grammar - no escaper can rescue a bare identifier. The 1.x
					// rule this replaces admitted '-', which JavaScript reads as
					// the subtraction operator: the settings screen accepted a
					// name that made every GTM4WP script block a SyntaxError.
					if ( ( '' !== $value ) && ! ContainerRows::is_valid_js_identifier( $value ) ) {
						return new \WP_Error(
							'gtm4wp_invalid_datalayer_name',
							__( "Invalid dataLayer variable name. It has to be a valid JavaScript variable name: start with a letter, '_' or '\$', followed by letters, digits, '_' or '\$'. A hyphen is not allowed.", 'duracelltomi-google-tag-manager' )
						);
					}

					return $value;
				},
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_LOADEARLY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Load GTM container as early as possible', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Turning on this option will load your Google Tag Manager container as early as possible during page load. This can cause issues if you are using jQuery in your custom HTML tags that fire on \'Page View\' events.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_NOCONSOLELOG,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Do not use console.log() messages on frontend', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'GTM4WP puts several useful messages into the console of your browser which can also help give proper support in some cases. If you see any issues regarding this functionality, you can disable it here.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_PRODUCTIONONLY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Only output the container on production environments', 'duracelltomi-google-tag-manager' ),
				description: $this->production_only_description(),
				group: 'advanced',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_NOGTMFORLOGGEDIN,
				type: Field::TYPE_MULTISELECT,
				default_value: '',
				label: __( 'User roles to exclude', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Do not load the GTM container on the frontend when the logged in user has any of the checked roles.', 'duracelltomi-google-tag-manager' ),
				group: 'advanced',
				choices: $role_choices,
				sanitizer: static function ( $value ) {
					// The admin UI submits an array of role ids; stored as comma separated string as in 1.x.
					// Field::to_string() per element: a crafted import can nest arrays
					// inside the list, and sanitize_key() warns on a non-string.
					if ( is_array( $value ) ) {
						return implode( ',', array_map( static fn ( $one ) => sanitize_key( Field::to_string( $one ) ), $value ) );
					}

					return implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', Field::to_string( $value ) ) ) ) );
				},
				doc: self::DOC_EXCLUDE_ROLES
			),
		);
	}

	/**
	 * Description of the container table.
	 *
	 * The static part is followed by a live readout whenever a GTM4WP_HARDCODED_*
	 * constant is in effect: a wp-config.php file is invisible from the admin, so
	 * a read-only control on its own would leave the admin wondering why the
	 * table cannot be edited. The readout names every constant that is actually
	 * being applied (a malformed one overrides nothing and is reported by the
	 * admin notice instead), and promises what the save route enforces - the
	 * stored container setup is kept and used again once the constants are gone.
	 *
	 * Rendered as HTML in the settings app (FieldControl's help slot, an
	 * innerHTML sink - PA-13); the constant names are class constants of
	 * HardcodedContainers, never user input.
	 *
	 * @param array{columns: array<string, string>, rows: string[]} $locks Lock report of HardcodedContainers::locks().
	 * @return string
	 */
	private function containers_description( array $locks ): string {
		$intro = wp_kses(
			__(
				'Add one row for each Google Tag Manager container you want to load.<br />
				The environment parameters (gtm_auth and gtm_preview) activate a specific container environment; both values are required to activate an environment, leave both empty to load the live version.<br />
				Enter a custom domain name (without the https:// prefix) and a custom path if you are using a server side GTM container for tracking. Leave them empty to use www.googletagmanager.com and gtm.js.<br />
				When a custom path is set you can also turn on "Omit container ID" so that the container ID is left out of the loader URL - use this when your server side GTM container is selected by its path and expects no id parameter.',
				'duracelltomi-google-tag-manager'
			),
			array(
				'br' => array(),
			)
		);

		if ( array() === $locks['columns'] ) {
			return $intro;
		}

		$constants = array_values( array_unique( array_merge( array_values( $locks['columns'] ), $locks['rows'] ) ) );

		$readout = sprintf(
			/* translators: %s: comma separated list of wp-config.php constant names, e.g. GTM4WP_HARDCODED_GTM_ID. */
			esc_html__( 'Part of this setting is fixed in your wp-config.php file by %s and cannot be changed here.', 'duracelltomi-google-tag-manager' ),
			esc_html( implode( ', ', $constants ) )
		);

		if ( array() !== $locks['rows'] ) {
			$readout .= ' ' . esc_html__( 'Those constants also decide which containers are loaded, so the whole table is read-only and shows the containers that are actually running. The container list you saved here is kept untouched and is used again as soon as the constants are removed from wp-config.php.', 'duracelltomi-google-tag-manager' );
		} else {
			$readout .= ' ' . esc_html__( 'The affected columns are read-only and show the values that are actually used. The values you saved for them are kept untouched and are used again as soon as the constants are removed from wp-config.php.', 'duracelltomi-google-tag-manager' );
		}

		if ( in_array( HardcodedContainers::CONSTANT_AUTH, $locks['rows'], true ) ) {
			$readout .= ' ' . esc_html__( 'Both environment parameters are hard coded, therefore only the first container is loaded.', 'duracelltomi-google-tag-manager' );
		}

		return $intro . '<br /><strong>' . $readout . '</strong>';
	}

	/**
	 * Description of the "only output on production environments" option.
	 *
	 * The effect of this option depends entirely on WP_ENVIRONMENT_TYPE, which
	 * lives in wp-config.php / the server config and is invisible from the
	 * admin - so the static explanation is followed by a live readout of what
	 * THIS instance actually reports, and what turning the option on would do
	 * here. The common trap is a staging copy with no WP_ENVIRONMENT_TYPE set:
	 * WordPress then reports "production" and the container keeps loading.
	 *
	 * The readout is rendered as HTML in the settings app (FieldControl's help
	 * slot, an innerHTML sink - PA-13), so the environment value is escaped even
	 * though wp_get_environment_type() can only return one of the four values
	 * whitelisted by WordPress core.
	 *
	 * @return string
	 */
	private function production_only_description(): string {
		$intro = esc_html__( 'When turned on, the GTM container code is only output when WordPress reports the environment type as "production". On any other environment (local, development, staging) the container is suppressed while the data layer stays active - so a cloned or staging copy of your site does not send hits to your live Google Tag Manager container without deactivating the plugin. This relies on the WP_ENVIRONMENT_TYPE constant (or WP_ENVIRONMENT_TYPE environment variable) being set on non-production copies; it defaults to "production" when unset. For host-based control without an option, return false from the gtm4wp_output_container filter.', 'duracelltomi-google-tag-manager' );

		// Resolved exactly as ContainerCode::should_output_container() does, so
		// the readout can never disagree with the gate it describes.
		// wp_get_environment_type() ships with WordPress 5.5+; the guard mirrors
		// that sibling and falls back to core's own default.
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		// Whether the value was set at all, looked up the same way core does.
		// An unset WP_ENVIRONMENT_TYPE silently resolves to "production", which
		// is worth calling out separately from an explicit "production".
		$is_configured = defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' );

		if ( 'production' !== $environment ) {
			$effect = sprintf(
				/* translators: %s: the environment type reported by WordPress, e.g. "staging". */
				esc_html__( 'On this site WordPress currently reports the environment type "%s", so with this option turned on the GTM container is NOT loaded here (the data layer stays active).', 'duracelltomi-google-tag-manager' ),
				esc_html( $environment )
			);
		} elseif ( $is_configured ) {
			$effect = sprintf(
				/* translators: %s: the environment type reported by WordPress, always "production" here. */
				esc_html__( 'On this site WordPress currently reports the environment type "%s", so with this option turned on the GTM container is still loaded here.', 'duracelltomi-google-tag-manager' ),
				esc_html( $environment )
			);
		} else {
			$effect = esc_html__( 'On this site WP_ENVIRONMENT_TYPE is not set, so WordPress falls back to the environment type "production" and the GTM container is still loaded here even with this option turned on. Set WP_ENVIRONMENT_TYPE to "staging", "development" or "local" on your non-production copies for this option to take effect there.', 'duracelltomi-google-tag-manager' );
		}

		return $intro . '<br /><strong>' . $effect . '</strong>';
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
