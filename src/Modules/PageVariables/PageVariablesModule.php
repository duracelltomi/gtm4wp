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

use GTM4WP\Frontend\VisitorIp;
use GTM4WP\Module\AbstractModule;
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
			GTM4WP_OPTION_INCLUDE_POSTTYPE          => true,
			GTM4WP_OPTION_INCLUDE_CATEGORIES        => true,
			GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES  => false,
			GTM4WP_OPTION_INCLUDE_TAGS              => true,
			GTM4WP_OPTION_INCLUDE_AUTHOR            => true,
			GTM4WP_OPTION_INCLUDE_AUTHORID          => false,
			GTM4WP_OPTION_INCLUDE_POSTDATE          => false,
			GTM4WP_OPTION_INCLUDE_POSTTITLE         => false,
			GTM4WP_OPTION_INCLUDE_POSTCOUNT         => false,
			GTM4WP_OPTION_INCLUDE_POSTID            => false,
			GTM4WP_OPTION_INCLUDE_POSTFORMAT        => false,
			GTM4WP_OPTION_INCLUDE_POSTTERMLIST      => false,
			GTM4WP_OPTION_INCLUDE_POSTMETA          => false,
			GTM4WP_OPTION_INCLUDE_SEARCHDATA        => false,
			GTM4WP_OPTION_INCLUDE_LOGGEDIN          => false,
			GTM4WP_OPTION_INCLUDE_USERROLE          => false,
			GTM4WP_OPTION_INCLUDE_USERID            => false,
			GTM4WP_OPTION_INCLUDE_USERNAME          => false,
			GTM4WP_OPTION_INCLUDE_USEREMAIL         => false,
			GTM4WP_OPTION_INCLUDE_USERREGDATE       => false,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP        => false,
			GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER => '',
			GTM4WP_OPTION_INCLUDE_MISCGEOCF         => false,
			GTM4WP_OPTION_INCLUDE_SITEID            => false,
			GTM4WP_OPTION_INCLUDE_SITENAME          => false,
			GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT  => false,
			GTM4WP_OPTION_INCLUDE_READINGTIME       => false,
			GTM4WP_OPTION_INCLUDE_MODIFIEDDATE      => false,
			GTM4WP_OPTION_INCLUDE_CONTENTAGE        => false,
			GTM4WP_OPTION_INCLUDE_COMMENTCOUNT      => false,
			GTM4WP_OPTION_INCLUDE_PAGETEMPLATE      => false,
			GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE     => false,
			GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY     => false,
			GTM4WP_OPTION_INCLUDE_POSTSTICKY        => false,
			GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY   => false,
			GTM4WP_OPTION_INCLUDE_PAGELANGUAGE      => false,
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
		global $wp_query;

		// When the cache-safe data layer is on (issue #398), visitor/session
		// specific values must not be baked into the cacheable page HTML: they are
		// omitted here and, where the browser can compute them itself, delivered
		// client-side instead (see declare_visitor_scoped_fields() and the
		// gtm4wp-visitor-data script). Content/URL/site data is unaffected.
		$cache_safe = (bool) $this->opt( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_SITEID ) || $this->opt( GTM4WP_OPTION_INCLUDE_SITENAME ) ) {
			$data_layer['siteID']   = 0;
			$data_layer['siteName'] = '';

			if ( function_exists( 'get_blog_details' ) ) {
				$gtm4wp_blogdetails = get_blog_details();

				// WP_Site exposes blog_id as a numeric STRING; typed here so it keeps
				// reaching GTM as a JSON number now that the data layer encode no
				// longer numeric-coerces (JSON_NUMERIC_CHECK removed).
				$data_layer['siteID']   = (int) $gtm4wp_blogdetails->blog_id;
				$data_layer['siteName'] = $gtm4wp_blogdetails->blogname;
			}
		}

		if ( ! $cache_safe && $this->opt( GTM4WP_OPTION_INCLUDE_LOGGEDIN ) ) {
			$data_layer['visitorLoginState'] = 'logged-out';

			if ( is_user_logged_in() ) {
				$data_layer['visitorLoginState'] = 'logged-in';
			}
		}

		if ( ! $cache_safe && ( $this->opt( GTM4WP_OPTION_INCLUDE_USERROLE ) || $this->opt( GTM4WP_OPTION_INCLUDE_USEREMAIL ) || $this->opt( GTM4WP_OPTION_INCLUDE_USERREGDATE ) || $this->opt( GTM4WP_OPTION_INCLUDE_USERNAME ) ) ) {
			$current_user = wp_get_current_user();

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERROLE ) ) {
				$data_layer['visitorType'] = ( 0 === $current_user->ID ? 'visitor-logged-out' : implode( ',', $current_user->roles ) );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USEREMAIL ) ) {
				$data_layer['visitorEmail']     = ( empty( $current_user->user_email ) ? '' : $current_user->user_email );
				$data_layer['visitorEmailHash'] = ( empty( $current_user->user_email ) ? '' : hash( 'sha256', $current_user->user_email ) );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERREGDATE ) ) {
				$data_layer['visitorRegistrationDate'] = ( empty( $current_user->user_registered ) ? '' : strtotime( $current_user->user_registered ) );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERNAME ) ) {
				$data_layer['visitorUsername'] = ( empty( $current_user->user_login ) ? '' : $current_user->user_login );
			}
		}

		if ( ! $cache_safe && $this->opt( GTM4WP_OPTION_INCLUDE_USERID ) ) {
			$_gtm4wp_userid = get_current_user_id();
			if ( $_gtm4wp_userid > 0 ) {
				$data_layer['visitorId'] = $_gtm4wp_userid;
			}
		}

		if ( ! $cache_safe && $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP ) ) {
			// Passed raw: the data layer output sink runs every value through
			// wp_json_encode() with the full hex flag set, which is the correct
			// escaper for the inline-script context. VisitorIp::get() already
			// validates the value with filter_var( FILTER_VALIDATE_IP ).
			$data_layer['visitorIP'] = VisitorIp::get( (string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ) );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTTITLE ) ) {
			$data_layer['pageTitle'] = wp_strip_all_tags( wp_title( '|', false, 'right' ) );
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_PAGELANGUAGE ) ) {
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
		}

		// is_singular() does not guarantee the global post object is set up
		// (unusual template routing, a plugin resetting the global), so the
		// singular blocks below resolve it once into this nullable local, gate
		// every post-derived variable on it and simply OMIT those variables
		// when it is null - never ''/0/false placeholders, because a GTM
		// trigger may test for key presence.
		$post = get_post();

		if ( is_singular() ) {
			if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) && null !== $post ) {
				$data_layer['pagePostType']  = get_post_type();
				$data_layer['pagePostType2'] = 'single-' . get_post_type();
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_CATEGORIES ) && null !== $post ) {
				$_post_cats = get_the_category();
				if ( $_post_cats ) {
					$data_layer['pageCategory'] = $this->build_category_slugs( $_post_cats );
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_TAGS ) && null !== $post ) {
				$_post_tags = get_the_tags();
				if ( $_post_tags ) {
					$data_layer['pageAttributes'] = array();
					foreach ( $_post_tags as $_one_tag ) {
						$data_layer['pageAttributes'][] = $_one_tag->slug;
					}
				}
			}

			if ( ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) || $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) && null !== $post ) {
				// PublishPress Authors lets a post have several authors (co-authors,
				// guest authors), which matters for E-E-A-T. When it is active, the
				// single-value vars are sourced from its primary (first) author - this
				// also covers a single GUEST author, whose name is not the WordPress
				// user in $post->post_author that get_userdata() would return. Only when
				// there is MORE than one author are the array vars (pagePostAuthors /
				// pagePostAuthorIDs) added alongside them. When PublishPress is not
				// active (or returns no author), the get_userdata() fallback is unchanged.
				$multiple_authors = array();
				if ( function_exists( 'get_multiple_authors' ) ) {
					$ppress_authors = get_multiple_authors( $post->ID );
					if ( is_array( $ppress_authors ) ) {
						$multiple_authors = $ppress_authors;
					}
				}

				if ( count( $multiple_authors ) >= 1 ) {
					$author_names = array();
					$author_ids   = array();

					// Author display names and IDs are passed RAW to the data layer:
					// the single output sink (wp_json_encode with the full hex flag
					// set) is the correct escaper for the inline-script context, so
					// pre-escaping here would only corrupt the values (RI-2/RI-4).
					// The IDs are typed (int) - PublishPress may expose them as
					// numeric strings, and the data layer encode no longer
					// numeric-coerces (JSON_NUMERIC_CHECK removed), so without the
					// cast the array would mix strings with the int fallback 0.
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
				} else {
					$postuser = get_userdata( $post->post_author );

					if ( false !== $postuser ) {
						if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) ) {
							$data_layer['pagePostAuthorID'] = (int) $postuser->ID;
						}

						if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
							$data_layer['pagePostAuthor'] = $postuser->display_name;
						}
					}
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTDATE ) && null !== $post ) {
				$data_layer['pagePostDate']        = get_the_date();
				$data_layer['pagePostDateYear']    = get_the_date( 'Y' );
				$data_layer['pagePostDateMonth']   = get_the_date( 'm' );
				$data_layer['pagePostDateDay']     = get_the_date( 'd' );
				$data_layer['pagePostDateDayName'] = get_the_date( 'l' );
				$data_layer['pagePostDateHour']    = get_the_date( 'H' );
				$data_layer['pagePostDateMinute']  = get_the_date( 'i' );
				$data_layer['pagePostDateIso']     = get_the_date( 'c' );
				// Typed (int): a pure numeric timestamp has no leading-zero risk and
				// consumers do arithmetic on it, so it keeps reaching GTM as a JSON
				// number now that the data layer encode no longer numeric-coerces
				// (JSON_NUMERIC_CHECK removed). The zero-padded date parts above
				// stay strings on purpose ("07" must not become 7).
				$data_layer['pagePostDateUnix'] = (int) get_the_date( 'U' );
			}

			// Taxonomy terms and post meta are two separate opt-ins since 2.0: the
			// single "Post Terms" option used to emit both while its description
			// named only the taxonomies, so enabling taxonomy tracking silently
			// published every public custom field to the page. They still share the
			// pagePostTerms container so existing GTM setups keep their variable
			// paths (taxonomies at pagePostTerms.<taxonomy>, meta at
			// pagePostTerms.meta); Migration seeds the meta option from the legacy
			// one, so an upgrading site keeps sending exactly what it sent before.
			$include_post_terms = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_POSTTERMLIST );
			$include_post_meta  = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_POSTMETA );

			if ( ( $include_post_terms || $include_post_meta ) && null !== $post ) {
				$data_layer['pagePostTerms'] = array();

				if ( $include_post_terms ) {
					$object_taxonomies = get_object_taxonomies( get_post_type() );

					foreach ( $object_taxonomies as $one_object_taxonomy ) {
						$post_taxonomy_values = get_the_terms( $post->ID, $one_object_taxonomy );
						if ( is_array( $post_taxonomy_values ) ) {
							$data_layer['pagePostTerms'][ $one_object_taxonomy ] = array();
							foreach ( $post_taxonomy_values as $one_taxonomy_value ) {
								$data_layer['pagePostTerms'][ $one_object_taxonomy ][] = $one_taxonomy_value->name;
							}
						}
					}
				}

				if ( $include_post_meta ) {
					$post_meta = get_post_meta( $post->ID );
					if ( is_array( $post_meta ) ) {
						$data_layer['pagePostTerms']['meta'] = array();
						foreach ( $post_meta as $post_meta_key => $post_meta_value ) {
							if ( '_' !== substr( $post_meta_key, 0, 1 ) ) {

								/**
								 * Applies a filter to determine if post meta should be included in the data layer.
								 * This allows other plugins or themes to modify whether post meta should be included
								 * in the data layer.
								 *
								 * @since 1.17
								 *
								 * @param bool $true_false_default The default value (true).
								 * @param string $post_meta_key The name of the post meta key to be included in the data layer.
								 *
								 * @return bool Whether to include this post meta in the data layer.
								 */
								$include_post_meta_in_datalayer = (bool) apply_filters( 'gtm4wp_post_meta_in_datalayer', true, $post_meta_key );

								if ( $include_post_meta_in_datalayer ) {
									if ( is_array( $post_meta_value ) && ( 1 === count( $post_meta_value ) ) ) {
										$post_meta_dl_value = $post_meta_value[0];
									} else {
										$post_meta_dl_value = $post_meta_value;
									}
									$data_layer['pagePostTerms']['meta'][ $post_meta_key ] = $post_meta_dl_value;
								}
							}
						}
					}
				}
			}

			if ( ( $this->opt( GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT ) || $this->opt( GTM4WP_OPTION_INCLUDE_READINGTIME ) ) && null !== $post ) {
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

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_MODIFIEDDATE ) && null !== $post ) {
				$data_layer['pageModifiedDate']        = get_the_modified_date();
				$data_layer['pageModifiedDateYear']    = get_the_modified_date( 'Y' );
				$data_layer['pageModifiedDateMonth']   = get_the_modified_date( 'm' );
				$data_layer['pageModifiedDateDay']     = get_the_modified_date( 'd' );
				$data_layer['pageModifiedDateDayName'] = get_the_modified_date( 'l' );
				$data_layer['pageModifiedDateHour']    = get_the_modified_date( 'H' );
				$data_layer['pageModifiedDateMinute']  = get_the_modified_date( 'i' );
				$data_layer['pageModifiedDateIso']     = get_the_modified_date( 'c' );
				// Typed (int) for the same reason as pagePostDateUnix above.
				$data_layer['pageModifiedDateUnix'] = (int) get_the_modified_date( 'U' );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_CONTENTAGE ) && null !== $post ) {
				$post_published_gmt = get_post_time( 'U', true );
				if ( false !== $post_published_gmt ) {
					$data_layer['pageContentAgeDays'] = (int) max( 0, floor( ( time() - $post_published_gmt ) / DAY_IN_SECONDS ) );
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_COMMENTCOUNT ) && null !== $post ) {
				$data_layer['pageCommentCount']  = (int) get_comments_number( get_the_ID() );
				$data_layer['pageCommentStatus'] = comments_open( get_the_ID() ) ? 'open' : 'closed';
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_PAGETEMPLATE ) && null !== $post ) {
				$page_template_slug         = (string) get_page_template_slug( get_the_ID() );
				$data_layer['pageTemplate'] = ( '' === $page_template_slug ? 'default' : $page_template_slug );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE ) && null !== $post ) {
				$data_layer['pageHasFeaturedImage'] = has_post_thumbnail( get_the_ID() );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY ) && null !== $post ) {
				$data_layer['pageParentID'] = (int) $post->post_parent;
				$data_layer['pageDepth']    = count( get_post_ancestors( get_the_ID() ) );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTSTICKY ) && null !== $post ) {
				$data_layer['pagePostSticky'] = is_sticky( get_the_ID() );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY ) && null !== $post ) {
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
				 * Filters the term id used as the primary category of the current post.
				 *
				 * Allows integrators to override the detected primary category, for
				 * example when a different SEO plugin or a custom taxonomy is used.
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
					$primary_category_term = get_term( $primary_category_id );
					if ( $primary_category_term instanceof \WP_Term ) {
						$data_layer['pagePrimaryCategory']     = $primary_category_term->slug;
						$data_layer['pagePrimaryCategoryName'] = $primary_category_term->name;
					}
				}
			}
		}

		if ( is_archive() || is_post_type_archive() ) {
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

			// is_author() reports what the main query matched, not that $authordata
			// was set up (RI-13) - so resolve it once here and OMIT both keys when
			// it is unavailable, instead of emitting a 0 / '' placeholder a GTM
			// trigger would read as a real author. This mirrors how the singular
			// author block above handles a null $post.
			if ( is_author() ) {
				global $authordata;

				// Read through the RI-12-safe accessor rather than isset(): the
				// object may expose ID through __get() without __isset(). Any object
				// carrying an id is accepted, matching the previous behavior; only
				// the "no author at all" case changes, and it now omits.
				$author_id = is_object( $authordata )
					? self::read_author_prop( $authordata, 'ID', null )
					: null;

				if ( null !== $author_id && $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) ) {
					// Typed (int) for parity with the singular path: the data layer
					// encode no longer numeric-coerces (JSON_NUMERIC_CHECK removed).
					$data_layer['pagePostAuthorID'] = (int) $author_id;
				}

				if ( null !== $author_id && $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
					$data_layer['pagePostAuthor'] = get_the_author();
				}
			}
		}

		if ( is_search() ) {
			$data_layer['pagePostType'] = 'search-results';

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_SEARCHDATA ) ) {
				// siteSearchTerm (from the URL) and siteSearchFrom (from the
				// referrer) are things the browser can compute itself, so under the
				// cache-safe data layer they are delivered client-side (see
				// declare_visitor_scoped_fields()) rather than rendered here — which
				// also removes their server-side reflected-XSS surface.
				// siteSearchResults stays server-side: only the server knows it.
				if ( ! $cache_safe ) {
					$data_layer['siteSearchTerm'] = get_search_query();
					$data_layer['siteSearchFrom'] = '';
					if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
						$referer_url_parts            = explode( '?', esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
						$data_layer['siteSearchFrom'] = $referer_url_parts[0];

						if ( count( $referer_url_parts ) > 1 ) {
							$data_layer['siteSearchFrom'] = $referer_url_parts[0] . '?' . rawurlencode( $referer_url_parts[1] );
						}
					}
				}
				// Typed (int) like postCountOnPage/postCountTotal below: all counts
				// agree on reaching GTM as a JSON number (RI-2, type at source).
				$data_layer['siteSearchResults'] = (int) $wp_query->post_count;
			}
		}

		if ( is_front_page() && $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) ) {
			$data_layer['pagePostType'] = 'frontpage';
		}

		if ( ! is_front_page() && is_home() && $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) ) {
			$data_layer['pagePostType'] = 'bloghome';
		}

		if ( is_404() ) {
			$data_layer['pagePostType'] = '404-error';
		}

		// The main query global is not guaranteed either (a plugin resetting it,
		// the compile fired before the main query exists), so the counts are
		// gated the same way as the post-derived variables above: omitted, not
		// emitted as placeholders, when the global cannot answer.
		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTCOUNT ) && isset( $wp_query->post_count, $wp_query->found_posts ) ) {
			$data_layer['postCountOnPage'] = (int) $wp_query->post_count;
			$data_layer['postCountTotal']  = (int) $wp_query->found_posts;
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTID ) && is_singular() === true && null !== $post ) {
			$data_layer['postID'] = (int) get_the_ID();
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTFORMAT ) && is_singular() === true && null !== $post ) {
			// get_post_format() returns the format slug, or false for a standard
			// post. Emit the slug itself ('aside', 'gallery', ...), falling back
			// to 'standard' - the inherited short-ternary variant emitted '' for
			// every post that HAD a format, making the variable unusable.
			$post_format              = get_post_format();
			$data_layer['postFormat'] = $post_format ? $post_format : 'standard';
		}

		if ( ! $cache_safe && $this->opt( GTM4WP_OPTION_INCLUDE_MISCGEOCF ) && isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			// Sanitized but not esc_js'd: the data layer output sink hex-encodes
			// every value via wp_json_encode(), so pre-escaping would only
			// corrupt the country code for special-character inputs.
			$data_layer['geoCloudflareCountryCode'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		}

		return $data_layer;
	}

	/**
	 * Counts the words of a plain-text string in a UTF-8 aware way.
	 *
	 * PHP's str_word_count() only recognizes ASCII letters plus whatever the
	 * current locale adds, so it returns 0 for Cyrillic, Greek, Hebrew, Arabic and
	 * CJK content and MIS-counts Latin text with diacritics ("Größe Straße Übung"
	 * counted 5 instead of 3, because each multi-byte character split a word).
	 * That silently made pageContentWordCount 0 and collapsed pageReadingTime to a
	 * constant 1 minute on every non-English site.
	 *
	 * Two scripts are counted differently because they delimit words differently:
	 *
	 * - Space-delimited scripts (Latin, Cyrillic, Greek, Arabic, ...) are split on
	 *   Unicode whitespace.
	 * - CJK (Han, Hiragana, Katakana, Hangul) does not put spaces between words, so
	 *   a whitespace split would report a whole Japanese article as one word. Each
	 *   CJK character is counted as one word instead - the same approximation
	 *   word processors use - and those characters are removed before the
	 *   whitespace split so mixed-script content is not counted twice.
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
	 * Reads a property from a PublishPress author object without depending on the
	 * magic __isset() method (finding #43).
	 *
	 * PublishPress's Author objects resolve display_name/ID through __get(). isset(),
	 * ?? and empty() all consult __isset() first, which a class exposing __get() need
	 * not implement — and if it does not, isset() reports false even though __get()
	 * would return a real value, so every author name/ID would silently blank out.
	 * Reading through __get() directly (guarded by property_exists() for a plain
	 * object and method_exists('__get') for a magic one) avoids that trap without
	 * risking an "undefined property" warning for an object exposing the value in
	 * neither way. A null value falls back to the default, matching the old isset()
	 * semantics.
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
	 * Declares the page-variables fields that must be delivered outside the
	 * cacheable HTML when the cache-safe data layer is on (issue #398).
	 *
	 * Tier 1 — the site search term (from the URL) and the referring page
	 * (siteSearchFrom) — is only declared on a search results page (the only place
	 * these two fields are output) because the browser computes it with no request.
	 *
	 * Tier 2/3 — the visitor IP, Cloudflare country and the logged-in-user fields —
	 * is declared independent of the page type and gated only on its own option,
	 * because the session endpoint resolves it for the current request, which has no
	 * page context. Each carries a server resolver (run only on the endpoint) so the
	 * value is delivered client-side, once per session (Tier 2) or when the logged-in
	 * cookie changed (Tier 3), instead of baked into cacheable HTML.
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
	 * Declares the Tier 2 (session) and Tier 3 (logged-in user) fields the session
	 * endpoint delivers client-side. Each field is added only when its own option
	 * is on, and carries a resolver that runs for the current request on the
	 * endpoint; the resolver is the field's identity gate (the user resolvers return
	 * null for an anonymous request, so a logged-out caller never receives user data).
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
	 * Tier 2 resolver: the validated visitor IP for the current request, or null
	 * when it cannot be determined. VisitorIp::get() already wp_unslash+validates
	 * the value with filter_var( FILTER_VALIDATE_IP ), so it is a plain IP string.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_ip(): ?string {
		$ip = VisitorIp::get( (string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ) );

		return '' === $ip ? null : $ip;
	}

	/**
	 * Tier 2 resolver: the Cloudflare country code from the current request header,
	 * or null when absent. The value is wp_unslash+sanitized on the way in; the
	 * endpoint hex-encodes it on the way out, so it is passed raw (RI-4).
	 *
	 * @return string|null
	 */
	public function resolve_cloudflare_country(): ?string {
		if ( ! isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return null;
		}

		$country = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );

		return '' === $country ? null : $country;
	}

	/**
	 * Tier 3 resolver: 'logged-in' for an authenticated request, null otherwise.
	 * Returning null for an anonymous request is the identity gate — a logged-out
	 * caller receives no login-state field at all.
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
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$email = wp_get_current_user()->user_email;

		return empty( $email ) ? '' : $email;
	}

	/**
	 * Tier 3 resolver: the SHA-256 hash of the current user's email address, or
	 * null for an anonymous request.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_email_hash(): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$email = wp_get_current_user()->user_email;

		return empty( $email ) ? '' : hash( 'sha256', $email );
	}

	/**
	 * Tier 3 resolver: the Unix timestamp of the current user's registration date,
	 * or null for an anonymous request.
	 *
	 * @return int|string|null
	 */
	public function resolve_visitor_registration_date() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$registered = wp_get_current_user()->user_registered;

		return empty( $registered ) ? '' : strtotime( $registered );
	}

	/**
	 * Tier 3 resolver: the current user's login name, or null for an anonymous
	 * request. Passed raw; the endpoint hex-encodes it on output.
	 *
	 * @return string|null
	 */
	public function resolve_visitor_username(): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$login = wp_get_current_user()->user_login;

		return empty( $login ) ? '' : $login;
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
	 * Builds the pageCategory slug list for a set of category terms.
	 *
	 * With the "include parent categories" option off (the default) this returns
	 * just the immediate category slugs, keeping the 1.x output unchanged. With
	 * it on, each category's ancestor slugs (from get_ancestors(): immediate
	 * parent first, up to the top-level category) are appended after the
	 * category's own slug and the final list is de-duplicated while preserving
	 * order.
	 *
	 * @param array<int, \WP_Term> $categories Category term objects, as returned by get_the_category().
	 * @return array<int, string> List of category slugs.
	 */
	private function build_category_slugs( array $categories ): array {
		$include_parents = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES );

		$slugs = array();
		foreach ( $categories as $one_cat ) {
			$slugs[] = $one_cat->slug;

			if ( $include_parents ) {
				foreach ( get_ancestors( $one_cat->term_id, 'category' ) as $ancestor_id ) {
					$ancestor_term = get_term( (int) $ancestor_id, 'category' );
					if ( $ancestor_term instanceof \WP_Term ) {
						$slugs[] = $ancestor_term->slug;
					}
				}
			}
		}

		return array_values( array_unique( $slugs ) );
	}
}
