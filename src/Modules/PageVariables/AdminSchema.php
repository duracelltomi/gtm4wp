<?php
/**
 * Page variables module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\PageVariables;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the page variables module. Labels and descriptions
 * are ported from the 1.x Basic data admin tab.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return esc_html__( 'Page variables', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Here you can check what data is needed to be included in the dataLayer to be able to access them in Google Tag Manager', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'post'    => esc_html__( 'Post data', 'duracelltomi-google-tag-manager' ),
			'search'  => esc_html__( 'Search data', 'duracelltomi-google-tag-manager' ),
			'visitor' => esc_html__( 'Visitor data', 'duracelltomi-google-tag-manager' ),
			'site'    => esc_html__( 'Site data', 'duracelltomi-google-tag-manager' ),
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
				key: GTM4WP_OPTION_INCLUDE_POSTTYPE,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: esc_html__( 'Posttype of current post/archive', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the type of the current post or archive page (post, page or any custom post type).', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_CATEGORIES,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: esc_html__( 'Category list of current post/archive', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the category names of the current post or archive page', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_TAGS,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: esc_html__( 'Tags of current post', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the tags of the current post.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_AUTHORID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Post author ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the ID of the author on the current post or author page.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_AUTHOR,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: esc_html__( 'Post author name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the name of the author on the current post or author page.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Post date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the date of the current post. This will include 4 dataLayer variables: full date, post year, post month, post date.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTTITLE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Post title', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the title of the current post.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTCOUNT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Post count', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the count of the posts currently shown on the page and the total number of posts in the category/tag/any taxonomy.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Post ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the post id.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTFORMAT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Post Format', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the post format.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTTERMLIST,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Post Terms', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include taxonomy values associated with a given post.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SEARCHDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Search data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the search term, referring page URL and number of results on the search page.', 'duracelltomi-google-tag-manager' ),
				group: 'search'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_LOGGEDIN,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Logged in status', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include whether there is a logged in user on your website.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERROLE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Logged in user role', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the role of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Logged in user ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the ID of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERNAME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Logged in user name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the username of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USEREMAIL,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Logged in user email', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the email address of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERREGDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Logged in user creation date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the date of creation (registration) of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_VISITOR_IP,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Visitor IP', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the IP address of the visitor. You might use this to filter internal traffic inside your GTM container. Please be aware that per GDPR its not allowed to transmit this full IP address to Google Analytics or to any other measurement system without explicit consent from the visitor.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: esc_html__( 'Visitor IP - Read from custom header.', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'By default, the plugin will check the so called REMOTE_ADDR system variable for IP addresses. In some cases, this might not include the correct address. You may specify a custom header to read the IP address from.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				sanitizer: static function ( $value ) {
					$value = (string) $value;

					if ( '' === $value ) {
						return '';
					}

					$custom_header = strtoupper( str_replace( '-', '_', $value ) );
					if ( preg_match( '/[A-Z0-9_]+/', $custom_header ) ) {
						return $custom_header;
					}

					return '';
				}
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_MISCGEOCF,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Cloudflare country code', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Add the country code of the user provided by Cloudflare (if Cloudflare is used with your site)', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SITEID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Site ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'ID of the current site in a WordPress Multisite environment', 'duracelltomi-google-tag-manager' ),
				group: 'site'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SITENAME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: esc_html__( 'Site name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Name of the current site in a WordPress Multisite environment', 'duracelltomi-google-tag-manager' ),
				group: 'site'
			),
		);
	}

	/**
	 * The page variables module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
