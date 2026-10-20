<?php
/**
 * Unit tests for the encrypted service-account key store.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\ServiceAccountKey;
use GTM4WP\Google\TokenService;
use GTM4WP\Tests\unit\TestCase;

/**
 * The vault holds the one secret the plugin ever stores. Three properties are
 * pinned here, each from both directions (TS-2):
 *
 * - Custody: the private key never leaves the class except through open(),
 *   and the row it is written to carries ciphertext only. all()/get() are
 *   allow-lists, asserted by exact key set so a field added to the stored
 *   shape later cannot slip into a listing by default.
 * - Integrity: a tampered blob, a blob moved to another account, or a changed
 *   site secret (rotated salts) fails CLOSED - a WP_Error plus the
 *   "re-upload required" status persisted for the notice - never a fatal and
 *   never junk handed to the signer.
 * - Storage: the row is created non-autoloaded (add_option with autoload
 *   false) and updated without touching that flag. Only the recorded autoload
 *   argument can prove it, so the option stubs record it (UC-3).
 *
 * A fixed secret is injected; wp_salt() is never reached in this suite, so
 * the derivation from the WordPress salts is a one-line property of
 * storage_key() covered by reading the source, not by a test (BE-3).
 *
 * NOTE (untestable branches): the no-OpenSSL bail in seal()/unseal() and the
 * openssl_encrypt()-returns-false leg cannot be exercised in-process -
 * patchwork.json redefines no openssl internals, and function_exists() on a
 * loaded extension cannot be made false (the TS-16 documented-limitation
 * class; same note in ServiceAccountKeyTest for its no-OpenSSL bail). Both
 * legs fail closed to a WP_Error by reading the source.
 */
final class KeyVaultTest extends TestCase {

	use OptionStoreTrait;

	private const SECRET = 'unit-test-site-secret-do-not-reuse';

	/**
	 * The fixed clock every vault under test runs on.
	 */
	private const NOW = 1_800_000_000;

