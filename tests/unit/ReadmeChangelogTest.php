<?php
/**
 * Pins the shape of the readme.txt changelog wordpress.org shows.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit;

/**
 * The wordpress.org directory truncates a readme changelog after 5,000 words and emails
 * only the committers afterwards (U150), so older sections get trimmed to a link.
 * Whatever a trim removes, the section must keep opening with the link to the
 * full changelog on gtm4wp.com.
 */
final class ReadmeChangelogTest extends TestCase {

	/**
	 * Returns the `== Changelog ==` section of readme.txt, up to the next section.
	 *
	 * @return string Section text without its heading.
	 */
	private function changelog_section(): string {
		$path = GTM4WP_PATH . 'readme.txt';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file in a test.
		$readme = str_replace( "\r\n", "\n", (string) file_get_contents( $path ) );

		$this->assertSame( 1, preg_match( '/^== Changelog ==\n(.*?)^== /ms', $readme, $matches ), 'readme.txt has no == Changelog == section followed by another section.' );
		return $matches[1];
	}

	public function test_the_changelog_opens_with_the_full_changelog_link(): void {
		$first_paragraph = strtok( ltrim( $this->changelog_section() ), "\n" );

		$this->assertStringContainsString( 'https://gtm4wp.com/changelog', (string) $first_paragraph );
		$this->assertStringStartsNotWith( '=', (string) $first_paragraph, 'The link must come before the first version heading.' );
	}

	public function test_the_changelog_stays_under_the_wordpress_org_word_cap(): void {
		$words = preg_split( '/\s+/', trim( $this->changelog_section() ) );

		$this->assertLessThan( 5000, count( $words ), 'wordpress.org truncates the changelog at 5,000 words: summarize the oldest section and link the full changelog.' );
	}
}
