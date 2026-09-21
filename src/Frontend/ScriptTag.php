<?php
/**
 * Inline script tag helper.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Frontend;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and safely outputs inline <script> blocks.
 *
 * Port of gtm4wp_generate_script_opening_tag() and
 * gtm4wp_get_sanitize_script_block_rules() from 1.x (public/frontend.php).
 */
final class ScriptTag {

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Generates an opening <script> tag that includes all the necessary attributes.
	 * Adds data-cfasync="false" and data-pagespeed-no-defer.
	 * Calls current_theme_supports( 'html5' ) to detect whether to add the type attribute.
	 * Checks whether Cookiebot integration is active to add the data-cookieconsent="ignore" attribute.
	 * Uses the GTM4WP_WPFILTER_GET_CSP_NONCE filter to add a nonce attribute for Content Security Policy setups.
	 *
	 * @return string
	 */
	public function opening_tag(): string {
		$has_html5_support    = current_theme_supports( 'html5' );
		$add_cookiebot_ignore = (bool) $this->options->get( GTM4WP_OPTION_INTEGRATE_COOKIEBOT );
		$csp_nonce            = apply_filters( GTM4WP_WPFILTER_GET_CSP_NONCE, '' );

		return '<script data-cfasync="false" data-pagespeed-no-defer' . ( $has_html5_support ? '' : ' type="text/javascript"' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . ( $csp_nonce ? ' nonce="' . esc_attr( $csp_nonce ) . '"' : '' ) . '>';
	}

	/**
	 * Returns an array that can be used to sanitize a <script> block using wp_kses().
	 *
	 * @return array
	 */
	public static function sanitize_rules(): array {
		return array(
			'script' => array(
				'data-cfasync'            => array(),
				'data-pagespeed-no-defer' => array(),
				'data-cookieconsent'      => array(),
				'type'                    => array(),
				'nonce'                   => array(),
			),
		);
	}

	/**
	 * Encodes one value as a JavaScript literal, never returning an empty string
	 * (RI-21, #141). wp_json_encode() returns FALSE for INF/NAN, a resource or
	 * over-deep nesting (a filter-supplied value; invalid UTF-8 is repaired by
	 * the encoder itself), and PHP renders FALSE as '', so `var x = ;` would be a
	 * SyntaxError taking the whole block. The `null` fallback confines the
	 * failure to one value; every JS reader treats null like absent. Use this
	 * where the assignment MUST exist; where the statement can be left out,
	 * prefer testing `false ===` and omitting (RI-13; see
	 * DataLayer::wrapper_fragments()).
	 *
	 * @param mixed $value The value to encode.
	 * @param int   $flags wp_json_encode() flags for this value's context.
	 * @return string A JavaScript literal, never an empty string.
	 */
	public static function json_literal( $value, int $flags ): string {
		$json = wp_json_encode( $value, $flags );

		return false === $json ? 'null' : $json;
	}

	/**
	 * Safely outputs an inline script block: wp_kses() with the <script>
	 * allow-list, then the ampersand - and ONLY the ampersand - restored, since
	 * wp_kses() encodes it and that breaks && and &l=. Never widen this to a
	 * blanket htmlspecialchars_decode() (RI-3): the browser never HTML-decodes
	 * inside <script>, so &quot;/&lt; are inert as they are, and decoding them
	 * re-enabled break-outs from esc_attr'd values.
	 *
	 * @param string     $block The full script block including the <script> tags.
	 * @param array|null $rules Optional wp_kses() rule set override.
	 * @return void
	 */
	public function print_script_block( string $block, ?array $rules = null ): void {
		$sanitized = wp_kses(
			$block,
			$rules ?? self::sanitize_rules()
		);

		echo str_replace( '&amp;', '&', $sanitized ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() sanitized above; only the ampersand entity is restored so inline JS operators and URLs stay valid.
	}

	/**
	 * Safely outputs a block that MIXES HTML markup with inline <script>
	 * elements. Same sanitizer as print_script_block(), but the ampersand is
	 * restored ONLY inside script bodies: an encoded && is a SyntaxError there,
	 * while `ns.html?id=X&amp;gtm_auth=Y` is the correct spelling of the iframe
	 * attribute (and the 1.x byte form).
	 *
	 * @param string     $block The markup block, possibly containing <script> elements.
	 * @param array|null $rules Optional wp_kses() rule set override.
	 * @return void
	 */
	public function print_markup_block( string $block, ?array $rules = null ): void {
		$sanitized = wp_kses(
			$block,
			$rules ?? self::sanitize_rules()
		);

		echo self::restore_script_ampersands( $sanitized ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() sanitized above; only the ampersand entity is restored, and only inside <script> bodies, so inline JS operators stay valid.
	}

	/**
	 * Turns &amp; back into & inside every <script> element of a sanitized block.
	 * Safe because the transform can only produce an ampersand, never `<` or a
	 * quote (RI-3). preg_replace_callback(), not preg_replace(), so a `$1` in the
	 * script body is never expanded as a backreference (PA-7); on a PCRE failure
	 * the input is emitted unchanged rather than blanked (#85).
	 *
	 * @param string $markup wp_kses()-sanitized markup.
	 * @return string
	 */
	private static function restore_script_ampersands( string $markup ): string {
		$restored = preg_replace_callback(
			'#(<script\b[^>]*>)(.*?)(</script>)#is',
			static function ( array $matches ): string {
				return $matches[1] . str_replace( '&amp;', '&', $matches[2] ) . $matches[3];
			},
			$markup
		);

		return null === $restored ? $markup : $restored;
	}
}
