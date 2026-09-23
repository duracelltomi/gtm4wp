<?php
/**
 * The plugin's section on the Site Health Info tab.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Module\Registry;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles ONE Site Health Info section for the whole plugin ("paste the
 * Google Tag Manager section" must name a single thing): two plugin-level
 * rows, then the rows each module's admin schema contributes via
 * SiteHealthInfoInterface, in registry order (PA-21). The disclosure rule is
 * on the interface; this class changes no row.
 */
final class SiteHealthInfo {

	/**
	 * Id of the debug-information section.
	 */
	public const SECTION = 'gtm4wp';

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 * @param Options  $options  The plugin options service.
	 */
	public function __construct( private Registry $registry, private Options $options ) {
	}

	/**
	 * Registers the filter.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
	}

	/**
	 * Adds the section.
	 *
	 * @param array<string, mixed> $info The registered sections.
	 * @return array<string, mixed>
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$info[ self::SECTION ] = array(
			'label'       => __( 'Google Tag Manager for WordPress', 'duracelltomi-google-tag-manager' ),
			'description' => __( 'The state of this plugin\'s features on this site. Statuses, counts and option states only - no keys, no account addresses and no visitor data - so this section is safe to paste into a support thread.', 'duracelltomi-google-tag-manager' ),
			'fields'      => $this->fields(),
		);

		return $info;
	}

	/**
	 * The plugin-level rows, then every module's rows in registry order with
	 * keys prefixed by the module id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$fields = array(
			'version'  => SiteHealthRows::text(
				__( 'Plugin version', 'duracelltomi-google-tag-manager' ),
				defined( 'GTM4WP_VERSION' ) ? (string) GTM4WP_VERSION : ''
			),
			// Codes only: the messages quote configured values.
			'problems' => SiteHealthRows::items(
				__( 'Configuration problems', 'duracelltomi-google-tag-manager' ),
				array_column( ( new ConfigurationChecks( $this->options ) )->problems(), 'code' )
			),
		);

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();

			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$schema = new $schema_class();

			// instanceof, not method_exists(): a third party schema predating
			// the interface stays valid and simply contributes nothing.
			if ( ! $schema instanceof SiteHealthInfoInterface ) {
				continue;
			}

			foreach ( $schema->site_health_info( $this->options ) as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$fields[ $module->id() . '_' . (string) $key ] = $row;
			}
		}

		/**
		 * Filters the rows of the plugin's Site Health Info section.
		 *
		 * For code that has state worth reporting but no module of its own.
		 * The same disclosure rule applies as to a module's rows: the Info tab
		 * is pasted into public threads, so nothing secret or personal.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string, array<string, mixed>> $fields Rows keyed by id, in the shape of the debug_information filter.
		 * @param Options                             $options The plugin options service.
		 */
		$filtered = apply_filters( 'gtm4wp_site_health_info', $fields, $this->options );

		return is_array( $filtered ) ? $filtered : $fields;
	}
}
