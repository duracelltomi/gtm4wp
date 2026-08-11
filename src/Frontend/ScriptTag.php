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
	 * Encodes one value as a JavaScript literal, never returning an empty string.
	 *
	 * The encoder returns FALSE for a value it cannot encode, and PHP renders
	 * FALSE as '' in string concatenation - so a sink that writes
	 * `'var x = ' . wp_json_encode( $v, $f ) . ';'` emits `var x = ;`, a SyntaxError
	 * that takes down the WHOLE <script> block rather than the one value. The
	 * reachable triggers are INF/NAN, a resource, and nesting past the encoder's
	 * depth limit; invalid UTF-8 is NOT one, because wp_json_encode() repairs that
	 * itself (_wp_json_sanity_check), which is why no request-sourced string can get
	 * here. It takes a value supplied by a third party through one of the public
	 * filters - which is exactly the input this plugin is built to accept.
	 *
	 * Falling back to the `null` literal keeps the block parseable and confines the
	 * failure to one value. Every JS reader of these globals already copes with the
	 * value being absent (`window.x || {}`, `typeof x === 'string' ? …`), and `null`
	 * takes the same branch as absent at each of them.
	 *
	 * This lives here, next to the wp_kses/ampersand contract, because it is the same
	 * question those answer: what has to be true for a string to be safe to put in a
	 * <script> body. It was private to ContainerCode until 2026-08-10 with a single
	 * caller, so eight sibling sinks kept the defect it was written to fix (#141).
	 *
	 * Use this where the assignment MUST exist. Where the whole statement can simply
	 * be left out, prefer that: call wp_json_encode() directly, test `false ===`, and
	 * omit - an absent key is honest where an invented `null` is not (RI-13).
	 * DataLayer::wrapper_fragments() is the reference for that shape.
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
	 * Safely outputs an inline script block.
	 *
	 * The block is sanitized with wp_kses() so only the allow-listed <script>
	 * tag and its attributes survive. wp_kses() also entity-encodes every bare
	 * ampersand (& becomes &amp;), which would break JavaScript operators such
	 * as && and query string separators such as &l=, so the ampersand — and
	 * only the ampersand — is restored afterwards.
	 *
	 * Earlier versions ran htmlspecialchars_decode() over the whole block, which
	 * also turned &quot;, &lt;, &gt; and &#039; back into raw ", <, > and '
	 * characters. Inside a <script> element the browser never HTML-decodes
	 * entities, so those escaped sequences are already inert and decoding them
	 * only re-enabled string/tag break-outs from values escaped with esc_js() or
	 * esc_attr() (e.g. the site search term reaching the data layer as &quot;).
	 * Leaving everything but the ampersand encoded keeps such values safe while
	 * the trusted JavaScript still runs.
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
	 * Safely outputs a block that MIXES HTML markup with inline <script> elements.
	 *
	 * Same sanitizer as print_script_block(), different ampersand rule, and the
	 * difference is the whole reason this method exists. wp_kses() entity-encodes
	 * every bare ampersand, which is
	 *
	 * - wrong inside a <script> body: `console.warn && console.warn(…)` becomes
	 *   `console.warn &amp;&amp; console.warn(…)`, a SyntaxError that kills the
	 *   entire block, because the browser never HTML-decodes inside <script>; but
	 * - right inside an HTML attribute: `ns.html?id=X&amp;gtm_auth=Y` is the
	 *   correct spelling of that URL and is what both 2.0 and 1.x have always
	 *   emitted for the noscript iframe.
	 *
	 * So the ampersand is restored ONLY within script elements, leaving attributes
	 * as wp_kses() left them. Blanket-restoring (print_script_block) would corrupt
	 * the iframe URL; not restoring at all is the defect this fixes - the
	 * container placement OFF, kill-switch and excluded-user-role console warnings
	 * all reach the page through here.
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
	 * Turns &amp; back into & inside every <script> element of an
	 * already-sanitized block, leaving the rest of the markup untouched.
	 *
	 * Safe by the same argument as print_script_block()'s blanket restore: the only
	 * way out of a <script> body is a literal `</script`, and this transform can
	 * only ever produce an ampersand - never `<`, `>`, `"` or `'`. Every other
	 * entity stays inert exactly as RI-3 requires.
	 *
	 * preg_replace_callback(), not preg_replace(): a callback RETURNS the
	 * replacement, so a `$1`/`\1` sequence occurring in the script body is never
	 * expanded as a backreference (PA-7). On a PCRE failure the callback returns
	 * null, in which case the sanitized input is emitted unchanged rather than the
	 * empty string - the same "degrade, never blank the output" rule as
	 * self::json_literal() (#85).
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
