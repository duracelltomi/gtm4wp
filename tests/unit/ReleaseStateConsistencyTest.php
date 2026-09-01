<?php
/**
 * Pins the release-state facts to the files that publish them.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

/**
 * `.claude/RELEASE-STATE.md` is the single source of truth for "what version
 * lives where"; the main plugin file, readme.txt and package.json each publish
 * a copy of some of those facts. Nothing at runtime reads them side by side,
 * so drift between them is silent — and per the upstream review system's UC-1,
 * the header/constant pair drifts first, because one edit is a documentation
 * act and the other a code act. This test is the control that breaks: any of
 * these values moving without its siblings fails the suite.
 *
 * Deliberately plain string extraction, not a markdown/JSON parsing library:
 * the claim under test is about the literal bytes each consumer (WordPress,
 * wordpress.org, npm) reads out of these files.
 */
final class ReleaseStateConsistencyTest extends TestCase {

	/**
	 * Reads a repo file relative to the plugin root, asserting it exists.
	 *
	 * @param string $relative Repo-relative path.
	 * @return string File contents.
	 */
	private function read_file( string $relative ): string {
		$path = GTM4WP_PATH . $relative;
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file in a test.
		return (string) file_get_contents( $path );
	}

	/**
	 * Extracts one value from the Facts table in RELEASE-STATE.md.
	 *
	 * @param string $key Row key, e.g. 'PHP floor'.
	 * @return string The row's value cell, trimmed.
	 */
	private function state_fact( string $key ): string {
		$state = $this->read_file( '.claude/RELEASE-STATE.md' );
		$found = preg_match(
			'/^\| ' . preg_quote( $key, '/' ) . ' \| (.+?) \|$/m',
			$state,
			$matches
		);
		$this->assertSame( 1, $found, "RELEASE-STATE.md Facts table has no '{$key}' row - keep its Key strings stable, this test greps them." );
		return trim( $matches[1] );
	}

	/**
	 * Extracts a "Field: value" line from a plugin header or readme header.
	 *
	 * @param string $content File contents.
	 * @param string $field   Field name before the colon.
	 * @param string $where   File label for the failure message.
	 * @return string The field value, trimmed.
	 */
	private function header_field( string $content, string $field, string $where ): string {
		$found = preg_match(
			'/^[ \t*]*' . preg_quote( $field, '/' ) . ': *(.+?)\r?$/m',
			$content,
			$matches
		);
		$this->assertSame( 1, $found, "No '{$field}:' line found in {$where}." );
		return trim( $matches[1] );
	}

	/**
	 * The same-commit version quartet: plugin header Version, GTM4WP_VERSION,
	 * package.json and both package-lock.json entries always agree.
	 *
	 * @return void
	 */
	public function test_version_quartet_agrees(): void {
		$main = $this->read_file( 'duracelltomi-google-tag-manager-for-wordpress.php' );

		$header_version = $this->header_field( $main, 'Version', 'the plugin header' );

		$this->assertSame(
			1,
			preg_match( "/define\\( 'GTM4WP_VERSION', '([^']+)' \\);/", $main, $constant ),
			'GTM4WP_VERSION define not found in the main plugin file.'
		);
		$this->assertSame( $header_version, $constant[1], 'Plugin header Version and GTM4WP_VERSION differ.' );

		$package = json_decode( $this->read_file( 'package.json' ), true );
		$this->assertSame( $header_version, $package['version'], 'package.json version differs from the plugin header.' );

		$lock = json_decode( $this->read_file( 'package-lock.json' ), true );
		$this->assertSame( $header_version, $lock['version'], 'package-lock.json root version differs from the plugin header.' );
		$this->assertSame( $header_version, $lock['packages']['']['version'], 'package-lock.json packages."" version differs from the plugin header.' );
	}

	/**
	 * The released stable version in RELEASE-STATE.md is the readme Stable tag.
	 *
	 * The plugin header Version may legitimately run ahead of it between
	 * releases (a -dev or pre-release build), so that pair is deliberately
	 * not asserted here.
	 *
	 * @return void
	 */
	public function test_stable_tag_matches_release_state(): void {
		$readme     = $this->read_file( 'readme.txt' );
		$stable_tag = $this->header_field( $readme, 'Stable tag', 'readme.txt' );

		$this->assertSame( $stable_tag, $this->state_fact( 'Released stable version' ), 'RELEASE-STATE.md released stable version differs from the readme.txt Stable tag.' );
		$this->assertSame( $stable_tag, $this->state_fact( 'wordpress.org Stable tag' ), 'RELEASE-STATE.md wordpress.org Stable tag row differs from readme.txt.' );
	}

	/**
	 * The requirement floors and tested-up-to claims in RELEASE-STATE.md match
	 * the plugin header and readme.txt.
	 *
	 * @return void
	 */
	public function test_floors_match_release_state(): void {
		$main   = $this->read_file( 'duracelltomi-google-tag-manager-for-wordpress.php' );
		$readme = $this->read_file( 'readme.txt' );

		$php_floor = $this->state_fact( 'PHP floor' );
		$this->assertSame( $php_floor, $this->header_field( $main, 'Requires PHP', 'the plugin header' ) );
		$this->assertSame( $php_floor, $this->header_field( $readme, 'Requires PHP', 'readme.txt' ) );

		$wp_floor = $this->state_fact( 'WP floor' );
		$this->assertSame( $wp_floor, $this->header_field( $main, 'Requires at least', 'the plugin header' ) );
		$this->assertSame( $wp_floor, $this->header_field( $readme, 'Requires at least', 'readme.txt' ) );

		$this->assertSame(
			$this->state_fact( 'WP tested up to' ),
			$this->header_field( $readme, 'Tested up to', 'readme.txt' )
		);

		$this->assertSame(
			$this->state_fact( 'WC floor' ),
			$this->header_field( $main, 'WC requires at least', 'the plugin header' )
		);
		$this->assertSame(
			$this->state_fact( 'WC tested up to' ),
			$this->header_field( $main, 'WC tested up to', 'the plugin header' )
		);
	}
}
