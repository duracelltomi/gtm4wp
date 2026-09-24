<?php
/**
 * Pins the release zip's top folder to the wordpress.org slug.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

/**
 * WordPress matches a plugin to its wordpress.org updates by the folder it is
 * installed in, so a GitHub release zip whose top folder is not the wp.org slug
 * installs as a plugin that never updates, with no error anywhere (U168). The
 * slug is the text domain (U97), so the two are compared here.
 */
final class ReleaseZipSlugTest extends TestCase {

	/**
	 * Reads tools/build-release.js.
	 *
	 * @return string The script source.
	 */
	private function build_script(): string {
		$file = GTM4WP_PATH . 'tools/build-release.js';
		$this->assertFileExists( $file );
		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file.
	}

	/**
	 * The folder constant is the wp.org slug, i.e. the plugin's text domain.
	 */
	public function test_zip_folder_is_the_wporg_slug(): void {
		$header = (string) file_get_contents( GTM4WP_PATH . 'duracelltomi-google-tag-manager-for-wordpress.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file.
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)\s*$/m', $header, $domain ) );

		$this->assertSame( 1, preg_match( "/^const WPORG_SLUG = '([^']+)';$/m", $this->build_script(), $slug ) );
		$this->assertSame( 'duracelltomi-google-tag-manager', $slug[1] );
		$this->assertSame( $domain[1], $slug[1], 'The release zip folder must be the wp.org slug, which is the text domain.' );
	}

	/**
	 * Both the staging folder and the folder inside the zip use that constant.
	 */
	public function test_staging_and_zip_folder_use_the_slug(): void {
		$script = $this->build_script();

		$this->assertStringContainsString( 'const STAGE_DIR = path.join( RELEASE_DIR, WPORG_SLUG );', $script );
		$this->assertStringContainsString( 'zip.addLocalFolder( STAGE_DIR, WPORG_SLUG );', $script );
		$this->assertStringNotContainsString( 'addLocalFolder( STAGE_DIR, SLUG )', $script );
	}
}
