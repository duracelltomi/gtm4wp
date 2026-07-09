<?php
/**
 * Frontend orchestrator.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Frontend;

use GTM4WP\Module\Registry;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the frontend services, loads the backward compatible template
 * functions and boots the frontend part of every available module.
 */
final class Frontend {

	/**
	 * The script tag helper.
	 *
	 * @var ScriptTag|null
	 */
	private ?ScriptTag $script_tag = null;

	/**
	 * The data layer service.
	 *
	 * @var DataLayer|null
	 */
	private ?DataLayer $datalayer = null;

	/**
	 * The consent mode default state service.
	 *
	 * @var ConsentDefaults|null
	 */
	private ?ConsentDefaults $consent = null;

	/**
	 * The container code output service.
	 *
	 * @var ContainerCode|null
	 */
	private ?ContainerCode $container = null;

	/**
	 * Constructor.
	 *
	 * @param Options  $options  The plugin options service.
	 * @param Registry $registry The module registry.
	 */
	public function __construct( private Options $options, private Registry $registry ) {
	}

	/**
	 * Boots the whole frontend: core services, compat functions, modules.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->script_tag = new ScriptTag( $this->options );
		$this->datalayer  = new DataLayer( $this->options );
		$this->consent    = new ConsentDefaults( $this->options );
		$this->container  = new ContainerCode( $this->options, $this->datalayer, $this->script_tag, $this->consent );

		// Public template functions (gtm4wp_the_gtm_tag() etc.) need the
		// services above, therefore the compat layer loads afterwards.
		require_once GTM4WP_PATH . 'compat/functions.php';

		$this->container->register_hooks();
		$this->datalayer->register_hooks();

		$this->registry->frontend( $this->options );
	}

	/**
	 * Returns the container code service.
	 *
	 * @return ContainerCode
	 */
	public function container(): ContainerCode {
		return $this->container;
	}

	/**
	 * Returns the data layer service.
	 *
	 * @return DataLayer
	 */
	public function datalayer(): DataLayer {
		return $this->datalayer;
	}

	/**
	 * Returns the script tag helper.
	 *
	 * @return ScriptTag
	 */
	public function script_tag(): ScriptTag {
		return $this->script_tag;
	}

	/**
	 * Returns the consent mode default state service.
	 *
	 * @return ConsentDefaults
	 */
	public function consent(): ConsentDefaults {
		return $this->consent;
	}
}
