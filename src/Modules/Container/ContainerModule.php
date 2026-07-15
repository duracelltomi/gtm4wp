<?php
/**
 * Container module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Container;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the core container and data layer options (GTM ID, placement,
 * environments, custom domain/path, data layer name, console logging,
 * role exclusions).
 *
 * The actual frontend output is implemented by the core
 * GTM4WP\Frontend\ContainerCode service which is always active, therefore
 * this module registers no frontend hooks itself.
 */
final class ContainerModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'container';
	}

	/**
	 * Option defaults.
	 *
	 * Since 2.0 the container list is stored as per-container rows (ID +
	 * environment + domain + path, see ContainerRows). The flat 1.x options
	 * (gtm-code, gtm-env-*, gtm-domain-name, gtm-custom-path) are no longer
	 * stored fields; the Options service derives them from the rows as
	 * read-only mirrors.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_GTM_CONTAINERS   => array(),
			GTM4WP_OPTION_GTM_PLACEMENT    => GTM4WP_PLACEMENT_FOOTER,
			GTM4WP_OPTION_DATALAYER_NAME   => '',
			GTM4WP_OPTION_LOADEARLY        => false,
			GTM4WP_OPTION_NOGTMFORLOGGEDIN => '',
			GTM4WP_OPTION_NOCONSOLELOG     => false,
			GTM4WP_OPTION_PRODUCTIONONLY   => false,
		);
	}

	/**
	 * No module-specific frontend hooks; output is done by the core
	 * ContainerCode service.
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
