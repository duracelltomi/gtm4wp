<?php
/**
 * Admin notice for Data Manager destinations that keep failing.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Admin\SettingsPage;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Names every configured destination whose sends keep failing. Not
 * dismissible: a live gap that clears itself once a send succeeds or the
 * destination is removed. Reads the STORED rows, not the filtered runtime
 * list, because it deep-links to the settings table; a stale health record
 * is invisible, not alarming.
 */
final class HealthNotice {

	/**
	 * Constructor.
	 *
	 * @param Options           $options The plugin options service.
	 * @param DestinationHealth $health  The health records.
	 */
	public function __construct( private Options $options, private DestinationHealth $health ) {
	}

	/**
	 * Registers the admin hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'show_notice' ) );
	}

	/**
	 * Prints the notice when at least one configured destination is failing.
	 *
	 * @return void
	 */
	public function show_notice(): void {
		$stored = $this->options->get( GTM4WP_OPTION_GDM_DESTINATIONS );
		if ( ! is_array( $stored ) || ( array() === $stored ) ) {
			return;
		}

		$failing = array();

		foreach ( $stored as $raw_row ) {
			if ( ! is_array( $raw_row ) ) {
				continue;
			}

			$row         = DestinationRows::normalize_row( $raw_row );
			$measurement = $row[ DestinationRows::COLUMN_MEASUREMENT ];

			if ( ( '' !== $measurement ) && $this->health->is_failing( $measurement ) ) {
				$label     = $row[ DestinationRows::COLUMN_LABEL ];
				$failing[] = ( '' !== $label ) ? $label : $measurement;
			}
		}

		if ( array() === $failing ) {
			return;
		}

		echo '<div class="gtm4wp-notice notice notice-error" data-href="?gdm-destination-failing"><p><strong>';
		printf(
			esc_html(
				/* translators: 1: comma separated list of destination labels. 2: opening anchor element pointing to the GTM4WP options page. 3: closing anchor element. */
				_n(
					'Sending data to the Google Data Manager destination %1$s keeps failing, so events are not reaching Google Analytics. Check that the service account still has access to the property, then use the Test button of %2$sthe destination%3$s to find the reason.',
					'Sending data to the Google Data Manager destinations %1$s keeps failing, so events are not reaching Google Analytics. Check that the service accounts still have access to the properties, then use the Test buttons of %2$sthe destinations%3$s to find the reason.',
					count( $failing ),
					'duracelltomi-google-tag-manager'
				)
			),
			esc_html( implode( ', ', $failing ) ),
			'<a href="' . esc_url( SettingsPage::url( GTM4WP_OPTION_GDM_DESTINATIONS ) ) . '">',
			'</a>'
		);
		echo '</strong></p></div>';
	}
}
