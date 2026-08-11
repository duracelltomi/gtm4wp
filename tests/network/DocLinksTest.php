<?php
/**
 * Network probe for the gtm4wp.com documentation links.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\network;

use Brain\Monkey\Functions;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\Registry;
use GTM4WP\Tests\unit\TestCase;

/**
 * Checks that every documentation target the admin schemas point at actually
 * exists on gtm4wp.com, and that each option's `<a name="<option key>">` anchor
 * is on the page it points at.
 *
 * This is the probe behind upstream registry row U107. It is NOT part of the
 * `unit` suite and never runs in CI: the phpunit.xml testsuite covers
 * ./tests/unit/ only, and every test here also skips unless
 * GTM4WP_CHECK_DOC_LINKS=1 is set, so a checkout with no network is unaffected.
 *
 *     GTM4WP_CHECK_DOC_LINKS=1 vendor/bin/phpunit tests/network/DocLinksTest.php
 *
 * Why it has to be a network test at all: a help link that 404s produces no PHP
 * error, no failing unit test, and no report from the user who gave up and
 * closed the tab. The ModuleConsistencyTest guard pins that every option NAMES a
 * target; only this one can say whether the target is there.
 *
 * A redirect counts as a failure, not a pass. The two links this feature
 * replaced had been pointing at a retired path for as long as anybody can tell,
 * and the only reason nobody noticed is that WordPress kept redirecting them -
 * "it evidently works" is not evidence (UD-11).
 */
final class DocLinksTest extends TestCase {

	private const BASE = 'https://gtm4wp.com/';

	/**
	 * Cached page bodies, keyed by path, so a page shared by 23 options is
	 * fetched once.
	 *
	 * @var array<string, string|null>
	 */
	private static array $bodies = array();

	/**
	 * Final response codes, keyed by path.
	 *
	 * @var array<string, int>
	 */
	private static array $codes = array();

	protected function setUp(): void {
		if ( '1' !== getenv( 'GTM4WP_CHECK_DOC_LINKS' ) ) {
			$this->markTestSkipped( 'Set GTM4WP_CHECK_DOC_LINKS=1 to probe gtm4wp.com.' );
		}

		parent::setUp();

		// The schemas are built for their paths only, but building one runs its
		// labels through i18n/escaping and the WooCommerce/Container ones reach
		// for WP and WC helpers. Stubbed here, never inherited (TS-16).
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names(): array {
					return array();
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();
	}

	/**
	 * Every documentation target of the built-in modules.
	 *
	 * Read out of the schemas rather than scraped out of the PHP with a regex:
	 * the paths are assembled from class constants, so only the schema itself
	 * can say what a field's path resolves to.
	 *
	 * @return array<string, array{path: string, anchor: string, what: string}>
	 */
	private function targets(): array {
		$targets = array();

		foreach ( Registry::with_default_modules()->all() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$schema       = new $schema_class();

			if ( $schema instanceof DocumentedSchemaInterface && '' !== $schema->doc_url() ) {
				$targets[ 'module:' . $module_id ] = array(
					'path'   => $schema->doc_url(),
					'anchor' => '',
					'what'   => "module '{$module_id}'",
				);
			}

			foreach ( $schema->fields() as $field ) {
				if ( '' === $field->doc ) {
					continue;
				}

				$targets[ 'option:' . $field->key ] = array(
					'path'   => $field->doc,
					'anchor' => $field->key,
					'what'   => "option '{$field->key}'",
				);
			}
		}

		return $targets;
	}

	/**
	 * Fetches a documentation page once, recording its FINAL status code and
	 * whether it was redirected on the way.
	 *
	 * @param string $path Path relative to self::BASE.
	 * @return string|null Response body, or null when the request itself failed.
	 */
	private function body( string $path ): ?string {
		if ( array_key_exists( $path, self::$bodies ) ) {
			return self::$bodies[ $path ];
		}

		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'follow_location' => 0,
					'ignore_errors'   => true,
					'timeout'         => 20,
					'header'          => "User-Agent: GTM4WP doc link check\r\n",
				),
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- test-only probe; wp_remote_get() needs a loaded WordPress, and a connection failure is a result this probe REPORTS (as a null body, counted as fetch-failed) rather than an error to raise. Without the @ the PHP warning would be the only output and the run would say nothing about which paths were reached.
		$body    = @file_get_contents( self::BASE . ltrim( $path, '/' ), false, $context );
		$headers = $http_response_header ?? array();

		$code = 0;
		if ( isset( $headers[0] ) && 1 === preg_match( '#\s(\d{3})\s#', $headers[0], $matches ) ) {
			$code = (int) $matches[1];
		}

		self::$codes[ $path ]  = $code;
		self::$bodies[ $path ] = ( false === $body ) ? null : $body;

		return self::$bodies[ $path ];
	}

	public function test_every_documentation_page_resolves_without_a_redirect(): void {
		$broken = array();

		foreach ( $this->targets() as $target ) {
			$this->body( $target['path'] );
			$code = self::$codes[ $target['path'] ] ?? 0;

			if ( 200 !== $code ) {
				// Keyed by path so a page shared by many options is reported
				// once rather than 23 times.
				$broken[ $target['path'] ] = $code;
			}
		}

		$this->assertSame(
			array(),
			$broken,
			"Documentation pages that did not answer 200 (path => status; 3xx means the path moved and the link still points at the old one):\n"
				. print_r( $broken, true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		);
	}

	public function test_every_option_anchor_exists_on_its_page(): void {
		$missing = array();

		foreach ( $this->targets() as $target ) {
			if ( '' === $target['anchor'] ) {
				continue;
			}

			$body = $this->body( $target['path'] );

			if ( null === $body ) {
				// Reported by the sibling test; not silently counted as a pass
				// here either (a fetch-failed is neither verified nor drifted).
				$missing[ $target['what'] ] = $target['path'] . ' (page could not be fetched)';
				continue;
			}

			$anchor = preg_quote( $target['anchor'], '#' );

			// Either form works in a browser, and the page editor may produce
			// whichever; the block editor writes <a name>, a heading anchor
			// writes id.
			if ( 1 !== preg_match( '#(?:name|id)=[\'"]' . $anchor . '[\'"]#', $body ) ) {
				$missing[ $target['what'] ] = $target['path'];
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Options whose anchor is not on the page they link to (the link still opens the page, at the top):\n"
				. print_r( $missing, true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		);
	}
}
