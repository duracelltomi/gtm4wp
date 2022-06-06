<?php
/**
 * Handle WordPress admin page related hooks and functions
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

define( 'GTM4WP_ADMINSLUG', 'gtm4wp-settings' );
define( 'GTM4WP_ADMIN_GROUP', 'gtm4wp-admin-group' );

define( 'GTM4WP_ADMIN_GROUP_GENERAL', 'gtm4wp-admin-group-general' );
define( 'GTM4WP_ADMIN_GROUP_GTMID', 'gtm4wp-admin-group-gtm-id' );
define( 'GTM4WP_ADMIN_GROUP_CONTAINERON', 'gtm4wp-admin-container-on' );
define( 'GTM4WP_ADMIN_GROUP_COMPATMODE', 'gtm4wp-admin-compat-mode' );
define( 'GTM4WP_ADMIN_GROUP_INFO', 'gtm4wp-admin-group-datalayer-info' );

define( 'GTM4WP_ADMIN_GROUP_INCLUDES', 'gtm4wp-admin-group-includes' );
define( 'GTM4WP_ADMIN_GROUP_EVENTS', 'gtm4wp-admin-group-events' );
define( 'GTM4WP_ADMIN_GROUP_SCROLLER', 'gtm4wp-admin-group-scroller' );
define( 'GTM4WP_ADMIN_GROUP_BLACKLIST', 'gtm4wp-admin-group-blacklist-tags' );
define( 'GTM4WP_ADMIN_GROUP_INTEGRATION', 'gtm4wp-admin-group-integration' );
define( 'GTM4WP_ADMIN_GROUP_ADVANCED', 'gtm4wp-admin-group-advanced' );
define( 'GTM4WP_ADMIN_GROUP_CREDITS', 'gtm4wp-admin-group-credits' );

define( 'GTM4WP_USER_NOTICES_KEY', 'gtm4wp_user_notices_dismisses_json' );

define( 'GTM4WP_PHASE_STABLE', 'gtm4wp-phase-stable' );
define( 'GTM4WP_PHASE_BETA', 'gtm4wp-phase-beta' );
define( 'GTM4WP_PHASE_EXPERIMENTAL', 'gtm4wp-phase-experimental' );
define( 'GTM4WP_PHASE_DEPRECATED', 'gtm4wp-phase-deprecated' );

$GLOBALS['gtm4wp_def_user_notices_dismisses'] = array(
	'enter-gtm-code'            => false,
	'wc-ga-plugin-warning'      => false,
	'wc-gayoast-plugin-warning' => false,
	'php72-warning'             => false,
	'deprecated-warning'        => false,
);

/**
 * Generic function to safely escape translated text that outputs on the admin page.
 * Allows only basic HTML tags for formatting purposes. No anchor element is allowed.
 *
 * @param string $text The admin text that needs escaping.
 * @return string The escaped text.
 */
function gtm4wp_safe_admin_html( $text ) {
	return wp_kses(
		$text,
		array(
			'br'     => array(),
			'strong' => array(
				'style' => array(),
				'class' => array(),
			),
			'em'     => array(
				'style' => array(),
				'class' => array(),
			),
			'p'      => array(
				'style' => array(),
				'class' => array(),
			),
			'span'   => array(
				'style' => array(),
				'class' => array(),
			),
			'code'   => array(),
			'ul'     => array(
				'style' => array(),
				'class' => array(),
			),
			'li'     => array(
				'style' => array(),
				'class' => array(),
			),
		)
	);
}

/**
 * Generic function to safely escape text that outputs on the admin page.
 * Works just like gtm4wp_safe_admin_html() but also allows anchor elements.
 *
 * @param string $text The admin text that needs escaping.
 * @return string The escaped text.
 */
