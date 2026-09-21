<?php
/**
 * Pins the script file-name contract the privacy block lists depend on.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

/**
 * Since 2026-09-16 the EasyPrivacy and AdGuard SpywareFilter lists block
 * `/gtm4wp-$script` instead of everything under this plugin's folder
 * (easylist/easylist#25228). That was changed at our request so the settings
 * screen is no longer blank behind an ad blocker, and it was granted on the
 * strength of two claims about our file names:
 *
 * 1. every script loaded on a public page is named `gtm4wp-*.js`, so the
 *    lists lose no coverage;
 * 2. no script loaded in wp-admin carries that prefix, so nothing of ours is
 *    blocked there.
 *
 * Neither claim is observable at runtime. A frontend script without the prefix
 * simply runs unblocked for the lists' users, and an admin script with it
 * simply fails to load; no error, no failing feature test. Per the upstream
 * review system's UD-2 the control has to be something that breaks, so this
 * test reads the source tree the way the build does. `webpack.config.js`
 * refuses the same names at build time; this is the half that runs without
 * node. Rule text: "Script file naming" in .claude/CLAUDE.md; registry row
 * U151 in .upstream/upstream-review-checklist.md.
 */
final class ScriptNamingContractTest extends TestCase {

	private const PREFIX = 'gtm4wp-';

	/**
	 * Lists the base names of the JavaScript files in a repo directory.
	 *
	 * @param string $relative  Repo-relative directory.
	 * @param bool   $recursive Whether to descend into subdirectories.
	 * @return string[] Base names, sorted.
	 */
	private function js_files( string $relative, bool $recursive ): array {
		$dir = GTM4WP_PATH . $relative;
		$this->assertDirectoryExists( $dir );

		$pattern = $recursive ? '/\.js$/' : '/^[^\/\\\\]+\.js$/';
		$files   = array();

		$iterator = $recursive
			? new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) )
			: new \FilesystemIterator( $dir, \FilesystemIterator::SKIP_DOTS );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && preg_match( $pattern, $file->getFilename() ) ) {
				$files[] = $file->getFilename();
			}
		}

		sort( $files );
		return $files;
	}

	/**
	 * Every frontend bundle carries the prefix the block lists match on.
	 *
	 * Top level only: webpack turns exactly the top-level files of js/frontend/
	 * into bundles, and js/frontend/lib/ and js/frontend/test/ never become
	 * request URLs.
	 */
	public function test_every_frontend_bundle_carries_the_prefix(): void {
		$files = $this->js_files( 'js/frontend', false );
		$this->assertNotEmpty( $files, 'js/frontend/ has no bundles - the directory the contract is about moved.' );

		foreach ( $files as $file ) {
			$this->assertStringStartsWith(
				self::PREFIX,
				$file,
				"js/frontend/{$file} would ship unblocked to EasyPrivacy and AdGuard users - frontend bundles are named gtm4wp-*.js (easylist/easylist#25228)."
			);
		}
	}

	/**
	 * Nothing that can become an admin bundle carries the prefix.
	 *
	 * Recursive on purpose: today only js/admin/index.js is an entry, but a
	 * second admin entry would be added from this tree.
	 */
	public function test_no_admin_source_file_carries_the_prefix(): void {
		foreach ( $this->js_files( 'js/admin', true ) as $file ) {
			$this->assertStringStartsNotWith(
				self::PREFIX,
				$file,
				"js/admin/{$file} would be blocked in wp-admin by the /gtm4wp-\$script filter (easylist/easylist#25228)."
			);
		}
	}

	/**
	 * The PHP side enqueues no prefixed script on an admin screen.
	 *
	 * Reads the literal `build/<file>` arguments in src/Admin/ rather than
	 * booting the settings page, because the claim is about the URL bytes an
	 * admin request produces, and those are the string literals.
	 */
	public function test_admin_php_enqueues_no_prefixed_build_file(): void {
		$dir = GTM4WP_PATH . 'src/Admin';
		$this->assertDirectoryExists( $dir );

		$found    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file in a test.
			$source = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( '#[\'"]build/([^\'"]+)[\'"]#', $source, $matches ) ) {
				foreach ( $matches[1] as $built ) {
					$found[] = $built;
				}
			}
		}

		$this->assertContains( 'admin.js', $found, 'src/Admin/ no longer references build/admin.js - the admin bundle moved, re-pin this test and U125.' );

		foreach ( $found as $built ) {
			$this->assertStringStartsNotWith(
				self::PREFIX,
				basename( $built ),
				"src/Admin/ loads build/{$built} on an admin screen, which the /gtm4wp-\$script filter blocks (easylist/easylist#25228)."
			);
		}
	}
}
