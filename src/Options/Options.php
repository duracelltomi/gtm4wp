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

use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\Container\HardcodedContainers;

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
 *
 * Since 2.0 the container list is stored as per-container rows
 * (GTM4WP_OPTION_GTM_CONTAINERS, see ContainerRows). The flat 1.x options
 * (gtm-code, gtm-env-*, gtm-domain-name, gtm-custom-path) are derived from
 * the rows here so that $GLOBALS['gtm4wp_options'] readers keep working.
 */
final class Options {

	/**
	 * Merged option values (defaults overlaid with stored values).
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Names of the GTM4WP_HARDCODED_* wp-config constants that were defined but
	 * malformed, and were therefore ignored while building the container rows.
	 *
	 * A silently discarded constant is very hard to diagnose from the outside -
	 * the site operator sees a container that ignores their wp-config - so the
	 * admin notices read this list and tell them exactly which constant is wrong.
	 *
	 * @var string[]
	 */
	private array $hardcoded_errors = array();

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

		$rows = ContainerRows::normalize( $values[ GTM4WP_OPTION_GTM_CONTAINERS ] ?? array() );

		// Pre-migration fallback: build the rows from the flat 1.x options
		// as long as the row option has never been saved.
		if ( ! array_key_exists( GTM4WP_OPTION_GTM_CONTAINERS, $stored ) && array() === $rows ) {
			$rows = ContainerRows::from_legacy( $values );
		}

		// The GTM4WP_HARDCODED_* wp-config.php constants override the stored
		// container setup. Both the override itself and the read-only state of the
		// settings screen are resolved by HardcodedContainers, so the screen can
		// never disagree with what the frontend loads.
		list( $rows, $this->hardcoded_errors ) = HardcodedContainers::apply( $rows );

		$values[ GTM4WP_OPTION_GTM_CONTAINERS ] = $rows;

		// Read-only 1.x mirrors for $GLOBALS['gtm4wp_options'] consumers.
		$values = array_merge( $values, ContainerRows::legacy_values( $rows ) );

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
	 * Returns the names of the GTM4WP_HARDCODED_* wp-config constants that were
	 * defined but malformed, and were ignored as a result. Empty when everything
	 * hard coded is valid (the common case).
	 *
	 * @return string[]
	 */
	public function hardcoded_errors(): array {
		return $this->hardcoded_errors;
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
