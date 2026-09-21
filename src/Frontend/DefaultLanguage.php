<?php
/**
 * Default (master) language resolver for multilingual sites.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a post or term to its default (master) language equivalent via WPML
 * or Polylang, so data layer values report in one language (issue #145).
 * Detection mirrors the PageVariables pageLanguage detection. Unresolvable ids
 * are returned unchanged; the result is filterable
 * (gtm4wp_master_language_post_id / gtm4wp_master_language_term_id).
 */
final class DefaultLanguage {

	/**
	 * Whether a supported multilingual plugin (WPML or Polylang) is active.
	 * Lets callers skip all resolution work on a single-language site.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return has_filter( 'wpml_current_language' ) || function_exists( 'pll_get_post' );
	}

	/**
	 * Resolves the id of the default (master) language equivalent of a post.
	 *
	 * @param int    $post_id Post id in the current language.
	 * @param string $type    Post type / WPML element type (e.g. 'post', 'page', 'product', 'wpcf7_contact_form').
	 * @return int Master-language post id, or the given id when it cannot be resolved.
	 */
	public static function post_id( int $post_id, string $type ): int {
		return self::resolve( $post_id, $type, false, 'gtm4wp_master_language_post_id' );
	}

	/**
	 * Resolves the id of the default (master) language equivalent of a term.
	 *
	 * @param int    $term_id  Term id in the current language.
	 * @param string $taxonomy Taxonomy of the term (also the WPML element type for terms).
	 * @return int Master-language term id, or the given id when it cannot be resolved.
	 */
	public static function term_id( int $term_id, string $taxonomy ): int {
		return self::resolve( $term_id, $taxonomy, true, 'gtm4wp_master_language_term_id' );
	}

	/**
	 * Shared WPML/Polylang resolution for a post or term id: wpml_object_id (the
	 * `true` argument returns the original id without a translation) or
	 * pll_get_post()/pll_get_term() (0 without one). Filterable.
	 *
	 * @param int    $id      Post or term id in the current language.
	 * @param string $type    WPML element type: the post type for posts, the taxonomy for terms.
	 * @param bool   $is_term Whether $id is a term id (true) or a post id (false).
	 * @param string $filter  Filter hook applied to the resolved id.
	 * @return int
	 */
	private static function resolve( int $id, string $type, bool $is_term, string $filter ): int {
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
		 * Filters the id resolved to the site's default (master) language, so
		 * integrators can support other multilingual plugins. Fires as
		 * gtm4wp_master_language_post_id for posts and
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
