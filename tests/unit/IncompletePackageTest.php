<?php
/**
 * Boots a copy of the plugin with files removed, as an incomplete package or
 * upload leaves it, in a subprocess: the failure is an uncaught fatal, which
 * cannot be observed in-process, and the real classes exist in this one.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- test-only scratch copy in the system temp dir; WP_Filesystem is not bootstrapped in unit tests.

/**
 * A missing file must leave the site running, with the plugin inactive and the
 * administrator told how to repair it (#488, #454).
 */
final class IncompletePackageTest extends TestCase {

	private const MAIN_FILE = 'duracelltomi-google-tag-manager-for-wordpress.php';

	private const NOTICE_MARKER = 'some of its files are missing';

	/**
	 * The scratch copy of the plugin.
	 *
	 * @var string
	 */
	private string $copy = '';

	/**
	 * Removes the scratch copy.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->copy ) {
			$this->delete_tree( $this->copy );
		}

		parent::tearDown();
	}

	/**
	 * Deletes a directory tree.
	 *
	 * @param string $dir The directory.
	 * @return void
	 */
	private function delete_tree( string $dir ): void {
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( $entry->isDir() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Copies the plugin's PHP into a scratch folder, leaving out the given paths.
	 *
	 * @param string[] $missing Paths relative to the plugin root.
	 * @return void
	 */
	private function copy_plugin_without( array $missing ): void {
		$this->copy = sys_get_temp_dir() . '/gtm4wp_package_' . uniqid( '', true );
		mkdir( $this->copy );
		copy( GTM4WP_PATH . self::MAIN_FILE, $this->copy . '/' . self::MAIN_FILE );

		foreach ( array( 'src', 'compat' ) as $root ) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( GTM4WP_PATH . $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $files as $file ) {
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( GTM4WP_PATH ) ) );

				foreach ( $missing as $path ) {
					if ( str_starts_with( $relative, $path ) ) {
						continue 2;
					}
				}

				$target = $this->copy . '/' . $relative;
				if ( ! is_dir( dirname( $target ) ) ) {
					mkdir( dirname( $target ), 0777, true );
				}
				copy( $file->getPathname(), $target );
			}
		}
	}

	/**
	 * Loads the copied plugin in a subprocess under minimal WordPress stubs.
	 *
	 * @param string $after_load PHP run after the main file was loaded.
	 * @param bool   $can_manage What current_user_can() answers.
	 * @return array{output: string, status: int}
	 */
	private function run_copy( string $after_load, bool $can_manage = true ): array {
		$main    = var_export( $this->copy . '/' . self::MAIN_FILE, true );
		$can     = var_export( $can_manage, true );
		$harness = <<<PHP
		<?php
		define( 'ABSPATH', __DIR__ . '/' );
		\$GLOBALS['wp_version'] = '7.1';
		\$GLOBALS['test_hooks'] = array();
		function add_action( \$hook, \$callback, \$priority = 10, \$args = 1 ) {
			\$GLOBALS['test_hooks'][ \$hook ][] = \$callback;
			return true;
		}
		function add_filter( \$hook, \$callback, \$priority = 10, \$args = 1 ) {
			return add_action( \$hook, \$callback, \$priority, \$args );
		}
		function run_filter( \$hook, \$value ) {
			foreach ( \$GLOBALS['test_hooks'][ \$hook ] ?? array() as \$callback ) {
				\$value = \$callback( \$value );
			}
			return \$value;
		}
		function __( \$text, \$domain ) {
			return \$text;
		}
		function esc_html( \$text ) {
			return \$text;
		}
		function plugin_dir_path( \$file ) {
			return dirname( \$file ) . '/';
		}
		function current_user_can( \$capability ) {
			return {$can};
		}
		function esc_html__( \$text, \$domain ) {
			return \$text;
		}
		function run_hook( \$hook ) {
			foreach ( \$GLOBALS['test_hooks'][ \$hook ] ?? array() as \$callback ) {
				\$callback();
			}
		}
		require {$main};
		{$after_load}
		echo "\\nhooks:" . implode( ',', array_keys( \$GLOBALS['test_hooks'] ) ) . "\\nreached-end\\n";
		PHP;

		$harness_file = $this->copy . '/harness.php';
		file_put_contents( $harness_file, $harness );

		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $harness_file ) . ' 2>&1', $output, $status );

		return array(
			'output' => implode( "\n", $output ),
			'status' => $status,
		);
	}

	/**
	 * The shapes seen in the field: the 2.1.0 package without its four new
	 * module folders, one of them alone, and an interrupted upload (#454).
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public static function incomplete_packages(): array {
		return array(
			'the four folders of the partial 2.1.0' => array(
				array(
					'src/Modules/EasyDigitalDownloads/',
					'src/Modules/GoogleAuth/',
					'src/Modules/GoogleDataManager/',
					'src/Modules/Services/',
				),
			),
			'one module folder'                     => array( array( 'src/Modules/GoogleDataManager/' ) ),
			'a module file'                         => array( array( 'src/Modules/Amp/AmpModule.php' ) ),
			'the plugin core'                       => array( array( 'src/Plugin.php' ) ),
			'the module registry'                   => array( array( 'src/Module/Registry.php' ) ),
		);
	}

	/**
	 * The site survives, nothing of the plugin boots, and the notice explains it.
	 *
	 * @param string[] $missing Paths left out of the copy.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'incomplete_packages' )]
	public function test_a_missing_file_leaves_the_site_running_with_a_notice( array $missing ): void {
		$this->copy_plugin_without( $missing );

		$result = $this->run_copy( "run_hook( 'plugins_loaded' ); run_hook( 'admin_notices' );" );

		$this->assertStringNotContainsString( 'Fatal error', $result['output'], 'A missing file must not stop the site.' );
		$this->assertSame( 0, $result['status'], $result['output'] );
		$this->assertStringContainsString( 'reached-end', $result['output'] );
		$this->assertStringContainsString( self::NOTICE_MARKER, $result['output'], 'The administrator is told what happened.' );
		$this->assertStringNotContainsString( 'rest_api_init', $result['output'], 'Nothing of the plugin boots on a partial copy.' );
	}

	/**
	 * Site Health reports the state as critical and names what is missing. The
	 * plugin's own collectors never boot here, so this test stands alone.
	 */
	public function test_site_health_reports_a_critical_item_naming_what_is_missing(): void {
		$this->copy_plugin_without( array( 'src/Modules/GoogleDataManager/' ) );

		$result = $this->run_copy(
			<<<'PHP'
			run_hook( 'plugins_loaded' );
			$tests  = run_filter( 'site_status_tests', array( 'direct' => array( 'core_test' => array( 'label' => 'x' ) ) ) );
			$entry  = $tests['direct']['gtm4wp_incomplete_package'];
			$report = call_user_func( $entry['test'] );
			echo json_encode( array( 'kept' => isset( $tests['direct']['core_test'] ), 'label' => $entry['label'], 'report' => $report ) ) . "\n";
			PHP
		);

		$this->assertStringContainsString( 'reached-end', $result['output'], $result['output'] );

		$lines   = explode( "\n", $result['output'] );
		$decoded = json_decode( (string) $lines[0], true );

		$this->assertTrue( $decoded['kept'], 'The tests of WordPress and other plugins stay in the list.' );
		$this->assertSame( 'critical', $decoded['report']['status'] );
		$this->assertSame( 'gtm4wp_incomplete_package', $decoded['report']['test'] );
		$this->assertStringContainsString( self::NOTICE_MARKER, $decoded['report']['description'] );
		$this->assertStringContainsString( 'GoogleDataManagerModule', $decoded['report']['description'], 'The report names what is missing.' );
		$this->assertArrayHasKey( 'badge', $decoded['report'] );
		$this->assertArrayHasKey( 'actions', $decoded['report'] );
	}

	/**
	 * The plugin core itself missing still gives a report, with no Registry to ask.
	 */
	public function test_site_health_names_a_missing_registry(): void {
		$this->copy_plugin_without( array( 'src/Module/Registry.php' ) );

		$result = $this->run_copy( "run_hook( 'plugins_loaded' ); echo gtm4wp_incomplete_package_site_health_test()['description'];" );

		$this->assertStringContainsString( 'reached-end', $result['output'], $result['output'] );
		$this->assertStringContainsString( 'GTM4WP\Module\Registry', $result['output'] );
	}

	/**
	 * A network-activated plugin is managed in the Network Admin, where
	 * admin_notices does not fire.
	 */
	public function test_the_notice_also_reaches_the_network_admin(): void {
		$this->copy_plugin_without( array( 'src/Modules/Services/' ) );

		$result = $this->run_copy( "run_hook( 'plugins_loaded' ); run_hook( 'network_admin_notices' );" );

		$this->assertStringContainsString( 'reached-end', $result['output'], $result['output'] );
		$this->assertStringContainsString( self::NOTICE_MARKER, $result['output'] );
	}

	/**
	 * The notice tells how to repair the plugin, so only someone who can may see it.
	 */
	public function test_the_notice_is_not_shown_to_a_user_who_cannot_reinstall(): void {
		$this->copy_plugin_without( array( 'src/Modules/Services/' ) );

		$result = $this->run_copy( "run_hook( 'plugins_loaded' ); run_hook( 'admin_notices' );", false );

		$this->assertStringContainsString( 'reached-end', $result['output'], $result['output'] );
		$this->assertStringNotContainsString( self::NOTICE_MARKER, $result['output'] );
	}

	/**
	 * The other direction: a complete copy passes, or every site would go inactive.
	 */
	public function test_a_complete_package_passes_the_check(): void {
		$this->copy_plugin_without( array() );

		$result = $this->run_copy( "echo gtm4wp_package_is_complete() ? 'complete' : 'incomplete';" );

		$this->assertStringContainsString( 'reached-end', $result['output'], $result['output'] );
		$this->assertStringContainsString( 'complete', $result['output'] );
		$this->assertStringNotContainsString( 'incomplete', $result['output'] );
	}
}
