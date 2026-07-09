<?php
/**
 * Base class for GTM4WP feature modules.
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
 * Shared plumbing for lean module classes: option access and frontend
 * script enqueueing with a deferred loading strategy.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * The plugin options service, available after frontend() has been called.
	 *
	 * @var Options|null
	 */
	protected ?Options $options = null;

	/**
	 * Modules without external dependencies are always available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Stores the options service and delegates to register_frontend_hooks().
	 *
	 * @param Options $options The plugin options service.
	 * @return void
	 */
	public function frontend( Options $options ): void {
		$this->options = $options;
		$this->register_frontend_hooks();
	}

	/**
	 * Registers all frontend hooks of the module. Implementations should
	 * check their own enabling option(s) and return early when disabled.
	 *
	 * @return void
	 */
	abstract protected function register_frontend_hooks(): void;

	/**
	 * Returns a single option value.
	 *
	 * @param string $key Option key, see the GTM4WP_OPTION_* constants.
	 * @return mixed
	 */
	protected function opt( string $key ) {
		return ( null !== $this->options ) ? $this->options->get( $key ) : null;
	}

	/**
	 * Returns the URL of a built plugin script.
	 *
	 * @param string $file File name inside the build directory.
	 * @return string
	 */
	protected function script_url( string $file ): string {
		return plugins_url( 'build/' . $file, GTM4WP_PLUGIN_FILE );
	}

	/**
	 * Enqueues a built frontend script.
	 *
	 * Uses the WordPress 6.3+ script loading strategy API so tracking
	 * scripts do not block page rendering.
	 *
	 * @param string $handle    Script handle.
	 * @param string $file      File name inside the build directory.
	 * @param array  $deps      Script dependencies.
	 * @param bool   $in_footer Whether to print the script in the footer.
	 * @param string $strategy  Loading strategy: 'defer', 'async' or '' for blocking.
	 * @return void
	 */
	protected function enqueue_script( string $handle, string $file, array $deps = array(), bool $in_footer = true, string $strategy = 'defer' ): void {
		$args = array(
			'in_footer' => $in_footer,
		);

		if ( '' !== $strategy ) {
			$args['strategy'] = $strategy;
		}

		wp_enqueue_script( $handle, $this->script_url( $file ), $deps, GTM4WP_VERSION, $args );
	}
}
