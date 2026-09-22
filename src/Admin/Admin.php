<?php
/**
 * Admin orchestrator.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Google\KeyVault;
use GTM4WP\Module\Registry;
use GTM4WP\Modules\GoogleAuth\KeyNotice;
use GTM4WP\Modules\GoogleDataManager\CaptureStats;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\HealthNotice;
use GTM4WP\Modules\GoogleDataManager\SiteHealth;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Boots all admin services. Only instantiated inside wp-admin for users
 * with the settings capability; nothing in this namespace ever loads on
 * frontend requests.
 */
final class Admin {

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 * @param Options  $options  The plugin options service.
	 */
	public function __construct( private Registry $registry, private Options $options ) {
	}

	/**
	 * Registers all admin hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		// The routes themselves are registered by Plugin on rest_api_init; the
		// screen only needs the store the routes read and write through.
		( new SettingsPage( $this->registry, new SettingsStore( $this->registry ) ) )->register_hooks();
		( new Notices( $this->options ) )->register_hooks();
		( new KeyNotice( new KeyVault() ) )->register_hooks();
		( new HealthNotice( $this->options, new DestinationHealth() ) )->register_hooks();
		( new SiteHealth( $this->options, new DestinationHealth(), new CaptureStats(), new KeyVault() ) )->register_hooks();
		( new SiteHealthInfo( $this->registry, $this->options ) )->register_hooks();
		( new PluginRow() )->register_hooks();
	}
}
