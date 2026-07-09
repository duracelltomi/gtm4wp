<?php
/**
 * Scroll tracking module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ScrollTracking;

use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Fires tags based on how the visitor scrolls through the page.
 * Port of the scroll tracking parts of public/frontend.php from 1.x
 * (script enqueue and inline configuration constants).
 */
final class ScrollTrackingModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'scroll-tracking';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_SCROLLER_ENABLED      => false,
			GTM4WP_OPTION_SCROLLER_DEBUGMODE    => false,
			GTM4WP_OPTION_SCROLLER_CALLBACKTIME => 100,
			GTM4WP_OPTION_SCROLLER_DISTANCE     => 150,
			GTM4WP_OPTION_SCROLLER_CONTENTID    => 'content',
			GTM4WP_OPTION_SCROLLER_READERTIME   => 60,
		);
	}

	/**
	 * Registers the frontend hooks.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( ! $this->opt( GTM4WP_OPTION_SCROLLER_ENABLED ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $this, 'add_header_js' ) );
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
	 * Loads the scroll tracking script.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_SCROLLER_ENABLED, false );
		$this->enqueue_script( 'gtm4wp-scroll-tracking', 'analytics-talk-content-tracking.js', array(), $in_footer );
	}

	/**
	 * Adds the scroll tracker configuration constants to the data layer
	 * initialization block in the head. Byte-identical to the 1.x block
	 * inside gtm4wp_wp_header_top().
	 *
	 * @param string $inline_js Inline JS collected so far.
	 * @return string
	 */
	public function add_header_js( $inline_js ) {
		return $inline_js . '

	const gtm4wp_scrollerscript_debugmode         = ' . ( $this->opt( GTM4WP_OPTION_SCROLLER_DEBUGMODE ) ? 'true' : 'false' ) . ';
	const gtm4wp_scrollerscript_callbacktime      = ' . (int) $this->opt( GTM4WP_OPTION_SCROLLER_CALLBACKTIME ) . ';
	const gtm4wp_scrollerscript_readerlocation    = ' . (int) $this->opt( GTM4WP_OPTION_SCROLLER_DISTANCE ) . ';
	const gtm4wp_scrollerscript_contentelementid  = "' . esc_js( $this->opt( GTM4WP_OPTION_SCROLLER_CONTENTID ) ) . '";
	const gtm4wp_scrollerscript_scannertime       = ' . (int) $this->opt( GTM4WP_OPTION_SCROLLER_READERTIME ) . ';';
	}
}
