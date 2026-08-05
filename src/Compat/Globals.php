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

use GTM4WP\Modules\Container\ContainerRows;
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

		// Resolved through the same helper as DataLayer::name() rather than
		// repeating the fallback here: this global is what 1.x consumers push
		// through (window[gtm4wp_datalayer_name]), so a second definition that
		// drifted would hand third-party code a different variable name from
		// the one the container code declares (RI-14).
		$GLOBALS['gtm4wp_datalayer_name'] = ContainerRows::datalayer_name(
			$options->get( GTM4WP_OPTION_DATALAYER_NAME )
		);

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
