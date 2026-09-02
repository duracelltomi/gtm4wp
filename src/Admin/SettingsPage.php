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
	 * Class of the static boot-failure notice printed inside the app container.
	 *
	 * Written into the markup by render() and into the reveal CSS by
	 * enqueue_assets(); defined once so the two ends cannot drift apart (UC-6).
	 */
	public const BOOT_FALLBACK_CLASS = 'gtm4wp-admin-app-fallback';

	/**
	 * Seconds before the boot-failure notice becomes visible.
	 *
	 * Long enough for build/admin.js to load and boot on a slow connection
	 * (which removes the notice before it is ever seen), short enough that an
	 * admin staring at a blank screen gets an answer.
	 */
	public const BOOT_FALLBACK_REVEAL_DELAY = 3;

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
	 * Renders the React app container, with a static boot-failure notice inside.
	 *
	 * The screen is drawn by build/admin.js, and the EasyPrivacy filter list
	 * blocks everything under the plugin folder, so an admin running a content
	 * blocker gets no script and, before this notice existed, a silently blank
	 * page. The notice is plain server-rendered HTML that no blocker can touch:
	 * React clears the container's children on the app's first render (U117),
	 * so it is only ever seen when the app did not boot - the script blocked,
	 * missing, failed, or JavaScript off entirely. The reveal CSS added in
	 * enqueue_assets() keeps it invisible for the first few seconds so it does
	 * not flash while the app loads normally.
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

		// Reveal CSS for the boot-failure notice printed by render(). Attached to
		// the core wp-components handle, never to the plugin's own stylesheet:
		// the notice exists for the case where everything under the plugin folder
		// is blocked, so its styling must not load from there. A zero-duration
		// delayed animation flips the visibility with no JavaScript involved,
		// which keeps the notice working when scripts are blocked or off.
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
