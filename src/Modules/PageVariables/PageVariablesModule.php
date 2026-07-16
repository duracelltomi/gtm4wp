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
		global $wp_query;

		// When the cache-safe data layer is on (issue #398), visitor/session
		// specific values must not be baked into the cacheable page HTML: they are
		// omitted here and, where the browser can compute them itself, delivered
		// client-side instead (see declare_visitor_scoped_fields() and the
		// gtm4wp-visitor-data script). Content/URL/site data is unaffected.
		$cache_safe = (bool) $this->opt( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );

		// When on, post/term derived values (title, category, tags, taxonomy
		// terms) are output in the site's default (master) language instead of
		// the current translation, so GA reports combine across languages.
		// Read once here; each call site short-circuits on it so the default
		// (off) path stays byte-for-byte the current behavior. See
		// resolve_default_language_object_id() for the WPML/Polylang branching.
		$use_master_language = (bool) $this->opt( GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE );

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
			$data_layer['visitorIP'] = VisitorIp::get(
				(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ),
				(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )
			);
		}

		if ( $this->opt( GTM4WP_OPTION_INCLUDE_POSTTITLE ) ) {
			$page_title = wp_title( '|', false, 'right' );

			// On a singular page with the master-language option on, report the
			// post's title in the site's default language. Only overridden when
			// an actual translation exists (a distinct master post id), so a
			// monolingual site, an untranslated post, or no active multilingual
			// plugin all keep the current wp_title() output unchanged.
			if ( $use_master_language && is_singular() ) {
				$_post_id        = (int) get_the_ID();
				$_master_post_id = $this->resolve_default_language_post_id( $_post_id, (string) get_post_type() );
				if ( $_master_post_id > 0 && $_master_post_id !== $_post_id ) {
					$_master_title = (string) get_the_title( $_master_post_id );
					if ( '' !== $_master_title ) {
						$page_title = $_master_title;
					}
				}
			}

			$data_layer['pageTitle'] = wp_strip_all_tags( $page_title );
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
						$data_layer['pageAttributes'][] = $use_master_language
							? $this->localized_term_field( (int) $_one_tag->term_id, 'post_tag', 'slug', (string) $_one_tag->slug )
							: $_one_tag->slug;
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
								$data_layer['pagePostTerms'][ $one_object_taxonomy ][] = $use_master_language
									? $this->localized_term_field( (int) $one_taxonomy_value->term_id, $one_object_taxonomy, 'name', (string) $one_taxonomy_value->name )
									: $one_taxonomy_value->name;
							}
						}
					}
				}

				if ( $include_post_meta ) {
					// get_post_meta() WITHOUT a key is the one branch of the core
					// meta API that does NOT unserialize: get_metadata_raw() returns
					// the meta cache verbatim as soon as $meta_key is empty, and
					// update_meta_cache() stores the raw DB column. Every value that
					// was stored as an array therefore arrives here as a serialized
					// PHP string - see the is_serialized() skip below.
					$post_meta = get_post_meta( $post->ID );
					if ( is_array( $post_meta ) ) {
						$allowed_meta_keys = self::parse_meta_key_list( Field::to_string( $this->opt( GTM4WP_OPTION_INCLUDE_POSTMETA_KEYS ) ) );

						$meta_values = array();
						foreach ( $post_meta as $post_meta_key => $post_meta_value ) {
							// An allow-list, once filled in, is the whole rule for what
							// may be CONSIDERED - nothing outside it is published,
							// whatever the key looks like. It is NOT a grant: the
							// protected gate and the filter below still decide what is
							// actually published, which is what the field description
							// promises ("Protected fields and the
							// gtm4wp_post_meta_in_datalayer filter still apply on top of
							// this list"). Only the order of these three guards enforces
							// that, so both interactions are pinned by tests.
							// Empty (the default) keeps the 1.x "everything that is not
							// protected" behaviour so an upgraded site is unaffected.
							if ( array() !== $allowed_meta_keys && ! in_array( $post_meta_key, $allowed_meta_keys, true ) ) {
								continue;
							}

							// The underscore test is the FLOOR and is_protected_meta()
							// only ever adds to it. Both halves are load-bearing:
							// is_protected_meta() honours a plugin or site that declares
							// a non-underscore key protected through the core filter, but
							// that filter's return value IS the function's return value,
							// so on its own it also lets a callback UNPROTECT an
							// underscore key - and that key would then be published to
							// the public page. The filter's ecosystem purpose is admin-UI
							// visibility, not public-output privacy, so it may only widen
							// what we withhold, never narrow it.
							if (
								'_' === substr( $post_meta_key, 0, 1 )
								|| is_protected_meta( $post_meta_key, 'post' )
							) {
								continue;
							}

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

							if ( ! $include_post_meta_in_datalayer ) {
								continue;
							}

							// Filter FIRST, then collapse a surviving single value to a
							// scalar. The other order makes the emitted JSON type depend
							// on whether a serialized sibling happened to be dropped -
							// a key left with one value would emit a one-element array
							// while a natively single-valued key emits a bare string,
							// and a GTM Custom JS variable sees a different type for the
							// same key. The second call covers the one case the collapse
							// can expose: a single value that is itself an array still
							// carrying packed entries.
							$post_meta_dl_value = self::drop_serialized_meta_values( $post_meta_value );

							if ( is_array( $post_meta_dl_value ) && ( 1 === count( $post_meta_dl_value ) ) ) {
								$post_meta_dl_value = self::drop_serialized_meta_values( array_values( $post_meta_dl_value )[0] );
							}

							// Nothing usable left: OMIT the key rather than emit null
							// or an empty array, since a GTM trigger may test for key
							// presence (RI-13).
							if ( null === $post_meta_dl_value ) {
								continue;
							}

							$meta_values[ $post_meta_key ] = $post_meta_dl_value;
						}

						// Omit the container rather than ship an empty one. PHP's
						// array() encodes as a JSON [], which is TRUTHY in JavaScript
						// and a different type from the object the populated form
						// produces - so a GTM variable reading pagePostTerms.meta sees
						// the type flip depending on whether anything survived. This is
						// the same omit-don't-invent rule the per-key branch above
						// already follows (RI-13/RI-20), applied to the container.
						if ( array() !== $meta_values ) {
							$data_layer['pagePostTerms']['meta'] = $meta_values;
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
		$ip = VisitorIp::get(
			(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ),
			(string) $this->opt( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )
		);

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
	 * Parses the post-meta allow-list option into a list of meta keys.
	 *
	 * Accepts one key per line or a comma separated list, in any mix. Shared by
	 * the AdminSchema sanitizer and the frontend read above so that what is
	 * stored is exactly what the reader honours - two copies of the same parsing
	 * rule is a divergence waiting for the next tightening (PA-2, the pattern the
	 * trusted-proxy list already follows).
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
	 * Removes serialized values from a post meta value before it reaches the
	 * data layer.
	 *
	 * A serialized string is a plugin's internal storage format by definition -
	 * it reaches the browser as an opaque a:8:{...} blob that no Google Tag
	 * Manager variable can read, while publishing the whole nested structure the
	 * plugin keeps behind that key. Skipping is deliberate rather than
	 * unserializing: no working GTM setup can be consuming such a value today,
	 * and unserializing would run object instantiation over every custom field
	 * on the post for data nobody asked for.
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
	 * Returns a field (slug or name) of the default (master) language
	 * equivalent of a taxonomy term.
	 *
	 * Used to output category/tag/taxonomy values in the site's default
	 * language (issue #145). When the term has a distinct translation in the
	 * default language, that term's slug/name is returned; otherwise (no active
	 * multilingual plugin, an untranslated term, or the term already being the
	 * master one) the supplied fallback - the term's own value in the current
	 * language - is returned unchanged.
	 *
	 * @param int    $term_id  Term id in the current language.
	 * @param string $taxonomy Taxonomy of the term ('category', 'post_tag' or a custom taxonomy).
	 * @param string $field    Field to read from the resolved term: 'slug' or 'name'.
	 * @param string $fallback Current-language value to return when no master term is resolved.
	 * @return string
	 */
	private function localized_term_field( int $term_id, string $taxonomy, string $field, string $fallback ): string {
		$master_term_id = $this->resolve_default_language_term_id( $term_id, $taxonomy );
		if ( $master_term_id > 0 && $master_term_id !== $term_id ) {
			$master_term = get_term( $master_term_id, $taxonomy );
			if ( $master_term instanceof \WP_Term ) {
				return (string) ( 'name' === $field ? $master_term->name : $master_term->slug );
			}
		}

		return $fallback;
	}

	/**
	 * Resolves the id of the default (master) language equivalent of a post.
	 *
	 * @param int    $post_id Post id in the current language.
	 * @param string $type    Post type (WPML element type for posts, e.g. 'post', 'page', a custom post type).
	 * @return int Master-language post id, or the given id when it cannot be resolved.
	 */
	private function resolve_default_language_post_id( int $post_id, string $type ): int {
		return $this->resolve_default_language_object_id( $post_id, $type, false, 'gtm4wp_master_language_post_id' );
	}

	/**
	 * Resolves the id of the default (master) language equivalent of a term.
	 *
	 * @param int    $term_id  Term id in the current language.
	 * @param string $taxonomy Taxonomy of the term (also the WPML element type for terms).
	 * @return int Master-language term id, or the given id when it cannot be resolved.
	 */
	private function resolve_default_language_term_id( int $term_id, string $taxonomy ): int {
		return $this->resolve_default_language_object_id( $term_id, $taxonomy, true, 'gtm4wp_master_language_term_id' );
	}

	/**
	 * Resolves the id of the default (master) language equivalent of a post or
	 * term using whichever multilingual plugin is active.
	 *
	 * Mirrors the WPML/Polylang guarding used by the pageLanguage detection:
	 * WPML is detected through its wpml_current_language filter and resolved
	 * with wpml_object_id (the `true` argument returns the original id when no
	 * translation exists); Polylang is detected through its pll_* functions and
	 * resolved with pll_get_post()/pll_get_term() (both return 0 when there is
	 * no translation). When neither plugin is active - or no default-language
	 * object is found - the given id is returned unchanged so the caller keeps
	 * the current behavior. The result is always filterable so integrators can
	 * support other multilingual plugins.
	 *
	 * @param int    $id      Post or term id in the current language.
	 * @param string $type    WPML element type: the post type for posts, the taxonomy for terms.
	 * @param bool   $is_term Whether $id is a term id (true) or a post id (false).
	 * @param string $filter  Filter hook applied to the resolved id.
	 * @return int
	 */
	private function resolve_default_language_object_id( int $id, string $type, bool $is_term, string $filter ): int {
		$resolved = $id;

		if ( $id > 0 ) {
			if ( has_filter( 'wpml_current_language' ) ) {
				// WPML: wpml_default_language + wpml_object_id.
				$default_lang = apply_filters( 'wpml_default_language', null );
				if ( is_string( $default_lang ) && '' !== $default_lang ) {
					$wpml_id = apply_filters( 'wpml_object_id', $id, $type, true, $default_lang );
					if ( is_numeric( $wpml_id ) && (int) $wpml_id > 0 ) {
						$resolved = (int) $wpml_id;
					}
				}
			} else {
				// Polylang: pll_default_language + pll_get_post/pll_get_term.
				$pll_ready = $is_term ? function_exists( 'pll_get_term' ) : function_exists( 'pll_get_post' );

				if ( $pll_ready && function_exists( 'pll_default_language' ) ) {
					$default_lang = pll_default_language();
					if ( is_string( $default_lang ) && '' !== $default_lang ) {
						$pll_id = $is_term ? pll_get_term( $id, $default_lang ) : pll_get_post( $id, $default_lang );
						if ( is_numeric( $pll_id ) && (int) $pll_id > 0 ) {
							$resolved = (int) $pll_id;
						}
					}
				}
			}
		}

		/**
		 * Filters the id resolved to the site's default (master) language.
		 *
		 * Lets integrators support other multilingual plugins, or override the
		 * WPML/Polylang resolution, for the master-language data layer values.
		 * Fires as gtm4wp_master_language_post_id for posts and
		 * gtm4wp_master_language_term_id for terms.
		 *
		 * @since 2.0
		 *
		 * @param int    $resolved Resolved master-language id (the original id when nothing was resolved).
		 * @param int    $id       Original post/term id in the current language.
		 * @param string $type     Post type (posts) or taxonomy (terms).
		 *
		 * @return int Id to read the master-language value from.
		 */
		return (int) apply_filters( $filter, $resolved, $id, $type );
	}
}
