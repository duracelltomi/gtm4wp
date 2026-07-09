<?php
/**
 * Backward compatible global variable mirrors.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Compat;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Populates the well-known 1.x globals that third party code reads.
 *
 * These are read-only mirrors of the new OOP services; internal 2.x code
 * must never read them back.
 */
final class Globals {

	/**
	 * Fills the backward compatible globals from the options service.
	 *
	 * @param Options $options The plugin options service.
	 * @return void
	 */
	public static function populate( Options $options ): void {
		$GLOBALS['gtm4wp_options'] = $options->all();

		/**
		 * Check for empty is needed to prevent error in WP CLI,
		 * bugfix by Patrick Holberg Hesselberg (carried over from 1.x).
		 */
		$datalayer_name = $options->get( GTM4WP_OPTION_DATALAYER_NAME );
		if ( empty( $datalayer_name ) || ! is_string( $datalayer_name ) ) {
			$datalayer_name = 'dataLayer';
		}
		$GLOBALS['gtm4wp_datalayer_name'] = $datalayer_name;

		if ( ! isset( $GLOBALS['gtm4wp_datalayer_data'] ) ) {
			$GLOBALS['gtm4wp_datalayer_data'] = array();
		}

		if ( ! isset( $GLOBALS['gtm4wp_additional_datalayer_pushes'] ) ) {
			$GLOBALS['gtm4wp_additional_datalayer_pushes'] = array();
		}

		if ( ! isset( $GLOBALS['gtm4wp_container_code_written'] ) ) {
			$GLOBALS['gtm4wp_container_code_written'] = false;
		}
	}
}
