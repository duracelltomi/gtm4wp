<?php
/**
 * Unit tests for the unreadable-key admin notice.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Modules\GoogleAuth\KeyNotice;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The notice is the only place a key that stopped decrypting is reported
 * outside the plugin's own screen, and account labels are admin free text
 * (RI-6) printed into admin HTML - so both directions of the escape are
 * asserted on a hostile label (TS-2), and the silent state is asserted as
 * exactly no output rather than "no error".
 */
final class GoogleAuthKeyNoticeTest extends TestCase {

	use OptionStoreTrait;

	private const SECRET = 'unit-test-site-secret-do-not-reuse';

	private const SETTINGS_URL = 'https://example.com/wp-admin/admin.php?page=gtm4wp-settings';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $number ) => ( 1 === $number ) ? $single : $plural );
		Functions\when( 'menu_page_url' )->justReturn( self::SETTINGS_URL );
		// Notices render inside wp-admin, after the menu was registered (#282).
		do_action( 'admin_menu' );
		// The vault sanitizes labels on write; the stand-in strips tags the way
		// sanitize_text_field() does, so the notice's own escape is what the
		// assertions below exercise, not the write-time cleanup.
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$this->stub_option_store();
	}

	/**
	 * Stores an account under one secret and marks it unreadable by opening it
	 * under another - the salt-rotation scenario the notice exists for.
	 *
	 * @param string $label Label.
	 * @return string The account id.
	 */
	private function store_unreadable( string $label ): string {
		$id = ( new KeyVault( self::SECRET ) )->add( KeyFileFixture::parse(), $label );
		$this->assertIsString( $id );

		$rotated = new KeyVault( 'a-different-secret-after-salt-rotation' );
		$this->assertInstanceOf( \WP_Error::class, $rotated->open( $id ) );

		return $id;
	}

	/**
	 * Renders the notice with the vault as it is stored now.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		( new KeyNotice( new KeyVault( self::SECRET ) ) )->show_notice();

		return (string) ob_get_clean();
	}

	public function test_registers_on_admin_notices(): void {
		$vault  = new KeyVault( self::SECRET );
		$notice = new KeyNotice( $vault );

		$notice->register_hooks();

		$this->assertNotFalse( has_action( 'admin_notices', array( $notice, 'show_notice' ) ) );
	}

	public function test_prints_nothing_when_no_account_is_stored(): void {
		$this->assertSame( '', $this->render() );
	}

	public function test_prints_nothing_while_every_stored_key_still_decrypts(): void {
		( new KeyVault( self::SECRET ) )->add( KeyFileFixture::parse(), 'Production' );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * The link carries the settings app's `#google-auth` bookmark: the app
	 * opens the module named by the fragment, so the admin lands on the
	 * service-accounts panel instead of on the first module of the page.
	 */
	public function test_names_the_one_unreadable_account_and_links_the_service_accounts_panel(): void {
		$this->store_unreadable( 'Production' );

		$html = $this->render();

		$this->assertStringStartsWith( '<div class="gtm4wp-notice notice notice-error"', $html );
		$this->assertStringContainsString( 'The stored key of the Google service account Production can no longer be decrypted', $html );
		$this->assertStringContainsString( '<a href="' . self::SETTINGS_URL . '#google-auth">upload its key file again</a>', $html );
		$this->assertStringEndsWith( '</strong></p></div>', $html );
	}

	public function test_lists_every_unreadable_account_in_the_plural_form(): void {
		$this->store_unreadable( 'Production' );
		$this->store_unreadable( 'Staging' );

		$html = $this->render();

		$this->assertStringContainsString( 'The stored keys of the Google service accounts Production, Staging can no longer be decrypted', $html );
		$this->assertStringContainsString( 'upload their key files again', $html );
	}

	public function test_only_the_unreadable_accounts_are_named(): void {
		$this->store_unreadable( 'Old' );
		( new KeyVault( self::SECRET ) )->add( KeyFileFixture::parse(), 'Fresh' );

		$html = $this->render();

		$this->assertStringContainsString( 'service account Old can', $html );
		$this->assertStringNotContainsString( 'Fresh', $html );
	}

	/**
	 * A label is admin free text. The vault strips tags on write, so the
	 * hostile part that survives is the quote and ampersand; the notice must
	 * still escape those itself (TS-2, both directions).
	 */
	public function test_a_hostile_label_is_escaped_in_the_notice(): void {
		$this->store_unreadable( 'Tom & Jerry "prod"' );

		$html = $this->render();

		$this->assertStringContainsString( 'Tom &amp; Jerry &quot;prod&quot;', $html );
		$this->assertStringNotContainsString( 'Tom & Jerry "prod"', $html );
	}

	public function test_the_notice_carries_no_key_material(): void {
		$this->store_unreadable( 'Production' );

		$html = $this->render();

		$this->assertStringNotContainsString( 'BEGIN', $html );
		foreach ( $this->options[ KeyVault::OPTION_NAME ] as $row ) {
			$this->assertStringNotContainsString( $row['key']['ciphertext'], $html );
		}
	}
}
