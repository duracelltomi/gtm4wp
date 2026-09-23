<?php
/**
 * Optional contract for an admin schema that adds Site Health status tests.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Module;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Declares that a module contributes direct tests to Tools -> Site Health ->
 * Status, collected by Admin\SiteHealthTests, which walks the registry with
 * instanceof (PA-21). On the admin schema like the other opt-ins, because a
 * result carries translated text.
 *
 * Each callable returns core's result shape minus what the collector fills
 * in: `status` (good, recommended or critical), `label`, `description` and
 * `actions`. The last two are HTML that core prints unescaped, so the module
 * escapes them itself. The collector adds the test id
 * (`gtm4wp_<module id>_<key>`) and the plugin badge. Runs only when the
 * Status tab renders or an ability asks, so stored records may be read; an
 * HTTP request may not be made.
 */
interface SiteHealthTestsInterface {

	/**
	 * The module's status tests, keyed by a module-local id.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<string, callable> Key => a callable returning the result; empty for none.
	 */
	public function site_health_tests( Options $options ): array;
}
