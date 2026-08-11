<?php
/**
 * Settings page with the React admin app.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the options page under Settings and loads the React app with
 * its bootstrap data (module schemas + current values).
 */
final class SettingsPage {

	/**
	 * Query argument carrying the option key a deep link points at.
	 *
	 * Defined once here and handed to the React app in the bootstrap data
	 * (`focusArg`) rather than written a second time in JavaScript: a contract
	 * spelled out at both ends of our own codebase diverges long before anything
	 * upstream moves (UC-6).
	 */
	public const FOCUS_QUERY_ARG = 'gtm4wp-focus';

	/**
	 * Constructor.
	 *
	 * @param Registry       $registry The module registry.
	 * @param RestController $rest     The settings REST controller (for current values).
	 */
	public function __construct( private Registry $registry, private RestController $rest ) {
	}

	/**
	 * URL of the plugin settings page, optionally deep linking to a single option.
	 *
	 * With a `$field_key` the URL carries `gtm4wp-focus=<option key>`, which the
	 * settings app resolves against its own bootstrap data: it selects the module
	 * and the group tab that hold the option and highlights the control. The
	 * address is the option key alone and never a module/tab path, so the link
	 * keeps working when a field is regrouped or moves to another module, and the
	 * caller never has to know where the option currently lives.
	 *
	 * A key that matches nothing (a removed option, a hand-edited URL) simply
	 * opens the page the way it did before.
	 *
	 * @param string $field_key Option key to focus - a GTM4WP_OPTION_* value - or '' for the page itself.
	 * @return string URL for use in an href; escape it at the point of output.
	 */
	public static function url( string $field_key = '' ): string {
		$url = menu_page_url( GTM4WP_ADMINSLUG, false );

		if ( '' === $field_key ) {
			return $url;
		}

		// add_query_arg() URL-encodes the value, so the key is passed raw.
		return add_query_arg( self::FOCUS_QUERY_ARG, $field_key, $url );
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the plugin options page into the Settings menu of the WordPress admin.
	 * The capability can be changed with the gtm4wp_admin_page_capability filter.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		/** This filter is documented in src/Plugin.php */
		$capability = apply_filters( 'gtm4wp_admin_page_capability', 'manage_options' );

		add_options_page(
			esc_html__( 'Google Tag Manager for WordPress settings', 'duracelltomi-google-tag-manager' ),
			esc_html__( 'Google Tag Manager', 'duracelltomi-google-tag-manager' ),
			$capability,
			GTM4WP_ADMINSLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the React app container.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Google Tag Manager for WordPress options', 'duracelltomi-google-tag-manager' ) . '</h1>';
		echo '<div id="gtm4wp-admin-app"></div>';
		echo '</div>';
	}

	/**
	 * Loads the built React app on the settings page only.
	 *
	 * @param string $hook The ID of the admin page that is currently being shown.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( 'settings_page_' . GTM4WP_ADMINSLUG !== $hook ) {
			return;
		}

		$asset_file = GTM4WP_PATH . 'build/admin.asset.php';
		$asset      = is_file( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => GTM4WP_VERSION,
			);

		wp_enqueue_script(
			'gtm4wp-admin-app',
			plugins_url( 'build/admin.js', GTM4WP_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'gtm4wp-admin-app', 'duracelltomi-google-tag-manager' );

		wp_enqueue_style( 'wp-components' );

		// wp-scripts emits CSS imported from the entry point as style-<entry>.css.
		if ( is_file( GTM4WP_PATH . 'build/style-admin.css' ) ) {
			wp_enqueue_style(
				'gtm4wp-admin-app',
				plugins_url( 'build/style-admin.css', GTM4WP_PLUGIN_FILE ),
				array( 'wp-components' ),
				$asset['version']
			);
			wp_style_add_data( 'gtm4wp-admin-app', 'rtl', 'replace' );
		}

		wp_add_inline_script(
			'gtm4wp-admin-app',
			'var gtm4wpSettings = ' . ScriptTag::json_literal( $this->bootstrap_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
			'before'
		);
	}

	/**
	 * Collects the bootstrap data of the React app: every module with its
	 * admin schema (title, intro, accordion groups, fields with current
	 * values) plus the REST endpoint location.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrap_data(): array {
		// ui_values(), not current_values(): where a wp-config.php constant
		// overrides the container setup the screen shows what is actually
		// loaded, alongside the read-only state the container schema declares.
		$values  = $this->rest->ui_values();
		$modules = array();

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();
			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$schema = new $schema_class();

			$fields = array();
			foreach ( $schema->fields() as $field ) {
				$ui = $field->to_ui_array( $values[ $field->key ] ?? $field->default_value );

				// Resolved here rather than inside to_ui_array() so that Field, which
				// belongs to the options layer, never learns the documentation domain.
				// The anchor is the option key itself - see the Docs class docblock.
				$ui['doc'] = Docs::url( $field->doc, $field->key );

				$fields[] = $ui;
			}

			$groups = array();
			foreach ( $schema->groups() as $group_id => $group_label ) {
				$groups[] = array(
					'id'    => $group_id,
					'label' => $group_label,
				);
			}

			$modules[] = array(
				'id'                 => $module->id(),
				'title'              => $schema->title(),
				'intro'              => $schema->intro(),
				'groups'             => $groups,
				'fields'             => $fields,
				'available'          => $module->is_available(),
				'unavailableMessage' => $schema->unavailable_message(),
				// instanceof, not method_exists(): a third party schema predating
				// the interface stays valid and simply gets no header link.
				'docUrl'             => $schema instanceof DocumentedSchemaInterface
					? Docs::url( $schema->doc_url() )
					: '',
			);
		}

		return array(
			'modules'    => $modules,
			'focusArg'   => self::FOCUS_QUERY_ARG,
			'restPath'   => RestController::REST_NAMESPACE . RestController::REST_ROUTE,
			'exportPath' => RestController::REST_NAMESPACE . RestController::REST_ROUTE_EXPORT,
			'importPath' => RestController::REST_NAMESPACE . RestController::REST_ROUTE_IMPORT,
		);
	}
}
