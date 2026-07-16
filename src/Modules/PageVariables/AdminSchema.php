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

use GTM4WP\Frontend\VisitorIp;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the page variables module. Labels and descriptions
 * are ported from the 1.x Basic data admin tab.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface {

	/**
	 * Documentation hub of this module on gtm4wp.com, and the pages below it that
	 * document individual data layer variables. This module's options are spread
	 * across the widest set of pages, which is why they are named here rather than
	 * spelled out on each of the 37 fields.
	 */
	private const DOC_BASE      = 'use-basic-wordpress-data-in-google-tag-manager';
	private const DOC_POST      = self::DOC_BASE . '/wordpress-page-post-attributes-in-google-tag-manager';
	private const DOC_VISITOR   = self::DOC_BASE . '/wordpress-site-user-and-visitor-data';
	private const DOC_SEARCH    = self::DOC_BASE . '/site-search-usage-on-your-wordpress-site';
	private const DOC_TAXONOMY  = self::DOC_BASE . '/wordpress-taxonomy-llisting-page-post-count-in-google-tag-manager';
	private const DOC_MULTISITE = self::DOC_BASE . '/wordpress-multisite-information';

	/**
	 * The Cloudflare country code is the one option of this module documented
	 * under the third party data hub rather than the basic data one, because it
	 * is the surviving part of the retired geo integration.
	 */
	private const DOC_GEO = 'use-special-3rd-party-data-in-google-tag-manager/track-country-city-and-other-geo-data-of-the-current-visitor';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_BASE;
	}

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
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_CATEGORIES,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: __( 'Category list of current post/archive', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the categories of the current post or archive page in the pageCategory data layer variable. Categories are listed by their slug (for example news-and-events), not by their display name.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Include parent categories in the category list', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to also add the parent (ancestor) categories to the pageCategory data layer variable. When off, only the categories directly assigned to the current post or archive are listed. Requires the "Category list of current post/archive" option above to be enabled.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				phase: Field::PHASE_BETA,
				depends_on: GTM4WP_OPTION_INCLUDE_CATEGORIES,
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_TAGS,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: __( 'Tags of current post', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the tags of the current post in the pageAttributes data layer variable. Tags are listed by their slug (for example black-friday), not by their display name.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_AUTHORID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post author ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the ID of the author on the current post or author page in the pagePostAuthorID data layer variable. On a post with several authors (PublishPress Authors) the full list is added as pagePostAuthorIDs as well.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_AUTHOR,
				type: Field::TYPE_CHECKBOX,
				default_value: true,
				label: __( 'Post author name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the display name of the author on the current post or author page in the pagePostAuthor data layer variable. On a post with several authors (PublishPress Authors) the full list is added as pagePostAuthors as well.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the date of the current post. This will include 9 dataLayer variables: full date, year, month, day, day name, hour, minute, ISO 8601 date and Unix timestamp. On date based archive pages only the parts the archive covers are included.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTTITLE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post title', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the title of the current post.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTCOUNT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post count', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the count of the posts currently shown on the page and the total number of posts in the category/tag/any taxonomy.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_TAXONOMY
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the post id.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTFORMAT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post Format', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the format of the current post in the postFormat data layer variable. The format is reported by its slug (aside, gallery, video and so on), and posts with no format set report standard.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTTERMLIST,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post Terms', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include taxonomy values associated with a given post, in the pagePostTerms data layer variable. Custom fields are a separate option below.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTMETA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Post custom fields (meta)', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the custom fields (post meta) of the current post in the pagePostTerms.meta data layer variable. Read this before enabling it: this publishes EVERY custom field whose name does not start with an underscore, together with its value, into the data layer of the public page, where any visitor can read it. That includes fields created by other plugins and themes - Advanced Custom Fields stores its values this way - so it may expose internal notes, ids, prices or contact details you did not intend to make public. Review your custom fields first, and use the key list below to name only the ones you need (or the gtm4wp_post_meta_in_datalayer filter to exclude individual keys in code). Custom fields that hold a structured value rather than plain text are skipped: plugins store those in a packed internal format that no Google Tag Manager variable can read. Fields that a plugin or your own site marks as protected are skipped too. Until 2.0 this data was sent as part of the "Post Terms" option above; if you had that enabled, this option was turned on for you during the upgrade so your Google Tag Manager setup keeps working.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS,
				type: Field::TYPE_TEXTAREA,
				default_value: '',
				label: __( 'Post custom fields - publish only these keys', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'The names of the custom fields you actually want in the data layer - one per line, or comma separated. Leave it empty to keep publishing every custom field whose name does not start with an underscore and is not otherwise protected or skipped, which is what the option above does on its own. Filling it in is the safer way to use that option: your Google Tag Manager container only ever needs a handful of keys, and naming them means a field added later by a plugin, a theme or an editor cannot start appearing on your public pages without you deciding it. Protected fields and the gtm4wp_post_meta_in_datalayer filter still apply on top of this list, so naming a field here cannot publish one that is held back for those reasons - a name starting with an underscore is never published however you list it. A field name that contains a comma cannot be listed at all, because commas separate the entries.', 'duracelltomi-google-tag-manager' ),
				group: 'post',
				depends_on: GTM4WP_OPTION_INCLUDE_POSTMETA,
				sanitizer: static function ( $value ) {
					// Type-defensive: a custom sanitizer REPLACES Field::sanitize()'s
					// type-based branches, so it never sits behind to_string() (RI-6).
					$value = Field::to_string( $value );

					// Normalized with the READER's own parser, so what is stored is
					// exactly the list PageVariablesModule will honour - one rule, one
					// place (PA-2). Stored one key per line, which is how the textarea
					// renders it back.
					//
					// There is deliberately NO per-entry validator here, unlike the two
					// sanitizers below: a WordPress meta key has no grammar to validate
					// against - core's add_metadata()/update_metadata() only unslash it,
					// never sanitize it - so any pattern would be inventing one (UC-5).
					// sanitize_textarea_field() is not the missing piece either: it
					// keeps control characters and STRIPS %xx sequences, so a legitimate
					// key such as my%2ffield would be stored as myfield and then match
					// nothing. The value reaches no output sink; it is only ever
					// compared with in_array().
					return implode( "\n", PageVariablesModule::parse_meta_key_list( $value ) );
				},
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Content word count', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the number of words in the current post content. Useful to normalize scroll depth and engagement metrics against the length of the content.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_READINGTIME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Estimated reading time', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the estimated reading time of the current post in minutes (based on 200 words per minute, adjustable with the gtm4wp_reading_time_wpm filter).', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_MODIFIEDDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Last modified date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the last modified date of the current post. This will include the same set of date variables as the post date option (full date, year, month, day, day name, hour, minute, ISO and Unix timestamp).', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_CONTENTAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Content age in days', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the number of days elapsed since the current post was published. Useful to segment engagement by fresh versus evergreen content.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_COMMENTCOUNT,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Comment count', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the number of comments on the current post and whether commenting is open or closed.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PAGETEMPLATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Page template', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the template file assigned to the current post or page (returns "default" when no custom template is used). Useful to segment behavior by page layout.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Featured image presence', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include whether the current post has a featured image set.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Page hierarchy', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the parent post ID and the depth of the current post in the page hierarchy (0 for top level pages).', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_POSTSTICKY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Sticky post', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include whether the current post is marked as sticky.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Primary category', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the primary category of the current post as chosen in Yoast SEO or Rank Math (falls back to the first assigned category). This adds two data layer variables: pagePrimaryCategory holds the category slug and pagePrimaryCategoryName holds its display name. Useful as a single content grouping dimension.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_PAGELANGUAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Page language', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the language code of the current page, detected from WPML or Polylang and falling back to the site locale. Useful to segment behavior on multilingual sites.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				doc: self::DOC_POST
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Output values in the default language', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'On multilingual sites (WPML or Polylang), output the language dependent page variables - post title, category slugs, tags and taxonomy terms - in the site\'s default (master) language instead of the translated one, so Google Analytics can combine reports across all languages instead of splitting them per translation. The values are replaced in place (no extra data layer variables). Requires WPML or Polylang; on a single-language site or an untranslated page the current values are unchanged. Experimental: correctness depends on the multilingual plugin\'s API. Off by default.', 'duracelltomi-google-tag-manager' ),
				group: 'content',
				phase: Field::PHASE_EXPERIMENTAL
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SEARCHDATA,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Search data', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the search term, referring page URL and number of results on the search page.', 'duracelltomi-google-tag-manager' ),
				group: 'search',
				doc: self::DOC_SEARCH
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_LOGGEDIN,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in status', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include whether there is a logged in user on your website, in the visitorLoginState data layer variable. The value is either logged-in or logged-out.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERROLE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user role', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the role of the logged in user in the visitorType data layer variable. Roles are reported by their slug, a user with several roles reports them comma separated, and a visitor who is not logged in reports visitor-logged-out.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the ID of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERNAME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the username of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USEREMAIL,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user email', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the email address of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_USERREGDATE,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Logged in user creation date', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the date of creation (registration) of the logged in user.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_VISITOR_IP,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Visitor IP', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Check this option to include the IP address of the visitor. You might use this to filter internal traffic inside your GTM container. Please be aware that per GDPR its not allowed to transmit this full IP address to Google Analytics or to any other measurement system without explicit consent from the visitor.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER,
				type: Field::TYPE_TEXT,
				default_value: '',
				label: __( 'Visitor IP - Read from custom header.', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'By default, the plugin will check the so called REMOTE_ADDR system variable for IP addresses. In some cases, this might not include the correct address. You may specify a custom header to read the IP address from. Important: an HTTP header is sent by the visitor, so on its own it is a claim and not a fact - anyone can put any address in it. Fill in the trusted proxy addresses below to make this header trustworthy. Without them the plugin keeps reading the header the way it always has, but the value can be chosen by the visitor, so do not use it for anything but analytics.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				depends_on: GTM4WP_OPTION_INCLUDE_VISITOR_IP,
				sanitizer: static function ( $value ) {
					// Field::to_string() keeps the cast warning-free on non-scalar
					// import values (a custom sanitizer replaces the type-defensive
					// default in Field::sanitize(), it does not run in front of it).
					// The name is then validated with the READER's own predicate, so
					// what is stored is exactly what VisitorIp::get() will honor -
					// #62 anchored the read end and #89 the save end, each with its
					// own copy of the pattern, and two copies of an allow-list is a
					// divergence waiting for the next tightening (PA-2). One rule now.
					return VisitorIp::normalize_header_name( Field::to_string( $value ) );
				},
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES,
				type: Field::TYPE_TEXTAREA,
				default_value: '',
				label: __( 'Visitor IP - Trusted proxy addresses', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'The IP addresses or CIDR ranges of the reverse proxies, load balancers and CDNs that sit in front of this site - one per line, or comma separated. This is what makes the custom header above trustworthy: with it set, an X-Forwarded-For list is read from the right (skipping your own hops) and a single-value header such as CF-Connecting-IP is only used when the request really did arrive through one of these addresses. Leave it empty if nothing sits in front of your site. Cloudflare publishes its ranges at cloudflare.com/ips; for a load balancer inside your own network the address is usually a private range such as 10.0.0.0/8.', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				phase: Field::PHASE_BETA,
				depends_on: GTM4WP_OPTION_INCLUDE_VISITOR_IP,
				sanitizer: static function ( $value ) {
					// Type-defensive: a custom sanitizer REPLACES Field::sanitize()'s
					// type-based branches, so it never sits behind to_string() (RI-6).
					$value = Field::to_string( $value );

					// Parsed AND validated with the reader's own method, so what is
					// stored is exactly what VisitorIp::get() will honor - splitting
					// rule included. An entry the reader would quietly skip is worse
					// than a rejected one: the admin believes that proxy is covered
					// when it is not. This used to keep its own copy of the split
					// while sharing only the validator, which is the divergence PA-2
					// is about.
					return implode( "\n", VisitorIp::parse_trusted_proxies( $value ) );
				},
				doc: self::DOC_VISITOR
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_MISCGEOCF,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Cloudflare country code', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Add the country code of the user provided by Cloudflare (if Cloudflare is used with your site)', 'duracelltomi-google-tag-manager' ),
				group: 'visitor',
				phase: Field::PHASE_EXPERIMENTAL,
				doc: self::DOC_GEO
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SITEID,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Site ID', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'ID of the current site in a WordPress Multisite environment', 'duracelltomi-google-tag-manager' ),
				group: 'site',
				doc: self::DOC_MULTISITE
			),
			new Field(
				key: GTM4WP_OPTION_INCLUDE_SITENAME,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Site name', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Name of the current site in a WordPress Multisite environment', 'duracelltomi-google-tag-manager' ),
				group: 'site',
				doc: self::DOC_MULTISITE
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
