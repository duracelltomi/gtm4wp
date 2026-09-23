<?php
/**
 * Page variables module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\PageVariables;

use GTM4WP\Ecommerce\Helpers as EcommerceHelpers;
use GTM4WP\Frontend\DefaultLanguage;
use GTM4WP\Frontend\VisitorIp;
use GTM4WP\Module\AbstractModule;
use GTM4WP\Options\Field;
use GTM4WP\Modules\VisitorData\VisitorDataModule;
use GTM4WP\Modules\VisitorData\VisitorField;

defined( 'ABSPATH' ) || exit;

/**
 * Adds page, post, author, search, site and visitor related variables to
 * the main data layer. Port of gtm4wp_add_basic_datalayer_data() from 1.x
 * (public/frontend.php:209) without the WhichBrowser, weather and geo
 * sections (removed in 2.0; browser/OS/device data moved to the
 * ClientDeviceData module).
 */
final class PageVariablesModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'page-variables';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_INCLUDE_POSTTYPE           => true,
			GTM4WP_OPTION_INCLUDE_CATEGORIES         => true,
			GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES   => false,
			GTM4WP_OPTION_INCLUDE_TAGS               => true,
			GTM4WP_OPTION_INCLUDE_AUTHOR             => true,
			GTM4WP_OPTION_INCLUDE_AUTHORID           => false,
			GTM4WP_OPTION_INCLUDE_POSTDATE           => false,
			GTM4WP_OPTION_INCLUDE_POSTTITLE          => false,
			GTM4WP_OPTION_INCLUDE_POSTCOUNT          => false,
			GTM4WP_OPTION_INCLUDE_POSTID             => false,
			GTM4WP_OPTION_INCLUDE_POSTFORMAT         => false,
			GTM4WP_OPTION_INCLUDE_POSTTERMLIST       => false,
			GTM4WP_OPTION_INCLUDE_POSTMETA           => false,
			GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS      => '',
			GTM4WP_OPTION_INCLUDE_SEARCHDATA         => false,
			GTM4WP_OPTION_INCLUDE_LOGGEDIN           => false,
			GTM4WP_OPTION_INCLUDE_USERROLE           => false,
			GTM4WP_OPTION_INCLUDE_USERID             => false,
			GTM4WP_OPTION_INCLUDE_USERNAME           => false,
			GTM4WP_OPTION_INCLUDE_USEREMAIL          => false,
			GTM4WP_OPTION_INCLUDE_USERREGDATE        => false,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP         => false,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => '',
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
			GTM4WP_OPTION_INCLUDE_MISCGEOCF          => false,
			GTM4WP_OPTION_INCLUDE_SITEID             => false,
			GTM4WP_OPTION_INCLUDE_SITENAME           => false,
			GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT   => false,
			GTM4WP_OPTION_INCLUDE_READINGTIME        => false,
			GTM4WP_OPTION_INCLUDE_MODIFIEDDATE       => false,
			GTM4WP_OPTION_INCLUDE_CONTENTAGE         => false,
			GTM4WP_OPTION_INCLUDE_COMMENTCOUNT       => false,
			GTM4WP_OPTION_INCLUDE_PAGETEMPLATE       => false,
			GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE      => false,
			GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY      => false,
			GTM4WP_OPTION_INCLUDE_POSTSTICKY         => false,
			GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY    => false,
			GTM4WP_OPTION_INCLUDE_PAGELANGUAGE       => false,
			GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE     => false,
		);
	}

	/**
	 * Registers the data layer compile filter.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, array( $this, 'add_datalayer_data' ) );
		add_filter( GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS, array( $this, 'declare_visitor_scoped_fields' ) );
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}

	/**
	 * Populates the main data layer output in the <head> before the GTM container snippet.
	 *
	 * @param array $data_layer Array of key-value pairs output as a JSON object into the data layer variable.
	 * @return array
	 */
	public function add_datalayer_data( $data_layer ) {
		// One private method per variable group (#294); each is gated on its own
		// options and hands the array back. Key order is the 1.x order.
		$data_layer = $this->add_site_variables( $data_layer );
		$data_layer = $this->add_visitor_variables( $data_layer );
		$data_layer = $this->add_page_title( $data_layer );
		$data_layer = $this->add_page_language( $data_layer );

		// is_singular() does not guarantee the global post (RI-13): resolve once,
		// gate every post-derived variable on it and OMIT them when null - never
		// placeholders, a GTM trigger may test for key presence.
		$post = get_post();

		if ( is_singular() && null !== $post ) {
			$data_layer = $this->add_singular_variables( $data_layer, $post );
		}

		if ( is_archive() || is_post_type_archive() ) {
			$data_layer = $this->add_archive_variables( $data_layer );
		}

		if ( is_search() ) {
			$data_layer = $this->add_search_variables( $data_layer );
		}

		$data_layer = $this->add_query_variables( $data_layer, $post );

		if ( ! $this->cache_safe() && $this->opt( GTM4WP_OPTION_INCLUDE_MISCGEOCF ) ) {
			$country = $this->cloudflare_country();
			if ( null !== $country ) {
				$data_layer['geoCloudflareCountryCode'] = $country;
			}
		}

		return $data_layer;
	}

	/**
	 * Whether the cache-safe data layer is on (issue #398): visitor/session
	 * values are then omitted from the cacheable HTML and delivered client-side
	 * where possible (see declare_visitor_scoped_fields()).
	 *
	 * @return bool
	 */
	private function cache_safe(): bool {
		return (bool) $this->opt( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );
	}

	/**
	 * Whether post/term derived values report the site's default language
	 * (#145); each call site short-circuits on it so the off path is unchanged.
	 *
	 * @return bool
	 */
	private function master_language(): bool {
		return (bool) $this->opt( GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE );
	}

	/**
	 * The siteID / siteName variables. Multisite reports the current site's details; a single
	 * site reports its own id (1) and name - never the 0 / '' placeholders 1.x
	 * emitted - and only the variable whose option is on (#276).
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_site_variables( array $data_layer ): array {
		$include_id   = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_SITEID );
		$include_name = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_SITENAME );

		if ( ! $include_id && ! $include_name ) {
			return $data_layer;
		}

		$details = function_exists( 'get_blog_details' ) ? get_blog_details() : false;

		if ( is_object( $details ) ) {
			// blog_id is a numeric STRING; typed since the encode no longer coerces.
			$site_id   = (int) $details->blog_id;
			$site_name = (string) $details->blogname;
		} else {
			$site_id   = (int) get_current_blog_id();
			$site_name = (string) get_bloginfo( 'name' );
		}

		if ( $include_id ) {
			$data_layer['siteID'] = $site_id;
		}

		if ( $include_name ) {
			$data_layer['siteName'] = $site_name;
		}

		return $data_layer;
	}

	/**
	 * The visitor variables of the classic (non cache-safe) data layer: login
	 * state, roles, the identity variables, user id and IP. The identity
	 * variables are OMITTED for a visitor who is not logged in or has no
	 * value, exactly as the cache-safe tier omits them (#277).
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_visitor_variables( array $data_layer ): array {
		if ( $this->cache_safe() ) {
			return $data_layer;
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_LOGGEDIN ) ) {
			$data_layer['visitorLoginState'] = is_user_logged_in() ? 'logged-in' : 'logged-out';
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERROLE ) || $this->opt( GTM4WP_OPTION_INCLUDE_USEREMAIL ) || $this->opt( GTM4WP_OPTION_INCLUDE_USERREGDATE ) || $this->opt( GTM4WP_OPTION_INCLUDE_USERNAME ) ) {
			$current_user = wp_get_current_user();

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERROLE ) ) {
				$data_layer['visitorType'] = ( 0 === $current_user->ID ? 'visitor-logged-out' : implode( ',', $current_user->roles ) );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USEREMAIL ) ) {
				$email = self::user_email( $current_user );
				if ( null !== $email ) {
					$data_layer['visitorEmail']     = $email;
					$data_layer['visitorEmailHash'] = self::user_email_hash( $email );
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERREGDATE ) ) {
				$registered = self::user_registration_timestamp( $current_user );
				if ( null !== $registered ) {
					$data_layer['visitorRegistrationDate'] = $registered;
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERNAME ) ) {
				$login = self::user_login( $current_user );
				if ( null !== $login ) {
					$data_layer['visitorUsername'] = $login;
				}
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERID ) ) {
			$_gtm4wp_userid = get_current_user_id();
			if ( $_gtm4wp_userid > 0 ) {
				$data_layer['visitorId'] = $_gtm4wp_userid;
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP ) ) {
			// Passed raw: the sink escapes via wp_json_encode() + hex flags, and
			// VisitorIp::get() already validates it as an IP.
			$data_layer['visitorIP'] = VisitorIp::get(
				(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ),
				(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )
			);
		}

		return $data_layer;
	}

	/**
	 * The user's email address, or null for a logged-out user or an empty value.
	 *
	 * @param object $user The current user (WP_User).
	 * @return string|null
	 */
	private static function user_email( object $user ): ?string {
		if ( empty( $user->ID ) || empty( $user->user_email ) ) {
			return null;
		}

		return (string) $user->user_email;
	}

	/**
	 * SHA-256 of the email address, normalized like the e-commerce user_data
	 * hashes (lower-cased, gmail dots and plus tags folded) so it matches what
	 * Google's user-provided data matching expects (#277).
	 *
	 * @param string $email The address.
	 * @return string
	 */
	private static function user_email_hash( string $email ): string {
		return EcommerceHelpers::normalize_and_hash_email_address( 'sha256', $email );
	}

	/**
	 * The user's registration date as a Unix timestamp, or null for a
	 * logged-out user, an empty value or the '0000-00-00' rows imports leave
	 * behind (strtotime() would turn those into a year-0 timestamp).
	 *
	 * @param object $user The current user (WP_User).
	 * @return int|null
	 */
	private static function user_registration_timestamp( object $user ): ?int {
		if ( empty( $user->ID ) || empty( $user->user_registered ) ) {
			return null;
		}

		$registered = (string) $user->user_registered;

		if ( 0 === strpos( $registered, '0000-00-00' ) ) {
			return null;
		}

		$timestamp = strtotime( $registered );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * The user's login name, or null for a logged-out user or an empty value.
	 *
	 * @param object $user The current user (WP_User).
	 * @return string|null
	 */
	private static function user_login( object $user ): ?string {
		if ( empty( $user->ID ) || empty( $user->user_login ) ) {
			return null;
		}

		return (string) $user->user_login;
	}

	/**
	 * The pageTitle variable, as the visitor reads it: the wp_title() / the_title filter
	 * chains texturize and entity-encode ("Marks &#038; Spencer", "Tom&#8217;s"),
	 * so the entities are decoded and the JSON sink escapes the text once (#273).
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_page_title( array $data_layer ): array {
		if ( ! $this->opt( GTM4WP_OPTION_INCLUDE_POSTTITLE ) ) {
			return $data_layer;
		}

		$page_title = wp_title( '|', false, 'right' );

		// Master language: overridden only when a distinct master post exists,
		// so an untranslated post keeps the wp_title() output.
		if ( $this->master_language() && is_singular() ) {
			$_post_id        = (int) get_the_ID();
			$_master_post_id = DefaultLanguage::post_id( $_post_id, (string) get_post_type() );
			if ( $_master_post_id > 0 && $_master_post_id !== $_post_id ) {
				$_master_title = (string) get_the_title( $_master_post_id );
				if ( '' !== $_master_title ) {
					$page_title = $_master_title;
				}
			}
		}

		$data_layer['pageTitle'] = html_entity_decode( wp_strip_all_tags( $page_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return $data_layer;
	}

	/**
	 * The pageLanguage variable: WPML, then Polylang, then the site locale, filterable.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_page_language( array $data_layer ): array {
		if ( ! $this->opt( GTM4WP_OPTION_INCLUDE_PAGELANGUAGE ) ) {
			return $data_layer;
		}

		$page_language = '';

		// WPML exposes the currently active language through this filter.
		if ( has_filter( 'wpml_current_language' ) ) {
			$page_language = (string) apply_filters( 'wpml_current_language', null );
		}

		// Polylang exposes the currently active language through its own function.
		if ( '' === $page_language && function_exists( 'pll_current_language' ) ) {
			$page_language = (string) pll_current_language();
		}

		// Fall back to the site locale.
		if ( '' === $page_language ) {
			$page_language = get_locale();
		}

		/**
		 * Filters the language code reported for the current page.
		 *
		 * @since 2.0
		 *
		 * @param string $page_language Detected language code (WPML/Polylang aware, falls back to the site locale).
		 *
		 * @return string Language code to output into the data layer.
		 */
		$data_layer['pageLanguage'] = (string) apply_filters( 'gtm4wp_page_language', $page_language );

		return $data_layer;
	}

	/**
	 * Every post-derived variable of a singular page, in the 1.x key order.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @param object               $post       The resolved post (WP_Post).
	 * @return array<string, mixed>
	 */
	private function add_singular_variables( array $data_layer, object $post ): array {
		$data_layer = $this->add_post_type_and_term_variables( $data_layer );
		$data_layer = $this->add_post_author_variables( $data_layer, $post );
		$data_layer = $this->add_post_date_variables( $data_layer );
		$data_layer = $this->add_post_terms_and_meta( $data_layer, $post );
		$data_layer = $this->add_post_content_variables( $data_layer, $post );
		$data_layer = $this->add_primary_category( $data_layer );

		return $data_layer;
	}

	/**
	 * The pagePostType / pagePostType2, pageCategory and pageAttributes variables of a post.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_post_type_and_term_variables( array $data_layer ): array {
		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) ) {
			$data_layer['pagePostType']  = get_post_type();
			$data_layer['pagePostType2'] = 'single-' . get_post_type();
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_CATEGORIES ) ) {
			$_post_cats = get_the_category();
			if ( $_post_cats ) {
				$data_layer['pageCategory'] = $this->build_category_slugs( $_post_cats );
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_TAGS ) ) {
			$_post_tags = get_the_tags();
			if ( $_post_tags ) {
				$data_layer['pageAttributes'] = array();
				foreach ( $_post_tags as $_one_tag ) {
					$data_layer['pageAttributes'][] = $this->master_language()
						? $this->localized_term_field( (int) $_one_tag->term_id, 'post_tag', 'slug', (string) $_one_tag->slug )
						: $_one_tag->slug;
				}
			}
		}

		return $data_layer;
	}

	/**
	 * The pagePostAuthor / pagePostAuthorID variables, plus the plural ones on a post
	 * with several PublishPress authors.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @param object               $post       The resolved post (WP_Post).
	 * @return array<string, mixed>
	 */
	private function add_post_author_variables( array $data_layer, object $post ): array {
		if ( ! $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) && ! $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
			return $data_layer;
		}

		// PublishPress Authors (co-authors, guest authors): the single-value
		// vars come from its primary author (also covers a single GUEST
		// author, who is not the user in $post->post_author); the array vars
		// are added only with MORE than one author. Otherwise the
		// get_userdata() fallback is unchanged.
		$multiple_authors = array();
		if ( function_exists( 'get_multiple_authors' ) ) {
			$ppress_authors = get_multiple_authors( $post->ID );
			if ( is_array( $ppress_authors ) ) {
				// PublishPress puts a false in place of an author it cannot
				// resolve, and read_author_prop() declares object. Filtered
				// HERE, not in the loop, because the count decides whether the
				// array vars are emitted; all-unresolvable takes the fallback.
				$multiple_authors = array_values( array_filter( $ppress_authors, 'is_object' ) );
			}
		}

		if ( count( $multiple_authors ) >= 1 ) {
			$author_names = array();
			$author_ids   = array();

			// Passed RAW (the sink escapes, RI-2/RI-4); IDs typed (int) since
			// PublishPress may expose numeric strings.
			foreach ( $multiple_authors as $one_author ) {
				$author_names[] = self::read_author_prop( $one_author, 'display_name', '' );
				$author_ids[]   = (int) self::read_author_prop( $one_author, 'ID', 0 );
			}

			$has_multiple = count( $multiple_authors ) > 1;

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) ) {
				$data_layer['pagePostAuthorID'] = $author_ids[0];

				if ( $has_multiple ) {
					/**
					 * Filters the list of post author IDs output into the data layer
					 * on a post with multiple authors (PublishPress Authors).
					 *
					 * @since 2.0
					 *
					 * @param array $author_ids       List of author IDs (WordPress user IDs; guest authors use a negative term id).
					 * @param array $multiple_authors The PublishPress Author objects the IDs were built from.
					 *
					 * @return array Author IDs to output into the data layer.
					 */
					$data_layer['pagePostAuthorIDs'] = apply_filters( 'gtm4wp_page_post_author_ids', $author_ids, $multiple_authors );
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
				$data_layer['pagePostAuthor'] = $author_names[0];

				if ( $has_multiple ) {
					/**
					 * Filters the list of post author display names output into the
					 * data layer on a post with multiple authors (PublishPress Authors).
					 *
					 * @since 2.0
					 *
					 * @param array $author_names     List of author display names.
					 * @param array $multiple_authors The PublishPress Author objects the names were built from.
					 *
					 * @return array Author display names to output into the data layer.
					 */
					$data_layer['pagePostAuthors'] = apply_filters( 'gtm4wp_page_post_authors', $author_names, $multiple_authors );
				}
			}

			return $data_layer;
		}

		$postuser = get_userdata( $post->post_author );

		if ( false !== $postuser ) {
			if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) ) {
				$data_layer['pagePostAuthorID'] = (int) $postuser->ID;
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
				$data_layer['pagePostAuthor'] = $postuser->display_name;
			}
		}

		return $data_layer;
	}

	/**
	 * The pagePostDate* variables.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_post_date_variables( array $data_layer ): array {
		if ( ! $this->opt( GTM4WP_OPTION_INCLUDE_POSTDATE ) ) {
			return $data_layer;
		}

		$data_layer['pagePostDate']        = get_the_date();
		$data_layer['pagePostDateYear']    = get_the_date( 'Y' );
		$data_layer['pagePostDateMonth']   = get_the_date( 'm' );
		$data_layer['pagePostDateDay']     = get_the_date( 'd' );
		$data_layer['pagePostDateDayName'] = get_the_date( 'l' );
		$data_layer['pagePostDateHour']    = get_the_date( 'H' );
		$data_layer['pagePostDateMinute']  = get_the_date( 'i' );
		$data_layer['pagePostDateIso']     = get_the_date( 'c' );
		// Typed (int); the zero-padded parts above stay strings ("07" != 7).
		$data_layer['pagePostDateUnix'] = (int) get_the_date( 'U' );

		return $data_layer;
	}

	/**
	 * The pagePostTerms container: terms and meta are separate opt-ins since 2.0 but share
	 * the container (pagePostTerms.<taxonomy> / pagePostTerms.meta) so
	 * existing GTM variable paths keep working. Built locally and assigned
	 * only when non-empty: [] is truthy in JavaScript and a different type
	 * from the populated object (#275).
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @param object               $post       The resolved post (WP_Post).
	 * @return array<string, mixed>
	 */
	private function add_post_terms_and_meta( array $data_layer, object $post ): array {
		$include_post_terms = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_POSTTERMLIST );
		$include_post_meta  = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_POSTMETA );

		if ( ! $include_post_terms && ! $include_post_meta ) {
			return $data_layer;
		}

		$post_terms = array();

		if ( $include_post_terms ) {
			$object_taxonomies = get_object_taxonomies( get_post_type() );

			foreach ( $object_taxonomies as $one_object_taxonomy ) {
				$post_taxonomy_values = get_the_terms( $post->ID, $one_object_taxonomy );
				if ( is_array( $post_taxonomy_values ) ) {
					$post_terms[ $one_object_taxonomy ] = array();
					foreach ( $post_taxonomy_values as $one_taxonomy_value ) {
						// As typed, not as stored ("Shirts &amp; Ties"): the same
						// decode the e-commerce items use.
						$post_terms[ $one_object_taxonomy ][] = EcommerceHelpers::decode_term_name(
							$this->master_language()
								? $this->localized_term_field( (int) $one_taxonomy_value->term_id, $one_object_taxonomy, 'name', (string) $one_taxonomy_value->name )
								: (string) $one_taxonomy_value->name
						);
					}
				}
			}
		}

		if ( $include_post_meta ) {
			$meta_values = $this->post_meta_values( (int) $post->ID );

			// Omit an empty container: [] is truthy in JavaScript and a
			// different type from the populated object (RI-13/RI-20).
			if ( array() !== $meta_values ) {
				$post_terms['meta'] = $meta_values;
			}
		}

		if ( array() !== $post_terms ) {
			$data_layer['pagePostTerms'] = $post_terms;
		}

		return $data_layer;
	}

	/**
	 * The post meta values allowed into pagePostTerms.meta: narrowed by the
	 * key allow-list, then the protected gate, then the per-key filter (the
	 * order of the three guards is pinned by tests).
	 *
	 * @param int $post_id The post.
	 * @return array<string, mixed>
	 */
	private function post_meta_values( int $post_id ): array {
		// get_post_meta() WITHOUT a key does NOT unserialize, so every
		// array value arrives as a serialized string (dropped below).
		$post_meta = get_post_meta( $post_id );
		if ( ! is_array( $post_meta ) ) {
			return array();
		}

		$allowed_meta_keys = self::parse_meta_key_list( Field::to_string( $this->opt( GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS ) ) );

		$meta_values = array();
		foreach ( $post_meta as $post_meta_key => $post_meta_value ) {
			// The allow-list narrows what may be CONSIDERED; it is not a
			// grant, the protected gate and the filter below still apply.
			// Empty keeps the 1.x "everything not protected" behaviour.
			if ( array() !== $allowed_meta_keys && ! in_array( $post_meta_key, $allowed_meta_keys, true ) ) {
				continue;
			}

			// The underscore test is the FLOOR; is_protected_meta() only
			// adds to it. Do NOT rely on is_protected_meta() alone: its
			// filter can UNPROTECT an underscore key, and its purpose is
			// admin-UI visibility, not public-output privacy.
			if (
				'_' === substr( $post_meta_key, 0, 1 )
				|| is_protected_meta( $post_meta_key, 'post' )
			) {
				continue;
			}

			/**
			 * Filters whether a post meta key is included in the data layer.
			 *
			 * @since 1.17
			 *
			 * @param bool $true_false_default The default value (true).
			 * @param string $post_meta_key The name of the post meta key to be included in the data layer.
			 *
			 * @return bool Whether to include this post meta in the data layer.
			 */
			$include_post_meta_in_datalayer = (bool) apply_filters( 'gtm4wp_post_meta_in_datalayer', true, $post_meta_key );

			if ( ! $include_post_meta_in_datalayer ) {
				continue;
			}

			// Filter FIRST, then collapse a surviving single value, or the
			// JSON type would depend on whether a serialized sibling was
			// dropped. The second call covers a single value that is
			// itself an array with packed entries.
			$post_meta_dl_value = self::drop_serialized_meta_values( $post_meta_value );

			if ( is_array( $post_meta_dl_value ) && ( 1 === count( $post_meta_dl_value ) ) ) {
				$post_meta_dl_value = self::drop_serialized_meta_values( array_values( $post_meta_dl_value )[0] );
			}

			// Nothing usable: OMIT the key (RI-13).
			if ( null === $post_meta_dl_value ) {
				continue;
			}

			$meta_values[ $post_meta_key ] = $post_meta_dl_value;
		}

		return $meta_values;
	}

	/**
	 * The content metrics and post details: word count, reading time, the
	 * pageModifiedDate* variables, content age, comments, template, featured
	 * image, hierarchy and sticky state.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @param object               $post       The resolved post (WP_Post).
	 * @return array<string, mixed>
	 */
	private function add_post_content_variables( array $data_layer, object $post ): array {
		if ( $this->opt( GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT ) || $this->opt( GTM4WP_OPTION_INCLUDE_READINGTIME ) ) {
			$post_content = (string) get_post_field( 'post_content', get_the_ID() );
			$word_count   = self::count_words( wp_strip_all_tags( strip_shortcodes( $post_content ) ) );

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT ) ) {
				$data_layer['pageContentWordCount'] = (int) $word_count;
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_READINGTIME ) ) {
				/**
				 * Filters the words-per-minute reading speed used to estimate the
				 * reading time of the current post.
				 *
				 * @since 2.0
				 *
				 * @param int $words_per_minute Default reading speed (200 words per minute).
				 *
				 * @return int Words-per-minute rate.
				 */
				$words_per_minute = (int) apply_filters( 'gtm4wp_reading_time_wpm', 200 );
				if ( $words_per_minute < 1 ) {
					$words_per_minute = 200;
				}

				$data_layer['pageReadingTime'] = (int) max( 1, (int) ceil( $word_count / $words_per_minute ) );
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_MODIFIEDDATE ) ) {
			$data_layer['pageModifiedDate']        = get_the_modified_date();
			$data_layer['pageModifiedDateYear']    = get_the_modified_date( 'Y' );
			$data_layer['pageModifiedDateMonth']   = get_the_modified_date( 'm' );
			$data_layer['pageModifiedDateDay']     = get_the_modified_date( 'd' );
			$data_layer['pageModifiedDateDayName'] = get_the_modified_date( 'l' );
			$data_layer['pageModifiedDateHour']    = get_the_modified_date( 'H' );
			$data_layer['pageModifiedDateMinute']  = get_the_modified_date( 'i' );
			$data_layer['pageModifiedDateIso']     = get_the_modified_date( 'c' );
			// Typed (int) for the same reason as pagePostDateUnix.
			$data_layer['pageModifiedDateUnix'] = (int) get_the_modified_date( 'U' );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_CONTENTAGE ) ) {
			$post_published_gmt = get_post_time( 'U', true );
			if ( false !== $post_published_gmt ) {
				$data_layer['pageContentAgeDays'] = (int) max( 0, floor( ( time() - $post_published_gmt ) / DAY_IN_SECONDS ) );
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_COMMENTCOUNT ) ) {
			$data_layer['pageCommentCount']  = (int) get_comments_number( get_the_ID() );
			$data_layer['pageCommentStatus'] = comments_open( get_the_ID() ) ? 'open' : 'closed';
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_PAGETEMPLATE ) ) {
			$page_template_slug         = (string) get_page_template_slug( get_the_ID() );
			$data_layer['pageTemplate'] = ( '' === $page_template_slug ? 'default' : $page_template_slug );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE ) ) {
			$data_layer['pageHasFeaturedImage'] = has_post_thumbnail( get_the_ID() );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY ) ) {
			$data_layer['pageParentID'] = (int) $post->post_parent;
			$data_layer['pageDepth']    = count( get_post_ancestors( get_the_ID() ) );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTSTICKY ) ) {
			$data_layer['pagePostSticky'] = is_sticky( get_the_ID() );
		}

		return $data_layer;
	}

	/**
	 * The pagePrimaryCategory / pagePrimaryCategoryName variables: Yoast SEO, then Rank
	 * Math, then the first category, filterable.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_primary_category( array $data_layer ): array {
		if ( ! $this->opt( GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY ) ) {
			return $data_layer;
		}

		$primary_category_id = 0;

		// Yoast SEO stores the chosen primary category id in post meta.
		$yoast_primary = get_post_meta( get_the_ID(), '_yoast_wpseo_primary_category', true );
		if ( '' !== $yoast_primary ) {
			$primary_category_id = (int) $yoast_primary;
		}

		// Rank Math stores the chosen primary term id in post meta.
		if ( 0 === $primary_category_id ) {
			$rankmath_primary = get_post_meta( get_the_ID(), 'rank_math_primary_category', true );
			if ( '' !== $rankmath_primary ) {
				$primary_category_id = (int) $rankmath_primary;
			}
		}

		// Fall back to the first category assigned to the post.
		if ( 0 === $primary_category_id ) {
			$post_categories = get_the_category();
			if ( ! empty( $post_categories ) ) {
				$primary_category_id = (int) $post_categories[0]->term_id;
			}
		}

		/**
		 * Filters the term id used as the primary category of the current post
		 * (e.g. for another SEO plugin or a custom taxonomy).
		 *
		 * @since 2.0
		 *
		 * @param int $primary_category_id Detected primary category term id (0 when none found).
		 * @param int $post_id             Id of the current post.
		 *
		 * @return int Term id to use as the primary category.
		 */
		$primary_category_id = (int) apply_filters( 'gtm4wp_primary_category_term_id', $primary_category_id, get_the_ID() );

		if ( $primary_category_id > 0 ) {
			if ( $this->master_language() ) {
				$primary_category_id = DefaultLanguage::term_id( $primary_category_id, 'category' );
			}

			$primary_category_term = get_term( $primary_category_id );
			if ( $primary_category_term instanceof \WP_Term ) {
				$data_layer['pagePrimaryCategory']     = $primary_category_term->slug;
				$data_layer['pagePrimaryCategoryName'] = EcommerceHelpers::decode_term_name( (string) $primary_category_term->name );
			}
		}

		return $data_layer;
	}

	/**
	 * The archive variables: pagePostType / pagePostType2 with the date parts
	 * of a date archive, pageCategory on a term archive, the author on an
	 * author archive.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_archive_variables( array $data_layer ): array {
		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) ) {
			$data_layer['pagePostType'] = get_post_type();

			if ( is_category() ) {
				$data_layer['pagePostType2'] = 'category-' . get_post_type();
			} elseif ( is_tag() ) {
				$data_layer['pagePostType2'] = 'tag-' . get_post_type();
			} elseif ( is_tax() ) {
				$data_layer['pagePostType2'] = 'tax-' . get_post_type();
			} elseif ( is_author() ) {
				$data_layer['pagePostType2'] = 'author-' . get_post_type();
			} elseif ( is_year() ) {
				$data_layer['pagePostType2'] = 'year-' . get_post_type();

				if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTDATE ) ) {
					$data_layer['pagePostDateYear'] = get_the_date( 'Y' );
				}
			} elseif ( is_month() ) {
				$data_layer['pagePostType2'] = 'month-' . get_post_type();

				if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTDATE ) ) {
					$data_layer['pagePostDateYear']  = get_the_date( 'Y' );
					$data_layer['pagePostDateMonth'] = get_the_date( 'm' );
				}
			} elseif ( is_day() ) {
				$data_layer['pagePostType2'] = 'day-' . get_post_type();

				if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTDATE ) ) {
					$data_layer['pagePostDate']      = get_the_date();
					$data_layer['pagePostDateYear']  = get_the_date( 'Y' );
					$data_layer['pagePostDateMonth'] = get_the_date( 'm' );
					$data_layer['pagePostDateDay']   = get_the_date( 'd' );
				}
			} elseif ( is_time() ) {
				$data_layer['pagePostType2'] = 'time-' . get_post_type();
			} elseif ( is_date() ) {
				$data_layer['pagePostType2'] = 'date-' . get_post_type();

				if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTDATE ) ) {
					$data_layer['pagePostDate']      = get_the_date();
					$data_layer['pagePostDateYear']  = get_the_date( 'Y' );
					$data_layer['pagePostDateMonth'] = get_the_date( 'm' );
					$data_layer['pagePostDateDay']   = get_the_date( 'd' );
				}
			}
		}

		if ( ( is_tax() || is_category() ) && $this->opt( GTM4WP_OPTION_INCLUDE_CATEGORIES ) ) {
			$data_layer['pageCategory'] = $this->build_category_slugs( get_the_category() );
		}

		// is_author() does not guarantee $authordata (RI-13): resolve once and
		// OMIT both keys when unavailable, like the singular block.
		if ( is_author() ) {
			global $authordata;

			// Through the RI-12-safe accessor, not isset(): the object may expose
			// ID via __get() without __isset().
			$author_id = is_object( $authordata )
				? self::read_author_prop( $authordata, 'ID', null )
				: null;

			if ( null !== $author_id && $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) ) {
				$data_layer['pagePostAuthorID'] = (int) $author_id;
			}

			if ( null !== $author_id && $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
				$data_layer['pagePostAuthor'] = get_the_author();
			}
		}

		return $data_layer;
	}

	/**
	 * The search page: pagePostType and, with the option on, the search term,
	 * its origin and the result count.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @return array<string, mixed>
	 */
	private function add_search_variables( array $data_layer ): array {
		global $wp_query;

		$data_layer['pagePostType'] = 'search-results';

		if ( ! $this->opt( GTM4WP_OPTION_INCLUDE_SEARCHDATA ) ) {
			return $data_layer;
		}

		// siteSearchTerm / siteSearchFrom are browser-computable, so under
		// the cache-safe data layer they are delivered client-side (see
		// declare_visitor_scoped_fields()); siteSearchResults stays server-side.
		if ( ! $this->cache_safe() ) {
			// The RAW term (#274): the hex-flag JSON sink escapes it, and the
			// cache-safe client tier sends the raw ?s= value, so both tiers
			// report one string. get_search_query() would esc_attr() it.
			$data_layer['siteSearchTerm'] = get_search_query( false );
			$data_layer['siteSearchFrom'] = '';
			if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$referer_url_parts            = explode( '?', esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
				$data_layer['siteSearchFrom'] = $referer_url_parts[0];

				if ( count( $referer_url_parts ) > 1 ) {
					$data_layer['siteSearchFrom'] = $referer_url_parts[0] . '?' . rawurlencode( $referer_url_parts[1] );
				}
			}
		}
		$data_layer['siteSearchResults'] = (int) $wp_query->post_count;

		return $data_layer;
	}

	/**
	 * The query-level variables that do not belong to one page type: the
	 * front page / blog home / 404 pagePostType, the post counts and, on a
	 * singular page, postID and postFormat.
	 *
	 * @param array<string, mixed> $data_layer The data layer so far.
	 * @param object|null          $post       The resolved post, or null.
	 * @return array<string, mixed>
	 */
	private function add_query_variables( array $data_layer, ?object $post ): array {
		global $wp_query;

		if ( is_front_page() && $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) ) {
			$data_layer['pagePostType'] = 'frontpage';
		}

		if ( ! is_front_page() && is_home() && $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) ) {
			$data_layer['pagePostType'] = 'bloghome';
		}

		if ( is_404() ) {
			$data_layer['pagePostType'] = '404-error';
		}

		// The main query global is not guaranteed either: omitted when it cannot answer.
		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTCOUNT ) && isset( $wp_query->post_count, $wp_query->found_posts ) ) {
			$data_layer['postCountOnPage'] = (int) $wp_query->post_count;
			$data_layer['postCountTotal']  = (int) $wp_query->found_posts;
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTID ) && is_singular() === true && null !== $post ) {
			$data_layer['postID'] = (int) get_the_ID();
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTFORMAT ) && is_singular() === true && null !== $post ) {
			// get_post_format() returns false for a standard post.
			$post_format              = get_post_format();
			$data_layer['postFormat'] = $post_format ? $post_format : 'standard';
		}

		return $data_layer;
	}

	/**
	 * The Cloudflare country code header, or null. Cloudflare REPLACES the
	 * header, so it only means anything when the request came through
	 * Cloudflare: gated on the trusted-proxy list like visitorIP (#272,
	 * RI-18); an empty list keeps the unverified read of 1.x. Sanitized here,
	 * hex-encoded by the sink (RI-4).
	 *
	 * @return string|null
	 */
	private function cloudflare_country(): ?string {
		if ( ! isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return null;
		}

		$trusted = VisitorIp::parse_trusted_proxies( (string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES ) );

		if ( array() !== $trusted && ! VisitorIp::request_via_trusted_proxy( $trusted ) ) {
			return null;
		}

		$country = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );

		return '' === $country ? null : $country;
	}

	/**
	 * Counts the words of a plain-text string in a UTF-8 aware way. Not
	 * str_word_count(): it recognizes ASCII letters only, so it returned 0 for
	 * Cyrillic/Greek/Arabic/CJK and mis-counted Latin diacritics. Space-delimited
	 * scripts are split on Unicode whitespace; each CJK character counts as one
	 * word (the word-processor approximation) and is removed before the split so
	 * mixed content is not counted twice.
	 *
	 * @param string $text Plain text (tags and shortcodes already stripped).
	 * @return int Number of words, 0 for empty/whitespace-only input.
	 */
	private static function count_words( string $text ): int {
		$cjk_pattern = '/[\x{1100}-\x{11FF}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{A960}-\x{A97F}\x{AC00}-\x{D7FF}\x{F900}-\x{FAFF}\x{20000}-\x{2FA1F}]/u';

		$cjk_count = preg_match_all( $cjk_pattern, $text );
		if ( false === $cjk_count ) {
			// The PCRE unicode pass failed (no UTF-8 support / invalid sequence):
			// fall back to the historical behavior rather than reporting nothing.
			return str_word_count( $text );
		}

		$remainder = (string) preg_replace( $cjk_pattern, ' ', $text );

		$words = preg_split( '/[\p{Z}\s]+/u', trim( $remainder ), -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words ) {
			return $cjk_count + str_word_count( $remainder );
		}

		return $cjk_count + count( $words );
	}

	/**
	 * Reads a property from a PublishPress author object without depending on
	 * __isset() (#43, RI-12): the Author objects resolve display_name/ID through
	 * __get(), and isset()/??/empty() consult __isset() first, which they need
	 * not implement, so every author would silently blank out. Guarded by
	 * property_exists() / method_exists('__get') so no undefined-property warning.
	 *
	 * @param object $author   The author object (PublishPress Author or a plain object).
	 * @param string $prop     The property name to read.
	 * @param mixed  $fallback Returned when the object exposes the property in neither way, or it is null.
	 * @return mixed
	 */
	private static function read_author_prop( object $author, string $prop, $fallback ) {
		if ( property_exists( $author, $prop ) || method_exists( $author, '__get' ) ) {
			$value = $author->$prop;
			if ( null !== $value ) {
				return $value;
			}
		}

		return $fallback;
	}

	/**
	 * Declares the page-variables fields delivered outside the cacheable HTML
	 * under the cache-safe data layer (issue #398). Tier 1 (search term and
	 * referrer) only on a search results page, where they are output; Tier 2/3
	 * (visitor IP, Cloudflare country, logged-in-user fields) independent of the
	 * page type, because the session endpoint has no page context.
	 *
	 * @param array<int, VisitorField> $fields Visitor-scoped fields declared so far.
	 * @return array<int, VisitorField>
	 */
	public function declare_visitor_scoped_fields( array $fields ): array {
		if ( ! $this->opt( GTM4WP_OPTION_CACHE_SAFE_DATALAYER ) ) {
			return $fields;
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_SEARCHDATA ) && is_search() ) {
			$fields[] = new VisitorField( 'siteSearchTerm', VisitorField::TIER_CLIENT, 'searchTerm' );
			$fields[] = new VisitorField( 'siteSearchFrom', VisitorField::TIER_CLIENT, 'searchReferrer' );
		}

		return $this->declare_server_visitor_fields( $fields );
	}

	/**
	 * Declares the Tier 2 (session) and Tier 3 (logged-in user) fields, each
	 * gated on its own option. The resolver is the field's identity gate: the
	 * user resolvers return null for an anonymous request.
	 *
	 * @param array<int, VisitorField> $fields Visitor-scoped fields declared so far.
	 * @return array<int, VisitorField>
	 */
	private function declare_server_visitor_fields( array $fields ): array {
		$login_gate = VisitorDataModule::LOGIN_GATE_COOKIE;

		// Tier 2: server-only but constant per session; fetched once per session.
		if ( $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP ) ) {
			$fields[] = new VisitorField( 'visitorIP', VisitorField::TIER_SESSION, '', array( $this, 'resolve_visitor_ip' ) );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_MISCGEOCF ) ) {
			$fields[] = new VisitorField( 'geoCloudflareCountryCode', VisitorField::TIER_SESSION, '', array( $this, 'resolve_cloudflare_country' ) );
		}

		// Tier 3: logged-in user data; fetched only when the login gate cookie changed.
		if ( $this->opt( GTM4WP_OPTION_INCLUDE_LOGGEDIN ) ) {
			$fields[] = new VisitorField( 'visitorLoginState', VisitorField::TIER_ACTION, '', array( $this, 'resolve_visitor_login_state' ), $login_gate );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERROLE ) ) {
			$fields[] = new VisitorField( 'visitorType', VisitorField::TIER_ACTION, '', array( $this, 'resolve_visitor_type' ), $login_gate );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USEREMAIL ) ) {
			$fields[] = new VisitorField( 'visitorEmail', VisitorField::TIER_ACTION, '', array( $this, 'resolve_visitor_email' ), $login_gate );
			$fields[] = new VisitorField( 'visitorEmailHash', VisitorField::TIER_ACTION, '', array( $this, 'resolve_visitor_email_hash' ), $login_gate );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERREGDATE ) ) {
			$fields[] = new VisitorField( 'visitorRegistrationDate', VisitorField::TIER_ACTION, '', array( $this, 'resolve_visitor_registration_date' ), $login_gate );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERNAME ) ) {
			$fields[] = new VisitorField( 'visitorUsername', VisitorField::TIER_ACTION, '', array( $this, 'resolve_visitor_username' ), $login_gate );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERID ) ) {
			$fields[] = new VisitorField( 'visitorId', VisitorField::TIER_ACTION, '', array( $this, 'resolve_visitor_id' ), $login_gate );
		}

		return $fields;
	}

	/**
	 * Tier 2 resolver: the validated visitor IP, or null.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_ip(): ?string {
		$ip = VisitorIp::get(
			(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ),
			(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )
		);

		return '' === $ip ? null : $ip;
	}

	/**
	 * Tier 2 resolver: the Cloudflare country code header, or null. Sanitized on
	 * the way in, hex-encoded by the endpoint on the way out (RI-4).
	 *
	 * @return string|null
	 */
	public function resolve_cloudflare_country(): ?string {
		return $this->cloudflare_country();
	}

	/**
	 * Tier 3 resolver: 'logged-in' for an authenticated request, null otherwise
	 * (the identity gate).
	 *
	 * @return string|null
	 */
	public function resolve_visitor_login_state(): ?string {
		return is_user_logged_in() ? 'logged-in' : null;
	}

	/**
	 * Tier 3 resolver: the current user's roles as a comma separated list, or null
	 * for an anonymous request.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_type(): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		return implode( ',', wp_get_current_user()->roles );
	}

	/**
	 * Tier 3 resolver: the current user's email address, or null for an anonymous
	 * request. Passed raw; the endpoint hex-encodes it on output.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_email(): ?string {
		return is_user_logged_in() ? self::user_email( wp_get_current_user() ) : null;
	}

	/**
	 * Tier 3 resolver: the normalized SHA-256 hash of the current user's email
	 * address, or null for an anonymous request or an empty address.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_email_hash(): ?string {
		$email = $this->resolve_visitor_email();

		return null === $email ? null : self::user_email_hash( $email );
	}

	/**
	 * Tier 3 resolver: the Unix timestamp of the current user's registration date,
	 * or null for an anonymous request or a missing date (#277).
	 *
	 * @return int|null
	 */
	public function resolve_visitor_registration_date(): ?int {
		return is_user_logged_in() ? self::user_registration_timestamp( wp_get_current_user() ) : null;
	}

	/**
	 * Tier 3 resolver: the current user's login name, or null for an anonymous
	 * request or an empty value. Passed raw; the endpoint hex-encodes it on output.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_username(): ?string {
		return is_user_logged_in() ? self::user_login( wp_get_current_user() ) : null;
	}

	/**
	 * Tier 3 resolver: the current user's id, or null for an anonymous request.
	 *
	 * @return int|null
	 */
	public function resolve_visitor_id(): ?int {
		$user_id = get_current_user_id();

		return $user_id > 0 ? $user_id : null;
	}

	/**
	 * Parses the post-meta allow-list option (one key per line or comma
	 * separated). Shared by the AdminSchema sanitizer and the read above so what
	 * is stored is what the reader honours (PA-2).
	 *
	 * @param string $value Raw option value.
	 * @return array<int, string> De-duplicated meta keys, empty when the option is blank.
	 */
	public static function parse_meta_key_list( string $value ): array {
		$entries = preg_split( '/[\r\n,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$keys = array();
		foreach ( $entries as $one_entry ) {
			$one_key = trim( $one_entry );
			if ( '' !== $one_key ) {
				$keys[] = $one_key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Removes serialized values from a post meta value: an a:8:{...} blob is a
	 * plugin's internal storage no GTM variable can read, while publishing its
	 * whole structure. Skipped rather than unserialized on purpose (object
	 * instantiation over every custom field, for data nobody asked for).
	 *
	 * @param mixed $value Single meta value, or the list of values for a multi-value key.
	 * @return mixed The value with serialized entries removed, or null when nothing usable is left.
	 */
	private static function drop_serialized_meta_values( $value ) {
		if ( is_array( $value ) ) {
			$kept = array();
			foreach ( $value as $one_value ) {
				if ( ! ( is_string( $one_value ) && is_serialized( $one_value ) ) ) {
					$kept[] = $one_value;
				}
			}

			return array() === $kept ? null : $kept;
		}

		if ( is_string( $value ) && is_serialized( $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Builds the pageCategory slug list. With "include parent categories" on,
	 * each category's ancestor slugs (nearest first) follow its own slug and the
	 * list is de-duplicated in order; off keeps the 1.x output.
	 *
	 * @param array<int, \WP_Term> $categories Category term objects, as returned by get_the_category().
	 * @return array<int, string> List of category slugs.
	 */
	private function build_category_slugs( array $categories ): array {
		$include_parents = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES );
		$use_master      = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE );

		$slugs = array();
		foreach ( $categories as $one_cat ) {
			$slugs[] = $use_master
				? $this->localized_term_field( (int) $one_cat->term_id, 'category', 'slug', (string) $one_cat->slug )
				: $one_cat->slug;

			if ( $include_parents ) {
				foreach ( get_ancestors( $one_cat->term_id, 'category' ) as $ancestor_id ) {
					$ancestor_term = get_term( (int) $ancestor_id, 'category' );
					if ( $ancestor_term instanceof \WP_Term ) {
						$slugs[] = $use_master
							? $this->localized_term_field( (int) $ancestor_term->term_id, 'category', 'slug', (string) $ancestor_term->slug )
							: $ancestor_term->slug;
					}
				}
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Returns the slug or name of a term's default-language equivalent (issue
	 * #145), or the supplied fallback when no distinct master term resolves.
	 *
	 * @param int    $term_id  Term id in the current language.
	 * @param string $taxonomy Taxonomy of the term ('category', 'post_tag' or a custom taxonomy).
	 * @param string $field    Field to read from the resolved term: 'slug' or 'name'.
	 * @param string $fallback Current-language value to return when no master term is resolved.
	 * @return string
	 */
	private function localized_term_field( int $term_id, string $taxonomy, string $field, string $fallback ): string {
		$master_term_id = DefaultLanguage::term_id( $term_id, $taxonomy );
		if ( $master_term_id > 0 && $master_term_id !== $term_id ) {
			$master_term = get_term( $master_term_id, $taxonomy );
			if ( $master_term instanceof \WP_Term ) {
				return (string) ( 'name' === $field ? $master_term->name : $master_term->slug );
			}
		}

		return $fallback;
	}
}