function gtm4wp_safe_admin_html_with_links( $text ) {
	return wp_kses(
		$text,
		array(
			'br'     => array(),
			'strong' => array(
				'style' => array(),
				'class' => array(),
			),
			'em'     => array(
				'style' => array(),
				'class' => array(),
			),
			'p'      => array(
				'style' => array(),
				'class' => array(),
			),
			'span'   => array(
				'style' => array(),
				'class' => array(),
			),
			'code'   => array(),
			'ul'     => array(
				'style' => array(),
				'class' => array(),
			),
			'li'     => array(
				'style' => array(),
				'class' => array(),
			),
			'a'      => array(
				'id'     => array(),
				'name'   => array(),
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		)
	);
}

require_once dirname( __FILE__ ) . '/admin-tab-basicdata.php';
require_once dirname( __FILE__ ) . '/admin-tab-events.php';
require_once dirname( __FILE__ ) . '/admin-tab-scrolltracking.php';
require_once dirname( __FILE__ ) . '/admin-tab-integrate.php';
require_once dirname( __FILE__ ) . '/admin-tab-advanced.php';

/**
 * Callback function for add_settings_section(). Outputs the HTML of an admin tab.
 *
 * @see https://developer.wordpress.org/reference/functions/add_settings_section/
 *
 * @param array $args array of tab attributes.
 * @return void
 */
function gtm4wp_admin_output_section( $args ) {
	echo '<span class="tabinfo">';

	switch ( $args['id'] ) {
		case GTM4WP_ADMIN_GROUP_GENERAL:
			sprintf(
				// translators: 1: opening anchor tag linking to GTM's developer doc homepage. 2: Closing anchor tag.
				esc_html__(
					'This plugin is intended to be used by IT and marketing staff. Please be sure you read the
					%1$sGoogle Tag Manager Help Center%2$s before you start using this plugin.<br /><br />',
					'duracelltomi-google-tag-manager'
				),
				'<a href="https://developers.google.com/tag-manager/" target="_blank" rel="noopener">',
				'</a>'
			);

			break;

		case GTM4WP_ADMIN_GROUP_INCLUDES:
			esc_html_e( 'Here you can check what data is needed to be included in the dataLayer to be able to access them in Google Tag Manager', 'duracelltomi-google-tag-manager' );
			echo '<br />';
			printf(
				/* translators: 1: opening anchor tag that points to WhichBrowser website. 2: closing anchor tag. */
				esc_html__(
					'* Browser, OS and Device data is provided using %1$sWhichBrowser%2$s library.',
					'duracelltomi-google-tag-manager'
				),
				'<a href="http://whichbrowser.net/" target="_blank" rel="noopener">',
				'</a>'
			);

			break;

		case GTM4WP_ADMIN_GROUP_EVENTS:
			esc_html_e( 'Fire tags in Google Tag Manager on special events on your website', 'duracelltomi-google-tag-manager' );

			break;

		case GTM4WP_ADMIN_GROUP_SCROLLER:
			esc_html_e( 'Fire tags based on how the visitor scrolls through your page.', 'duracelltomi-google-tag-manager' );
			echo '<br />';
			printf(
				/* translators: 1: opening anchor tag that points to the corresponding Analytics Talks blog post. 2: closing anchor tag. */
				esc_html__(
					'Based on the script originaly posted to %1$sAnalytics Talk%2$s',
					'duracelltomi-google-tag-manager'
				),
				'<a href="http://cutroni.com/blog/2012/02/21/advanced-content-tracking-with-google-analytics-part-1/" target="_blank" rel="noopener">',
				'</a>'
			);

			break;

		case GTM4WP_ADMIN_GROUP_BLACKLIST:
			esc_html_e( 'Here you can control which types of tags, triggers and variables can be executed on your site regardless of what tags are included in your container on the Google Tag Manager site. Use this to increase security!', 'duracelltomi-google-tag-manager' );
			echo '<br />';
			esc_html_e( 'Do not modify if you do not know what to do, since it can cause issues with your tag deployment!', 'duracelltomi-google-tag-manager' );
			echo '<br />';
			esc_html_e( 'For example blacklisting everything and only whitelisting the Google Analytics tag without whitelisting the URL variable type will cause your Google Analytics tags to be blocked anyway since the attached triggers (Page View) can not fire!', 'duracelltomi-google-tag-manager' );

			break;

		case GTM4WP_ADMIN_GROUP_INTEGRATION:
			esc_html_e( 'Google Tag Manager for WordPress can integrate with several popular plugins. Please check the plugins you would like to integrate with:', 'duracelltomi-google-tag-manager' );

			break;

		case GTM4WP_ADMIN_GROUP_ADVANCED:
			esc_html_e( 'You usually do not need to modify thoose settings. Please be carefull while hacking here.', 'duracelltomi-google-tag-manager' );

			break;

		case GTM4WP_ADMIN_GROUP_CREDITS:
			esc_html_e( 'Some info about the author of this plugin', 'duracelltomi-google-tag-manager' );

			break;
	} // end switch

	echo '</span>';
}

/**
 * Callback function for add_settings_field() to output the HTML of a specific plugin option
 *
 * @see https://developer.wordpress.org/reference/functions/add_settings_field/
 *
 * @param array $args Field attributes as array key-value pairs. 'label_for' is the unique ID of the option. 'description' is usually outputed below the option field.
 * @return void
 */
function gtm4wp_admin_output_field( $args ) {
	global $gtm4wp_options, $gtm4wp_business_verticals;

	switch ( $args['label_for'] ) {
		case GTM4WP_ADMIN_GROUP_GTMID:
			echo wp_kses(
				sprintf(
					'<input type="text" id="%s" name="%s" value="%s"%s />',
					esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_GTM_CODE . ']' ),
					esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_GTM_CODE . ']' ),
					defined( 'GTM4WP_HARDCODED_GTM_ID' ) ? constant( 'GTM4WP_HARDCODED_GTM_ID' ) : $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ],
					defined( 'GTM4WP_HARDCODED_GTM_ID' ) ? ' readonly="readonly"' : ''
				),
				array(
					'input' => array(
						'type'     => array(),
						'id'       => array(),
						'name'     => array(),
						'value'    => array(),
						'readonly' => array(),
					),
				)
			);
			echo '<br />';

			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

			if ( defined( 'GTM4WP_HARDCODED_GTM_ID' ) ) {
				echo '<br /><span class="gtm_wpconfig_set">WARNING! Container ID was set and fixed in wp-config.php. If you wish to change this value, please edit your wp-config.php and change the container ID or remove the GTM4WP_HARDCODED_GTM_ID constant!</span>';
			}
			echo '<br /><span class="gtmid_validation_error">' . esc_html__( 'This does not seems to be a valid Google Tag Manager ID! Valid format: GTM-XXXXX where X can be numbers and capital letters. Use comma without any space (,) to enter multpile container IDs.', 'duracelltomi-google-tag-manager' ) . '</span>';

			break;

		case GTM4WP_ADMIN_GROUP_CONTAINERON:
			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore
			echo '<br/><br/>';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[container-on]_1' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[container-on]' ) . '" value="1" ' . ( GTM4WP_PLACEMENT_OFF !== $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'On', 'duracelltomi-google-tag-manager' ) . '<br />';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[container-on]_0' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[container-on]' ) . '" value="0" ' . ( GTM4WP_PLACEMENT_OFF === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Off', 'duracelltomi-google-tag-manager' ) . '<br />';

			break;

		case GTM4WP_ADMIN_GROUP_COMPATMODE:
			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore
			echo '<br/><br/>';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[compat-mode]_' . GTM4WP_PLACEMENT_BODYOPEN_AUTO ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[compat-mode]' ) . '" value="' . esc_attr( GTM4WP_PLACEMENT_BODYOPEN_AUTO ) . '" ' . ( GTM4WP_PLACEMENT_BODYOPEN_AUTO === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] || GTM4WP_PLACEMENT_OFF === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Off (no tweak, right placement)', 'duracelltomi-google-tag-manager' ) . '<br />';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[compat-mode]_' . GTM4WP_PLACEMENT_FOOTER ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[compat-mode]' ) . '" value="' . esc_attr( GTM4WP_PLACEMENT_FOOTER ) . '" ' . ( GTM4WP_PLACEMENT_FOOTER === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Footer of the page (not recommended by Google, Search Console verification will not work)', 'duracelltomi-google-tag-manager' ) . '<br />';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[compat-mode]_' . GTM4WP_PLACEMENT_BODYOPEN ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[compat-mode]' ) . '" value="' . esc_attr( GTM4WP_PLACEMENT_BODYOPEN ) . '" ' . ( GTM4WP_PLACEMENT_BODYOPEN === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Manually coded (needs tweak in your template)', 'duracelltomi-google-tag-manager' ) . '<br />';

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_DATALAYER_NAME . ']':
			echo '<input type="text" id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_DATALAYER_NAME . ']' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_DATALAYER_NAME . ']' ) . '" value="' . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_DATALAYER_NAME ] ) . '" /><br />';
			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore
			echo '<br /><span class="datalayername_validation_error">' . esc_html__( 'This does not seems to be a valid JavaScript variable name! Please check and try again', 'duracelltomi-google-tag-manager' ) . '</span>';

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_AUTH . ']':
			echo wp_kses(
				sprintf(
					'<input type="text" id="%s" name="%s" value="%s"%s />',
					esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_AUTH . ']' ),
					esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_AUTH . ']' ),
					defined( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ) ? constant( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ) : $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ],
					defined( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ) ? ' readonly="readonly"' : ''
				),
				array(
					'input' => array(
						'type'     => array(),
						'id'       => array(),
						'name'     => array(),
						'value'    => array(),
						'readonly' => array(),
					),
				)
			);

			echo '<br />';

			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

			if ( defined( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ) ) {
				echo '<br /><span class="gtm_wpconfig_set">WARNING! Environment auth parameter was set and fixed in wp-config.php. If you wish to change this value, please edit your wp-config.php and change the parameter value or remove the GTM4WP_HARDCODED_GTM_ENV_AUTH constant!</span>';
			}
			echo '<br /><span class="gtmauth_validation_error">' . esc_html__( 'This does not seems to be a valid gtm_auth parameter! It should only contain letters, number and the &quot;-&quot; character. Please check and try again', 'duracelltomi-google-tag-manager' ) . '</span>';

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_PREVIEW . ']':
			echo wp_kses(
				sprintf(
					'<input type="text" id="%s" name="%s" value="%s"%s />',
					esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_PREVIEW . ']' ),
					esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_PREVIEW . ']' ),
					defined( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ) ? constant( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ) : $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ],
					defined( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ) ? ' readonly="readonly"' : ''
				),
				array(
					'input' => array(
						'type'     => array(),
						'id'       => array(),
						'name'     => array(),
						'value'    => array(),
						'readonly' => array(),
					),
				)
			);

			echo '<br />';

			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

			if ( defined( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ) ) {
				echo '<br /><span class="gtm_wpconfig_set">WARNING! Environment preview parameter was set and fixed in wp-config.php. If you wish to change this value, please edit your wp-config.php and change the parameter value or remove the GTM4WP_HARDCODED_GTM_ENV_PREVIEW constant!</span>';
			}

			echo '<br /><span class="gtmpreview_validation_error">' . esc_html__( 'This does not seems to be a valid gtm_preview parameter! It should have the format &quot;env-NN&quot; where NN is an integer number. Please check and try again', 'duracelltomi-google-tag-manager' ) . '</span>';

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']':
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']_0' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']' ) . '" value="0" ' . ( 0 === $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_ENABLE ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Disable feature: control everything on Google Tag Manager interface', 'duracelltomi-google-tag-manager' ) . '<br />';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']_1' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']' ) . '" value="1" ' . ( 1 === $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_ENABLE ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Allow all, except the checked items on all blacklist tabs (blacklist)', 'duracelltomi-google-tag-manager' ) . '<br />';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']_2' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']' ) . '" value="2" ' . ( 2 === $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_ENABLE ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Block all, except the checked items on all blacklist tabs (whitelist)', 'duracelltomi-google-tag-manager' ) . '<br />';
			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INCLUDE_WEATHERUNITS . ']':
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INCLUDE_WEATHERUNITS . ']_0' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INCLUDE_WEATHERUNITS . ']' ) . '" value="0" ' . ( 0 === $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHERUNITS ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Celsius', 'duracelltomi-google-tag-manager' ) . '<br />';
			echo '<input type="radio" id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INCLUDE_WEATHERUNITS . ']_1' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INCLUDE_WEATHERUNITS . ']' ) . '" value="1" ' . ( 1 === $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHERUNITS ] ? 'checked="checked"' : '' ) . '/> ' . esc_html__( 'Fahrenheit', 'duracelltomi-google-tag-manager' ) . '<br />';
			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

			break;

		case GTM4WP_ADMIN_GROUP_INFO:
			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY . ']':
			echo '<select id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY . ']' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY . ']' ) . '">';
			echo '<option value="">(not set)</option>';

			$gtm4wp_taxonomies = get_taxonomies(
				array(
					'show_ui'  => true,
					'public'   => true,
					'_builtin' => false,
				),
				'object',
				'and'
			);

			foreach ( $gtm4wp_taxonomies as $onetaxonomy ) {
				echo '<option value="' . esc_attr( $onetaxonomy->name ) . '"' . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY ] === $onetaxonomy->name ? ' selected="selected"' : '' ) . '>' . esc_html( $onetaxonomy->label ) . '</option>';
			}

			echo '</select>';

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL . ']':
			echo '<select id="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL . ']' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL . ']' ) . '">';

			foreach ( $gtm4wp_business_verticals as $vertical_id => $vertical_display_name ) {
				echo '<option value="' . esc_attr( $vertical_id ) . '"' . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ] === $vertical_id ? ' selected="selected"' : '' ) . '>' . esc_html( $vertical_display_name ) . '</option>';
			}

			echo '</select><br>';

			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

			break;

		case GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_NOGTMFORLOGGEDIN . ']':
			$roles = get_editable_roles();

			// gtm4wp_safe_admin_html_with_links() calls wp_kses().
			echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore
			echo '<br/><br/>';

			$saved_roles = explode( ',', $gtm4wp_options[ GTM4WP_OPTION_NOGTMFORLOGGEDIN ] );

			foreach ( $roles as $role_id => $role_info ) {
				$role_name = translate_user_role( $role_info['name'] );
				echo '<input type="checkbox" id="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']_' . $role_id ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . '][]' ) . '" value="' . esc_attr( $role_id ) . '"' . esc_attr( in_array( $role_id, $saved_roles, true ) ? ' checked="checked"' : '' ) . '><label for="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']_' . $role_id ) . '">' . esc_html( $role_name ) . '</label><br/>';
			}

			break;

		default:
			if ( preg_match( '/' . GTM4WP_OPTIONS . '\\[blacklist\\-[^\\]]+\\]/i', $args['label_for'] ) ) {
				if ( 'blacklist-sandboxed' === $args['entityid'] ) {
					echo '<input type="checkbox" id="' . esc_attr( $args['label_for'] ) . '" name="' . esc_attr( $args['label_for'] ) . '" value="1" ' . checked( 1, $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_SANDBOXED ], false ) . ' /><br />';
				} else {
					echo '<input type="checkbox" id="' . esc_attr( $args['label_for'] ) . '" name="' . esc_attr( $args['label_for'] ) . '" value="1" ' . checked( 1, in_array( $args['entityid'], $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_STATUS ], true ), false ) . ' /><br />';
				}

				// gtm4wp_safe_admin_html_with_links() calls wp_kses().
				echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore
			} else {
				$optval = $gtm4wp_options[ $args['optionfieldid'] ];

				switch ( gettype( $optval ) ) {
					case 'boolean':
						echo '<input type="checkbox" id="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']' ) . '" value="1" ' . checked( 1, $optval, false ) . ' /><br />';

						break;

					case 'integer':
						echo '<input type="number" step="1" min="0" class="small-text" id="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']' ) . '" value="' . esc_attr( $optval ) . '" /><br />';

						break;

					default:
						echo '<input type="text" id="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']' ) . '" name="' . esc_attr( GTM4WP_OPTIONS . '[' . $args['optionfieldid'] . ']' ) . '" value="' . esc_attr( $optval ) . '" size="80" /><br />';
				} // end switch gettype optval

				// gtm4wp_safe_admin_html_with_links() calls wp_kses().
				echo gtm4wp_safe_admin_html_with_links( $args['description'] ); // phpcs:ignore

				if ( isset( $args['plugintocheck'] ) && ( '' !== $args['plugintocheck'] ) ) {
					if ( is_plugin_active( $args['plugintocheck'] ) ) {
						echo '<br />' . sprintf(
							// translators: 1: the name of the conflicting plugin being checked. 2: either 'active' or 'inactive' using bolded formatting.
							esc_html__(
								'This plugin (%1$s) is %2$s, it is strongly recommended to enable this integration!',
								'duracelltomi-google-tag-manager'
							),
							esc_html( $args['plugintocheck'] ),
							'<strong class="gtm4wp-plugin-active">active</strong>'
						);
					} else {
						echo '<br />' . sprintf(
							// translators: 1: the name of the conflicting plugin being checked. 2: either 'active' or 'inactive' using bolded formatting.
							esc_html__(
								'This plugin (%1$s) is %2$s, enabling this integration could cause issues on frontend!',
								'duracelltomi-google-tag-manager'
							),
							esc_html( $args['plugintocheck'] ),
							'<strong class="gtm4wp-plugin-not-active">not active</strong>'
						);
					}
				}
			}
	} // end switch args label_for
}

