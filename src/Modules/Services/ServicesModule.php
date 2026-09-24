<?php
/**
 * Services for agencies and freelancers module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Services;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * An informational settings section pointing agencies and freelancers to the
 * services page on gtm4wp.com. It owns no option and does nothing on the frontend.
 */
final class ServicesModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'services';
	}

	/**
	 * No options.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array();
	}

	/**
	 * Nothing to register on the frontend.
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
