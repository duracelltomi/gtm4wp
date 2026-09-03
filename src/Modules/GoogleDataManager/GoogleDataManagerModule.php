<?php
/**
 * Google Data Manager module (lean class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Sends e-commerce signals from the server to Google through the Data Manager
 * API. This version carries the foundation: the destination list (which GA4
 * property and data stream to send to, with which stored GoogleAuth service
 * account), its validateOnly test probe and the per-destination health
 * records. The features that actually send - server-side refund events,
 * attribution capture - build on these in later development phases.
 *
 * The is_available() answer stays unconditional on purpose (the inherited true): "no
 * service account uploaded yet" is an onboarding state, not an environment
 * dependency, and a vault check here would both hide the settings panel
 * behind a disabled card and re-add the per-pageview option read the
 * autoload=false choice exists to avoid. Gating is per feature instead,
 * inside the paths that need a key.
 */
final class GoogleDataManagerModule extends AbstractModule {

	/**
	 * Module id; also the settings-app bookmark (`#google-data-manager`).
	 */
	public const ID = 'google-data-manager';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Option defaults. Every option of this module starts off/empty: each one
	 * is part of the plugin's first data-leaves-the-site feature.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_GDM_DESTINATIONS => array(),
		);
	}

	/**
	 * Nothing runs on a frontend pageview yet: destinations are only read at
	 * send time and on the settings screen. The attribution-capture script of
	 * a later phase registers here.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}
}