/**
 * Callback function for register_setting(). Sanitizes GTM4WP option values.
 *
 * @see https://developer.wordpress.org/reference/functions/register_setting/
 *
 * @param array $options Array of key-value pairs with GTM4WP options and values.
 * @return mixed The sanitized option value.
 */
function gtm4wp_sanitize_options( $options ) {
	global $wpdb, $gtm4wp_entity_ids;

	$output = gtm4wp_reload_options();

	foreach ( $output as $optionname => $optionvalue ) {
		if ( isset( $options[ $optionname ] ) ) {
			$newoptionvalue = $options[ $optionname ];
		} else {
			$newoptionvalue = '';
		}

		if ( 'include-' === substr( $optionname, 0, 8 ) ) {
			// "include" settings.
			$output[ $optionname ] = (bool) $newoptionvalue;

		} elseif ( 'event-' === substr( $optionname, 0, 6 ) ) {
			// dataLayer events.
			$output[ $optionname ] = (bool) $newoptionvalue;

			// clear oembed transients when feature is enabled because we need to hook into the oembed process to enable some 3rd party APIs.
			if ( $output[ $optionname ] && ! $optionvalue ) {
				if ( GTM4WP_OPTION_EVENTS_YOUTUBE === $optionname ) {
					// TODO: replace with $wpdb->delete() https://developer.wordpress.org/reference/classes/wpdb/delete/.
					$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_value LIKE '%youtube.com%' AND meta_key LIKE '_oembed_%'" ); // phpcs:ignore
				}

				if ( GTM4WP_OPTION_EVENTS_VIMEO === $optionname ) {
					// TODO: replace with $wpdb->delete() https://developer.wordpress.org/reference/classes/wpdb/delete/.
					$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_value LIKE '%vimeo.com%' AND meta_key LIKE '_oembed_%'" ); // phpcs:ignore
				}
			}
		} elseif ( 'blacklist-' === substr( $optionname, 0, 10 ) ) {
			// blacklist / whitelist entities.
			if ( GTM4WP_OPTION_BLACKLIST_ENABLE === $optionname ) {
				$output[ $optionname ] = (int) $options[ GTM4WP_OPTION_BLACKLIST_ENABLE ];
			} elseif ( GTM4WP_OPTION_BLACKLIST_SANDBOXED === $optionname ) {
				$output[ $optionname ] = (bool) $newoptionvalue;
			} elseif ( GTM4WP_OPTION_BLACKLIST_STATUS === $optionname ) {
				$selected_blacklist_entities = array();

				foreach ( $gtm4wp_entity_ids as $gtm_entity_group_id => $gtm_entity_group_list ) {
					foreach ( $gtm_entity_group_list as $gtm_entity_id => $gtm_entity_label ) {
						$entity_option_id = 'blacklist-' . $gtm_entity_group_id . '-' . $gtm_entity_id;
						if ( array_key_exists( $entity_option_id, $options ) ) {
							$newoptionvalue = (bool) $options[ $entity_option_id ];
							if ( $newoptionvalue ) {
								$selected_blacklist_entities[] = $gtm_entity_id;
							}
						}
					}
				}

				$output[ $optionname ] = implode( ',', $selected_blacklist_entities );
			}
		} elseif ( GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS === $optionname ) {
			// Google Optimize settings.
			$_goid_val = trim( $newoptionvalue );
			if ( '' === $_goid_val ) {
				$_goid_list = array();
			} else {
				$_goid_list = explode( ',', $_goid_val );
			}
			$_goid_haserror = false;

			foreach ( $_goid_list as $one_go_id ) {
				$_goid_haserror = $_goid_haserror || ! preg_match( '/^(GTM|OPT)-[A-Z0-9]+$/', $one_go_id );
			}

			if ( $_goid_haserror && ( count( $_goid_list ) > 0 ) ) {
				add_settings_error( GTM4WP_ADMIN_GROUP, GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS . ']', esc_html__( 'Invalid Google Optimize ID. Valid ID format: GTM-XXXXX or OPT-XXXXX. Use comma without additional space (,) to enter more than one ID.', 'duracelltomi-google-tag-manager' ) );
			} else {
				$output[ $optionname ] = $newoptionvalue;
			}
		} elseif ( GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZETIMEOUT === $optionname ) {
			$output[ $optionname ] = (int) $newoptionvalue;
		} elseif ( GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION === $optionname ) {
			$output[ $optionname ] = (int) $newoptionvalue;
		} elseif ( GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE === $optionname ) {
			$output[ $optionname ] = (int) $newoptionvalue;
		} elseif ( GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX === $optionname ) {
			$output[ $optionname ] = trim( (string) $newoptionvalue );
		} elseif ( GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY === $optionname ) {
			$output[ $optionname ] = trim( (string) $newoptionvalue );
		} elseif ( GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL === $optionname ) {
			$output[ $optionname ] = trim( (string) $newoptionvalue );
		} elseif ( GTM4WP_OPTION_GTMDOMAIN === $optionname ) {
			// for PHP 7- compatibility.
			if ( ! defined( 'FILTER_FLAG_HOSTNAME' ) ) {
				define( 'FILTER_FLAG_HOSTNAME', 0 );
			}

			// remove https:// prefix if used.
			$newoptionvalue = str_replace( 'https://', '', $newoptionvalue );

			$newoptionvalue = filter_var( $newoptionvalue, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
			if ( false === $newoptionvalue ) {
				$newoptionvalue = '';
			}
			$output[ $optionname ] = trim( (string) $newoptionvalue );

		} elseif ( GTM4WP_OPTION_INTEGRATE_AMPID === $optionname ) {
			// Accelerated Mobile Pages settings.
			$_ampid_val = trim( $newoptionvalue );
			if ( '' === $_ampid_val ) {
				$_ampid_list = array();
			} else {
				$_ampid_list = explode( ',', $_ampid_val );
			}
			$_ampid_haserror = false;

			foreach ( $_ampid_list as $one_amp_id ) {
				$_ampid_haserror = $_ampid_haserror || ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_amp_id );
			}

			if ( $_ampid_haserror && ( count( $_ampid_list ) > 0 ) ) {
				add_settings_error( GTM4WP_ADMIN_GROUP, GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_INTEGRATE_AMPID . ']', esc_html__( 'Invalid AMP Google Tag Manager Container ID. Valid ID format: GTM-XXXXX. Use comma without additional space (,) to enter more than one ID.', 'duracelltomi-google-tag-manager' ) );
			} else {
				$output[ $optionname ] = $newoptionvalue;
			}
		} elseif ( substr( $optionname, 0, 10 ) === 'integrate-' ) {
			// integrations.
			$output[ $optionname ] = (bool) $newoptionvalue;

		} elseif ( ( GTM4WP_OPTION_GTM_CODE === $optionname ) || ( GTM4WP_OPTION_DATALAYER_NAME === $optionname ) || ( GTM4WP_OPTION_ENV_GTM_AUTH === $optionname ) || ( GTM4WP_OPTION_ENV_GTM_PREVIEW === $optionname ) ) {
			// GTM code or dataLayer variable name.
			$newoptionvalue = trim( $newoptionvalue );

			if ( GTM4WP_OPTION_GTM_CODE === $optionname ) {
				$_gtmid_list     = explode( ',', $newoptionvalue );
				$_gtmid_haserror = false;

				foreach ( $_gtmid_list as $one_gtm_id ) {
					$_gtmid_haserror = $_gtmid_haserror || ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id );
				}

				if ( $_gtmid_haserror ) {
					add_settings_error( GTM4WP_ADMIN_GROUP, GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_GTM_CODE . ']', esc_html__( 'Invalid Google Tag Manager ID. Valid ID format: GTM-XXXXX. Use comma without additional space (,) to enter more than one container ID.', 'duracelltomi-google-tag-manager' ) );
				} else {
					$output[ $optionname ] = $newoptionvalue;
				}
			} elseif ( ( GTM4WP_OPTION_DATALAYER_NAME === $optionname ) && ( '' !== $newoptionvalue ) && ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/', $newoptionvalue ) ) ) {
				add_settings_error( GTM4WP_ADMIN_GROUP, GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_DATALAYER_NAME . ']', esc_html__( "Invalid dataLayer variable name. Please start with a character from a-z or A-Z followed by characters from a-z, A-Z, 0-9 or '_' or '-'!", 'duracelltomi-google-tag-manager' ) );

			} elseif ( ( GTM4WP_OPTION_ENV_GTM_AUTH === $optionname ) && ( '' !== $newoptionvalue ) && ( ! preg_match( '/^[a-zA-Z0-9-_]+$/', $newoptionvalue ) ) ) {
				add_settings_error( GTM4WP_ADMIN_GROUP, GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_AUTH . ']', esc_html__( "Invalid gtm_auth environment parameter value. It should only contain letters, numbers or the '-' and '_' characters.", 'duracelltomi-google-tag-manager' ) );

			} elseif ( ( GTM4WP_OPTION_ENV_GTM_PREVIEW === $optionname ) && ( '' !== $newoptionvalue ) && ( ! preg_match( '/^env-[0-9]+$/', $newoptionvalue ) ) ) {
				add_settings_error( GTM4WP_ADMIN_GROUP, GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_ENV_GTM_PREVIEW . ']', esc_html__( "Invalid gtm_preview environment parameter value. It should have the format 'env-NN' where NN is an integer number.", 'duracelltomi-google-tag-manager' ) );

			} else {
				$output[ $optionname ] = $newoptionvalue;
			}
		} elseif ( GTM4WP_OPTION_GTM_PLACEMENT === $optionname ) {
			// GTM container ON/OFF + compat mode.
			$container_on_off = (bool) $options['container-on'];
			$container_compat = (int) $options['compat-mode'];

			if ( ! $container_on_off ) {
				$output[ $optionname ] = GTM4WP_PLACEMENT_OFF;
			} else {
				if ( ( $container_compat < 0 ) || ( $container_compat > 2 ) ) {
					$container_compat = 2;
				}

				$output[ $optionname ] = $container_compat;
			}
		} elseif ( GTM4WP_OPTION_SCROLLER_CONTENTID === $optionname ) {
			// scroll tracking content ID.
			$output[ $optionname ] = trim( str_replace( '#', '', $newoptionvalue ) );
		} elseif ( GTM4WP_OPTION_NOGTMFORLOGGEDIN === $optionname ) {
			// do not output GTM container code for specific user roles.
			if ( is_array( $newoptionvalue ) ) {
				$output[ $optionname ] = implode( ',', $newoptionvalue );
			} else {
				$output[ $optionname ] = '';
			}
		} else {
			// anything else.
			switch ( gettype( $optionvalue ) ) {
				case 'boolean':
					$output[ $optionname ] = (bool) $newoptionvalue;
					break;

				case 'integer':
					$output[ $optionname ] = (int) $newoptionvalue;
					break;

				default:
					$output[ $optionname ] = $newoptionvalue;
			} // end switch.
		}
	}

	return $output;
}

