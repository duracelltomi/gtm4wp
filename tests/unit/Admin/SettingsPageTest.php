<?php
/**
 * Unit tests for the settings page bootstrap sink.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Functions;
use GTM4WP\Admin\RestController;
use GTM4WP\Admin\SettingsPage;
use GTM4WP\Module\Registry;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the admin bootstrap sink: enqueue_assets() writes the module schemas +
 * current option values into an inline <script> as
 * `var gtm4wpSettings = wp_json_encode( bootstrap_data(), HEX_FLAGS )`.
 *
 * The security-relevant assertion (RI-2 / finding #11) is the hex-flag
 * regression: a stored option value is admin free-text that reaches this inline
 * script un-sanitized (current_values() returns the RAW stored value; only the
 * REST save path sanitizes), so a break-out payload must be hex-encoded at the
 * sink. The hostile value carries `<`, `>`, `"`, `&` and `'` so dropping ANY one
 * of the four JSON hex flags changes the encoded fragment and fails the test.
 * Break-out characters are written with \xNN escapes and the expected fragment is
 * computed with json_encode() using the same flags the source uses (TC-2).
 */
final class SettingsPageTest extends TestCase {

	private const HEX_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;

	/**
	 * Every wp_add_inline_script() call recorded during the test.
	 *
	 * @var array<int, array{handle: string, code: string, position: string}>
	 */
	private array $inline_scripts = array();

	/**
	 * Every wp_enqueue_script() call recorded during the test.
	 *
	 * @var array<int, array{handle: string, src: string}>
	 */
	private array $enqueued_scripts = array();

	/**
	 * Every wp_set_script_translations() call recorded during the test.
	 *
	 * @var array<int, array{handle: string, domain: string, path: string|null}>
	 */
	private array $script_translations = array();

