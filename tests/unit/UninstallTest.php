<?php
/**
 * Unit tests for the plugin uninstall routine.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

use Brain\Monkey\Functions;

/**
 * Uninstall routine — the plugin's only data-destruction path: it drops the
 * plugin's option rows (settings, stored version, encrypted service-account
 * keys, destination health records, capture counters, the send log), unschedules
 * the background send queue, and, with $delete_all = true, drops the
 * dismissed-notice meta of EVERY user. Two things are worth pinning (T38):
 *
 * 1. It deletes exactly the things it should, with the exact keys - a typo
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
		function wp_unschedule_hook( \$hook ) {
			echo 'wp_unschedule_hook:' . \$hook . "\n";
			return 0;
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
		$this->assertStringContainsString( 'delete_option:gtm4wp_google_service_accounts', $result['output'], 'The encrypted service-account keys are deleted; a leftover row would keep key material in the database after the plugin is gone.' );
		$this->assertStringContainsString( 'delete_option:gtm4wp_gdm_destination_health', $result['output'], 'The per-destination health records are deleted.' );
		$this->assertStringContainsString( 'delete_option:gtm4wp_gdm_capture_stats', $result['output'], 'The capture-rate counters are deleted.' );
		$this->assertStringContainsString( 'delete_option:gtm4wp_gdm_send_log', $result['output'], 'The send diagnostics ring is deleted.' );
		$this->assertStringContainsString( 'reached-end', $result['output'], 'The script runs to completion.' );
		$this->assertSame( 0, $result['status'], 'The uninstaller completes without error.' );
	}

	/**
	 * A queued send outlives the plugin: WP-Cron keeps firing a hook nothing
	 * listens on any more, and the job payload sits in the cron array for as long
	 * as it takes someone to notice. Both queue hooks are therefore unscheduled,
	 * and the names asserted here are the literals the uninstaller writes out -
	 * it runs without the autoloader, so it cannot read SendQueue::HOOKS, and
	 * SendQueueTest pins the two lists against each other from the other side.
	 */
	public function test_unschedules_the_background_send_queue(): void {
		$result = $this->run_in_subprocess( true );

		$this->assertStringContainsString( 'wp_unschedule_hook:gtm4wp_gdm_send_refund', $result['output'], 'Queued refund sends are unscheduled.' );
		$this->assertStringContainsString( 'wp_unschedule_hook:gtm4wp_gdm_poll_status', $result['output'], 'Queued status checks are unscheduled.' );
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
	public function test_makes_exactly_six_option_deletions_and_one_meta_deletion(): void {
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

		$unscheduled = array();
		Functions\when( 'wp_unschedule_hook' )->alias(
			static function ( $hook ) use ( &$unscheduled ) {
				$unscheduled[] = $hook;
				return 0;
			}
		);

		// Stubbed here rather than relied on: another suite defining an as_*
		// function defines it for the whole process, so whether the uninstaller
		// takes its Action Scheduler branch would otherwise depend on test order
		// (TS-16). With the stub in place the branch is taken deliberately, and
		// the purge it performs is asserted instead of merely tolerated.
		//
		// Every parameter is recorded, not only the hook: the alias that kept
		// just the hook and the group stayed green while the real call named a
		// group AND an empty argument list, which Action Scheduler reads as
		// "actions scheduled with no arguments" and so cancelled nothing (#240).
		// The library's default values stand in for what was not passed, so the
		// assertion below is on the exact call shape the library receives.
		$purged = array();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$purged ) {
				$purged[] = array( $hook, $args, $group );
			}
		);

		require self::UNINSTALL_FILE;

		$this->assertSame(
			array(
				'gtm4wp-options',
				'gtm4wp-plugin-version',
				'gtm4wp_google_service_accounts',
				'gtm4wp_gdm_destination_health',
				'gtm4wp_gdm_capture_stats',
				'gtm4wp_gdm_send_log',
			),
			$deleted_options,
			'Exactly the six plugin option rows are deleted, in order and with no extras.'
		);

		$this->assertSame(
			\GTM4WP\Modules\GoogleDataManager\SendQueue::HOOKS,
			$unscheduled,
			'Every hook SendQueue schedules is unscheduled - the uninstaller writes the names out, so this is what keeps the two lists identical.'
		);

		$this->assertSame(
			array(
				array( 'gtm4wp_gdm_send_refund', array(), '' ),
				array( 'gtm4wp_gdm_poll_status', array(), '' ),
			),
			$purged,
			'On a store with Action Scheduler the queued actions are cancelled by hook name alone: no group and no argument filter, which is the only call shape that takes the cancel-by-hook path of the library and matches actions scheduled with a payload.'
		);
	}
}