/**
 * Function for admin_init hook. Adds option page tabs.
 *
 * @see https://developer.wordpress.org/reference/hooks/admin_init/
 *
 * @return void
 */
function gtm4wp_admin_init() {
	global $gtm4wp_includefieldtexts, $gtm4wp_eventfieldtexts, $gtm4wp_integratefieldtexts, $gtm4wp_scrollerfieldtexts,
		$gtm4wp_advancedfieldtexts, $gtm4wp_entity_ids;

	register_setting(
		GTM4WP_ADMIN_GROUP,
		GTM4WP_OPTIONS,
		array(
			'sanitize_callback' => 'gtm4wp_sanitize_options',
		)
	);

	add_settings_section(
		GTM4WP_ADMIN_GROUP_GENERAL,
		esc_html__( 'General', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	add_settings_field(
		GTM4WP_ADMIN_GROUP_GTMID,
		esc_html__( 'Google Tag Manager ID', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_field',
		GTM4WP_ADMINSLUG,
		GTM4WP_ADMIN_GROUP_GENERAL,
		array(
			'label_for'   => GTM4WP_ADMIN_GROUP_GTMID,
			'description' => esc_html__( 'Enter your Google Tag Manager ID here. Use comma without space (,) to enter multiple IDs.', 'duracelltomi-google-tag-manager' ),
		)
	);

	add_settings_field(
		GTM4WP_ADMIN_GROUP_CONTAINERON,
		esc_html__( 'Container code ON/OFF', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_field',
		GTM4WP_ADMINSLUG,
		GTM4WP_ADMIN_GROUP_GENERAL,
		array(
			'label_for'   => GTM4WP_ADMIN_GROUP_CONTAINERON,
			'description' => gtm4wp_safe_admin_html( 'Turning OFF the Google Tag Manager container itself will remove both the head and the body part of the container code but leave data layer codes working.<br/>This should be only used in specific cases where you need to place the container code manually or using another tool.', 'duracelltomi-google-tag-manager' ),
		)
	);

	add_settings_field(
		GTM4WP_ADMIN_GROUP_COMPATMODE,
		esc_html__( 'Container code compatibility mode', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_field',
		GTM4WP_ADMINSLUG,
		GTM4WP_ADMIN_GROUP_GENERAL,
		array(
			'label_for'   => GTM4WP_ADMIN_GROUP_COMPATMODE,
			'description' => gtm4wp_safe_admin_html(
				__(
					'Compatibility mode decides where to put the second, so called <code>&lt;noscript&gt;</code> or <code>&lt;iframe&gt;</code> part of the GTM container code.<br />
					This code is usually only executed if your visitor has disabled JavaScript for some reason.<br/>
					It is also mandatory in order to verify your site in Google Search Console using the GTM method.<br/>
					The main GTM container code will be placed into the <code>&lt;head&gt;</code> section of your webpages anyway (where it belongs to).<br/><br/>
					If you select "Manually coded", you need to edit your template files and add the following line just after the opening <code>&lt;body&gt;</code> tag:<br />
					<code>&lt;?php if ( function_exists( \'gtm4wp_the_gtm_tag\' ) ) { gtm4wp_the_gtm_tag(); } ?&gt;</code>',
					'duracelltomi-google-tag-manager'
				)
			),
		)
	);

	add_settings_section(
		GTM4WP_ADMIN_GROUP_INCLUDES,
		esc_html__( 'Basic data', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	foreach ( $gtm4wp_includefieldtexts as $fieldid => $fielddata ) {
		$phase = isset( $fielddata['phase'] ) ? $fielddata['phase'] : GTM4WP_PHASE_STABLE;

		add_settings_field(
			'gtm4wp-admin-' . $fieldid . '-id',
			$fielddata['label'] . '<span class="' . $phase . '"></span>',
			'gtm4wp_admin_output_field',
			GTM4WP_ADMINSLUG,
			GTM4WP_ADMIN_GROUP_INCLUDES,
			array(
				'label_for'     => 'gtm4wp-options[' . $fieldid . ']',
				'description'   => $fielddata['description'],
				'optionfieldid' => $fieldid,
			)
		);
	}

	add_settings_section(
		GTM4WP_ADMIN_GROUP_EVENTS,
		esc_html__( 'Events', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	foreach ( $gtm4wp_eventfieldtexts as $fieldid => $fielddata ) {
		$phase = isset( $fielddata['phase'] ) ? $fielddata['phase'] : GTM4WP_PHASE_STABLE;

		add_settings_field(
			'gtm4wp-admin-' . $fieldid . '-id',
			$fielddata['label'] . '<span class="' . $phase . '"></span>',
			'gtm4wp_admin_output_field',
			GTM4WP_ADMINSLUG,
			GTM4WP_ADMIN_GROUP_EVENTS,
			array(
				'label_for'     => 'gtm4wp-options[' . $fieldid . ']',
				'description'   => $fielddata['description'],
				'optionfieldid' => $fieldid,
			)
		);
	}

	add_settings_section(
		GTM4WP_ADMIN_GROUP_SCROLLER,
		esc_html__( 'Scroll tracking', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	foreach ( $gtm4wp_scrollerfieldtexts as $fieldid => $fielddata ) {
		$phase = isset( $fielddata['phase'] ) ? $fielddata['phase'] : GTM4WP_PHASE_STABLE;

		add_settings_field(
			'gtm4wp-admin-' . $fieldid . '-id',
			$fielddata['label'] . '<span class="' . $phase . '"></span>',
			'gtm4wp_admin_output_field',
			GTM4WP_ADMINSLUG,
			GTM4WP_ADMIN_GROUP_SCROLLER,
			array(
				'label_for'     => 'gtm4wp-options[' . $fieldid . ']',
				'description'   => $fielddata['description'],
				'optionfieldid' => $fieldid,
			)
		);
	}

	add_settings_section(
		GTM4WP_ADMIN_GROUP_BLACKLIST,
		esc_html__( 'Security', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	add_settings_field(
		GTM4WP_OPTION_BLACKLIST_ENABLE,
		esc_html__( 'Enable blacklist/whitelist', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_field',
		GTM4WP_ADMINSLUG,
		GTM4WP_ADMIN_GROUP_BLACKLIST,
		array(
			'label_for'      => GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_ENABLE . ']',
			'description'    => '',
			'optionsfieldid' => GTM4WP_OPTION_BLACKLIST_ENABLE,
		)
	);

	add_settings_field(
		GTM4WP_OPTION_BLACKLIST_SANDBOXED,
		esc_html__( 'Custom tag/variable templates', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_field',
		GTM4WP_ADMINSLUG,
		GTM4WP_ADMIN_GROUP_BLACKLIST,
		array(
			'label_for'   => GTM4WP_OPTIONS . '[' . GTM4WP_OPTION_BLACKLIST_SANDBOXED . ']',
			'description' => '',
			'entityid'    => GTM4WP_OPTION_BLACKLIST_SANDBOXED,
		)
	);

	foreach ( $gtm4wp_entity_ids as $gtm_entity_group_id => $gtm_entity_group_list ) {
		foreach ( $gtm_entity_group_list as $gtm_entity_id => $gtm_entity_label ) {
			add_settings_field(
				'gtm4wp-admin-blacklist-' . $gtm_entity_group_id . '-' . $gtm_entity_id . '-id',
				$gtm_entity_label,
				'gtm4wp_admin_output_field',
				GTM4WP_ADMINSLUG,
				GTM4WP_ADMIN_GROUP_BLACKLIST,
				array(
					'label_for'   => 'gtm4wp-options[blacklist-' . $gtm_entity_group_id . '-' . $gtm_entity_id . ']',
					'description' => '',
					'entityid'    => $gtm_entity_id,
				)
			);
		}
	}

	add_settings_section(
		GTM4WP_ADMIN_GROUP_INTEGRATION,
		esc_html__( 'Integration', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	foreach ( $gtm4wp_integratefieldtexts as $fieldid => $fielddata ) {
		$phase = isset( $fielddata['phase'] ) ? $fielddata['phase'] : GTM4WP_PHASE_STABLE;

		add_settings_field(
			'gtm4wp-admin-' . $fieldid . '-id',
			$fielddata['label'] . '<span class="' . $phase . '"></span>',
			'gtm4wp_admin_output_field',
			GTM4WP_ADMINSLUG,
			GTM4WP_ADMIN_GROUP_INTEGRATION,
			array(
				'label_for'     => 'gtm4wp-options[' . $fieldid . ']',
				'description'   => $fielddata['description'],
				'optionfieldid' => $fieldid,
				'plugintocheck' => isset( $fielddata['plugintocheck'] ) ? $fielddata['plugintocheck'] : '',
			)
		);
	}

	add_settings_section(
		GTM4WP_ADMIN_GROUP_ADVANCED,
		esc_html__( 'Advanced', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	foreach ( $gtm4wp_advancedfieldtexts as $fieldid => $fielddata ) {
		$phase = isset( $fielddata['phase'] ) ? $fielddata['phase'] : GTM4WP_PHASE_STABLE;

		add_settings_field(
			'gtm4wp-admin-' . $fieldid . '-id',
			$fielddata['label'] . '<span class="' . $phase . '"></span>',
			'gtm4wp_admin_output_field',
			GTM4WP_ADMINSLUG,
			GTM4WP_ADMIN_GROUP_ADVANCED,
			array(
				'label_for'     => 'gtm4wp-options[' . $fieldid . ']',
				'description'   => $fielddata['description'],
				'optionfieldid' => $fieldid,
				'plugintocheck' => isset( $fielddata['plugintocheck'] ) ? $fielddata['plugintocheck'] : '',
			)
		);
	}

	add_settings_section(
		GTM4WP_ADMIN_GROUP_CREDITS,
		esc_html__( 'Credits', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_section',
		GTM4WP_ADMINSLUG
	);

	add_settings_field(
		GTM4WP_ADMIN_GROUP_INFO,
		esc_html__( 'Author', 'duracelltomi-google-tag-manager' ),
		'gtm4wp_admin_output_field',
		GTM4WP_ADMINSLUG,
		GTM4WP_ADMIN_GROUP_CREDITS,
		array(
			'label_for'   => GTM4WP_ADMIN_GROUP_INFO,
			'description' => '<strong>Thomas Geiger</strong><br />
				Website: <a href="https://gtm4wp.com/" target="_blank" rel="noopener">gtm4wp.com</a><br />
				<a href="https://www.linkedin.com/in/duracelltomi" target="_blank" rel="noopener">Me on LinkedIn</a><br />
				<a href="http://www.linkedin.com/company/jabjab-online-marketing-ltd" target="_blank" rel="noopener">JabJab Online Marketing on LinkedIn</a>',
		)
	);

	// Apply oembed code changes on the admin as well since the oembed call on the admin is cached by WordPress into a transient that is applied on the frontend later.
	require_once dirname( __FILE__ ) . '/../integration/youtube.php';
	require_once dirname( __FILE__ ) . '/../integration/vimeo.php';
	require_once dirname( __FILE__ ) . '/../integration/soundcloud.php';
}

/**
 * Callback function for add_options_page(). Generates the GTM4WP plugin options page.
 *
 * @see https://developer.wordpress.org/reference/functions/add_options_page/
 *
 * @return void
 */
function gtm4wp_show_admin_page() {
	global $gtp4wp_plugin_url;
	?>
<div class="wrap">
	<h2><?php esc_html_e( 'Google Tag Manager for WordPress options', 'duracelltomi-google-tag-manager' ); ?></h2>
	<form action="options.php" method="post">

	<?php settings_fields( GTM4WP_ADMIN_GROUP ); ?>
	<?php do_settings_sections( GTM4WP_ADMINSLUG ); ?>
	<?php submit_button(); ?>

	</form>
</div>
	<?php
}

/**
 * Hook function for admin_menu. Adds the plugin options page into the Settings menu of the WordPress admin.
 *
 * @see https://developer.wordpress.org/reference/hooks/admin_menu/
 *
 * @return void
 */
function gtm4wp_add_admin_page() {
	add_options_page(
		esc_html__( 'Google Tag Manager for WordPress settings', 'duracelltomi-google-tag-manager' ),
		esc_html__( 'Google Tag Manager', 'duracelltomi-google-tag-manager' ),
		'manage_options',
		GTM4WP_ADMINSLUG,
		'gtm4wp_show_admin_page'
	);
}

/**
 * Hook function for admin_enqueue_scripts(). Adds the frontend JavaScript code into the WordPress admin when GTM4WP option page is loaded.
 *
 * @see https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
 *
 * @param string $hook The ID of the option page that is currently being shown.
 * @return void
 */
function gtm4wp_add_admin_js( $hook ) {
	global $gtp4wp_plugin_url;

	if ( 'settings_page_' . GTM4WP_ADMINSLUG === $hook ) {
		// phpcs ignore set due to in_footer set to true does not load the script.
		wp_register_script( 'admin-subtabs', $gtp4wp_plugin_url . 'js/admin-subtabs.js', array(), GTM4WP_VERSION ); // phpcs:ignore

		$subtabtexts = array(
			'posttabtitle'             => esc_html__( 'Posts', 'duracelltomi-google-tag-manager' ),
			'searchtabtitle'           => esc_html__( 'Search', 'duracelltomi-google-tag-manager' ),
			'visitortabtitle'          => esc_html__( 'Visitors', 'duracelltomi-google-tag-manager' ),
			'browsertabtitle'          => esc_html__( 'Browser/OS/Device', 'duracelltomi-google-tag-manager' ),
			'blocktagstabtitle'        => esc_html__( 'Blacklist tags', 'duracelltomi-google-tag-manager' ),
			'blocktriggerstabtitle'    => esc_html__( 'Blacklist triggers', 'duracelltomi-google-tag-manager' ),
			'blockmacrostabtitle'      => esc_html__( 'Blacklist variables', 'duracelltomi-google-tag-manager' ),
			'wpcf7tabtitle'            => esc_html__( 'Contact Form 7', 'duracelltomi-google-tag-manager' ),
			'wctabtitle'               => esc_html__( 'WooCommerce', 'duracelltomi-google-tag-manager' ),
			'gotabtitle'               => esc_html__( 'Google Optimize', 'duracelltomi-google-tag-manager' ),
			'amptabtitle'              => esc_html__( 'Accelerated Mobile Pages', 'duracelltomi-google-tag-manager' ),
			'cookiebottabtitle'        => esc_html__( 'Cookiebot', 'duracelltomi-google-tag-manager' ),
			'weathertabtitle'          => esc_html__( 'Weather & geo data', 'duracelltomi-google-tag-manager' ),
			'generaleventstabtitle'    => esc_html__( 'General events', 'duracelltomi-google-tag-manager' ),
			'mediaeventstabtitle'      => esc_html__( 'Media events', 'duracelltomi-google-tag-manager' ),
			'depecratedeventstabtitle' => esc_html__( 'Deprecated', 'duracelltomi-google-tag-manager' ),
			'sitetabtitle'             => esc_html__( 'Site', 'duracelltomi-google-tag-manager' ),
			'misctabtitle'             => esc_html__( 'Misc', 'duracelltomi-google-tag-manager' ),
		);
		wp_localize_script( 'admin-subtabs', 'gtm4wp', $subtabtexts );

		wp_enqueue_script( 'admin-subtabs' );

		// phpcs ignore set due to in_footer set to true does not load the script.
		wp_enqueue_script( 'admin-tabcreator', $gtp4wp_plugin_url . 'js/admin-tabcreator.js', array( 'jquery' ), GTM4WP_VERSION ); // phpcs:ignore

		wp_enqueue_style( 'gtm4wp-admin-css', $gtp4wp_plugin_url . 'css/admin-gtm4wp.css', array(), GTM4WP_VERSION );
	}
}

/**
 * Hook function for admin_head(). Adds some inline style and JavaScript into the header of the admin page.
 *
 * @see https://developer.wordpress.org/reference/hooks/admin_head/
 *
 * @return void
 */
function gtm4wp_admin_head() {
	echo '
<style type="text/css">
	.gtmid_validation_error,
	.goid_validation_error,
	.goid_ga_validation_error,
	.ampid_validation_error,
	.datalayername_validation_error,
	.gtmauth_validation_error,
	.gtmpreview_validation_error,
	.gtm_wpconfig_set	{
		color: #c00;
		font-weight: bold;
	}
	.gtmid_validation_error,
	.goid_validation_error,
	.goid_ga_validation_error,
	.ampid_validation_error,
	.datalayername_validation_error,
	.gtmauth_validation_error,
	.gtmpreview_validation_error {
		display: none;
	}
</style>
<script type="text/javascript">
	jQuery(function() {
		jQuery( "#gtm4wp-options\\\\[gtm-code\\\\]" )
			.on( "blur", function() {
				var gtmid_regex = /^GTM-[A-Z0-9]+$/;
				var gtmid_list_str = jQuery( this ).val();
				if ( typeof gtmid_list_str != "string" ) {
					return;
				}
				var gtmid_list = gtmid_list_str.trim().split( "," );

				var gtmid_haserror = false;
				for( var i=0; i<gtmid_list.length; i++ ) {
					gtmid_haserror = gtmid_haserror || !gtmid_regex.test( gtmid_list[ i ] );
				}

				if ( gtmid_haserror ) {
					jQuery( ".gtmid_validation_error" )
						.show();
				} else {
					jQuery( ".gtmid_validation_error" )
						.hide();
				}
			});

		jQuery( "#gtm4wp-options\\\\[integrate-google-optimize-idlist\\\\]" )
			.on( "blur", function() {
				var goid_regex = /^(GTM|OPT)-[A-Z0-9]+$/;
				var goid_val_str = jQuery( this ).val();
				if ( typeof goid_val_str != "string" ) {
					return;
				}
				var goid_val  = goid_val_str.trim();
				if ( "" == goid_val ) {
					goid_list = [];
				} else {
					var goid_list = goid_val.split( "," );
				}

				var goid_haserror = false;
				for( var i=0; i<goid_list.length; i++ ) {
					goid_haserror = goid_haserror || !goid_regex.test( goid_list[ i ] );
				}

				if ( goid_haserror && (goid_list.length > 0) ) {
					jQuery( ".goid_validation_error" )
						.show();
				} else {
					jQuery( ".goid_validation_error" )
						.hide();
				}
			});

		jQuery( "#gtm4wp-options\\\\[integrate-google-optimize-gaid\\\\]" )
			.on( "blur", function() {
				var gogaid_regex = /^UA-[0-9]+-[0-9]+$/;
				var gogaid_val_str = jQuery( this ).val();
				if ( typeof gogaid_val_str != "string" ) {
					return;
				}
				var gogaid_val  = gogaid_val_str.trim();
				if ( "" == gogaid_val ) {
					gogaid_list = [];
				} else {
					var gogaid_list = gogaid_val.split( "," );
				}

				var gogaid_haserror = false;
				for( var i=0; i<gogaid_list.length; i++ ) {
					gogaid_haserror = gogaid_haserror || !gogaid_regex.test( gogaid_list[ i ] );
				}

				if ( gogaid_haserror && (gogaid_list.length > 0) ) {
					jQuery( ".goid_ga_validation_error" )
						.show();
				} else {
					jQuery( ".goid_ga_validation_error" )
						.hide();
				}
			});

		jQuery( "#gtm4wp-options\\\\[integrate-amp-id\\\\]" )
			.on( "blur", function() {
				var ampid_regex = /^GTM-[A-Z0-9]+$/;
				var ampid_val_str = jQuery( this ).val();
				if ( typeof ampid_val_str != "string" ) {
					return;
				}
				var ampid_val  = ampid_val_str.trim();
				if ( "" == ampid_val ) {
					ampid_list = [];
				} else {
					var ampid_list = ampid_val.split( "," );
				}

				var ampid_haserror = false;
				for( var i=0; i<ampid_list.length; i++ ) {
					ampid_haserror = ampid_haserror || !ampid_regex.test( ampid_list[ i ] );
				}

				if ( ampid_haserror && (ampid_list.length > 0) ) {
					jQuery( ".ampid_validation_error" )
						.show();
				} else {
					jQuery( ".ampid_validation_error" )
						.hide();
				}
			});

		jQuery( "#gtm4wp-options\\\\[gtm-datalayer-variable-name\\\\]" )
			.on( "blur", function() {
				var currentval = jQuery( this ).val();

				jQuery( ".datalayername_validation_error" )
					.hide();

				if ( currentval != "" ) {
					// I know this is not the exact definition for a variable name but I think other kind of variable names should not be used.
					var gtmvarname_regex = /^[a-zA-Z][a-zA-Z0-9_-]*$/;
					if ( ! gtmvarname_regex.test( currentval ) ) {
						jQuery( ".datalayername_validation_error" )
							.show();
					}
				}
			});

		jQuery( "#gtm4wp-options\\\\[gtm-env-gtm-auth\\\\]" )
			.on( "blur", function() {
				var currentval = jQuery( this ).val();

				jQuery( ".gtmauth_validation_error" )
					.hide();

				if ( currentval != "" ) {
					var gtmauth_regex = /^[a-zA-Z0-9-_]+$/;
					if ( ! gtmauth_regex.test( currentval ) ) {
						jQuery( ".gtmauth_validation_error" )
							.show();
					}
				}
			});

		jQuery( "#gtm4wp-options\\\\[gtm-env-gtm-preview\\\\]" )
			.on( "blur", function() {
				var currentval = jQuery( this ).val();

				jQuery( ".gtmpreview_validation_error" )
					.hide();

				if ( currentval != "" ) {
					var gtmpreview_regex = /^env-[0-9]+$/;
					if ( ! gtmpreview_regex.test( currentval ) ) {
						jQuery( ".gtmpreview_validation_error" )
							.show();
					}
				}
			});

		jQuery( document )
			.on( "click", ".gtm4wp-notice .notice-dismiss", function( e ) {
				jQuery.post(ajaxurl, {
					action: "gtm4wp_dismiss_notice",
					noticeid: jQuery( this ).closest(".gtm4wp-notice")
						.attr( "data-href" )
						.substring( 1 ),
					nonce: "' . esc_html( wp_create_nonce( 'gtm4wp-notice-dismiss-nonce' ) ) . '"
				});
			});
	});
</script>';
}

/**
 * Hook function for admin_notices. Shows warning messages on the WordPress admin page about possible conflicting plugins.
 *
 * @see https://developer.wordpress.org/reference/hooks/admin_notices/
 *
 * @return void
 */
function gtm4wp_show_warning() {
	global $gtm4wp_options, $gtp4wp_plugin_url, $gtm4wp_integratefieldtexts, $current_user,
		$gtm4wp_def_user_notices_dismisses;

	$woo_plugin_active = is_plugin_active( $gtm4wp_integratefieldtexts[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ]['plugintocheck'] );
	if ( $woo_plugin_active && function_exists( 'WC' ) ) {
		$woo = WC();
	} else {
		$woo = null;
	}

	$gtm4wp_user_notices_dismisses = get_user_meta( $current_user->ID, GTM4WP_USER_NOTICES_KEY, true );
	if ( '' === $gtm4wp_user_notices_dismisses ) {
		if ( is_array( $gtm4wp_def_user_notices_dismisses ) ) {
			$gtm4wp_user_notices_dismisses = $gtm4wp_def_user_notices_dismisses;
		} else {
			$gtm4wp_user_notices_dismisses = array();
		}
	} else {
		$gtm4wp_user_notices_dismisses = json_decode( $gtm4wp_user_notices_dismisses, true );
		if ( null === $gtm4wp_user_notices_dismisses || ! is_array( $gtm4wp_user_notices_dismisses ) ) {
			$gtm4wp_user_notices_dismisses = array();
		}
	}
	$gtm4wp_user_notices_dismisses = array_merge( $gtm4wp_def_user_notices_dismisses, $gtm4wp_user_notices_dismisses );

	if ( ( '' === trim( $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) ) && ( false === $gtm4wp_user_notices_dismisses['enter-gtm-code'] ) ) {
		echo '<div class="gtm4wp-notice notice notice-error is-dismissible" data-href="?enter-gtm-code"><p><strong>';
		echo sprintf(
			// translators: 1: opening anchor element pointing to the GTM4WP options page. 2: clsing anchor element.
			esc_html__(
				'To start using Google Tag Manager for WordPress, please %1$senter your GTM ID%2$s',
				'duracelltomi-google-tag-manager'
			),
			'<a href="' . esc_url( menu_page_url( GTM4WP_ADMINSLUG, false ) ) . '">',
			'</a>'
		);
		echo '</strong></p></div>';
	}

	if ( (
		( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) && ( '' === $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] )
	) || (
		( '' === $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) && ( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] )
	) ) {
		echo '<div class="gtm4wp-notice notice notice-error" data-href="?incomplete-gtm-env-config"><p><strong>';
		esc_html_e(
			'Incomplete Google Tag Manager environment configuration: either gtm_preview or gtm_auth parameter value is missing!',
			'duracelltomi-google-tag-manager'
		);
		echo '</strong></p></div>';
	}

	if ( ( false === $gtm4wp_user_notices_dismisses['wc-ga-plugin-warning'] ) || ( false === $gtm4wp_user_notices_dismisses['wc-gayoast-plugin-warning'] ) ) {
		$is_wc_active = $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ||
				$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ||
				$gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCREMARKETING ];

		if ( ( false === $gtm4wp_user_notices_dismisses['wc-ga-plugin-warning'] ) && $is_wc_active && is_plugin_active( 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' ) ) {
			echo '<div class="gtm4wp-notice notice notice-warning is-dismissible" data-href="?wc-ga-plugin-warning"><p><strong>' . esc_html__( 'Notice: you should deactivate the plugin "WooCommerce Google Analytics Integration" if you are using Google Analytics tags inside Google Tag Manager!', 'duracelltomi-google-tag-manager' ) . '</strong></p></div>';
		}

		if ( ( false === $gtm4wp_user_notices_dismisses['wc-gayoast-plugin-warning'] ) && $is_wc_active && is_plugin_active( 'google-analytics-for-wordpress/googleanalytics.php' ) ) {
			echo '<div class="gtm4wp-notice notice notice-warning is-dismissible" data-href="?wc-gayoast-plugin-warning"><p><strong>' . esc_html__( 'Notice: you should deactivate the plugin "Google Analytics for WordPress by MonsterInsights" if you are using Google Analytics tags inside Google Tag Manager!', 'duracelltomi-google-tag-manager' ) . '</strong></p></div>';
		}
	}

	if ( ( false === $gtm4wp_user_notices_dismisses['php72-warning'] ) && ( version_compare( PHP_VERSION, '7.2.0' ) < 0 ) ) {
		echo '<div class="gtm4wp-notice notice notice-warning is-dismissible" data-href="?php72-warning"><p><strong>';
		printf(
			// translators: %s: PHP version number.
			esc_html__(
				'Warning: You are using an outdated version of PHP (%s) that might be not compatible with future versions of the plugin Google Tag Manager for WordPress (GTM4WP). Please consider to upgrade your PHP.',
				'duracelltomi-google-tag-manager'
			),
			PHP_VERSION
		);
		echo '</strong></p></div>';
	}
}

/**
 * Action run when users dismiss a notice of GTM4WP on the WordPress admin.
 * Saves the dismissed notice ID as user meta to hide the notice on next pageview.
 *
 * @see https://developer.wordpress.org/reference/hooks/wp_ajax_action/
 *
 * @return void
 */
function gtm4wp_dismiss_notice() {
	global $gtm4wp_def_user_notices_dismisses, $current_user;

	check_ajax_referer( 'gtm4wp-notice-dismiss-nonce', 'nonce' );

	$gtm4wp_user_notices_dismisses = get_user_meta( $current_user->ID, GTM4WP_USER_NOTICES_KEY, true );
	if ( '' === $gtm4wp_user_notices_dismisses ) {
		if ( is_array( $gtm4wp_def_user_notices_dismisses ) ) {
			$gtm4wp_user_notices_dismisses = $gtm4wp_def_user_notices_dismisses;
		} else {
			$gtm4wp_user_notices_dismisses = array();
		}
	} else {
		$gtm4wp_user_notices_dismisses = json_decode( $gtm4wp_user_notices_dismisses, true );
		if ( null === $gtm4wp_user_notices_dismisses || ! is_array( $gtm4wp_user_notices_dismisses ) ) {
			$gtm4wp_user_notices_dismisses = array();
		}
	}
	$gtm4wp_user_notices_dismisses = array_merge( $gtm4wp_def_user_notices_dismisses, $gtm4wp_user_notices_dismisses );

	$noticeid = isset( $_POST['noticeid'] ) ? esc_url_raw( wp_unslash( $_POST['noticeid'] ) ) : '';
	$noticeid = trim( basename( $noticeid ) );
	if ( array_key_exists( $noticeid, $gtm4wp_user_notices_dismisses ) ) {
		$gtm4wp_user_notices_dismisses[ $noticeid ] = true;
		update_user_meta( $current_user->ID, GTM4WP_USER_NOTICES_KEY, wp_json_encode( $gtm4wp_user_notices_dismisses ) );
	}
}

/**
 * Action hook for plugin_action_links.
 * Adds link to the settings page on the Plugins page.
 *
 * @see https://developer.wordpress.org/reference/hooks/plugin_action_links/
 *
 * @param array  $links Existing links that can be extended by this action.
 * @param string $file Plugin ID where the links belong to.
 * @return array Array of anchor HTML elements with links that will be shown below the plugin on the Plugins page.
 */
function gtm4wp_add_plugin_action_links( $links, $file ) {
	global $gtp4wp_plugin_basename;

	if ( $file !== $gtp4wp_plugin_basename ) {
			return $links;
	}

	$settings_link = '<a href="' . menu_page_url( GTM4WP_ADMINSLUG, false ) . '">' . esc_html__( 'Settings', 'duracelltomi-google-tag-manager' ) . '</a>';

	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Action hook for in_plugin_update_message-file.
 * Shows upgrade message below plugin description.
 *
 * @see https://developer.wordpress.org/reference/hooks/in_plugin_update_message-file/
 *
 * @param array  $current_plugin_metadata Meta data of the currently active plugin version.
 * @param object $new_plugin_metadata Meta data of the available new plugin version.
 * @return void
 */
function gtm4wp_show_upgrade_notification( $current_plugin_metadata, $new_plugin_metadata ) {
	if ( isset( $new_plugin_metadata->upgrade_notice ) && strlen( trim( $new_plugin_metadata->upgrade_notice ) ) > 0 ) {
		echo '<p style="background-color: #d54e21; padding: 10px; color: #f9f9f9; margin-top: 10px"><strong>Important Upgrade Notice:</strong> ';
		echo esc_html( $new_plugin_metadata->upgrade_notice ), '</p>';
	}
}

add_action( 'admin_init', 'gtm4wp_admin_init' );
add_action( 'admin_menu', 'gtm4wp_add_admin_page' );
add_action( 'admin_enqueue_scripts', 'gtm4wp_add_admin_js' );
add_action( 'admin_notices', 'gtm4wp_show_warning' );
add_action( 'admin_head', 'gtm4wp_admin_head' );
add_filter( 'plugin_action_links', 'gtm4wp_add_plugin_action_links', 10, 2 );
add_action( 'wp_ajax_gtm4wp_dismiss_notice', 'gtm4wp_dismiss_notice' );
add_action( 'in_plugin_update_message-duracelltomi-google-tag-manager-for-wordpress/duracelltomi-google-tag-manager-for-wordpress.php', 'gtm4wp_show_upgrade_notification', 10, 2 );
