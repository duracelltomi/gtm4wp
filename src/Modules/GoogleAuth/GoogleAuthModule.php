<?php
/**
 * Google service accounts module (lean class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleAuth;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the Google Cloud service accounts an admin uploads so other modules
 * can call Google APIs. Identity only: scopes and targets belong to the
 * consuming module. No options in the settings row: the accounts live in the
 * encrypted, non-autoloaded \GTM4WP\Google\KeyVault, managed over dedicated
 * REST routes by a custom panel. Nothing runs on a frontend pageview.
 */
final class GoogleAuthModule extends AbstractModule {

	/**
	 * Module id; also the settings-app bookmark (`#google-auth`) that opens the panel.
	 */
	public const ID = 'google-auth';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * No options in the settings row - see the class doc block.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array();
	}

	/**
	 * Nothing to do on the frontend: the accounts are only ever read at send
	 * time by a consumer, never on a pageview.
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
