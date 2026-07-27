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
}
