<?php
/**
 * Unit tests for the plugin uninstall routine.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Functions;

/**
 * Uninstall routine — the plugin's only data-destruction path: it drops both
 * option rows and, with $delete_all = true, the dismissed-notice meta of EVERY
 * user. Two things are worth pinning (T38):
 *
 * 1. It deletes exactly the three things it should, with the exact keys - a typo
 *    in a key silently orphans data, and a wrong $delete_all argument is the
 *    difference between clearing one user's meta and clearing everyone's.
 * 2. The WP_UNINSTALL_PLUGIN guard actually halts. That branch calls die(), so it
 *    cannot be observed in-process (TC-7's throw-and-observe trick needs a
 *    stubbable function, and die is not one). It is driven in a SUBPROCESS
 *    instead, which has the second benefit of never defining WP_UNINSTALL_PLUGIN
 *    inside the test runner - a process-wide constant would be exactly the sticky
 *    state TS-16 is about.
 */
final class UninstallTest extends TestCase {

	private const UNINSTALL_FILE = GTM4WP_PATH . 'uninstall.php';

	/**
	 * Runs uninstall.php in a subprocess with delete_option()/delete_metadata()
	 * replaced by echo, so the calls the script makes are observable as output.
	 *
	 * @param bool $define_guard Whether to define WP_UNINSTALL_PLUGIN first.
	 * @return array{output: string, status: int}
	 */
	private function run_in_subprocess( bool $define_guard ): array {
		$guard = $define_guard ? "define( 'WP_UNINSTALL_PLUGIN', true );" : '';

		$harness = <<<PHP
		<?php
		function delete_option( \$key ) {
			echo 'delete_option:' . \$key . "\n";
			return true;
		}
		function delete_metadata( \$type, \$object_id, \$meta_key, \$meta_value = '', \$delete_all = false ) {
			echo 'delete_metadata:' . \$type . '|' . var_export( \$object_id, true )
				. '|' . \$meta_key . '|' . var_export( \$delete_all, true ) . "\n";
			return true;
		}
		{$guard}
		require %s;
		echo "reached-end\n";
		PHP;

		$harness_file = tempnam( sys_get_temp_dir(), 'gtm4wp_uninstall_' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- test-only scratch harness in the system temp dir; WP_Filesystem is not bootstrapped in unit tests.
		file_put_contents( $harness_file, sprintf( $harness, var_export( self::UNINSTALL_FILE, true ) ) );

		$output = array();
		$status = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- the die() branch cannot be observed in-process; a subprocess is the only way to assert the guard halts (see the class doc block).
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $harness_file ) . ' 2>&1', $output, $status );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this test's own temp file; wp_delete_file() is not loaded in unit tests.
		unlink( $harness_file );

		return array(
			'output' => implode( "\n", $output ),
			'status' => $status,
		);
	}

	/**
	 * The guard is the whole security property of this file: without it, anyone
	 * who can reach uninstall.php over HTTP triggers the deletions. Proven by
	 * running the script with WP_UNINSTALL_PLUGIN undefined and asserting it
	 * halted BEFORE the first delete - the marker after the require never prints
	 * either, so this fails if the guard is removed or weakened to a warning.
	 */
	public function test_halts_without_the_wordpress_uninstall_constant(): void {
		$result = $this->run_in_subprocess( false );

		$this->assertStringNotContainsString( 'delete_option:', $result['output'], 'No option is deleted when WordPress did not invoke the uninstaller.' );
		$this->assertStringNotContainsString( 'delete_metadata:', $result['output'], 'No user meta is deleted when WordPress did not invoke the uninstaller.' );
		$this->assertStringNotContainsString( 'reached-end', $result['output'], 'The script halts rather than returning to its caller.' );
		$this->assertSame( 0, $result['status'], 'The guard exits cleanly (die), it does not fatal.' );
	}

	/**
	 * The grant direction, driven the same way so both directions are compared
	 * against the same harness.
	 */
	public function test_deletes_exactly_the_plugin_data_when_invoked_by_wordpress(): void {
		$result = $this->run_in_subprocess( true );

		$this->assertStringContainsString( 'delete_option:gtm4wp-options', $result['output'], 'The settings row is deleted.' );
		$this->assertStringContainsString( 'delete_option:gtm4wp-plugin-version', $result['output'], 'The stored plugin version is deleted.' );
		$this->assertStringContainsString( 'reached-end', $result['output'], 'The script runs to completion.' );
		$this->assertSame( 0, $result['status'], 'The uninstaller completes without error.' );
	}

	/**
	 * The dismissed-notice meta is per-user, so clearing it needs the $delete_all
	 * form ($object_id 0 + $delete_all true). Pinned separately because getting
	 * this wrong is silent: passing a real user id would delete one user's row and
	 * leave every other user's behind, and no other test would notice.
	 */
	public function test_removes_the_dismissed_notice_meta_of_every_user(): void {
		$result = $this->run_in_subprocess( true );

		$this->assertStringContainsString(
			'delete_metadata:user|0|gtm4wp_user_notices_dismisses_json|true',
			$result['output'],
			'The per-user notice meta is cleared for all users (object id 0 + $delete_all true).'
		);
	}

	/**
	 * In-process companion to the subprocess tests: asserts the exact call count
	 * so an added or dropped deletion is caught, which string matching on the
	 * subprocess output cannot do on its own.
	 */
	public function test_makes_exactly_two_option_deletions_and_one_meta_deletion(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$deleted_options = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$deleted_options ) {
				$deleted_options[] = $key;
				return true;
			}
		);

		Functions\expect( 'delete_metadata' )
			->once()
			->with( 'user', 0, 'gtm4wp_user_notices_dismisses_json', '', true )
			->andReturn( true );

		require self::UNINSTALL_FILE;

		$this->assertSame(
			array( 'gtm4wp-options', 'gtm4wp-plugin-version' ),
			$deleted_options,
			'Exactly the two plugin option rows are deleted, in order and with no extras.'
		);
	}
}