	protected function setUp(): void {
		parent::setUp();

		// Building every module admin schema needs the same stub set the REST
		// controller test uses (labels/descriptions go through i18n + wp_kses).
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $value ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		// Reached through Container\AdminSchema::production_only_description() when
		// the real module registry is built. Stubbed here rather than borrowed from
		// whichever test file happened to run first (TS-16): 'production' is exactly
		// what the source's own function_exists() fallback returns, so this pins the
		// value instead of inheriting another file's 'staging'.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names(): array {
					return array();
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();

		// Asset/enqueue machinery: no-ops. is_file() is a PHP internal Patchwork
		// cannot redefine, but the bootstrap inline script is emitted whether the
		// build asset file exists or not, so the assertions do not depend on it.
		Functions\when( 'plugins_url' )->alias( static fn ( $path ) => 'https://example.com/' . $path );
		// Both doubles RECORD their arguments rather than swallowing them. A
		// stand-in that accepts and ignores the very argument the coupling rides
		// on cannot fail when that argument changes, and a green suite then reads
		// as evidence the contract is covered when it is the opposite.
		$this->enqueued_scripts    = array();
		$this->script_translations = array();

		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
				$this->enqueued_scripts[] = array(
					'handle' => $handle,
					'src'    => (string) $src,
				);
				return true;
			}
		);
		Functions\when( 'wp_set_script_translations' )->alias(
			function ( $handle, $domain = 'default', $path = null ) {
				$this->script_translations[] = array(
					'handle' => $handle,
					'domain' => $domain,
					'path'   => $path,
				);
				return true;
			}
		);
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$this->inline_scripts = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position = 'after' ) {
				$this->inline_scripts[] = array(
					'handle'   => $handle,
					'code'     => $code,
					'position' => $position,
				);
				return true;
			}
		);
	}

	/**
	 * Builds a settings page over the real default registry with the given
	 * stored options. The registry is shared with the REST controller, exactly
	 * as Plugin wires them.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return SettingsPage
	 */
	private function make_settings_page( array $stored ): SettingsPage {
		Functions\when( 'get_option' )->justReturn( $stored );

		$registry = Registry::with_default_modules();

		return new SettingsPage( $registry, new RestController( $registry ) );
	}

	/**
	 * Returns the JSON-encoded form of a scalar without the surrounding quotes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function encoded_fragment( string $value ): string {
		return trim( (string) json_encode( $value, self::HEX_FLAGS ), '"' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Pins the two strings the admin screen's translations are keyed on.
	 *
	 * Because wp_set_script_translations() is called with no $path, WordPress looks
	 * for WP_LANG_DIR/plugins/{domain}-{locale}-{md5}.json where the md5 is taken
	 * over the script src relative to the plugin root - `build/admin.js`. The
	 * wordpress.org
	 * language-pack builder independently hashes the source reference its string
	 * extractor recorded, which is the same `build/admin.js`. The two md5s agree
	 * only while that literal path and that domain both hold.
	 *
	 * Renaming the build output (a webpack contenthash, an entry rename) or the
	 * domain would break every locale's admin translations with no error, no
	 * warning and nothing else in the suite going red - the screen would simply
	 * stay English. Measured 2026-08-06: md5('build/admin.js') =
	 * aa377c165c6b87664fc2deacf28cbe53. Registry row U98.
	 */
	public function test_admin_app_script_path_and_text_domain_stay_pinned_for_translations(): void {
		$page = $this->make_settings_page( array() );

		$page->enqueue_assets( 'settings_page_' . GTM4WP_ADMINSLUG );

		$handles = array_column( $this->enqueued_scripts, 'src', 'handle' );

		$this->assertArrayHasKey( 'gtm4wp-admin-app', $handles, 'The admin app script is enqueued.' );
		$this->assertStringEndsWith(
			'build/admin.js',
			$handles['gtm4wp-admin-app'],
			'The md5 wordpress.org hashes is taken over this exact relative path.'
		);

		$this->assertCount( 1, $this->script_translations, 'Translations are registered once for the admin app.' );
		$this->assertSame( 'gtm4wp-admin-app', $this->script_translations[0]['handle'] );
		$this->assertSame( 'duracelltomi-google-tag-manager', $this->script_translations[0]['domain'] );
	}

	public function test_enqueue_assets_hex_encodes_breakout_characters_in_stored_values(): void {
		// A container custom-domain is admin free-text; here it carries a break-out
		// payload exercising all four hex flags: `</script>"&'`.
		$hostile = "\x3C/script\x3E\x22\x26\x27";

		$page = $this->make_settings_page(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-ABC123',
						'gtm_auth'    => '',
						'gtm_preview' => '',
						'domain'      => $hostile,
						'path'        => '',
					),
				),
			)
		);

		$page->enqueue_assets( 'settings_page_' . GTM4WP_ADMINSLUG );

		$this->assertCount( 1, $this->inline_scripts, 'The bootstrap inline script is emitted once.' );
		$inline = $this->inline_scripts[0];

		$this->assertSame( 'gtm4wp-admin-app', $inline['handle'] );
		$this->assertSame( 'before', $inline['position'] );
		$this->assertStringContainsString( 'var gtm4wpSettings = ', $inline['code'] );

		// Present: the hex-encoded form as the source's wp_json_encode() emits it.
		// Dropping any of JSON_HEX_TAG/AMP/QUOT/APOS changes this fragment and fails.
		$this->assertStringContainsString( $this->encoded_fragment( $hostile ), $inline['code'] );

		// Absent: the raw break-out payload that would appear if a flag were dropped.
		$this->assertStringNotContainsString( '</script>', $inline['code'] );
		$this->assertStringNotContainsString( $hostile, $inline['code'] );
	}

	/**
	 * Deep links address an option KEY, and the app resolves the module and tab
	 * from the schema it is handed. These pin the two halves of that contract on
	 * the PHP side: the URL a caller builds, and the query-argument name the app
	 * is told to look for - which exists so the name is not written a second time
	 * in JavaScript (UC-6). The JS half is in js/admin/test/utils.test.js.
	 */
	public function test_url_deep_links_to_the_named_option(): void {
		Functions\when( 'menu_page_url' )->justReturn( 'https://example.com/wp-admin/options-general.php?page=' . GTM4WP_ADMINSLUG );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $key, $value, $url ) => $url . '&' . $key . '=' . rawurlencode( (string) $value )
		);

		$this->assertSame(
			'https://example.com/wp-admin/options-general.php?page=' . GTM4WP_ADMINSLUG
				. '&gtm4wp-focus=' . GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES,
			SettingsPage::url( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES )
		);
	}

	public function test_url_without_an_option_is_the_plain_settings_page(): void {
		Functions\when( 'menu_page_url' )->justReturn( 'https://example.com/wp-admin/options-general.php?page=' . GTM4WP_ADMINSLUG );

		$url = SettingsPage::url();

		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=' . GTM4WP_ADMINSLUG, $url );
		$this->assertStringNotContainsString( SettingsPage::FOCUS_QUERY_ARG, $url );
	}

	public function test_bootstrap_data_names_the_focus_query_argument_for_the_app(): void {
		$data = $this->make_settings_page( array() )->bootstrap_data();

		$this->assertSame( SettingsPage::FOCUS_QUERY_ARG, $data['focusArg'] );
	}

	/**
	 * The keys the shipped notices deep link to must be keys the app can actually
	 * find, otherwise the link silently degrades to "opens the page" - the exact
	 * behaviour the feature replaces, and one nothing else would report.
	 */
	public function test_deep_linked_option_keys_exist_in_the_schema(): void {
		$keys = array();
		foreach ( $this->make_settings_page( array() )->bootstrap_data()['modules'] as $module ) {
			foreach ( $module['fields'] as $field ) {
				$keys[] = $field['key'];
			}
		}

		$this->assertContains( GTM4WP_OPTION_GTM_CONTAINERS, $keys );
		$this->assertContains( GTM4WP_OPTION_DATALAYER_NAME, $keys );
		$this->assertContains( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES, $keys );

		// The 1.x flat container key is a derived mirror with no control of its
		// own; a notice pointing at it would resolve to nothing.
		$this->assertNotContains( GTM4WP_OPTION_GTM_CODE, $keys );
	}

	public function test_enqueue_assets_does_nothing_outside_the_settings_page(): void {
		$page = $this->make_settings_page( array() );

		$page->enqueue_assets( 'plugins.php' );

		$this->assertCount( 0, $this->inline_scripts, 'The app is loaded on the settings page only.' );
	}

	/**
	 * End to end through the bootstrap sink: with a hard coded container ID the
	 * React app must receive the container that is actually loaded AND the
	 * read-only flags that stop the admin from editing it. Getting only one of
	 * the two is what let an admin save a container ID that never loads.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_bootstrap_data_locks_the_container_table_fixed_in_wp_config(): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', 'GTM-HARD01' );

		$page = $this->make_settings_page(
			array(
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						'id'          => 'GTM-STORED1',
						'gtm_auth'    => '',
						'gtm_preview' => '',
						'domain'      => '',
						'path'        => '',
						'no_id'       => '',
					),
				),
			)
		);

		$field = null;
		foreach ( $page->bootstrap_data()['modules'] as $module ) {
			foreach ( $module['fields'] as $one_field ) {
				if ( GTM4WP_OPTION_GTM_CONTAINERS === $one_field['key'] ) {
					$field = $one_field;
				}
			}
		}

		$this->assertNotNull( $field, 'The container table reaches the React app.' );
		$this->assertSame( 'GTM-HARD01', $field['value'][0]['id'], 'The screen is given the container the frontend loads.' );
		$this->assertTrue( $field['rows_locked'], 'The row set is fixed in wp-config.php.' );

		$columns = array_column( $field['columns'], null, 'key' );
		$this->assertTrue( $columns['id']['readonly'] );
	}

	public function test_bootstrap_data_exposes_modules_and_rest_path(): void {
		$page = $this->make_settings_page( array() );

		$data = $page->bootstrap_data();

		$this->assertArrayHasKey( 'modules', $data );
		$this->assertNotEmpty( $data['modules'], 'Every registered module is exposed to the React app.' );
		$this->assertSame(
			RestController::REST_NAMESPACE . RestController::REST_ROUTE,
			$data['restPath']
		);
		$this->assertSame(
			RestController::REST_NAMESPACE . RestController::REST_ROUTE_EXPORT,
			$data['exportPath'],
			'The React app is told where to fetch the settings export.'
		);
		$this->assertSame(
			RestController::REST_NAMESPACE . RestController::REST_ROUTE_IMPORT,
			$data['importPath'],
			'The React app is told where to POST a settings import.'
		);
	}

	/**
	 * The bootstrap payload is where the documentation paths declared by the
	 * schemas become the absolute URLs the settings app renders as an href. Each
	 * field's fragment is its own option key, which is what makes a help icon
	 * land on the section that documents THAT option rather than on the page top.
	 */
	public function test_bootstrap_data_resolves_documentation_links_for_modules_and_fields(): void {
		$page = $this->make_settings_page( array() );

		$modules = $page->bootstrap_data()['modules'];
		$by_id   = array_column( $modules, null, 'id' );

		$this->assertArrayHasKey( 'woocommerce', $by_id );
		$this->assertSame(
			'https://gtm4wp.com/google-tag-manager-for-woocommerce',
			$by_id['woocommerce']['docUrl'],
			'A module panel links to its own documentation page.'
		);

		$fields = array_column( $by_id['woocommerce']['fields'], 'doc', 'key' );

		$this->assertSame(
			'https://gtm4wp.com/google-tag-manager-for-woocommerce/woocommerce-settings-reference#' . GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE,
			$fields[ GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE ],
			'An option deep links to its own anchor, which is its option key.'
		);

		// Not one page for the whole module: the media trackers each have their
		// own, and collapsing them onto the hub would look identical in a test
		// that only checked "a link is present".
		$media  = array_column( $by_id['media-events']['fields'], 'doc', 'key' );
		$prefix = 'https://gtm4wp.com/track-embedded-media-players-in-google-tag-manager/';

		$this->assertSame(
			$prefix . 'youtube-video-tracking#' . GTM4WP_OPTION_EVENTS_YOUTUBE,
			$media[ GTM4WP_OPTION_EVENTS_YOUTUBE ]
		);
		$this->assertSame(
			$prefix . 'spotify-tracking#' . GTM4WP_OPTION_EVENTS_SPOTIFY,
			$media[ GTM4WP_OPTION_EVENTS_SPOTIFY ]
		);
	}

	/**
	 * A third party module registered through 'gtm4wp_register_modules' predates
	 * DocumentedSchemaInterface and never implements it. It must still render:
	 * the panel simply gets no header link. Asserted through the real registry
	 * extension point rather than by calling the schema directly, because the
	 * instanceof check lives in the loop, not in the schema.
	 */
	public function test_a_module_schema_without_the_documentation_interface_still_boots(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$registry = new Registry();
		$registry->add( new UndocumentedThirdPartyModule() );

		$page    = new SettingsPage( $registry, new RestController( $registry ) );
		$modules = $page->bootstrap_data()['modules'];

		$this->assertCount( 1, $modules );
		$this->assertSame( 'third-party', $modules[0]['id'] );
		$this->assertSame(
			'',
			$modules[0]['docUrl'],
			'No interface, no header link - and no fatal.'
		);
		$this->assertSame(
			'',
			$modules[0]['fields'][0]['doc'],
			'A field that declares no page gets an empty string, never a bare base URL.'
		);
	}
}