	/**
	 * Names passed to delete_transient() - the vault purges cached tokens on
	 * delete (#226), which is only observable through these calls.
	 *
	 * @var string[]
	 */
	private array $deleted_transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->deleted_transients = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				$this->deleted_transients[] = $name;
				return true;
			}
		);

		Functions\stubTranslationFunctions();
		// Modelled on core, not returnArg(): sanitize_text_field() strips tags
		// and line breaks, which the label/last-error caps below depend on.
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => self::sanitize_text_field_stand_in( (string) $value ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$this->stub_option_store();
	}

	/**
	 * A vault over the in-memory store with the fixed secret and a fixed
	 * clock, so stored timestamps are exact-assertable.
	 *
	 * @param string $secret Input keying material.
	 * @return KeyVault
	 */
	private function make_vault( string $secret = self::SECRET ): KeyVault {
		return new KeyVault( $secret, static fn () => self::NOW );
	}

	/**
	 * The parsed fixture key.
	 *
	 * @return ServiceAccountKey
	 */
	private function fixture_key(): ServiceAccountKey {
		$key = ServiceAccountKey::from_json( KeyFileFixture::key_file() );
		$this->assertInstanceOf( ServiceAccountKey::class, $key );

		return $key;
	}

	/**
	 * Adds the fixture key and returns its id.
	 *
	 * @param KeyVault $vault The vault.
	 * @param string   $label Label.
	 * @return string
	 */
	private function add_fixture( KeyVault $vault, string $label = 'Production' ): string {
		$id = $vault->add( $this->fixture_key(), $label );
		$this->assertIsString( $id );

		return $id;
	}

	/**
	 * The raw stored row of one account.
	 *
	 * @param string $id Account id.
	 * @return array<string, mixed>
	 */
	private function stored_row( string $id ): array {
		return $this->options[ KeyVault::OPTION_NAME ][ $id ];
	}

	// ---- Storage -----------------------------------------------------------

	public function test_add_writes_the_row_with_the_key_encrypted_and_never_in_plaintext(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		$this->assertMatchesRegularExpression( '/^' . KeyVault::ID_PATTERN . '$/', $id );

		$row = $this->stored_row( $id );

		$this->assertSame( array( 'iv', 'tag', 'ciphertext', 'secret' ), array_keys( $row['key'] ), 'The key is stored as an authenticated-encryption blob.' );

		// Absent: the PEM and any fragment of it, in the row as a whole.
		$serialized = serialize( $this->options[ KeyVault::OPTION_NAME ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening the stored row to search it.
		$this->assertStringNotContainsString( 'BEGIN', $serialized );
		$this->assertStringNotContainsString( KeyFileFixture::rsa_pem(), $serialized );
		// The base64 of the PEM is what a "store it encoded" mistake would look like.
		$this->assertStringNotContainsString( base64_encode( KeyFileFixture::rsa_pem() ), $serialized ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building the negative fixture.

		$this->assertSame( 'Production', $row['label'] );
		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $row['client_email'] );
		$this->assertSame( KeyFileFixture::PRIVATE_KEY_ID, $row['private_key_id'] );
		$this->assertSame( KeyVault::STATUS_UNVERIFIED, $row['status'] );
	}

	public function test_the_row_is_created_non_autoloaded_and_updated_without_changing_that(): void {
		$vault = $this->make_vault();

		$this->add_fixture( $vault, 'First' );
		$this->add_fixture( $vault, 'Second' );

		$this->assertSame(
			array(
				array(
					'fn'       => 'add_option',
					'key'      => KeyVault::OPTION_NAME,
					'autoload' => false,
				),
				array(
					'fn'       => 'update_option',
					'key'      => KeyVault::OPTION_NAME,
					'autoload' => false,
				),
			),
			$this->option_writes,
			'First write creates the row with autoload off (only add_option() can set it); later writes update.'
		);
		$this->assertCount( 2, $this->options[ KeyVault::OPTION_NAME ], 'The second add is an update of the same row, not a second row.' );
	}

	/**
	 * A refused database write must surface as an error, not as a 201 with a
	 * phantom account the next read cannot find (#228). Covered on both write()
	 * branches. The "update_option returns false for an unchanged value" case
	 * cannot occur on the add path - every call stores a fresh random id and a
	 * fresh IV - so false here genuinely means the store refused the write.
	 */
	public function test_add_reports_a_failed_first_write_instead_of_a_phantom_id(): void {
		Functions\when( 'add_option' )->justReturn( false );

		$result = $this->make_vault()->add( $this->fixture_key(), 'Production' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_store_failed', $result->get_error_code() );
	}

	public function test_add_reports_a_failed_update_write_instead_of_a_phantom_id(): void {
		$vault = $this->make_vault();
		$kept  = $this->add_fixture( $vault, 'Kept' );

		Functions\when( 'update_option' )->justReturn( false );
		$result = $vault->add( $this->fixture_key(), 'Refused' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_store_failed', $result->get_error_code() );
		$this->assertNotNull( $vault->get( $kept ), 'The account stored before the failure is untouched.' );
	}

	public function test_a_vault_row_that_is_not_an_array_is_treated_as_empty(): void {
		$this->stub_option_store( array( KeyVault::OPTION_NAME => 'corrupted' ) );

		$vault = $this->make_vault();

		$this->assertSame( array(), $vault->all() );
		$this->assertFalse( $vault->has( 'sa_000000000000' ) );
		$this->assertSame( array(), $vault->unreadable() );
	}

	// ---- Custody -----------------------------------------------------------

	public function test_listings_expose_exactly_the_public_fields_and_no_key_material(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		$expected_keys = array( 'id', 'label', 'client_email', 'private_key_id', 'uploaded_at', 'status', 'last_checked', 'last_error' );

		$listed = $vault->all();
		$this->assertCount( 1, $listed );
		$this->assertSame( $expected_keys, array_keys( $listed[0] ), 'all() is an allow-list: exactly these fields, in this order.' );
		$this->assertSame( $expected_keys, array_keys( $vault->get( $id ) ), 'get() is the same allow-list.' );

		$this->assertSame( $id, $listed[0]['id'] );
		$this->assertSame( KeyVault::STATUS_UNVERIFIED, $listed[0]['status'] );
		$this->assertSame( self::NOW, $listed[0]['uploaded_at'] );

		// Neither the PEM nor the ciphertext of it: a blob an admin can copy out
		// is a blob that ends up in a support thread.
		$flat = serialize( $listed ) . serialize( $vault->get( $id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening to search.
		$this->assertStringNotContainsString( 'BEGIN', $flat );
		$this->assertStringNotContainsString( $this->stored_row( $id )['key']['ciphertext'], $flat );
		$this->assertStringNotContainsString( 'token_uri', $flat, 'Even the endpoint is not listed; it is the constant.' );
	}

	public function test_get_returns_null_for_an_unknown_id(): void {
		$vault = $this->make_vault();
		$this->add_fixture( $vault );

		$this->assertNull( $vault->get( 'sa_ffffffffffff' ) );
		$this->assertFalse( $vault->has( 'sa_ffffffffffff' ) );
	}

	public function test_open_round_trips_the_key_and_re_validates_it(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		// A second instance: nothing may depend on state cached in the object
		// that did the sealing.
		$opened = $this->make_vault()->open( $id );

		$this->assertInstanceOf( ServiceAccountKey::class, $opened );
		$this->assertSame( KeyFileFixture::rsa_pem(), $opened->private_key );
		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $opened->client_email );
		$this->assertSame( KeyFileFixture::PRIVATE_KEY_ID, $opened->private_key_id );
		$this->assertSame( KeyVault::STATUS_UNVERIFIED, $vault->get( $id )['status'], 'Opening is not a verification; the status is unchanged.' );
	}

	public function test_open_of_an_unknown_id_is_an_error(): void {
		$result = $this->make_vault()->open( 'sa_ffffffffffff' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
	}

	// ---- Integrity ---------------------------------------------------------

	/**
	 * Ways a stored blob stops authenticating. Each mutator receives the stored
	 * row and returns the modified row; the vault it is opened with may use
	 * another secret.
	 *
	 * @return array<string, array{0: callable, 1: string}>
	 */
	public static function unreadable_blobs(): array {
		$flip_last_byte = static function ( string $b64 ): string {
			$raw                       = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test mutation.
			$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0x01 );
			return base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test mutation.
		};

		return array(
			'rotated site secret'    => array(
				static fn ( array $row ) => $row,
				'a-different-secret-after-salt-rotation',
			),
			'tampered ciphertext'    => array(
				static function ( array $row ) use ( $flip_last_byte ) {
					$row['key']['ciphertext'] = $flip_last_byte( $row['key']['ciphertext'] );
					return $row;
				},
				self::SECRET,
			),
			'tampered tag'           => array(
				static function ( array $row ) use ( $flip_last_byte ) {
					$row['key']['tag'] = $flip_last_byte( $row['key']['tag'] );
					return $row;
				},
				self::SECRET,
			),
			'iv of the wrong length' => array(
				static function ( array $row ) {
					$row['key']['iv'] = base64_encode( 'short' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test mutation.
					return $row;
				},
				self::SECRET,
			),
			'not base64'             => array(
				static function ( array $row ) {
					$row['key']['ciphertext'] = '!!!not base64!!!';
					return $row;
				},
				self::SECRET,
			),
			'blob missing'           => array(
				static function ( array $row ) {
					unset( $row['key'] );
					return $row;
				},
				self::SECRET,
			),
			'blob is a string'       => array(
				static function ( array $row ) {
					$row['key'] = 'plaintext-that-was-never-sealed';
					return $row;
				},
				self::SECRET,
			),
		);
	}

	/**
	 * A blob that fails authentication is refused and the account flagged.
	 *
	 * @param callable $mutate Modifies the stored row.
	 * @param string   $secret Secret of the vault that opens it.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unreadable_blobs' )]
	public function test_a_blob_that_does_not_authenticate_fails_closed_and_flags_the_account( callable $mutate, string $secret ): void {
		$id = $this->add_fixture( $this->make_vault() );

		$this->options[ KeyVault::OPTION_NAME ][ $id ] = $mutate( $this->options[ KeyVault::OPTION_NAME ][ $id ] );

		$vault  = $this->make_vault( $secret );
		$result = $vault->open( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_unreadable', $result->get_error_code() );

		// The status is persisted (not just returned) so the admin notice, which
		// runs on a later request, can pick it up.
		$this->assertSame( KeyVault::STATUS_REUPLOAD, $this->stored_row( $id )['status'] );
		$this->assertSame( array( $id ), $vault->unreadable() );
		$this->assertSame( array( $id ), $this->make_vault( $secret )->unreadable(), 'Visible to a fresh instance: it is found by trying the key, not cached.' );
		$this->assertSame( KeyVault::STATUS_REUPLOAD, $this->make_vault( $secret )->get( $id )['status'] );
	}

	/**
	 * The account id is the authenticated data of the GCM blob, so a blob
	 * copied from one row to another - the only "tampering" that keeps a valid
	 * tag - is refused too.
	 */
	public function test_a_blob_moved_to_another_account_row_does_not_open(): void {
		$vault = $this->make_vault();
		$a     = $this->add_fixture( $vault, 'A' );
		$b     = $this->add_fixture( $vault, 'B' );

		$this->options[ KeyVault::OPTION_NAME ][ $b ]['key'] = $this->options[ KeyVault::OPTION_NAME ][ $a ]['key'];

		$this->assertInstanceOf( ServiceAccountKey::class, $vault->open( $a ), 'The original row still opens.' );

		$moved = $vault->open( $b );
		$this->assertInstanceOf( \WP_Error::class, $moved );
		$this->assertSame( 'gtm4wp_google_key_unreadable', $moved->get_error_code() );
		$this->assertSame( array( $b ), $vault->unreadable() );
	}

	public function test_re_uploading_replaces_the_unreadable_status_with_a_fresh_account(): void {
		$vault = $this->make_vault();
		$old   = $this->add_fixture( $vault );
		$this->options[ KeyVault::OPTION_NAME ][ $old ]['status'] = KeyVault::STATUS_REUPLOAD;

		$this->assertTrue( $vault->delete( $old ) );
		$fresh = $this->add_fixture( $vault );

		$this->assertSame( array(), $vault->unreadable() );
		$this->assertInstanceOf( ServiceAccountKey::class, $vault->open( $fresh ) );
	}

	// ---- Replace key (#468) -------------------------------------------------

	/**
	 * A key of the same service account parsed from the fixture with a new key id.
	 *
	 * @param array<string, mixed> $overrides See KeyFileFixture::key_data().
	 * @return ServiceAccountKey
	 */
	private function other_key( array $overrides = array( 'private_key_id' => 'ffffffffffffffffffffffffffffffffffffffff' ) ): ServiceAccountKey {
		$key = ServiceAccountKey::from_json( KeyFileFixture::key_file( $overrides ) );
		$this->assertInstanceOf( ServiceAccountKey::class, $key );

		return $key;
	}

	public function test_replace_key_recovers_an_unreadable_account_under_the_same_id(): void {
		$id = $this->add_fixture( $this->make_vault() );
		$this->make_vault()->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-a' );
		$before = $this->stored_row( $id );

		$rotated = $this->make_vault( 'a-different-secret-after-salt-rotation' );
		$this->assertSame( array( $id ), $rotated->unreadable() );

		$account = $rotated->replace_key( $id, $this->other_key() );

		$this->assertIsArray( $account );
		$this->assertSame( $id, $account['id'] );
		$this->assertSame( KeyVault::STATUS_UNVERIFIED, $account['status'] );
		$this->assertSame( 'ffffffffffffffffffffffffffffffffffffffff', $account['private_key_id'] );
		$this->assertSame( 'Production', $account['label'], 'The label is kept.' );
		$this->assertSame( array(), $rotated->unreadable() );
		$this->assertInstanceOf( ServiceAccountKey::class, $rotated->open( $id ) );
		$this->assertNotSame( $before['key'], $this->stored_row( $id )['key'] );
		$this->assertSame( 0, $this->stored_row( $id )['last_checked'] );
		$this->assertSame( array( 'gtm4wp_google_token_' . $id . '_' ), array_map( static fn ( $n ) => substr( $n, 0, strlen( 'gtm4wp_google_token_' . $id . '_' ) ), $this->deleted_transients ), 'The token minted with the old key is purged.' );
	}

	public function test_replace_key_refuses_a_key_of_another_service_account_and_changes_nothing(): void {
		$vault  = $this->make_vault();
		$id     = $this->add_fixture( $vault );
		$before = $this->stored_row( $id );

		$result = $vault->replace_key( $id, $this->other_key( array( 'client_email' => 'someone-else@other-project.iam.gserviceaccount.com' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_other_account', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( $before, $this->stored_row( $id ) );
		$this->assertSame( array(), $this->deleted_transients );
	}

	public function test_replace_key_of_an_unknown_id_is_a_404(): void {
		$result = $this->make_vault()->replace_key( 'sa_000000000000', $this->other_key() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( KeyVault::OPTION_NAME, $this->options );
	}

	// ---- GTM4WP_ENCRYPTION_KEY (#469) ----------------------------------------

	private const CONSTANT = 'a-gtm4wp-encryption-key-of-at-least-32-chars';

	/**
	 * A vault with the encryption key in use, over the given salts.
	 *
	 * @param string $salts The salt material.
	 * @return KeyVault
	 */
	private function constant_vault( string $salts = self::SECRET ): KeyVault {
		return new KeyVault( $salts, static fn () => self::NOW, self::CONSTANT );
	}

	public function test_a_key_sealed_with_the_constant_survives_a_salt_rotation(): void {
		$id = $this->add_fixture( $this->constant_vault() );

		$this->assertSame( KeyVault::SECRET_CONSTANT, $this->stored_row( $id )['key']['secret'] );

		$rotated = $this->constant_vault( 'a-different-secret-after-salt-rotation' );
		$this->assertSame( array(), $rotated->unreadable() );
		$this->assertInstanceOf( ServiceAccountKey::class, $rotated->open( $id ) );
		$this->assertInstanceOf( \WP_Error::class, $this->make_vault()->open( $id ), 'The salts alone do not open it.' );
	}

	public function test_adding_the_constant_reseals_a_salt_key_on_the_first_look(): void {
		$id = $this->add_fixture( $this->make_vault() );
		$this->assertSame( KeyVault::SECRET_SALTS, $this->stored_row( $id )['key']['secret'] );

		$this->constant_vault()->all();

		$this->assertSame( KeyVault::SECRET_CONSTANT, $this->stored_row( $id )['key']['secret'] );
		$this->assertInstanceOf( ServiceAccountKey::class, $this->constant_vault( 'a-different-secret-after-salt-rotation' )->open( $id ) );
	}

	public function test_the_reseal_never_overwrites_a_key_replaced_meanwhile(): void {
		$id       = $this->add_fixture( $this->make_vault() );
		$replaced = array( 'replaced-by-a-concurrent-request' );

		// The reseal's fresh read is the window a concurrent replace lands in.
		Functions\when( 'wp_cache_delete' )->alias(
			function () use ( $id, $replaced ) {
				$this->options[ KeyVault::OPTION_NAME ][ $id ]['key'] = $replaced;
				return true;
			}
		);

		$this->constant_vault()->all();

		$this->assertSame( $replaced, $this->stored_row( $id )['key'] );
	}

	public function test_removing_the_constant_after_the_reseal_names_it(): void {
		$id = $this->add_fixture( $this->constant_vault() );

		$salts_only = $this->make_vault();

		$this->assertSame( array( $id ), $salts_only->unreadable() );
		$this->assertSame( array( KeyVault::CAUSE_CONSTANT_MISSING => array( $id ) ), $salts_only->unreadable_by_cause() );
		$this->assertSame( array(), $this->constant_vault()->unreadable_by_cause() );
	}

	public function test_each_unreadable_account_is_reported_under_its_own_cause(): void {
		$constant = $this->add_fixture( $this->constant_vault(), 'Constant' );
		$salts    = $this->add_fixture( $this->make_vault(), 'Salts' );

		$this->assertSame(
			array(
				KeyVault::CAUSE_CONSTANT_MISSING => array( $constant ),
				KeyVault::CAUSE_SALTS            => array( $salts ),
			),
			$this->make_vault( 'a-different-secret-after-salt-rotation' )->unreadable_by_cause(),
			'Restoring the constant would not fix the salt-sealed one, so it is not blamed on it.'
		);

		$changed = new KeyVault( self::SECRET, static fn () => self::NOW, 'another-gtm4wp-encryption-key-of-32-chars' );
		$this->assertSame( array( KeyVault::CAUSE_CONSTANT_CHANGED => array( $constant ) ), $changed->unreadable_by_cause() );
	}

	/**
	 * A mint that opened the key before a replace records nothing on the new
	 * key and tells its caller not to cache the token (#468).
	 */
	public function test_a_mint_with_a_replaced_key_records_nothing(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );
		$vault->replace_key( $id, $this->other_key() );
		$before = $this->stored_row( $id );

		$this->assertFalse( $vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-a', false, KeyFileFixture::PRIVATE_KEY_ID ) );
		$this->assertSame( $before, $this->stored_row( $id ) );

		$this->assertTrue( $vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-a', false, 'ffffffffffffffffffffffffffffffffffffffff' ) );
		$this->assertSame( KeyVault::STATUS_OK, $this->stored_row( $id )['status'] );
	}

	/**
	 * Status writers re-read the row just before writing, so a key replaced
	 * by another request before that read survives them (#468, C1).
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function status_writers(): array {
		return array(
			'token result' => array( static fn ( KeyVault $vault, string $id ) => $vault->record_token_result( $id, false, 'refused' ) ),
			'relabel'      => array( static fn ( KeyVault $vault, string $id ) => $vault->relabel( $id, 'Renamed' ) ),
		);
	}

	/**
	 * A key replaced before the writer's re-read is kept.
	 *
	 * @param callable $write The status write.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'status_writers' )]
	public function test_a_status_write_keeps_a_key_replaced_by_another_request( callable $write ): void {
		$vault    = $this->make_vault();
		$id       = $this->add_fixture( $vault );
		$replaced = array( 'replaced-by-a-concurrent-request' );

		// The writer's fresh read is where another request's replace shows up.
		Functions\when( 'wp_cache_delete' )->alias(
			function () use ( $id, $replaced ) {
				$this->options[ KeyVault::OPTION_NAME ][ $id ]['key'] = $replaced;
				return true;
			}
		);

		$write( $vault, $id );

		$this->assertSame( $replaced, $this->stored_row( $id )['key'] );
	}

	/**
	 * The stored token endpoint is not re-validated on open: a row written
	 * before an endpoint change keeps working (#498).
	 */
	public function test_a_stored_token_endpoint_never_locks_the_row(): void {
		$id = $this->add_fixture( $this->make_vault() );
		$this->options[ KeyVault::OPTION_NAME ][ $id ]['token_uri'] = 'https://oauth2.example.com/token';

		$key = $this->make_vault()->open( $id );

		$this->assertInstanceOf( ServiceAccountKey::class, $key );
		$this->assertSame( TokenService::TOKEN_ENDPOINT, $key->token_uri );
	}

	// ---- Custody across the R53 additions -------------------------------------

	/**
	 * The vault's public surface, pinned whole: a new public method is a new
	 * place key material could leave from, so adding one fails here first.
	 * Only open() may name ServiceAccountKey in its return type.
	 */
	public function test_only_open_hands_out_key_material(): void {
		$methods = array();
		$openers = array();

		foreach ( ( new \ReflectionClass( KeyVault::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$methods[] = $method->getName();
			if ( false !== strpos( (string) $method->getReturnType(), 'ServiceAccountKey' ) ) {
				$openers[] = $method->getName();
			}
		}

		sort( $methods );
		$this->assertSame(
			array( '__construct', 'add', 'all', 'clean_label', 'delete', 'encryption_key_state', 'get', 'has', 'open', 'record_token_result', 'relabel', 'replace_key', 'safe_label', 'unreadable', 'unreadable_by_cause' ),
			$methods
		);
		$this->assertSame( array( 'open' ), $openers );

		$vault = new KeyVault( self::SECRET, static fn () => self::NOW, 'a-gtm4wp-encryption-key-of-at-least-32-chars' );
		$id    = $this->add_fixture( $this->make_vault() );
		$flat  = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening to search.
			array( $vault->all(), $vault->get( $id ), $vault->replace_key( $id, $this->other_key() ), $vault->unreadable(), $vault->unreadable_by_cause() )
		);

		$this->assertStringNotContainsString( 'PRIVATE KEY', $flat );
		$this->assertStringNotContainsString( 'ciphertext', $flat );
		$this->assertStringNotContainsString( $this->stored_row( $id )['key']['ciphertext'], $flat );
	}

	// ---- Deletion ----------------------------------------------------------

	public function test_delete_removes_the_row_entry(): void {
		$vault = $this->make_vault();
		$keep  = $this->add_fixture( $vault, 'Keep' );
		$drop  = $this->add_fixture( $vault, 'Drop' );

		$this->assertTrue( $vault->delete( $drop ) );

		$this->assertSame( array( $keep ), array_keys( $this->options[ KeyVault::OPTION_NAME ] ) );
		$this->assertFalse( $vault->has( $drop ) );
		$this->assertTrue( $vault->has( $keep ) );
	}

	/**
	 * The vault owns the delete-time token purge (#226): every scope the
	 * account ever minted for is forgotten inside delete(), so any deletion
	 * path - not only the REST route - drops the cached tokens, and a scope
	 * added later is covered without another hardcoded forget call.
	 */
	public function test_delete_forgets_the_cached_token_of_every_minted_scope(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		$vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-a' );
		$vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-b' );

		$this->assertTrue( $vault->delete( $id ) );

		$this->assertCount( 2, $this->deleted_transients, 'One purge per minted scope.' );
		$this->assertCount( 2, array_unique( $this->deleted_transients ), 'The two scopes cache under different names.' );
		foreach ( $this->deleted_transients as $name ) {
			$this->assertStringStartsWith( 'gtm4wp_google_token_' . $id . '_', $name );
		}
	}

	public function test_a_scope_minted_twice_is_remembered_once_and_purged_once(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		$vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-a' );
		$vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-a' );

		$this->assertTrue( $vault->delete( $id ) );

		$this->assertCount( 1, $this->deleted_transients, 'The scope list is deduplicated, so one purge.' );
	}

	public function test_delete_of_a_never_minted_account_purges_nothing(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		$this->assertTrue( $vault->delete( $id ) );

		$this->assertSame( array(), $this->deleted_transients, 'No mint ever happened, so no token can be cached.' );
	}

	public function test_delete_of_an_unknown_id_is_an_error_and_writes_nothing(): void {
		$vault = $this->make_vault();
		$this->add_fixture( $vault );
		$writes_before = count( $this->option_writes );

		$result = $vault->delete( 'sa_ffffffffffff' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertCount( $writes_before, $this->option_writes );
	}

	/**
	 * The in-use filter is the contract a future consumer (a Data Manager
	 * destination) relies on to keep its account from being pulled out from
	 * under it. Both directions: a true answer refuses, the default false lets
	 * the deletion through.
	 */
	public function test_delete_is_refused_while_a_consumer_reports_the_account_in_use(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		Filters\expectApplied( GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE )
			->once()
			->with( false, $id )
			->andReturn( true );

		$result = $vault->delete( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_in_use', $result->get_error_code() );
		$this->assertTrue( $vault->has( $id ), 'The account survives.' );
	}

	public function test_delete_proceeds_when_the_in_use_filter_is_left_at_its_default(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		Filters\expectApplied( GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE )
			->once()
			->with( false, $id )
			->andReturnFirstArg();

		$this->assertTrue( $vault->delete( $id ) );
		$this->assertFalse( $vault->has( $id ) );
	}

	// ---- Status ------------------------------------------------------------

	public function test_record_token_result_stores_the_outcome_and_a_capped_sanitized_error(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		$vault->record_token_result( $id, false, "invalid_grant: <b>Invalid</b> JWT\nsignature. " . str_repeat( 'x', 300 ) );

		$account = $vault->get( $id );
		$this->assertSame( KeyVault::STATUS_ERROR, $account['status'] );
		$this->assertSame( self::NOW, $account['last_checked'] );
		$this->assertStringStartsWith( 'invalid_grant: Invalid JWT signature. ', $account['last_error'], 'Tags and line breaks are stripped before storage.' );
		$this->assertSame( 200, mb_strlen( $account['last_error'] ), 'The stored error is capped.' );

		$vault->record_token_result( $id, true );

		$account = $vault->get( $id );
		$this->assertSame( KeyVault::STATUS_OK, $account['status'] );
		$this->assertSame( '', $account['last_error'], 'A success clears the previous error.' );
	}

	/**
	 * The concurrency mitigation the background send lanes made worth having:
	 * the row is read, modified and written whole, with no compare-and-swap in
	 * the options API, and a cache miss can send several queued jobs to the
	 * token endpoint at once. A repeat that would change nothing but the
	 * timestamp therefore writes nothing.
	 */
	public function test_a_repeated_successful_mint_does_not_rewrite_the_row(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );
		$scope = 'https://www.googleapis.com/auth/datamanager';

		$vault->record_token_result( $id, true, '', $scope );
		$writes_after_first = count( $this->option_writes );

		$vault->record_token_result( $id, true, '', $scope );
		$vault->record_token_result( $id, true, '', $scope );

		$this->assertCount( $writes_after_first, $this->option_writes );
		$this->assertSame( KeyVault::STATUS_OK, $vault->get( $id )['status'], 'The recorded outcome is unchanged, which is why the write was pointless.' );
	}

	public function test_a_failure_always_writes_however_recent_the_last_check(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );
		$scope = 'https://www.googleapis.com/auth/datamanager';

		$vault->record_token_result( $id, true, '', $scope );
		$writes_after_success = count( $this->option_writes );

		$vault->record_token_result( $id, false, 'invalid_grant', $scope );

		$this->assertGreaterThan( $writes_after_success, count( $this->option_writes ) );
		$this->assertSame( KeyVault::STATUS_ERROR, $vault->get( $id )['status'] );
	}

	public function test_a_success_after_a_failure_always_writes(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );
		$scope = 'https://www.googleapis.com/auth/datamanager';

		$vault->record_token_result( $id, false, 'invalid_grant', $scope );
		$writes_after_failure = count( $this->option_writes );

		$vault->record_token_result( $id, true, '', $scope );

		$this->assertGreaterThan( $writes_after_failure, count( $this->option_writes ), 'The account recovering is exactly what the admin notice is waiting for.' );
		$this->assertSame( '', $vault->get( $id )['last_error'] );
	}

	public function test_a_newly_used_scope_always_writes(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );

		$vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-a' );
		$writes_after_first = count( $this->option_writes );

		$vault->record_token_result( $id, true, '', 'https://www.googleapis.com/auth/scope-b' );

		$this->assertGreaterThan(
			$writes_after_first,
			count( $this->option_writes ),
			'The scope list is what delete() purges cached tokens from; losing an entry would leave a usable token behind.'
		);
	}

	public function test_the_test_button_always_refreshes_the_checked_time(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );
		$scope = 'https://www.googleapis.com/auth/datamanager';

		$vault->record_token_result( $id, true, '', $scope );
		$writes_after_first = count( $this->option_writes );

		$vault->record_token_result( $id, true, '', $scope, true );

		$this->assertGreaterThan(
			$writes_after_first,
			count( $this->option_writes ),
			'An admin who pressed Test has to see the time move, whatever the throttle would otherwise say.'
		);
	}

	public function test_a_stale_check_writes_again_even_when_nothing_changed(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault );
		$scope = 'https://www.googleapis.com/auth/datamanager';

		$vault->record_token_result( $id, true, '', $scope );
		$writes_after_first = count( $this->option_writes );

		// A later vault, past the refresh interval: the timestamp is the whole
		// point of the record, so it does not go stale indefinitely.
		$later = new KeyVault( self::SECRET, static fn () => self::NOW + KeyVault::STATUS_REFRESH_INTERVAL + 1 );
		$later->record_token_result( $id, true, '', $scope );

		$this->assertGreaterThan( $writes_after_first, count( $this->option_writes ) );
		$this->assertSame( self::NOW + KeyVault::STATUS_REFRESH_INTERVAL + 1, $later->get( $id )['last_checked'] );
	}

	public function test_record_token_result_for_an_unknown_id_writes_nothing(): void {
		$vault = $this->make_vault();
		$this->add_fixture( $vault );
		$writes_before = count( $this->option_writes );

		$vault->record_token_result( 'sa_ffffffffffff', true );

		$this->assertCount( $writes_before, $this->option_writes );
	}

	// ---- Labels ------------------------------------------------------------

	public function test_an_empty_label_falls_back_to_the_account_email(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault, "  \n " );

		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $vault->get( $id )['label'] );
	}

	public function test_a_label_is_sanitized_and_capped_by_characters_not_bytes(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault, '<script>x</script>' . str_repeat( 'é', 150 ) );

		$label = $vault->get( $id )['label'];

		$this->assertStringNotContainsString( '<', $label );
		$this->assertSame( KeyVault::LABEL_MAX_LENGTH, mb_strlen( $label ), 'Capped at the character limit.' );
		$this->assertSame( 'x' . str_repeat( 'é', KeyVault::LABEL_MAX_LENGTH - 1 ), $label, 'A multibyte character is never cut in half.' );
	}

	public function test_relabel_changes_the_label_and_nothing_else(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault, 'Production' );

		$row_before = $this->stored_row( $id );
		$result     = $vault->relabel( $id, 'Live site' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Live site', $result['label'], 'The refreshed public view answers the new label.' );
		$this->assertSame( 'Live site', $vault->get( $id )['label'] );
		$this->assertArrayNotHasKey( 'key', $result, 'The public view stays key-free on this path too.' );

		$row_after = $this->stored_row( $id );
		unset( $row_before['label'], $row_after['label'] );
		$this->assertSame( $row_before, $row_after, 'The sealed key, status and timestamps are untouched - a relabel is never a re-seal.' );
		$this->assertSame( array(), $this->deleted_transients, 'No cached token is dropped: consumers reference the id, not the label.' );
	}

	public function test_relabel_runs_the_new_label_through_the_same_cleaning_as_add(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault, 'Production' );

		$vault->relabel( $id, '<script>x</script>' . str_repeat( 'é', 150 ) );

		$label = $vault->get( $id )['label'];
		$this->assertStringNotContainsString( '<', $label );
		$this->assertSame( 'x' . str_repeat( 'é', KeyVault::LABEL_MAX_LENGTH - 1 ), $label );
	}

	public function test_relabel_to_an_empty_label_falls_back_to_the_account_email(): void {
		$vault = $this->make_vault();
		$id    = $this->add_fixture( $vault, 'Production' );

		$vault->relabel( $id, "  \n " );

		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $vault->get( $id )['label'] );
	}

	public function test_relabel_of_an_unknown_id_is_an_error_and_writes_nothing(): void {
		$vault = $this->make_vault();
		$this->add_fixture( $vault );
		$writes_before = count( $this->option_writes );

		$result = $vault->relabel( 'sa_ffffffffffff', 'Anything' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertCount( $writes_before, $this->option_writes );
	}

	/**
	 * Stand-in for what sanitize_text_field() does in core: strip tags,
	 * collapse whitespace, trim. Enough for the label/last-error assertions.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_text_field_stand_in( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', (string) preg_replace( '/<[^>]*>/', '', $value ) ) );
	}
}
