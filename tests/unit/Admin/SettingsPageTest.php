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
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
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
}
