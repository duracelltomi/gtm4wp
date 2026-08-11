<?php
/**
 * Unit tests for the documentation link builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Admin\Docs;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers Docs::url(), which turns the paths carried by the admin schemas into
 * the absolute help URLs the settings app renders as an href.
 *
 * Two things are worth testing here and one is not. The composition rules are
 * ours (base + path + '#' + anchor, and the empty-path "no link" signal). The
 * validation is WordPress', so the assertion is that we hand it the right
 * protocol allow-list and honour the empty string it answers with - not a
 * re-test of esc_url_raw() through a stub of our own writing.
 */
final class DocsTest extends TestCase {

	private const BASE = 'https://gtm4wp.com/';

	/**
	 * Lets the URL our code built through unchanged, so a composition assertion
	 * reads what Docs::url() produced rather than what WordPress would make of
	 * it.
	 *
	 * Called per test rather than from setUp() (still never relying on another
	 * file's stub - TS-16): a Brain Monkey `when()` alias takes precedence over
	 * a later `expect()` on the same function, so a blanket pass-through here
	 * would quietly disarm the two tests below that assert HOW esc_url_raw() is
	 * called.
	 *
	 * @return void
	 */
	private function pass_through_url_validation(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	public function test_url_prefixes_the_documentation_base(): void {
		$this->pass_through_url_validation();

		$this->assertSame(
			self::BASE . 'setup-gtm4wp-features/how-to-track-contact-form-7-submissions',
			Docs::url( 'setup-gtm4wp-features/how-to-track-contact-form-7-submissions' )
		);
	}

	public function test_url_appends_the_option_key_as_the_fragment(): void {
		$this->pass_through_url_validation();

		$this->assertSame(
			self::BASE . 'google-tag-manager-for-woocommerce/woocommerce-settings-reference#integrate-woocommerce-order-max-age',
			Docs::url(
				'google-tag-manager-for-woocommerce/woocommerce-settings-reference',
				'integrate-woocommerce-order-max-age'
			)
		);
	}

	public function test_url_of_an_empty_path_is_empty(): void {
		$this->pass_through_url_validation();

		$this->assertSame( '', Docs::url( '' ) );
		$this->assertSame(
			'',
			Docs::url( '', 'integrate-woocommerce-order-max-age' ),
			'An option with no page yet gets no link even though it has a key to anchor on.'
		);
	}

	public function test_a_leading_slash_on_the_path_does_not_double_the_separator(): void {
		$this->pass_through_url_validation();

		$this->assertSame(
			self::BASE . 'setup-gtm4wp-features',
			Docs::url( '/setup-gtm4wp-features' )
		);
	}

	public function test_the_fragment_is_url_encoded(): void {
		$this->pass_through_url_validation();

		// No built-in option key needs encoding - every one of them is
		// [a-z0-9-]. A third party module registered through
		// gtm4wp_register_modules can key an option on anything, and a raw
		// space or quote in the fragment would end up in an href.
		$this->assertSame(
			self::BASE . 'their-page#weird%20key%22',
			Docs::url( 'their-page', 'weird key"' )
		);
	}

	public function test_the_filter_can_redirect_a_link_to_another_site(): void {
		$this->pass_through_url_validation();

		Filters\expectApplied( 'gtm4wp_admin_doc_url' )
			->once()
			->with(
				self::BASE . 'their-page#their-option',
				'their-page',
				'their-option'
			)
			->andReturn( 'https://example.com/docs/their-option' );

		$this->assertSame(
			'https://example.com/docs/their-option',
			Docs::url( 'their-page', 'their-option' )
		);
	}

	public function test_the_url_is_validated_against_an_http_only_protocol_list(): void {
		// The one assertion that matters for the sink: the filtered value ends
		// up in a React href, so the allow-list our code passes has to be the
		// http/https pair. Asserted on the argument rather than on the outcome
		// of a locally written esc_url_raw(), which would only test the double.
		Functions\expect( 'esc_url_raw' )
			->once()
			->with( self::BASE . 'a-page', array( 'http', 'https' ) )
			->andReturnFirstArg();

		Docs::url( 'a-page' );
	}

	public function test_a_url_wordpress_rejects_becomes_no_link_at_all(): void {
		// esc_url_raw() answers '' for a scheme outside the list, which has to
		// reach the app as the same "render nothing" signal an empty path
		// produces - never as an href of ''.
		Functions\expect( 'esc_url_raw' )->once()->andReturn( '' );

		Filters\expectApplied( 'gtm4wp_admin_doc_url' )
			->once()
			->andReturn( 'javascript:alert(1)' ); // phpcs:ignore Squiz.PHP.CommentedOutCode.Found

		$this->assertSame( '', Docs::url( 'a-page' ) );
	}
}
