<?php
/**
 * Unit tests for the Site Health surfaces of the Google service accounts module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Modules\GoogleAuth\SiteHealth;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The keys test reports the one failure of this module that is silent
 * everywhere but the admin notice: a stored key that stopped decrypting. The
 * rows are pasted into public threads, so what is NOT in them is the property
 * pinned: no account address (an account uploaded without a label is STORED
 * under its address, which is the case that used to leak), no key id, no key
 * material in any form.
 */
final class GoogleAuthSiteHealthTest extends TestCase {

	use OptionStoreTrait;

	private const NOW    = 1_800_000_000;
	private const SECRET = 'unit-test-site-secret-do-not-reuse';

	private KeyVault $vault;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'menu_page_url' )->alias( static fn ( $slug, $display = true ) => 'https://example.com/wp-admin/options-general.php?page=' . $slug );

		$this->stub_option_store();

		$this->vault = new KeyVault( self::SECRET, static fn () => self::NOW );
	}

	/**
	 * The rows as one flat string, for the "nowhere in here" assertions.
	 *
	 * @return string
	 */
	private function rows_text(): string {
		return (string) json_encode( ( new SiteHealth( $this->vault ) )->debug_fields() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for a substring assertion.
	}

	/**
	 * Stores an account and makes its key undecryptable the way a rotated
	 * wp-config salt does: the row survives, the first use flags it.
	 *
	 * @return string The account id.
	 */
	private function store_an_unreadable_key(): string {
		$id = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $id );

		$accounts = $this->options[ KeyVault::OPTION_NAME ];
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the stored blob really is base64; this fabricates one that will not decrypt.
		$accounts[ $id ]['key']['ciphertext'] = base64_encode( 'not-the-key-any-more' );

		$this->options[ KeyVault::OPTION_NAME ] = $accounts;

		$this->assertInstanceOf( \WP_Error::class, $this->vault->open( $id ), 'Opening the key is what flags the account.' );

		return $id;
	}

	// ---- The keys test -----------------------------------------------------

	public function test_no_stored_account_is_good(): void {
		$result = ( new SiteHealth( $this->vault ) )->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( '', $result['actions'] );
		$this->assertArrayNotHasKey( 'test', $result, 'The id and the badge are the collector\'s.' );
	}

	public function test_a_readable_key_is_good(): void {
		$this->vault->add( KeyFileFixture::parse(), 'Production' );

		$this->assertSame( 'good', ( new SiteHealth( $this->vault ) )->run_test()['status'] );
	}

	public function test_an_unreadable_key_is_critical_and_links_to_the_accounts(): void {
		$this->store_an_unreadable_key();

		$result = ( new SiteHealth( $this->vault ) )->run_test();

		$this->assertSame( 'critical', $result['status'], 'Every feature on that key has stopped reaching Google - not an improvement waiting to be made.' );
		$this->assertStringContainsString( 'no longer be read', $result['label'] );
		$this->assertStringContainsString( '#google-auth', $result['actions'], 'The settings app opens the panel from its bookmark.' );
	}

	// ---- The rows: what they say -------------------------------------------

	public function test_no_stored_account_is_one_none_row(): void {
		$fields = ( new SiteHealth( $this->vault ) )->debug_fields();

		$this->assertSame( array( 'accounts' ), array_keys( $fields ) );
		$this->assertSame( 'none', $fields['accounts']['debug'] );
	}

	public function test_an_account_is_named_by_its_label_and_status(): void {
		$this->vault->add( KeyFileFixture::parse(), 'Production' );

		$fields = ( new SiteHealth( $this->vault ) )->debug_fields();

		$this->assertSame( 'Production: unverified', $fields['account_0']['value'] );
		$this->assertSame( 'Production: unverified', $fields['account_0']['debug'] );
	}

	public function test_an_unreadable_account_says_so_in_its_row(): void {
		$this->store_an_unreadable_key();

		$this->assertStringContainsString( KeyVault::STATUS_REUPLOAD, ( new SiteHealth( $this->vault ) )->debug_fields()['account_0']['value'] );
	}

	// ---- The rows: what they must never say --------------------------------

	public function test_an_account_uploaded_without_a_label_is_named_by_its_id_never_its_address(): void {
		$id = $this->vault->add( KeyFileFixture::parse(), '' );

		$text = $this->rows_text();

		$this->assertStringContainsString( $id, $text, 'The id is what a destination row and the abilities name the account by.' );
		$this->assertStringNotContainsString( KeyFileFixture::CLIENT_EMAIL, $text, 'The vault stores the address as the label of an unlabelled account.' );
	}

	public function test_a_label_that_is_an_address_is_treated_the_same(): void {
		$account = array(
			'id'    => 'sa_aaaaaaaaaaaa',
			'label' => 'billing@example-project.iam.gserviceaccount.com',
		);

		$this->assertSame( 'sa_aaaaaaaaaaaa', KeyVault::safe_label( $account ) );
		$this->assertSame( 'Production', KeyVault::safe_label( array( 'label' => 'Production' ) ) );
	}

	public function test_the_rows_carry_no_address_and_no_key_material_in_any_form(): void {
		$this->vault->add( KeyFileFixture::parse(), 'Production' );

		$text = $this->rows_text();

		$this->assertStringContainsString( 'Production', $text );
		$this->assertStringNotContainsString( KeyFileFixture::CLIENT_EMAIL, $text );
		$this->assertStringNotContainsString( KeyFileFixture::PRIVATE_KEY_ID, $text );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $text );

		$stored = $this->options[ KeyVault::OPTION_NAME ];
		$blob   = reset( $stored )['key']['ciphertext'];
		$this->assertStringNotContainsString( $blob, $text, 'Not even the encrypted blob.' );
	}
}
