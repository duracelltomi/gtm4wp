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
	 * Sanitizes with wp_kses() then decodes entities so that the JavaScript
	 * source inside the block is not HTML-encoded, exactly as 1.x did.
	 *
	 * @param string     $block The full script block including the <script> tags.
	 * @param array|null $rules Optional wp_kses() rule set override.
	 * @return void
	 */
	public function print_script_block( string $block, ?array $rules = null ): void {
		echo htmlspecialchars_decode( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses sanitized, entity decode required for inline JS as in 1.x.
			wp_kses(
				$block,
				$rules ?? self::sanitize_rules()
			)
		);
	}
}
