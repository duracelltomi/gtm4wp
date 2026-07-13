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
			GTM4WP_OPTION_INCLUDE_TAGS              => true,
			GTM4WP_OPTION_INCLUDE_AUTHOR            => true,
			GTM4WP_OPTION_INCLUDE_AUTHORID          => false,
			GTM4WP_OPTION_INCLUDE_POSTDATE          => false,
			GTM4WP_OPTION_INCLUDE_POSTTITLE         => false,
			GTM4WP_OPTION_INCLUDE_POSTCOUNT         => false,
			GTM4WP_OPTION_INCLUDE_POSTID            => false,
			GTM4WP_OPTION_INCLUDE_POSTFORMAT        => false,
			GTM4WP_OPTION_INCLUDE_POSTTERMLIST      => false,
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

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_SITEID ) || $this->opt( GTM4WP_OPTION_INCLUDE_SITENAME ) ) {
			$data_layer['siteID']   = 0;
			$data_layer['siteName'] = '';

			if ( function_exists( 'get_blog_details' ) ) {
				$gtm4wp_blogdetails = get_blog_details();

				$data_layer['siteID']   = $gtm4wp_blogdetails->blog_id;
				$data_layer['siteName'] = $gtm4wp_blogdetails->blogname;
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_LOGGEDIN ) ) {
			$data_layer['visitorLoginState'] = 'logged-out';

			if ( is_user_logged_in() ) {
				$data_layer['visitorLoginState'] = 'logged-in';
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERROLE ) || $this->opt( GTM4WP_OPTION_INCLUDE_USEREMAIL ) || $this->opt( GTM4WP_OPTION_INCLUDE_USERREGDATE ) || $this->opt( GTM4WP_OPTION_INCLUDE_USERNAME ) ) {
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

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_USERID ) ) {
			$_gtm4wp_userid = get_current_user_id();
			if ( $_gtm4wp_userid > 0 ) {
				$data_layer['visitorId'] = $_gtm4wp_userid;
			}
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP ) ) {
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

		if ( is_singular() ) {
			if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTTYPE ) ) {
				$data_layer['pagePostType']  = get_post_type();
				$data_layer['pagePostType2'] = 'single-' . get_post_type();
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_CATEGORIES ) ) {
				$_post_cats = get_the_category();
				if ( $_post_cats ) {
					$data_layer['pageCategory'] = array();
					foreach ( $_post_cats as $_one_cat ) {
						$data_layer['pageCategory'][] = $_one_cat->slug;
					}
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_TAGS ) ) {
				$_post_tags = get_the_tags();
				if ( $_post_tags ) {
					$data_layer['pageAttributes'] = array();
					foreach ( $_post_tags as $_one_tag ) {
						$data_layer['pageAttributes'][] = $_one_tag->slug;
					}
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) || $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
				$postuser = get_userdata( $GLOBALS['post']->post_author );

				if ( false !== $postuser ) {
					if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) ) {
						$data_layer['pagePostAuthorID'] = $postuser->ID;
					}

					if ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) {
						$data_layer['pagePostAuthor'] = $postuser->display_name;
					}
				}
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTDATE ) ) {
				$data_layer['pagePostDate']        = get_the_date();
				$data_layer['pagePostDateYear']    = get_the_date( 'Y' );
				$data_layer['pagePostDateMonth']   = get_the_date( 'm' );
				$data_layer['pagePostDateDay']     = get_the_date( 'd' );
				$data_layer['pagePostDateDayName'] = get_the_date( 'l' );
				$data_layer['pagePostDateHour']    = get_the_date( 'H' );
				$data_layer['pagePostDateMinute']  = get_the_date( 'i' );
				$data_layer['pagePostDateIso']     = get_the_date( 'c' );
				$data_layer['pagePostDateUnix']    = get_the_date( 'U' );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTTERMLIST ) ) {
				$data_layer['pagePostTerms'] = array();

				$object_taxonomies = get_object_taxonomies( get_post_type() );

				foreach ( $object_taxonomies as $one_object_taxonomy ) {
					$post_taxonomy_values = get_the_terms( $GLOBALS['post']->ID, $one_object_taxonomy );
					if ( is_array( $post_taxonomy_values ) ) {
						$data_layer['pagePostTerms'][ $one_object_taxonomy ] = array();
						foreach ( $post_taxonomy_values as $one_taxonomy_value ) {
							$data_layer['pagePostTerms'][ $one_object_taxonomy ][] = $one_taxonomy_value->name;
						}
					}
				}

				$post_meta = get_post_meta( $GLOBALS['post']->ID );
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

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT ) || $this->opt( GTM4WP_OPTION_INCLUDE_READINGTIME ) ) {
				$post_content = (string) get_post_field( 'post_content', get_the_ID() );
				$word_count   = str_word_count( wp_strip_all_tags( strip_shortcodes( $post_content ) ) );

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
				$data_layer['pageModifiedDateUnix']    = get_the_modified_date( 'U' );
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
				$data_layer['pageParentID'] = (int) $GLOBALS['post']->post_parent;
				$data_layer['pageDepth']    = count( get_post_ancestors( get_the_ID() ) );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTSTICKY ) ) {
				$data_layer['pagePostSticky'] = is_sticky( get_the_ID() );
			}

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY ) ) {
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
				$_post_cats                 = get_the_category();
				$data_layer['pageCategory'] = array();
				foreach ( $_post_cats as $_one_cat ) {
					$data_layer['pageCategory'][] = $_one_cat->slug;
				}
			}

			if ( ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHORID ) ) && ( is_author() ) ) {
				global $authordata;
				$data_layer['pagePostAuthorID'] = isset( $authordata->ID ) ? $authordata->ID : 0;
			}

			if ( ( $this->opt( GTM4WP_OPTION_INCLUDE_AUTHOR ) ) && ( is_author() ) ) {
				$data_layer['pagePostAuthor'] = get_the_author();
			}
		}

		if ( is_search() ) {
			$data_layer['pagePostType'] = 'search-results';

			if ( $this->opt( GTM4WP_OPTION_INCLUDE_SEARCHDATA ) ) {
				$data_layer['siteSearchTerm'] = get_search_query();
				$data_layer['siteSearchFrom'] = '';
				if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
					$referer_url_parts            = explode( '?', esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
					$data_layer['siteSearchFrom'] = $referer_url_parts[0];

					if ( count( $referer_url_parts ) > 1 ) {
						$data_layer['siteSearchFrom'] = $referer_url_parts[0] . '?' . rawurlencode( $referer_url_parts[1] );
					}
				}
				$data_layer['siteSearchResults'] = $wp_query->post_count;
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

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTCOUNT ) ) {
			$data_layer['postCountOnPage'] = (int) $wp_query->post_count;
			$data_layer['postCountTotal']  = (int) $wp_query->found_posts;
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTID ) && is_singular() === true ) {
			$data_layer['postID'] = (int) get_the_ID();
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTFORMAT ) && is_singular() === true ) {
			$data_layer['postFormat'] = get_post_format() ? '' : 'standard';
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_MISCGEOCF ) && isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			// Sanitized but not esc_js'd: the data layer output sink hex-encodes
			// every value via wp_json_encode(), so pre-escaping would only
			// corrupt the country code for special-character inputs.
			$data_layer['geoCloudflareCountryCode'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		}

		return $data_layer;
	}
}
