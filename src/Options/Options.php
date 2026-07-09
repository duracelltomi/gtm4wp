<?php
/**
 * Plugin options service.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads plugin options from the database backend and merges them with the
 * default values collected from all registered modules.
 *
 * Port of gtm4wp_reload_options() from 1.x (common/readoptions.php) with
 * identical behavior:
 * - options are stored in a single option row under the key GTM4WP_OPTIONS,
 * - wp-config.php can hard code the GTM ID and environment parameters,
 * - the blacklist status option is stored as a comma separated string and
 *   exposed as an array.
 */
final class Options {

	/**
	 * Merged option values (defaults overlaid with stored values).
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Constructor. Loads and normalizes all options with a single
	 * get_option() call.
	 *
	 * @param array<string, mixed> $defaults Default option values collected from all modules.
	 */
	public function __construct( array $defaults ) {
		$stored = get_option( GTM4WP_OPTIONS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$values = array_merge( $defaults, $stored );

		if ( array_key_exists( GTM4WP_OPTION_BLACKLIST_STATUS, $values ) && is_string( $values[ GTM4WP_OPTION_BLACKLIST_STATUS ] ) ) {
			$values[ GTM4WP_OPTION_BLACKLIST_STATUS ] = explode( ',', $values[ GTM4WP_OPTION_BLACKLIST_STATUS ] );
		}

		if ( defined( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ) ) {
			$values[ GTM4WP_OPTION_ENV_GTM_AUTH ] = constant( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' );
		}

		if ( defined( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ) ) {
			$values[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] = constant( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' );
		}

		if ( defined( 'GTM4WP_HARDCODED_GTM_ID' ) ) {
			$hardcoded_gtm_id = constant( 'GTM4WP_HARDCODED_GTM_ID' );

			// Validate the hard coded GTM ID before overriding the stored value.
			$gtmid_list     = explode( ',', $hardcoded_gtm_id );
			$gtmid_haserror = false;

			foreach ( $gtmid_list as $one_gtm_id ) {
				$gtmid_haserror = $gtmid_haserror || ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id );
			}

			if ( ! $gtmid_haserror ) {
				$values[ GTM4WP_OPTION_GTM_CODE ] = $hardcoded_gtm_id;
			}
		}

		// Only load the first container if environment parameters are set.
		if (
			( '' !== ( $values[ GTM4WP_OPTION_ENV_GTM_AUTH ] ?? '' ) ) &&
			( '' !== ( $values[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ?? '' ) )
		) {
			$gtmid_list = explode( ',', (string) ( $values[ GTM4WP_OPTION_GTM_CODE ] ?? '' ) );
			if ( count( $gtmid_list ) > 0 ) {
				$values[ GTM4WP_OPTION_GTM_CODE ] = $gtmid_list[0];
			}
		}

		$this->values = $values;
	}

	/**
	 * Returns a single option value.
	 *
	 * @param string $key     Option key, see the GTM4WP_OPTION_* constants.
	 * @param mixed  $fallback Value to return when the key is unknown.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $fallback;
	}

	/**
	 * Returns all merged option values.
	 *
	 * Used to populate the backward compatible $GLOBALS['gtm4wp_options']
	 * mirror that third party code reads.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->values;
	}
}
