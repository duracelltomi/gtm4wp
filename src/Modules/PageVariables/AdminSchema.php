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
		return __( 'Page variables', 'duracelltomi-google-tag-manager' );
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
			'post'    => __( 'Post data', 'duracelltomi-google-tag-manager' ),
			'content' => __( 'Content & engagement data', 'duracelltomi-google-tag-manager' ),
			'search'  => __( 'Search data', 'duracelltomi-google-tag-manager' ),
			'visitor' => __( 'Visitor data', 'duracelltomi-google-tag-manager' ),
			'site'    => __( 'Site data', 'duracelltomi-google-tag-manager' ),
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
				label: __( 'Posttype of current post/archive', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the type of the current post or archive page (post, page or any custom post type).', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_CATEGORIES,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: __( 'Category list of current post/archive', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the category names of the current post or archive page', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Include parent categories in the category list', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to also add the parent (ancestor) categories to the pageCategory data layer variable. When off, only the categories directly assigned to the current post or archive are listed. Requires the "Category list of current post/archive" option above to be enabled.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				phase: Field::PHASE_BETA,
				depends_on: GTM4WP_OPTION_INCLUDE_CATEGORIES
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_TAGS,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: __( 'Tags of current post', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the tags of the current post.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_AUTHORID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post author ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the ID of the author on the current post or author page.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_AUTHOR,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: __( 'Post author name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the name of the author on the current post or author page.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the date of the current post. This will include 4 dataLayer variables: full date, post year, post month, post date.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTTITLE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post title', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the title of the current post.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTCOUNT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post count', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the count of the posts currently shown on the page and the total number of posts in the category/tag/any taxonomy.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the post id.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTFORMAT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post Format', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the post format.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTTERMLIST,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post Terms', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include taxonomy values associated with a given post.', 'duracelltomi-google-tag-manager' ),
				group: 'post'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Content word count', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the number of words in the current post content. Useful to normalize scroll depth and engagement metrics against the length of the content.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_READINGTIME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Estimated reading time', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the estimated reading time of the current post in minutes (based on 200 words per minute, adjustable with the gtm4wp_reading_time_wpm filter).', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_MODIFIEDDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Last modified date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the last modified date of the current post. This will include the same set of date variables as the post date option (full date, year, month, day, day name, hour, minute, ISO and Unix timestamp).', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_CONTENTAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Content age in days', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the number of days elapsed since the current post was published. Useful to segment engagement by fresh versus evergreen content.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_COMMENTCOUNT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Comment count', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the number of comments on the current post and whether commenting is open or closed.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PAGETEMPLATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Page template', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the template file assigned to the current post or page (returns "default" when no custom template is used). Useful to segment behavior by page layout.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Featured image presence', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include whether the current post has a featured image set.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Page hierarchy', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the parent post ID and the depth of the current post in the page hierarchy (0 for top level pages).', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTSTICKY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Sticky post', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include whether the current post is marked as sticky.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Primary category', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the primary category of the current post as chosen in Yoast SEO or Rank Math (falls back to the first assigned category). Useful as a single content grouping dimension.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PAGELANGUAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Page language', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the language code of the current page, detected from WPML or Polylang and falling back to the site locale. Useful to segment behavior on multilingual sites.', 'duracelltomi-google-tag-manager' ),
				group: 'content'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SEARCHDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Search data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the search term, referring page URL and number of results on the search page.', 'duracelltomi-google-tag-manager' ),
				group: 'search'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_LOGGEDIN,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in status', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include whether there is a logged in user on your website.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERROLE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user role', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the role of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the ID of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERNAME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the username of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USEREMAIL,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user email', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the email address of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERREGDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user creation date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the date of creation (registration) of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_VISITOR_IP,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Visitor IP', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the IP address of the visitor. You might use this to filter internal traffic inside your GTM container. Please be aware that per GDPR its not allowed to transmit this full IP address to Google Analytics or to any other measurement system without explicit consent from the visitor.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Visitor IP - Read from custom header.', 'duracelltomi-google-tag-manager' ),
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
				label: __( 'Cloudflare country code', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Add the country code of the user provided by Cloudflare (if Cloudflare is used with your site)', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SITEID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Site ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'ID of the current site in a WordPress Multisite environment', 'duracelltomi-google-tag-manager' ),
				group: 'site'
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SITENAME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Site name', 'duracelltomi-google-tag-manager' ),
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
