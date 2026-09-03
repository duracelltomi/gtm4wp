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
 * Owns the Google Cloud service accounts an admin uploads so that other
 * modules (the Data Manager API integration first) can call Google APIs on
 * the site's behalf. Identity only: which APIs an account may use, and the
 * targets it is used against, belong to the consuming module.
 *
 * The module has no options in the settings row: the accounts live in their
 * own encrypted, non-autoloaded option (\GTM4WP\Google\KeyVault) and are
 * managed over dedicated REST routes (RestController) by a custom panel of
 * the settings app. Nothing here runs on a frontend pageview.
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
