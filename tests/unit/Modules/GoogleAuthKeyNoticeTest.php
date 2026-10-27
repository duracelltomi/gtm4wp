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

		$rotated = new KeyVault( self::ROTATED );
		$this->assertInstanceOf( \WP_Error::class, $rotated->open( $id ) );

		return $id;
	}

	/**
	 * The site secret after the rotation store_unreadable() simulates.
	 */
	private const ROTATED = 'a-different-secret-after-salt-rotation';

	/**
	 * Renders the notice with the vault as it is stored now, read with the
	 * given site secret (readability is found by trying the key, #506).
	 *
	 * @param string $secret         The site secret the notice's vault reads with.
	 * @param string $encryption_key Stands in for GTM4WP_ENCRYPTION_KEY; '' is none.
	 * @return string
	 */
	private function render( string $secret = self::ROTATED, string $encryption_key = '' ): string {
		ob_start();
		( new KeyNotice( new KeyVault( $secret, null, $encryption_key ) ) )->show_notice();

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
		( new KeyVault( self::ROTATED ) )->add( KeyFileFixture::parse(), 'Production' );

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
		$this->assertStringContainsString( 'usually because the security keys in wp-config.php changed', $html );
		$this->assertStringContainsString( 'Use <a href="' . self::SETTINGS_URL . '#google-auth">Replace key</a> on the account with its key file.', $html );
		$this->assertStringEndsWith( '</strong></p></div>', $html );
	}

	public function test_lists_every_unreadable_account_in_the_plural_form(): void {
		$this->store_unreadable( 'Production' );
		$this->store_unreadable( 'Staging' );

		$html = $this->render();

		$this->assertStringContainsString( 'The stored keys of the Google service accounts Production, Staging can no longer be decrypted', $html );
		$this->assertStringContainsString( 'Replace key</a> on each account', $html );
	}

	public function test_only_the_unreadable_accounts_are_named(): void {
		$this->store_unreadable( 'Old' );
		( new KeyVault( self::ROTATED ) )->add( KeyFileFixture::parse(), 'Fresh' );

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

	// ---- GTM4WP_ENCRYPTION_KEY (#469) ----------------------------------------

	private const CONSTANT = 'a-gtm4wp-encryption-key-of-at-least-32-chars';

	public function test_a_key_sealed_with_a_removed_constant_names_the_constant(): void {
		( new KeyVault( self::SECRET, null, self::CONSTANT ) )->add( KeyFileFixture::parse(), 'Production' );

		$html = $this->render( self::SECRET );

		$this->assertStringContainsString( 'because GTM4WP_ENCRYPTION_KEY is no longer defined in wp-config.php, or is ignored. Restore it, or use', $html );
		$this->assertStringNotContainsString( 'usually because the security keys', $html );
		$this->assertSame( '', $this->render( self::ROTATED, self::CONSTANT ), 'Restoring the constant clears it, whatever the salts are.' );
	}

	public function test_each_cause_gets_its_own_sentence(): void {
		( new KeyVault( self::SECRET, null, self::CONSTANT ) )->add( KeyFileFixture::parse(), 'Constant' );
		( new KeyVault( self::SECRET ) )->add( KeyFileFixture::parse(), 'Salts' );

		$html = $this->render( self::ROTATED );

		$this->assertStringContainsString( 'service account Constant can no longer be decrypted because GTM4WP_ENCRYPTION_KEY is no longer defined', $html );
		$this->assertStringContainsString( 'service account Salts can no longer be decrypted, usually because the security keys', $html );

		$changed = $this->render( self::SECRET, 'another-gtm4wp-encryption-key-of-32-chars' );
		$this->assertStringContainsString( 'service account Constant can no longer be decrypted because the value of GTM4WP_ENCRYPTION_KEY in wp-config.php changed', $changed );
		$this->assertStringNotContainsString( 'Salts', $changed, 'The salts are unchanged here, so that key still opens.' );
	}

	/**
	 * #562: a salts-sealed key that went unreadable before it moved to the
	 * constant gets no advice to define the constant once it is defined.
	 * #602: with the constant refused no key ever moves, and Replace key alone
	 * seals under the salts again, so the constant is fixed first.
	 */
	public function test_the_salts_remedy_does_not_advise_a_constant_already_defined(): void {
		$this->store_unreadable( 'Production' );

		$this->assertStringContainsString( 'defining GTM4WP_ENCRYPTION_KEY prevents this', $this->render(), 'Undefined: the advice stands.' );

		$in_use = $this->render( self::ROTATED, self::CONSTANT );
		$this->assertStringContainsString( 'service account Production can no longer be decrypted, usually because the security keys in wp-config.php changed before it moved to the encryption key.', $in_use );
		$this->assertStringNotContainsString( 'prevents this', $in_use );

		$refused = $this->render( self::ROTATED, 'too-short' );
		$this->assertStringContainsString( 'service account Production can no longer be decrypted, usually because the security keys in wp-config.php changed. First correct GTM4WP_ENCRYPTION_KEY in wp-config.php as the notice above says, then use <a href=', $refused );
		$this->assertStringContainsString( '>Replace key</a> on the account with its key file.', $refused );
		$this->assertStringNotContainsString( 'moved to the encryption key', $refused, 'With the constant refused, no key ever moves.' );
		$this->assertStringNotContainsString( 'prevents this', $refused );
	}

	public function test_a_refused_constant_is_named_even_without_an_account(): void {
		$html = $this->render( self::SECRET, 'too-short' );

		$this->assertStringStartsWith( '<div class="gtm4wp-notice notice notice-error" data-href="?google-encryption-key-rejected">', $html );
		$this->assertStringContainsString( 'GTM4WP_ENCRYPTION_KEY in wp-config.php is ignored: it must be a text of at least 32 characters.', $html );
		$this->assertSame( '', $this->render( self::SECRET, self::CONSTANT ), 'A constant in use prints nothing.' );
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
