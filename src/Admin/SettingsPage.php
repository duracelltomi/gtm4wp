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

use GTM4WP\Capability;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\PanelSchemaInterface;
use GTM4WP\Module\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the options page under Settings and loads the React app with
 * its bootstrap data (module schemas + current values).
 */
final class SettingsPage {

	/**
	 * Query argument carrying the option key a deep link points at; handed to
	 * the React app as `focusArg` rather than written a second time (UC-6).
	 */
	public const FOCUS_QUERY_ARG = 'gtm4wp-focus';

	/**
	 * Class of the static boot-failure notice; used by render() and by the
	 * reveal CSS in enqueue_assets() (UC-6).
	 */
	public const BOOT_FALLBACK_CLASS = 'gtm4wp-admin-app-fallback';

	/**
	 * Seconds before the boot-failure notice becomes visible: long enough for
	 * admin.js to boot on a slow connection, short enough to answer a blank screen.
	 */
	public const BOOT_FALLBACK_REVEAL_DELAY = 3;

	/**
	 * Constructor.
	 *
	 * @param Registry      $registry The module registry.
	 * @param SettingsStore $store    The settings service the screen reads its values from.
	 */
	public function __construct( private Registry $registry, private SettingsStore $store ) {
	}

	/**
	 * URL of the settings page, optionally deep linking to one option via
	 * `gtm4wp-focus=<option key>`, which the app resolves against its bootstrap
	 * data. The address is the option key alone, never a module/tab path, so
	 * the link survives a field being regrouped; an unknown key opens the page.
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
		$capability = Capability::settings();

		add_options_page(
			esc_html__( 'Google Tag Manager for WordPress settings', 'duracelltomi-google-tag-manager' ),
			esc_html__( 'Google Tag Manager', 'duracelltomi-google-tag-manager' ),
			$capability,
			GTM4WP_ADMINSLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the React app container with a static boot-failure notice
	 * inside, for the admin whose content blocker stops build/admin.js. React
	 * clears the container on first render (U117), so the notice is only seen
	 * when the app did not boot; the reveal CSS keeps it invisible for the
	 * first seconds so it does not flash.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Google Tag Manager for WordPress options', 'duracelltomi-google-tag-manager' ) . '</h1>';
		echo '<div id="gtm4wp-admin-app">';
		echo '<div class="' . esc_attr( self::BOOT_FALLBACK_CLASS ) . ' notice notice-warning inline">';
		echo '<p><strong>' . esc_html__( 'The GTM4WP settings screen could not start.', 'duracelltomi-google-tag-manager' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'This screen is built in the browser by a JavaScript file loaded from the plugin folder (build/admin.js), and that file did not run. The most likely cause is an ad or privacy blocker: some filter lists block everything under the folder of this plugin, and that also blocks the file this screen is built from.', 'duracelltomi-google-tag-manager' ) . '</p>';
		echo '<p>' . esc_html__( 'Please pause the blocker for the admin area of this site, or add an exception for it, and reload this page. If the screen still stays empty, please check the browser console for errors, check on the Network tab whether admin.js loads from the plugin folder, and verify that all plugin files were uploaded completely.', 'duracelltomi-google-tag-manager' ) . '</p>';
		echo '</div>';
		echo '</div>';
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

		// Reveal CSS for the boot-failure notice, attached to the core
		// wp-components handle (the plugin folder may be blocked); a delayed
		// zero-duration animation flips visibility with no JavaScript.
		wp_add_inline_style(
			'wp-components',
			'#gtm4wp-admin-app .' . self::BOOT_FALLBACK_CLASS . '{visibility:hidden;animation:gtm4wp-admin-app-fallback-reveal 0s ' . self::BOOT_FALLBACK_REVEAL_DELAY . 's forwards}'
			. '@keyframes gtm4wp-admin-app-fallback-reveal{to{visibility:visible}}'
		);

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
		// ui_values(), not current_values(): the screen shows what a wp-config
		// constant actually loads.
		$values  = $this->store->ui_values();
		$modules = array();

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();
			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$schema = new $schema_class();

			$panel_data = $schema instanceof PanelSchemaInterface ? $schema->panel_data() : array();

			// The two reserved panel_data keys (see PanelSchemaInterface): column
			// choices that only exist at render time, since fields() must stay
			// database-free, and the accordion group the panel renders inside.
			$column_choices = is_array( $panel_data['columnChoices'] ?? null ) ? $panel_data['columnChoices'] : array();

			$panel_group = is_string( $panel_data['panelGroup'] ?? null ) ? $panel_data['panelGroup'] : '';

			$fields = array();
			foreach ( $schema->fields() as $field ) {
				$ui = $field->to_ui_array( $values[ $field->key ] ?? $field->default_value );

				// Resolved here so Field never learns the documentation domain.
				$ui['doc'] = Docs::url( $field->doc, $field->key );

				if ( isset( $column_choices[ $field->key ] ) && is_array( $column_choices[ $field->key ] ) ) {
					foreach ( $ui['columns'] as $index => $column ) {
						$key = $column['key'] ?? '';
						if ( isset( $column_choices[ $field->key ][ $key ] ) ) {
							$ui['columns'][ $index ]['choices'] = (array) $column_choices[ $field->key ][ $key ];
						}
					}
				}

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
				// A custom React panel with its own REST routes (the service
				// accounts manager). Same instanceof opt-in as the doc link.
				'panel'              => $schema instanceof PanelSchemaInterface ? $schema->panel() : '',
				'panelGroup'         => $panel_group,
				'panelData'          => (object) $panel_data,
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
