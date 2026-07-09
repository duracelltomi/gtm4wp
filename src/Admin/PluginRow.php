<?php
/**
 * Plugins page integration.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings action link and upgrade notice on the Plugins page.
 * Port of gtm4wp_add_plugin_action_links() and
 * gtm4wp_show_upgrade_notification() from 1.x.
 */
final class PluginRow {

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'plugin_action_links', array( $this, 'add_action_links' ), 10, 2 );
		add_action( 'in_plugin_update_message-' . plugin_basename( GTM4WP_PLUGIN_FILE ), array( $this, 'show_upgrade_notification' ), 10, 2 );
	}

	/**
	 * Adds a link to the settings page on the Plugins page.
	 *
	 * @param array  $links Existing links that can be extended by this filter.
	 * @param string $file Plugin ID where the links belong to.
	 * @return array Array of anchor HTML elements shown below the plugin on the Plugins page.
	 */
	public function add_action_links( $links, $file ) {
		if ( plugin_basename( GTM4WP_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		$settings_link = '<a href="' . menu_page_url( GTM4WP_ADMINSLUG, false ) . '">' . esc_html__( 'Settings', 'duracelltomi-google-tag-manager' ) . '</a>';

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Shows the upgrade message below the plugin description.
	 *
	 * @param array  $current_plugin_metadata Meta data of the currently active plugin version.
	 * @param object $new_plugin_metadata Meta data of the available new plugin version.
	 * @return void
	 */
	public function show_upgrade_notification( $current_plugin_metadata, $new_plugin_metadata ): void {
		if ( isset( $new_plugin_metadata->upgrade_notice ) && strlen( trim( $new_plugin_metadata->upgrade_notice ) ) > 0 ) {
			echo '<p style="background-color: #d54e21; padding: 10px; color: #f9f9f9; margin-top: 10px"><strong>Important Upgrade Notice:</strong> ';
			echo esc_html( $new_plugin_metadata->upgrade_notice ), '</p>';
		}
	}
}
